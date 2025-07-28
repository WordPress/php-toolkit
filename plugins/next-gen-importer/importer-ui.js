// importer-ui.js - Interactivity API store for the importer UI (ES Module)
import { store, getContext, getElement } from '@wordpress/interactivity';

let selectedFile = null;  // will hold the selected file

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
		maxFileSizeFormatted: window.importerInitialState?.maxFileSizeFormatted || '',
		
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
		
		// Import execution state
		importProgress: window.importerInitialState?.importProgress || 0,
		importTotal: window.importerInitialState?.importTotal || 0,
		importError: window.importerInitialState?.importError || '',
		importStatusMessage: window.importerInitialState?.importStatusMessage || '',
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
			return ['downloading', 'inserting', 'completed'].includes(state.importState);
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
				return `Downloaded ${state.importProgress}% of images`;
			} else if (state.importState === 'inserting') {
				return `Processed ${state.importProgress} / ${state.importTotal} entities`;
			} else if (state.importState === 'completed') {
				return 'Import completed successfully';
			}
			return `Progress: ${state.importProgress} / ${state.importTotal}`;
		}
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
            console.log('continuouslyTriggerNextImportStep');
            while (true) {
                if (state.isIndexingStage || state.isImportStage) {
                    await fetch(`/wp-content/plugins/next-gen-importer/next-import-step.php?action=run_import`);
                }
                await actions.nextImportStep();
                await new Promise((resolve) => setTimeout(resolve, 1000));
            }
        }
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
        	return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        },
        
        // Upload the selected file
        uploadFile: async () => {
            if (!selectedFile || state.uploading) {
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
                        state.uploadProgress = Math.round((event.loaded / event.total) * 100);
                    }
                });
                xhr.addEventListener('load', () => {
                    state.uploading = false;
                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            // Check if it's a REST API error
                            if (response.code && response.message) {
                                state.uploadError = response.message || 'Upload failed';
                                state.importState = 'idle';
                                return;
                            }
                            
                            // Update state directly with complete state from server
                            Object.assign(state, response);
                            
                            // Update the selected file info for the UI
                            if (state.fileDetails) {
                                state.selectedFileName = state.fileDetails.name;
                                state.selectedFileSize = state.fileDetails.size;
                                state.selectedFileSizeFormatted = actions.formatFileSize(state.fileDetails.size);
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
                
                xhr.open('POST', `${window.importerInitialState.restUrl}upload`);
                xhr.setRequestHeader('X-WP-Nonce', window.importerInitialState.restNonce);
                xhr.send(formData);
            } catch (error) {
                state.uploading = false;
                state.uploadError = 'Error: ' + error.message;
                state.importState = 'idle';
            }
        },

        // Consolidated next import step action
        nextImportStep: async () => {
            try {
                const res = await fetch(`${window.importerInitialState.restUrl}next-step`, {
                    headers: {
                        'X-WP-Nonce': window.importerInitialState.restNonce
                    }
                });
                const data = await res.json();
                
                // Check for REST API error
                if (data.code && data.message) {
                    state.importState = data.message || 'Unknown error';
                    return;
                }
                
                // Update state directly with the complete state from server
                Object.assign(state, data);
                
                // Handle stage-specific UI updates
                if (state.importState === 'configure' && state.authorsInFile.length > 0) {
                    actions.initializeAuthorMappings();
                    actions.renderAuthorMappings();
                }
                
                if (['downloading', 'inserting', 'completed'].includes(state.importState) && state.importErrors.length > 0) {
                    actions.renderImportErrors();
                }
                
            } catch (err) {
                state.importState = 'Error fetching import state: ' + err.message;
            }
        },
        
        // Initialize author mappings for authors found in file
        initializeAuthorMappings: () => {
        	state.authorMappings = {};
        	state.authorsInFile.forEach(author => {
        		state.authorMappings[author.author_login] = {
        			type: 'keep', // default to keeping original author
        			userId: '',
        			newLogin: ''
        		};
        	});
        },
        
        // Render author mapping UI dynamically
        renderAuthorMappings: () => {
        	const container = document.getElementById('author-mappings');
        	if (!container || !state.authorsInFile.length) return;
        	
        	// Get existing WordPress users for the dropdown
        	actions.fetchWordPressUsers().then(users => {
        		let html = '<ol class="import-authors">';
        		
        		state.authorsInFile.forEach((author, index) => {
        			const login = author.author_login;
        			const displayName = author.author_display_name;
        			
        			html += `
        				<li class="author-mapping">
        					<p>
        						<strong>Import author:</strong>
        						${displayName} (${login})
        					</p>
        					
        					<p>
        						<label>
        							<input type="radio" name="author_mapping_${login}" value="keep" checked
        								   onchange="window.customImporterStore.actions.setAuthorMappingType('${login}', 'keep')">
        							Keep original author
        						</label>
        					</p>
        					
        					<p>
        						<label>
        							<input type="radio" name="author_mapping_${login}" value="new"
        								   onchange="window.customImporterStore.actions.setAuthorMappingType('${login}', 'new')">
        							Create new user with login name:
        							<input type="text" class="regular-text" style="margin-left: 10px;"
        								   onchange="window.customImporterStore.actions.setAuthorMappingValue('${login}', 'new', this.value)"
        								   placeholder="New username">
        						</label>
        					</p>
        					
        					<p>
        						<label>
        							<input type="radio" name="author_mapping_${login}" value="existing"
        								   onchange="window.customImporterStore.actions.setAuthorMappingType('${login}', 'existing')">
        							Assign posts to an existing user:
        							<select style="margin-left: 10px;"
        								    onchange="window.customImporterStore.actions.setAuthorMappingValue('${login}', 'existing', this.value)">
        								<option value="">— Select —</option>
        								${users.map(user => 
        									`<option value="${user.ID}">${user.display_name} (${user.user_login})</option>`
        								).join('')}
        							</select>
        						</label>
        					</p>
        				</li>
        			`;
        		});
        		
        		html += '</ol>';
        		container.innerHTML = html;
        	});
        },
        
        // Fetch WordPress users for author mapping dropdown
        fetchWordPressUsers: async () => {
        	try {
        		const response = await fetch(`${window.importerInitialState.restUrl}users`, {
                    headers: {
                        'X-WP-Nonce': window.importerInitialState.restNonce
                    }
                });
        		const data = await response.json();
        		
        		// Check for REST API error
        		if (data.code && data.message) {
        			console.error('Error fetching users:', data.message);
        			return [];
        		}
        		
        		return data.users || [];
        	} catch (error) {
        		console.error('Error fetching users:', error);
        		return [];
        	}
        },
        
        // Set author mapping type (keep, new, existing)
        setAuthorMappingType: (authorLogin, type) => {
        	if (!state.authorMappings[authorLogin]) {
        		state.authorMappings[authorLogin] = {};
        	}
        	state.authorMappings[authorLogin].type = type;
        },
        
        // Set author mapping value (user ID for existing, new login for new)
        setAuthorMappingValue: (authorLogin, type, value) => {
        	if (!state.authorMappings[authorLogin]) {
        		state.authorMappings[authorLogin] = {};
        	}
        	if (type === 'existing') {
        		state.authorMappings[authorLogin].userId = value;
        	} else if (type === 'new') {
        		state.authorMappings[authorLogin].newLogin = value;
        	}
        },
        
        // Start the import process
        startImport: async () => {
        	// Set temporary loading state
        	state.importStatusMessage = 'Starting import...';
        	
        	const requestBody = {
        		download_attachments: state.downloadAttachments ? '1' : '0',
        		allowed_domains: state.allowedDomains,
        		author_mappings: JSON.stringify(state.authorMappings)
        	};
        	
        	try {
        		const response = await fetch(`${window.importerInitialState.restUrl}start`, {
        			method: 'POST',
        			headers: {
        				'Content-Type': 'application/json',
        				'X-WP-Nonce': window.importerInitialState.restNonce
        			},
        			body: JSON.stringify(requestBody)
        		});
        		
        		const result = await response.json();
        		
        		// Check for REST API error
        		if (result.code && result.message) {
        			state.importError = result.message || 'Failed to start import';
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
        		const timestamp = error.timestamp ? new Date(error.timestamp * 1000).toLocaleTimeString() : '';
        		html += `
        			<div style="margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px solid #dcdcde;">
        				<span style="color: #d63638;">✕</span>
        				<strong>${error.type || 'Error'}:</strong> ${error.message}
        				${timestamp ? `<span style="color: #646970; font-size: 11px; float: right;">${timestamp}</span>` : ''}
        			</div>
        		`;
        	});
        	container.innerHTML = html;
        },
        
        // Cancel import
        cancelImport: async () => {
        	try {
        		await fetch(`${window.importerInitialState.restUrl}cancel`, {
        			method: 'POST',
        			headers: {
        				'X-WP-Nonce': window.importerInitialState.restNonce
        			}
        		});
        	} catch (error) {
        		console.error('Error canceling import:', error);
        	} finally {
        		// Reset to idle state and show error message in dropzone
        		state.importState = 'idle';
        		state.uploadError = 'Import was canceled.';
        		
        		// Clear import-related state
        		state.importProgress = 0;
        		state.importTotal = 0;
        		state.importError = '';
        		state.importStatusMessage = '';
        		state.importErrors = [];
        		
        		// Keep file selection state so user can start again
        		// state.hasSelectedFile and selectedFile info remain intact
        		
        		// Clear UI elements
        		const authorMappingsContainer = document.getElementById('author-mappings');
        		if (authorMappingsContainer) {
        			authorMappingsContainer.innerHTML = '';
        		}
        		
        		const errorContainer = document.getElementById('import-error-list');
        		if (errorContainer) {
        			errorContainer.innerHTML = '';
        		}
        		
        		// Clean up server-side asynchronously
        	}
        },
        
        // Cancel current import/indexing process
        cancelCurrentImport: () => {
        	console.log('Cancel button clicked');
        	
        	// Reset to idle state and show cancellation message
        	state.importState = 'idle';
        	state.uploading = false;
        	state.uploadProgress = 0;
        	state.uploadError = 'Operation was canceled.';
        	
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
        	fetch(`${window.importerInitialState.restUrl}cancel-current`, {
        		method: 'POST',
        		headers: {
        			'X-WP-Nonce': window.importerInitialState.restNonce
        		}
        	}).then(response => response.json())
        	  .then(result => {
        		console.log('Cancel response:', result);
        	  })
        	  .catch(error => {
        		console.error('Cancel error:', error);
        	  });
        },
        
        // Reset to start over
        resetImporter: () => {
        	selectedFile = null;
        	state.hasSelectedFile = false;
        	state.selectedFileName = '';
        	state.selectedFileSize = 0;
        	state.selectedFileSizeFormatted = '';
        	state.importState = 'idle';
        	state.uploading = false;
        	state.uploadProgress = 0;
        	state.uploadError = '';
        	state.fileDetails = null;
        	state.importProgress = 0;
        	state.importTotal = 0;
        	state.importError = '';
        	state.importStatusMessage = '';
        	state.importErrors = [];
        	state.authorMappings = {};
        	state.authorsInFile = [];
        	
        	// Reset file input and clear author mappings UI
        	const fileInput = document.getElementById('import_file');
        	if (fileInput) {
        		fileInput.value = '';
        	}
        	
        	const authorMappingsContainer = document.getElementById('author-mappings');
        	if (authorMappingsContainer) {
        		authorMappingsContainer.innerHTML = '';
        	}
        	
        	// Clear error log
        	const errorContainer = document.getElementById('import-error-list');
        	if (errorContainer) {
        		errorContainer.innerHTML = '';
        	}
        },
        
        // Toggle download attachments
        setDownloadAttachments: (event) => {
        	state.downloadAttachments = event.target.checked;
        },
        
        // Set allowed domains
        setAllowedDomains: (event) => {
        	state.allowedDomains = event.target.value;
        }
    }
});

