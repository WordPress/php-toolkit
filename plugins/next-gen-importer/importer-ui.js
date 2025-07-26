// importer-ui.js - Interactivity API store for the importer UI (ES Module)
import { store, getContext, withScope } from '@wordpress/interactivity';

let selectedFile = null;  // will hold the selected file

const { state, actions } = store('custom-importer', {
	state: {
		// UI stages: 'select', 'upload', 'configure', 'import'
		currentStage: 'select',
		
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
		uploadedFileId: null,
		
		// Import configuration state
		downloadAttachments: true,
		allowedDomains: '',
		authorMappings: {},
		authorsInFile: [],
		
		// Import execution state
		importing: false,
		importProgress: 0,
		importTotal: 0,
		importError: '',
		importStatusMessage: '',
		
		// Computed states
		get canUpload() {
			return state.hasSelectedFile && !state.uploading && state.currentStage === 'select';
		},
		get showFileDetails() {
			return state.hasSelectedFile && state.currentStage === 'select';
		},
		get showUploadProgress() {
			return state.currentStage === 'upload' || state.uploading;
		},
		get showImportConfiguration() {
			return state.currentStage === 'configure';
		},
		get showImportProgress() {
			return state.currentStage === 'import' || state.importing;
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
        	if (!selectedFile) {
        		return;
        	}
        	
        	state.uploading = true;
        	state.currentStage = 'upload';
        	state.uploadProgress = 0;
        	state.uploadError = '';
        	
        	const formData = new FormData();
        	formData.append('action', 'upload_import_file');
        	formData.append('import_file', selectedFile);
        	formData.append('_ajax_nonce', window.importerInitialState?.uploadNonce || '');
        	
        	try {
        		const xhr = new XMLHttpRequest();
        		
        		// Track upload progress
        		xhr.upload.addEventListener('progress', (event) => {
        			if (event.lengthComputable) {
        				state.uploadProgress = Math.round((event.loaded / event.total) * 100);
        			}
        		});
        		
        		// Handle response
        		xhr.addEventListener('load', () => {
        			state.uploading = false;
        			
        			if (xhr.status === 200) {
        				try {
        					const response = JSON.parse(xhr.responseText);
        					if (response.success) {
        						state.uploadedFileId = response.data.file_id;
        						state.authorsInFile = response.data.authors || [];
        						state.currentStage = 'configure';
        						
        						// Initialize author mappings and render the UI
        						actions.initializeAuthorMappings();
        						actions.renderAuthorMappings();
        					} else {
        						state.uploadError = response.data?.error || 'Upload failed';
        						state.currentStage = 'select';
        					}
        				} catch (e) {
        					state.uploadError = 'Invalid server response';
        					state.currentStage = 'select';
        				}
        			} else {
        				state.uploadError = `Upload failed with status ${xhr.status}`;
        				state.currentStage = 'select';
        			}
        		});
        		
        		xhr.addEventListener('error', () => {
        			state.uploading = false;
        			state.uploadError = 'Network error during upload';
        			state.currentStage = 'select';
        		});
        		
        		xhr.open('POST', ajaxurl);
        		xhr.send(formData);
        		
        	} catch (error) {
        		state.uploading = false;
        		state.uploadError = 'Error: ' + error.message;
        		state.currentStage = 'select';
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
        		const response = await fetch(`${ajaxurl}?action=get_wordpress_users`);
        		const data = await response.json();
        		if (data.success) {
        			return data.data.users || [];
        		}
        		return [];
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
        	if (!state.uploadedFileId) {
        		return;
        	}
        	
        	state.importing = true;
        	state.currentStage = 'import';
        	state.importError = '';
        	state.importStatusMessage = 'Starting import...';
        	
        	const formData = new FormData();
        	formData.append('action', 'start_import');
        	formData.append('file_id', state.uploadedFileId);
        	formData.append('download_attachments', state.downloadAttachments ? '1' : '0');
        	formData.append('allowed_domains', state.allowedDomains);
        	formData.append('_ajax_nonce', window.importerInitialState?.importNonce || '');
        	
        	// Add author mappings
        	Object.keys(state.authorMappings).forEach(authorLogin => {
        		const mapping = state.authorMappings[authorLogin];
        		if (mapping.type === 'existing' && mapping.userId) {
        			formData.append(`existing_user[${authorLogin}]`, mapping.userId);
        		} else if (mapping.type === 'new' && mapping.newLogin) {
        			formData.append(`new_user[${authorLogin}]`, mapping.newLogin);
        		}
        	});
        	
        	try {
        		const response = await fetch(ajaxurl, { method: 'POST', body: formData });
        		const result = await response.json();
        		
        		if (!result.success) {
        			state.importError = result.data?.error || 'Failed to start import';
        			state.importing = false;
        			return;
        		}
        		
        		state.importTotal = result.data.total || 0;
        		state.importProgress = result.data.progress || 0;
        		state.importStatusMessage = 'Import in progress...';
        		
        		// Begin polling for progress
        		actions.pollImportProgress();
        		
        	} catch (error) {
        		state.importError = 'Error starting import: ' + error.message;
        		state.importing = false;
        	}
        },
        
        // Poll for import progress
        pollImportProgress: async () => {
        	try {
        		const res = await fetch(`${ajaxurl}?action=get_import_progress`);
        		const data = await res.json();
        		
        		if (!data.success) {
        			state.importError = data.data?.error || 'Import error occurred';
        			state.importing = false;
        			return;
        		}
        		
        		const status = data.data;
        		state.importProgress = status.count || 0;
        		state.importTotal = status.total || 0;
        		
        		if (status.finished) {
        			state.importing = false;
        			state.importStatusMessage = status.message || 'Import completed successfully!';
        		} else {
        			setTimeout(() => { withScope(actions.pollImportProgress); }, 1000);
        		}
        		
        	} catch (error) {
        		state.importError = 'Error fetching progress: ' + error.message;
        		state.importing = false;
        	}
        },
        
        // Cancel import
        cancelImport: async () => {
        	try {
        		await fetch(ajaxurl, {
        			method: 'POST',
        			body: new URLSearchParams({ 
        				action: 'cancel_import',
        				_ajax_nonce: window.importerInitialState?.importNonce || ''
        			})
        		});
        	} finally {
        		state.importing = false;
        		state.importStatusMessage = 'Import canceled';
        	}
        },
        
        // Reset to start over
        resetImporter: () => {
        	selectedFile = null;
        	state.hasSelectedFile = false;
        	state.selectedFileName = '';
        	state.selectedFileSize = 0;
        	state.selectedFileSizeFormatted = '';
        	state.currentStage = 'select';
        	state.uploading = false;
        	state.uploadProgress = 0;
        	state.uploadError = '';
        	state.uploadedFileId = null;
        	state.importing = false;
        	state.importProgress = 0;
        	state.importTotal = 0;
        	state.importError = '';
        	state.importStatusMessage = '';
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

// Make the store available globally for the dynamically generated HTML
window.customImporterStore = { state, actions };
