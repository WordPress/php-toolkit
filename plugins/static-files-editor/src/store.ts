import { createReduxStore, dispatch, select, resolveSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import apiFetch from '@wordpress/api-fetch';
import { store as coreStore } from '@wordpress/core-data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { FileNode } from 'components/FilePickerTree';
import { FileSubtree } from 'components/FilePickerTree/types';

// Pre-populated by plugin.php
// @ts-ignore
export const WP_LOCAL_FILE_POST_TYPE = window.WP_LOCAL_FILE_POST_TYPE;

export const isPreviewableAssetPath = (path: string) => {
	let extension = undefined;
	const lastDot = path.lastIndexOf('.');
	if (lastDot !== -1) {
		extension = path.substring(lastDot + 1).toLowerCase();
	}
	// We treat every extension except of the well-known ones
	// as a static asset.
	return extension && !['md', 'html', 'xhtml'].includes(extension);
};

await dispatch(coreStore).addEntities([
	{
		label: 'Local files',
		kind: 'static-files-editor',
		name: 'files',
		baseURL: '/static-files-editor/v1/files',
	},
]);

const STORE_NAME = 'static-files-editor/ui';
export const uiStore = createReduxStore(STORE_NAME, {
	reducer(
		state = {
			selectedPath: undefined,
			isListViewOpened: true,
			isPostIdResolving: false,
		},
		action
	) {
		switch (action.type) {
			case 'SET_SELECTED_PATH':
				return { ...state, selectedPath: action.path };
			case 'SET_POST_ID_RESOLVING':
				return { ...state, isPostIdResolving: action.isResolving };
			default:
				return state;
		}
	},
	actions: {
		closeListViewOnMobile() {
			return async ({ registry }) => {
				const filePickerContainer = document.getElementById(
					'file-picker-tree-container'
				);
				if (
					filePickerContainer &&
					filePickerContainer.offsetWidth > window.innerWidth * 0.9
				) {
					registry.dispatch(editorStore).setIsListViewOpened(false);
				}
			};
		},

		setSelectedPath(path) {
            return async ({ dispatch, registry, select }) => {
                dispatch({ type: 'SET_SELECTED_PATH', path });
                
				const node = registry
					.select(coreStore)
					.getEntityRecord('static-files-editor', 'files', path);
				if (!node) {
					return;
				}
				if (isPreviewableAssetPath(path)) {
					dispatch({
						type: 'SET_PREVIEW_PATH',
						path: path,
					});
					registry.dispatch(uiStore).closeListViewOnMobile();
					return;
				}

				const selectedFile = registry
					.select(coreStore)
					.getEntityRecord('static-files-editor', 'files', path);
				if (selectedFile.type === 'file') {
					const postId =
						selectedFile.postId ||
						(await dispatch.getOrCreatePostForFile(path));
					dispatch({ type: 'SET_PREVIEW_PATH', path: null });
					const post = await registry
						.resolveSelect(coreStore)
						.getEntityRecord(
							'postType',
							WP_LOCAL_FILE_POST_TYPE,
							postId
						);
					const onNavigateToEntityRecord = registry
						.select(blockEditorStore)
						.getSettings().onNavigateToEntityRecord;
					onNavigateToEntityRecord({
						postId: post.id,
						postType: WP_LOCAL_FILE_POST_TYPE,
					});
					registry.dispatch(uiStore).closeListViewOnMobile();
				}
			};
		},
		createFilesBatch(tree: FileSubtree) {
			return async ({ registry }) => {
				const formData = new FormData();
				formData.append('path', tree.path);

				const processNode = (node: FileNode, prefix: string): any => {
					const nodeData = { ...node } as any;
					if (node.content instanceof File) {
						formData.append(`${prefix}_content`, node.content);
						nodeData.content = `@file:${prefix}_content`;
					}
					if (node.children) {
						nodeData.children = node.children.map((child, index) =>
							processNode(child, `${prefix}_${index}`)
						);
					}
					return nodeData;
				};

				const processedNodes = tree.children.map((node, index) =>
					processNode(node as any, `file_${index}`)
				);
				formData.append('content', JSON.stringify(processedNodes));

				const response = (await apiFetch({
					path: '/static-files-editor/v1/files/batch',
					method: 'POST',
					body: formData,
				})) as {
					created_files: Array<{ path: string; post_id: string }>;
				};
				const entityRecords = {};
				for (const { path, post_id } of response.created_files) {
					entityRecords[path] = {
						id: path,
						post_id,
						path,
					};
				}
				registry
					.dispatch(coreStore)
					.receiveEntityRecords(
						'static-files-editor',
						'files',
						entityRecords,
						undefined,
						true
					);
			};
		},
		getOrCreatePostForFile(path) {
			return async ({ registry }) => {
				dispatch({ type: 'SET_POST_ID_RESOLVING', isResolving: true });
				try {
					const knownFiles = registry
						.select(coreStore)
						.getEntityRecords('static-files-editor', 'files', {
							per_page: -1,
						});

					// Try to find a known in-memory file representing the requested path
					const knownFile = knownFiles.find(
						(file) => file.path === path
					);
					if (knownFile?.post_id) {
						return knownFile.post_id;
					}

					const { post_id } = await apiFetch({
						path: '/static-files-editor/v1/get-or-create-post-for-file',
						method: 'POST',
						data: { path },
					});

					// Update the in-memory entity record
					registry
						.dispatch(coreStore)
						.editEntityRecord(
							'static-files-editor',
							'files',
							path,
							{
								post_id,
							}
						);
					return post_id;
				} finally {
					dispatch({
						type: 'SET_POST_ID_RESOLVING',
						isResolving: false,
					});
				}
			};
		},
	},
	selectors: {
		isFileListLoading(state) {
			return state.isFileListLoading;
		},
		isPostIdResolving(state) {
			return state.isPostIdResolving;
		},
        getSelectedPath(state) {
			return state.selectedPath;
		},
		getParentNode(state, path) {
			const parentPath = path.split('/').slice(0, -1).join('/') || '/';
			return state.files.find((node) => node.path === parentPath);
        },
        getSelectedNode(state) {
            return select(coreStore).getEntityRecord('static-files-editor', 'files', state.selectedPath);
        },
		listFiles(state, path) {
			const parentNode = this.getParentNode(state, path);
			if (parentNode) {
				return parentNode?.children || [];
			}
			return state.files.filter((node) => isTopLevelPath(node.path));
		},
	},
});

function isTopLevelPath(path: string) {
	return path.match(/^\/[^/]+$/);
}
