import { createReduxStore, dispatch, select } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import apiFetch from '@wordpress/api-fetch';
import { store as coreStore } from '@wordpress/core-data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { FileNode } from 'components/FilePickerTree';
import { FileSubtree } from 'components/FilePickerTree/types';
import { serialize } from '@wordpress/blocks';

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

export function getPostContent(post) {
	const contentField = post.content;
	if (post.blocks) {
		return serialize(post.blocks);
	}
	if (typeof contentField === 'string') {
		return contentField.trim();
	}
	if (typeof contentField?.raw === 'string') {
		return contentField.raw.trim();
	}
	return false;
}


const STORE_NAME = 'static-files-editor/ui';
export const uiStore = createReduxStore(STORE_NAME, {
	reducer(
		state = {
			selectedPath: undefined,
			isListViewOpened: true,
			isPostIdResolving: false,
			dataSourceSyncInfo: undefined,
			isSyncingDataSource: false,
		},
		action
	) {
		switch (action.type) {
			case 'SET_SELECTED_PATH':
				return { ...state, selectedPath: action.path };
			case 'SET_POST_ID_RESOLVING':
				return { ...state, isPostIdResolving: action.isResolving };
			case 'SET_LAST_DATA_SOURCE_SYNC_INFO':
				return { ...state, dataSourceSyncInfo: action.syncInfo };
			case 'UPDATE_LAST_DATA_SOURCE_SYNC_INFO':
				return {
					...state,
					dataSourceSyncInfo: {
						...state.dataSourceSyncInfo,
						...action.syncInfo,
					},
				};
			case 'SET_IS_SYNCING_DATA_SOURCE':
				return { ...state, isSyncingDataSource: action.isSyncing };
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
				if (node.type === 'file' && isPreviewableAssetPath(path)) {
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
		setHasUnsyncedChanges(hasUnsyncedChanges) {
			return {
				type: 'UPDATE_LAST_DATA_SOURCE_SYNC_INFO',
				syncInfo: {
					hasUnsyncedChanges,
				},
			};
		},
		syncDataSource() {
			return async ({ dispatch, select, registry }) => {
				if (select.isSyncingDataSource()) {
					return;
				}
				dispatch({
					type: 'SET_IS_SYNCING_DATA_SOURCE',
					isSyncing: true,
				});
				try {
					const syncInfo = await apiFetch({
						path: '/static-files-editor/v1/data-source/sync',
						method: 'POST',
					});
					dispatch({
						type: 'SET_LAST_DATA_SOURCE_SYNC_INFO',
						syncInfo,
					});
				} catch (error) {
					dispatch({
						type: 'UPDATE_LAST_DATA_SOURCE_SYNC_INFO',
						syncInfo: {
							error: true,
						},
					});
					throw error;
				} finally {
					dispatch({
						type: 'SET_IS_SYNCING_DATA_SOURCE',
						isSyncing: false,
					});
				}

				// Invalidate all the posts except the currently selected one
				const currentPostId = registry.select(editorStore).getCurrentPostId();
				const files = registry
					.select(coreStore)
					.getEntityRecords('static-files-editor', 'files', {
						per_page: -1,
					});
				for (const file of files) {
					if (!file.post_id || file.post_id === currentPostId) {
						continue;
					}
					registry
						.dispatch(coreStore)
						.invalidateResolution('getEntityRecord', [
							'postType',
							WP_LOCAL_FILE_POST_TYPE,
							file.post_id,
						]);
				}

				// Re-fetch the currently selected post. We can't just invalidate it
				// as we would lose all the unsaved edits.
				await dispatch.refreshCurrentPost();
			};
		},
		refreshCurrentPost() {
			return async ({ registry }) => {
				const { select, dispatch } = registry;
				const currentPostId = select(editorStore).getCurrentPostId();
				const post = select(coreStore).getEntityRecord(
					'postType',
					WP_LOCAL_FILE_POST_TYPE,
					currentPostId
				);
				const editedPost = select(coreStore).getEditedEntityRecord(
					'postType',
					WP_LOCAL_FILE_POST_TYPE,
					currentPostId
				);
				const postContent = getPostContent(post);
				const editedPostContent = getPostContent(editedPost);
				const hasEdits = postContent !== editedPostContent;
				if (hasEdits) {
					// Make sure the post content is up to date before autosaving.
					await dispatch(coreStore).editEntityRecord(
						'postType',
						WP_LOCAL_FILE_POST_TYPE,
						currentPostId,
						{ content: { raw: editedPostContent } }
					);
					await dispatch(coreStore).saveEditedEntityRecord(
						'postType',
						WP_LOCAL_FILE_POST_TYPE,
						currentPostId,
						{ throwOnError: true }
					);
				} else {
					const response = await apiFetch({
						path: `/wp/v2/${WP_LOCAL_FILE_POST_TYPE}/${currentPostId}?context=edit`,
					});
					dispatch(coreStore).receiveEntityRecords(
						'postType',
						WP_LOCAL_FILE_POST_TYPE,
						[response],
						undefined,
						true
					);
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
			return select(coreStore).getEntityRecord(
				'static-files-editor',
				'files',
				state.selectedPath
			);
		},
		listFiles(state, path) {
			const parentNode = this.getParentNode(state, path);
			if (parentNode) {
				return parentNode?.children || [];
			}
			return state.files.filter((node) => isTopLevelPath(node.path));
		},
		getDataSourceSyncInfo(state): SyncInfo | undefined {
			return state.dataSourceSyncInfo;
		},
		isSyncingDataSource(state) {
			return state.isSyncingDataSource;
		},
	},
	resolvers: {
		getDataSourceSyncInfo:
			() =>
			async ({ dispatch }) => {
				const syncInfo = await apiFetch({
					path: '/static-files-editor/v1/data-source/sync-info',
					method: 'GET',
				});
				dispatch({
					type: 'SET_LAST_DATA_SOURCE_SYNC_INFO',
					syncInfo,
				});
			},
	},
});

type SyncInfo = {
	lastSyncTime: number;
	version: string;
	hasUnsyncedChanges: boolean;
};

function isTopLevelPath(path: string) {
	return path.match(/^\/[^/]+$/);
}
