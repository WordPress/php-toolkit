import React, { useMemo } from 'react';
import { useEffect, createRoot } from '@wordpress/element';
import { FileNode, FilePickerTree } from './components/FilePickerTree';
import { MobileMenu } from './components/MobileMenu/index';
import { store as editorStore, ErrorBoundary } from '@wordpress/editor';
import { store as preferencesStore } from '@wordpress/preferences';
import {
	register,
	dispatch,
	select,
	resolveSelect,
	subscribe,
	useSelect,
} from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { store as noticesStore } from '@wordpress/notices';
import {
	addComponentToEditorContentArea,
	addLocalFilesTab,
} from './add-local-files-tab';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { Spinner } from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import css from './style.module.css';
import { FileSubtree } from 'components/FilePickerTree/types';
import './blocks/diff';
import {
	uiStore,
	WP_LOCAL_FILE_POST_TYPE,
	isPreviewableAssetPath,
} from './store';

// Register middleware to log any 500 error responses for easier
// debugging in development mode.
apiFetch.use((options, next) => {
	return next(options).catch((error) => {
		if (error?.data?.status === 500) {
			console.log(error.data.error?.message);
		} else if (error.message) {
			console.log(error.message);
		}
		throw error;
	});
});

const API_ROOT = (window as any).wpApiSettings.root;

const getCurrentNonce = () => {
	return (window as any).wpApiSettings.nonce;
};

const apiUrl = (path: string) => {
	const url = new URL(API_ROOT + path, window.location.href);
	url.searchParams.set('_wpnonce', getCurrentNonce());
	return url.toString();
};

// Create a custom store for transient UI state

register(uiStore);

type ConnectedFileNode = FileNode & {
	post_id?: string;
	post_title?: string;
};

function filesListToTree(list: ConnectedFileNode[]): ConnectedFileNode {
	const findChildren = (parentPath: string) => {
		return list
			.filter((item) => isDirectParentOf(parentPath, item.path))
			.map((item) => ({
				...item,
				name: item.path.split('/').pop() || '',
				children: findChildren(item.path),
			}));
	};

	return {
		path: '/',
		name: '',
		type: 'directory',
		children: list
			.filter((item) => item.path.split('/').length === 2)
			.map((item) => ({
				...item,
				name: item.post_title || item.path.split('/').pop() || '',
				children: findChildren(item.path),
			})),
	};
}

function isDirectParentOf(parentPath: string, childPath: string) {
	return (
		childPath.startsWith(parentPath + '/') &&
		!childPath.substring(parentPath.length + 1).includes('/')
	);
}