// Initialize state from server if available (for page refresh scenarios)
if (window.importerInitialState?.fileDetails) {
    // Ensure fileDetails object exists and format file size if not already formatted
    if (state.fileDetails && !state.fileDetails.sizeFormatted) {
        state.fileDetails.sizeFormatted = actions.formatFileSize(state.fileDetails.size);
    }
    
    // Set up selected file state if we have file details
    if (state.fileDetails) {
        state.hasSelectedFile = true;
        state.selectedFileName = state.fileDetails.name;
        state.selectedFileSize = state.fileDetails.size;
        state.selectedFileSizeFormatted = state.fileDetails.sizeFormatted || actions.formatFileSize(state.fileDetails.size);
    }
    
    // If we have authors and are in configure stage, initialize mappings
    if (state.importState === 'configure' && state.authorsInFile.length > 0) {
        actions.initializeAuthorMappings();
        // Use setTimeout to ensure DOM is ready
        setTimeout(() => {
            actions.renderAuthorMappings();
        }, 100);
    }
}

// Make the store available globally for the dynamically generated HTML
window.customImporterStore = { state, actions };

// Debug: Log current state for troubleshooting
console.log('Initial state:', {
    importState: state.importState,
    fileDetails: state.fileDetails,
    hasFileDetails: !!window.importerInitialState?.fileDetails
});


