// importer-ui.js - Interactivity API store for the importer UI (ES Module)
import { store, getContext, getElement } from '@wordpress/interactivity';

let selectedFile = null; // will hold the selected file

const { state, actions } = store('custom-importer', {
	state: {
		// Import state - single source of truth
		importState: window.importerInitialState?.importState || 'idle',

		// File selection state
		hasSelectedFile: false,
		selectedFileName: '',
		selectedFileSize: 0,
		selectedFileSizeFormatted: '',
		maxFileSize: window.importerInitialState?.maxFileSize || 0,
		maxFileSizeFormatted:
			window.importerInitialState?.maxFileSizeFormatted || '',

		// Upload state
		uploading: false,
		uploadProgress: 0,
		uploadError: '',

		// File and import data
		fileDetails: window.importerInitialState?.fileDetails || null,
		authorsInFile: window.importerInitialState?.authorsInFile || [],

		// Import configuration state
		downloadAttachments: true,
		allowedDomains: '',
		authorMappings: {},
		// wordpressUsers: [],

		// Import execution state
		importProgress: window.importerInitialState?.importProgress || 0,
		importTotal: window.importerInitialState?.importTotal || 0,
		importError: window.importerInitialState?.importError || '',
		importStatusMessage:
			window.importerInitialState?.importStatusMessage || '',
		importErrors: window.importerInitialState?.importErrors || [],

		// Computed states for stage visibility
		get isSelectStage() {
			return state.importState === 'idle';
		},
		get isUploadStage() {
			return state.importState === 'uploading';
		},
		get isIndexingStage() {
			return state.importState === 'indexing';
		},
		get isConfigureStage() {
			return state.importState === 'configure';
		},
		get isImportStage() {
			return ['downloading', 'inserting'].includes(state.importState);
		},
		get isCompletedStage() {
			return state.importState === 'completed';
		},
		get canUpload() {
			return state.hasSelectedFile && !state.uploading;
		},
		get hasAuthorsInFile() {
			return state.authorsInFile && state.authorsInFile.length > 0;
		},
		get hasImportErrors() {
			return state.importErrors && state.importErrors.length > 0;
		},
		get importStageLabel() {
			if (state.importState === 'downloading') {
				return 'Downloading images...';
			} else if (state.importState === 'inserting') {
				return 'Inserting entities...';
			} else if (state.importState === 'completed') {
				return 'Import completed';
			}
			return 'Processing...';
		},
		get importProgressLabel() {
			if (state.importState === 'downloading') {
				return `Downloaded ${state.importProgress} / ${state.importTotal} images`;
			} else if (state.importState === 'inserting') {
				return `Processed ${state.importProgress} / ${state.importTotal} entities`;
			} else if (state.importState === 'completed') {
				return 'Import completed successfully';
			}
			return `Progress: ${state.importProgress} / ${state.importTotal}`;
		},

		get isAuthorMappingKeep() {
			const context = getContext();
			if (!context) {
				return false;
			}
			const { author } = context;
			// Default to "keep" if no mapping is found.
			if(!state.authorMappings[author.author_login]) {
				return true;
			}
			return state.authorMappings[author.author_login].type === 'keep';
		},
		get isAuthorMappingNew() {
			const context = getContext();
			if (!context) {
				return false;
			}
			const { author } = context;
			return state.authorMappings[author.author_login]?.type === 'new';
		},
		get isAuthorMappingExisting() {
			const context = getContext();
			if (!context) {
				return false;
			}
			const { author } = context;
			return (
				state.authorMappings[author.author_login]?.type === 'existing'
			);
		},

		get getAuthorMappingNewLogin() {
			const context = getContext();
			if (!context) {
				return '';
			}
			const { author } = context;
			return state.authorMappings[author.author_login]?.newLogin || '';
		},
	},

	callbacks: {
		/**
		 * Continuously ask the server to do the next import step once the import is running.
		 *
		 * Why not just rely on wp-cron?
		 *
		 * Because we want a snappy user experience when the user is interacting with the UI.
		 * wp-cron may not move us forward for the next 10 or 60 minutes. It runs at most once
		 * a minute, it could be preoccupied with other tasks, etc. We still schedule a wp-cron
		 * task, but we also have a dedicated endpoint that performs the next step immediately.
		 * It safe to run anytime and doesn't conflict with a concurrent wp-cron task.
		 */
		async continuouslyTriggerNextImportStep() {
			// @TODO: Resolve race conditions between:
			//        * UI actions and periodic state updates.

			setTimeout(async () => {
				while(true) {
					if (
						state.importState === 'indexing' ||
						state.importState === 'downloading' ||
						state.importState === 'inserting'
					) {
						try {
							const response = await fetch(
								`${window.importerInitialState.restUrl}state`,
								{
									headers: {
										'X-WP-Nonce': window.importerInitialState.restNonce,
									},
								}
							);
							const nextState = await response.json();
							Object.assign(state, nextState);
						} catch (e) {
							console.error('Error fetching state:', e);
						}
					}
					await new Promise((resolve) => setTimeout(resolve, 1000));
				}

				await new Promise((resolve) => setTimeout(resolve, 1000));
			}, 0);

			while (true) {
				if (
					state.importState === 'indexing' ||
					state.importState === 'downloading' ||
					state.importState === 'inserting'
                ) {                    
					try {
						const response = await fetch(
							`${window.importerInitialState.restUrl}next-step`,
							{
								headers: {
									'X-WP-Nonce': window.importerInitialState.restNonce,
								},
							}
						);
						const nextState = await response.json();
						// Object.assign(state, nextState);
					} catch (e) {
						console.error('Error executing the next import step:', e);
						await new Promise((resolve) => setTimeout(resolve, 5000));
					}
				}

				await new Promise((resolve) => setTimeout(resolve, 1000));
			}
		},
	},

	actions: {
		// Trigger file input dialog on click of drop zone
		triggerFileInput: () => {
			const fileInput = document.getElementById('import_file');
			if (fileInput) fileInput.click();
		},

		// Handle file input change
		handleFileInputChange: (event) => {
			const files = event.target.files;
			if (files && files.length) {
				actions.selectFile(files[0]);
			}
		},

		// Handle drag over/enter events on drop zone (highlight)
		handleDragOver: (event) => {
			event.preventDefault();
			event.currentTarget.classList.add('drag-over');
		},

		handleDragLeave: (event) => {
			event.currentTarget.classList.remove('drag-over');
		},

		// Handle file drop on drop zone
		handleFileDrop: (event) => {
			event.preventDefault();
			event.currentTarget.classList.remove('drag-over');
			const files = event.dataTransfer.files;
			if (files && files.length) {
				actions.selectFile(files[0]);
			}
		},

		// Select a file and update state
		selectFile: (file) => {
			selectedFile = file;
			state.hasSelectedFile = true;
			state.selectedFileName = file.name;
			state.selectedFileSize = file.size;
			state.selectedFileSizeFormatted = actions.formatFileSize(file.size);
			state.uploadError = '';
		},

		// Format file size for display
		formatFileSize: (bytes) => {
			if (bytes === 0) return '0 Bytes';
			const k = 1024;
			const sizes = ['Bytes', 'KB', 'MB', 'GB'];
			const i = Math.floor(Math.log(bytes) / Math.log(k));
			return (
				parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
			);
		},

		// Upload the selected file
		uploadFile: async (event) => {
			event?.preventDefault();
			if (!selectedFile || state.uploading || !state.canUpload) {
				return;
			}
			// Immediately transition to upload stage
			state.importState = 'uploading';
			state.uploading = true;
			state.uploadProgress = 0;
			state.uploadError = '';

			const formData = new FormData();
			formData.append('import_file', selectedFile);

			try {
				// Using XHR over fetch() to get progress events in all browsers. Safari doesn't
				// support monitoring upload progress via fetch() ReadableStream.
				const xhr = new XMLHttpRequest();
				xhr.upload.addEventListener('progress', (event) => {
					if (event.lengthComputable) {
						state.uploadProgress = Math.round(
							(event.loaded / event.total) * 100
						);
					}
				});
				xhr.addEventListener('load', () => {
					state.uploading = false;
					if (xhr.status === 200) {
						try {
							const response = JSON.parse(xhr.responseText);
							// Check if it's a REST API error
							if (response.code && response.message) {
								state.uploadError =
									response.message || 'Upload failed';
								state.importState = 'idle';
								return;
							}

							// Update state directly with complete state from server
							Object.assign(state, response);

							// Update the selected file info for the UI
							if (state.fileDetails) {
								state.selectedFileName = state.fileDetails.name;
								state.selectedFileSize = state.fileDetails.size;
								state.selectedFileSizeFormatted =
									actions.formatFileSize(
										state.fileDetails.size
									);
							}
						} catch (e) {
							state.uploadError = 'Invalid server response';
							state.importState = 'idle';
						}
					} else {
						state.uploadError = `Upload failed with status ${xhr.status}`;
						state.importState = 'idle';
					}
				});
				xhr.addEventListener('error', () => {
					state.uploading = false;
					state.uploadError = 'Network error during upload';
					state.importState = 'idle';
				});

				xhr.open(
					'POST',
					`${window.importerInitialState.restUrl}upload`
				);
				xhr.setRequestHeader(
					'X-WP-Nonce',
					window.importerInitialState.restNonce
				);
				xhr.send(formData);
			} catch (error) {
				state.uploading = false;
				state.uploadError = 'Error: ' + error.message;
				state.importState = 'idle';
			}
		},

		// Set author mapping new login value
		setAuthorMappingNewLogin: (event) => {
			const context = getContext();
			if (!context) {
				return;
			}
			const { author } = context;
			if (!state.authorMappings[author.author_login]) {
				state.authorMappings[author.author_login] = {};
			}
			state.authorMappings[author.author_login].newLogin =
				event.target.value;
		},

		// Set author mapping type (keep, new, existing) - updated for Interactivity API
		setAuthorMappingType: (event) => {
			const context = getContext();
			const { author, mappingType } = context;
			if (!state.authorMappings[author.author_login]) {
				state.authorMappings[author.author_login] = {};
			}
			state.authorMappings[author.author_login].type = mappingType;
		},

		// Set author mapping user ID for existing user
		setAuthorMappingUserId: (event) => {
			const { author_login } = getContext();
			if (!state.authorMappings[author_login]) {
				state.authorMappings[author_login] = {};
			}
			state.authorMappings[author_login].userId = event.target.value;
		},

		// Start the import process
		startImport: async (event) => {
			event?.preventDefault();

			const requestBody = {
				download_attachments: state.downloadAttachments ? '1' : '0',
				allowed_domains: state.allowedDomains,
				author_mappings: JSON.stringify(state.authorMappings),
			};

			try {
				const response = await fetch(
					`${window.importerInitialState.restUrl}start`,
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce': window.importerInitialState.restNonce,
						},
						body: JSON.stringify(requestBody),
					}
				);

				const result = await response.json();

				// Check for REST API error
				if (result.code && result.message) {
					state.importError =
						result.message || 'Failed to start import';
					state.importState = 'idle';
					return;
				}

				// Update state directly with complete state from server
				Object.assign(state, result);
			} catch (error) {
				state.importError = 'Error starting import: ' + error.message;
				state.importState = 'idle';
			}
		},

		// Render import errors in the error log
		renderImportErrors: () => {
			const container = document.getElementById('import-error-list');
			if (!container || !state.importErrors.length) return;

			let html = '';
			state.importErrors.forEach((error, index) => {
				const timestamp = error.timestamp
					? new Date(error.timestamp * 1000).toLocaleTimeString()
					: '';
				html += `
        			<div style="margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px solid #dcdcde;">
        				<span style="color: #d63638;">✕</span>
        				<strong>${error.type || 'Error'}:</strong> ${error.message}
        				${
							timestamp
								? `<span style="color: #646970; font-size: 11px; float: right;">${timestamp}</span>`
								: ''
						}
        			</div>
        		`;
			});
			container.innerHTML = html;
		},

		// Cancel current import/indexing process
		cancelCurrentImport: () => {
			console.log('Cancel button clicked');

			// Reset to idle state and show cancellation message
			state.importState = 'idle';
			state.uploading = false;
			state.uploadProgress = 0;
			state.hasSelectedFile = false;
			state.selectFile = null;
			// state.uploadError = 'Operation was canceled.';

			// Clear indexing/import state
			state.fileDetails = null;
			state.importProgress = 0;
			state.importTotal = 0;
			state.importError = '';
			state.importStatusMessage = '';
			state.importErrors = [];
			state.authorMappings = {};
			state.authorsInFile = [];

			// Keep file selection state so user can start again
			// state.hasSelectedFile and selectedFile info remain intact

			// Clean up server-side asynchronously
			fetch(`${window.importerInitialState.restUrl}cancel`, {
				method: 'POST',
				headers: {
					'X-WP-Nonce': window.importerInitialState.restNonce,
				},
			})
				.then((response) => response.json())
				.then((result) => {
					console.log('Cancel response:', result);
				})
				.catch((error) => {
					console.error('Cancel error:', error);
				});
		},

		// Toggle download attachments
		setDownloadAttachments: (event) => {
			state.downloadAttachments = event.target.checked;
		},

		// Set allowed domains
		setAllowedDomains: (event) => {
			state.allowedDomains = event.target.value;
		},
	},
});

// Initialize state from server if available (for page refresh scenarios)
if (window.importerInitialState?.fileDetails) {
	// Ensure fileDetails object exists and format file size if not already formatted
	if (state.fileDetails && !state.fileDetails.sizeFormatted) {
		state.fileDetails.sizeFormatted = actions.formatFileSize(
			state.fileDetails.size
		);
	}

	// Set up selected file state if we have file details
	if (state.fileDetails) {
		state.hasSelectedFile = true;
		state.selectedFileName = state.fileDetails.name;
		state.selectedFileSize = state.fileDetails.size;
		state.selectedFileSizeFormatted =
			state.fileDetails.sizeFormatted ||
			actions.formatFileSize(state.fileDetails.size);
	}
}

// Make the store available globally for the dynamically generated HTML
window.customImporterStore = { state, actions };