function ConnectedFilePickerTree() {
	const { selectedPath, filesList, isFileListInitialized } = useSelect(
		(select) => {
			const originalFilesList =
				select(coreStore).getEntityRecords(
					'static-files-editor',
					'files',
					{
						per_page: -1,
					}
				) || [];
			const filesList = originalFilesList
				.map((file) =>
					select(coreStore).getEditedEntityRecord(
						'static-files-editor',
						'files',
						file.id
					)
				)
				.filter((file) => !file.isDeleted);
			return {
				selectedPath: select(uiStore).getSelectedPath(),
				filesList,
				isFileListInitialized:
					filesList.length ||
					!select(coreStore).isResolving('getEntityRecords', [
						'static-files-editor',
						'files',
						{
							per_page: -1,
						},
					]),
			};
		},
		[]
	);

	// One-time only – initialize the selected path
	useEffect(() => {
		if (
			!isFileListInitialized ||
			selectedPath !== undefined ||
			filesList.length === 0
		) {
			return;
		}
		const initialEditedPostId = select(editorStore).getCurrentPostId();
		const initialFile = filesList.find(
			(file) => file.post_id === initialEditedPostId
		);
		dispatch(uiStore).setSelectedPath(initialFile?.path || '/');
	}, [isFileListInitialized]);

	const fileTree = useMemo(() => {
		return filesListToTree(filesList);
	}, [filesList]);

	const onNavigateToEntityRecord = useSelect(
		(select) =>
			select(blockEditorStore).getSettings().onNavigateToEntityRecord
	);

	const handleNodeDeleted = async (path: string) => {
		// For optimistic updates
		dispatch(coreStore).editEntityRecord(
			'static-files-editor',
			'files',
			path,
			{
				isDeleted: true,
			}
		);
		try {
			dispatch(coreStore).deleteEntityRecord(
				'static-files-editor',
				'files',
				path
			);
		} catch (e) {
			// Naively assume we haven't edited anything else in the meantime
			dispatch(coreStore).undo();
			dispatch(noticesStore).createErrorNotice(
				'Error moving file. Please try again.',
				{
					type: 'snackbar',
				}
			);
		}
	};

	const handleFileClick = async (path: string) => {
		dispatch(uiStore).setSelectedPath(path);
	};

	const handleNodesCreated = async (tree: FileSubtree) => {
		if (
			tree.children.length > 1 ||
			tree.children[0].type !== 'file' ||
			tree.children[0].content instanceof File
		) {
			// Batch create multiple files
			await dispatch(uiStore).createFilesBatch(tree);
			return;
		}
		// Create a single file
		const node = tree.children[0];
		const nodePath = `${tree.path}/${node.name}`.replace(/^\/+/, '/');
		let newFile = null;
		try {
			newFile = await dispatch(coreStore).saveEntityRecord(
				'static-files-editor',
				'files',
				{
					path: nodePath,
					content: node.content || '',
				},
				{ throwOnError: true }
			);
		} catch (e) {
			dispatch(noticesStore).createErrorNotice(
				'Error creating file. Please try again.',
				{
					type: 'snackbar',
				}
			);
			return;
		}
		if (!newFile.post_id) {
			return;
		}
		// Wait until the post is considered available. Otherwise
		// The editor will attempt to load the new post, fail,
		// hide the block canvas, fetch the post, receive it,
		// and only then show the editor again.
		await resolveSelect(coreStore).getEntityRecord(
			'postType',
			WP_LOCAL_FILE_POST_TYPE,
			newFile.post_id
		);
		onNavigateToEntityRecord({
			postId: newFile.post_id,
			postType: WP_LOCAL_FILE_POST_TYPE,
		});
		dispatch(uiStore).setSelectedPath(nodePath);
		return nodePath;
	};

	const handleNodeMoved = async ({
		fromPath,
		toPath,
	}: {
		fromPath: string;
		toPath: string;
	}) => {
		dispatch(coreStore).editEntityRecord(
			'static-files-editor',
			'files',
			fromPath,
			{
				path: toPath,
			}
		);
		try {
			await dispatch(coreStore).saveEditedEntityRecord(
				'static-files-editor',
				'files',
				fromPath,
				{ throwOnError: true }
			);
		} catch (e) {
			// Naively assume we haven't edited anything else in the meantime
			dispatch(coreStore).undo();
			dispatch(noticesStore).createErrorNotice(
				'Error moving file. Please try again.',
				{
					type: 'snackbar',
				}
			);
		}
	};

	/**
	 * Enable drag and drop of files from the file picker tree to desktop.
	 */
	const handleDragStart = (
		e: React.DragEvent,
		path: string,
		node: ConnectedFileNode
	) => {
		// Directory downloads are not supported yet.
		if (node.type === 'file') {
			const url = apiUrl(
				`static-files-editor/v1/download-file?path=${path}`
			);
			const filename = path.split('/').pop();
			// For dragging & dropping to desktop
			e.dataTransfer.setData(
				'DownloadURL',
				`text/plain:${filename}:${url}`
			);
			if ('post_type' in node && node.post_type === 'attachment') {
				// Create DOM elements to safely construct HTML

				const figure = document.createElement('figure');
				figure.className = 'wp-block-image size-full';

				const img = document.createElement('img');
				img.src = url;
				img.alt = '';
				img.className = `wp-image-${node.post_id}`;

				figure.appendChild(img);

				// Wrap in WordPress block comments
				// For dragging & dropping into the editor canvas
				e.dataTransfer.setData(
					'text/html',
					`<!-- wp:image {"id":${JSON.stringify(
						node.post_id
					).replaceAll(
						'-->',
						''
					)},"sizeSlug":"full","linkDestination":"none"} -->
${figure.outerHTML}
<!-- /wp:image -->`
				);
			} else if (isPreviewableAssetPath(path)) {
				const img = document.createElement('img');
				img.src = url;
				img.alt = filename;
				e.dataTransfer.setData('text/html', img.outerHTML);
			}
		}
	};

	if (!isFileListInitialized) {
		return <Spinner />;
	}

	if (selectedPath === undefined) {
		// Wait until the selected path is initialized
		return <Spinner />;
	}

	if (!fileTree) {
		return <div>No files found</div>;
    }

	return (
		<FilePickerTree
			treeRoot={fileTree}
			onSelect={handleFileClick}
			selectedPath={selectedPath}
			onNodesCreated={handleNodesCreated}
			onNodeDeleted={handleNodeDeleted}
			onNodeMoved={handleNodeMoved}
			onDragStart={handleDragStart as any}
		/>
	);
}

