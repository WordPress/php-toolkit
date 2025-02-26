import { store, getContext, getElement, getServerContext } from '@wordpress/interactivity';
const { dispatch } = window.wp.data;

const apiFetch = window.wp.apiFetch;

const { state, actions } = store('staticFiles', {
	state: {
		get isLocalDirectory() {
			return state.dataSourceType === 'local_directory';
		},
		get isGitRepo() {
			return state.dataSourceType === 'git_repo';
		},
	},
    callbacks: {
        bindSelectedBranch(e) {
            const { ref } = getElement();
            ref.value = state.selectedBranch;
        },
    },
    actions: {
        updateGitRepo(e) {
            state.gitRepo = e.target.value;
        },
        updateSelectedBranch(e) {
            state.selectedBranch = e.target.value;
        },
        updateSubdirectory(e) {
            state.subdirectory = e.target.value;
        },
        updateLocalDirectory(e) {
            state.localDirectory = e.target.value;
        },
        updateDataSourceType(e) {
			state.dataSourceType = e.target.value;
			console.log(state.dataSourceType);
        },
        async onGitRepoInputEnter(e) {
            if(e.key === 'Enter') {
                e.preventDefault();
                e.stopPropagation();
                await actions.fetchBranches();
            }
        },
        async onFormEnter(e) {
            if(e.key === 'Enter') {
                e.preventDefault();
                await actions.saveSettings();
            }
        },
        async fetchBranches() {
            const response = await apiFetch({
                path: '/static-files-editor/v1/data-source/branches',
                method: 'POST',
                data: {
                    gitRepo: state.gitRepo,
                },
            });
            state.branches = response.refs;
            state.selectedBranch = response.default_branch;
        },
        async fetchFiles() {
            const response = await apiFetch({
                path: '/static-files-editor/v1/data-source/files',
                method: 'POST',
                data: {
                    gitRepo: state.gitRepo,
                    branch: state.selectedBranch,
                },
            });
            state.files = response.files;
        },
        async saveSettings() {
            try {
                const response = await apiFetch({
                    path: '/static-files-editor/v1/save-settings',
                    method: 'POST',
                    data: {
                        gitRepo: state.gitRepo,
                        selectedBranch: state.selectedBranch,
                        subdirectory: state.subdirectory,
                        localDirectory: state.localDirectory,
                        dataSourceType: state.dataSourceType,
                    },
                });
                state.notices.push({
                    type: 'notice-success notice is-dismissible',
                    message: 'Settings saved successfully!'
                });
            } catch (error) {
                state.notices.push({
                    type: 'notice-error notice is-dismissible',
                    message: 'Error saving settings. Please try again.'
                });
            }
        },
        async forcePull() {
            try {
                const response = await apiFetch({
                    path: '/static-files-editor/v1/data-source/sync',
                    method: 'POST',
                });
                state.notices.push({
                    type: 'notice-success notice is-dismissible',
                    message: 'Force pull completed!'
                });
            } catch (error) {
                state.notices.push({
                    type: 'notice-error notice is-dismissible',
                    message: 'Error force pulling. Please try again.'
                });
            }
        },
    }
});