addLocalFilesTab({
	name: 'local-files',
	title: 'Local Files',
	panel: (
		<div
			className={css['file-picker-tree-container']}
			id="file-picker-tree-container"
		>
			<ErrorBoundary>
				<ConnectedFilePickerTree />
			</ErrorBoundary>
		</div>
	),
});

function FilePreviewOverlay() {
	const selectedNode = useSelect(
		(select) => select(uiStore).getSelectedNode(),
		[]
	);

	if (
		!selectedNode ||
		selectedNode.type !== 'file' ||
		!isPreviewableAssetPath(selectedNode.path)
	) {
		return null;
	}

	const extension = selectedNode.path.split('.').pop()?.toLowerCase();
	const isPreviewable = ['jpg', 'jpeg', 'png', 'gif', 'svg'].includes(
		extension || ''
	);

	return (
		<div
			style={{
				position: 'absolute',
				top: 0,
				left: 0,
				right: 0,
				bottom: 0,
				backgroundColor: 'white',
				padding: '20px',
				zIndex: 1000,
			}}
		>
			<h2>{selectedNode.path.split('/').pop()}</h2>
			{isPreviewable ? (
				<img
					src={apiUrl(
						`static-files-editor/v1/download-file?path=${selectedNode.path}`
					)}
					alt={selectedNode.path}
					style={{ maxWidth: '100%', maxHeight: '80vh' }}
				/>
			) : (
				<div>Preview not available for this file type</div>
			)}
		</div>
	);
}

addComponentToEditorContentArea(<FilePreviewOverlay />);

function PostLoadingOverlay() {
	const isResolvingPost = useSelect((select) => {
		const selectedPath = select(uiStore).getSelectedPath();
		if (!selectedPath) {
			return false;
		}
		if (isPreviewableAssetPath(selectedPath)) {
			return false;
		}
		const file = select(coreStore).getEntityRecord(
			'static-files-editor',
			'files',
			selectedPath
		);
		if (!file?.post_id) {
			return false;
		}
		const isResolvingPostId = select(uiStore).isPostIdResolving();
		if (isResolvingPostId) {
			return true;
		}
		const isResolvingPost = !select(coreStore).hasFinishedResolution(
			'getEntityRecord',
			['postType', WP_LOCAL_FILE_POST_TYPE, file.post_id]
		);
		return isResolvingPost;
	}, []);
	if (!isResolvingPost) {
		return null;
	}
	return (
		<div
			style={{
				position: 'absolute',
				top: 0,
				left: 0,
				right: 0,
				bottom: 0,
				backgroundColor: 'rgba(0, 0, 0, 0.5)',
				display: 'flex',
				alignItems: 'center',
				justifyContent: 'center',
				zIndex: 1000,
			}}
		>
			<Spinner />
		</div>
	);
}

addComponentToEditorContentArea(<PostLoadingOverlay />);

dispatch(preferencesStore).set('welcomeGuide', false);
dispatch(preferencesStore).set('enableChoosePatternModal', false);
dispatch(editorStore).setIsListViewOpened(true);

function MobileMenuContainer() {
	useEffect(() => {
		const waitForEditPostLayout = setInterval(() => {
			const editPostLayout = document.querySelector(
				'.interface-interface-skeleton__editor'
			);
			if (editPostLayout) {
				clearInterval(waitForEditPostLayout);
				const mobileMenuContainer = document.createElement('div');
				editPostLayout.appendChild(mobileMenuContainer);

				const root = createRoot(mobileMenuContainer);
				root.render(<MobileMenu />);
			}
		}, 100);

		return () => clearInterval(waitForEditPostLayout);
	}, []);

	return null;
}

addComponentToEditorContentArea(<MobileMenuContainer />);

/**
 * On mobile devices, when a block is inserted when the inserter sidebar is open,
 * keeping the sidebar open is confusing – as in "wait, did I just insert a block?"
 * This function closes the sidebar when a block is inserted on mobile.
 */
const closeInserterOnBlockInsert = () => {
	let previousBlocks = select('core/block-editor').getBlocks();

	subscribe(() => {
		const currentBlocks = select('core/block-editor').getBlocks();

		if (currentBlocks.length > previousBlocks.length) {
			// Adjust the selector to match your container
			const filePickerContainer = document.querySelector(
				'.editor-inserter-sidebar'
			) as any;
			if (
				filePickerContainer &&
				filePickerContainer.offsetWidth > window.innerWidth * 0.9
			) {
				dispatch(editorStore).setIsInserterOpened(false);
			}
		}
		previousBlocks = currentBlocks;
	});
};

closeInserterOnBlockInsert();
