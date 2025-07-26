// importer-ui.js - Interactivity API store for the importer UI (ES Module)
import { store, getContext, withScope } from '@wordpress/interactivity';

let droppedFile = null;  // will hold file from drag-drop if used

const { state, actions } = store('custom-importer', {
	state: {
		// Initialize state from data passed by PHP (window.importerInitialState)
		downloadAttachments: window.importerInitialState?.downloadAttachments ?? true,
		importing: window.importerInitialState?.importing || false,
		progressCount: window.importerInitialState?.progressCount || 0,
		progressTotal: window.importerInitialState?.progressTotal || 0,
		errorMessage: window.importerInitialState?.errorMessage || '',
		statusMessage: window.importerInitialState?.statusMessage || '',
		// Derived state: true when import is NOT active (used to toggle UI)
		get isNotImporting() {
			return !this.importing;
		}
	},
    actions: {
        // Trigger file input dialog on click of drop zone
        triggerFileInput: () => {
            const fileInput = document.getElementById('import_file');
            if (fileInput) fileInput.click();
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
                droppedFile = files[0];
                // Update UI to show selected file name
                state.statusMessage = `Selected file: ${droppedFile.name}`;
                state.errorMessage = '';
            }
        },
        // Start import process when form is submitted
        startImport: async (event) => {
            event.preventDefault();
            // Clear any previous messages
            state.errorMessage = '';
            state.statusMessage = '';
            // Ensure a file is selected (via input or drag-drop)
            const form = event.target;
            const fileInput = form.querySelector('#import_file');
            if (!droppedFile && (!fileInput || !fileInput.files.length)) {
                state.errorMessage = 'Please select a file to import.';
                return;
            }
            // Mark as importing and update status
            state.importing = true;
            state.statusMessage = 'Starting import...';
            // Build FormData for AJAX request
            const formData = new FormData(form);
            if (droppedFile) {
                // If file was dropped, use that instead of the file input's value
                formData.set('import_file', droppedFile, droppedFile.name);
            }
            try {
                // Send AJAX request to start import (admin-ajax)
                const response = await fetch(ajaxurl, { method: 'POST', body: formData });
                const result = await response.json();
                if (!result.success) {
                    // Show error and reset state if starting import failed
                    state.errorMessage = result.data?.error || 'Failed to start import.';
                    state.importing = false;
                    return;
                }
                // Import started successfully: initialize progress and status
                state.progressTotal = result.data.total || 0;
                state.progressCount = result.data.progress || 0;
                state.statusMessage = 'Import in progress...';
                // Begin polling for progress updates
                actions.pollProgress();
            } catch (err) {
                state.errorMessage = 'Error starting import: ' + err.message;
                state.importing = false;
            }
        },
        // Poll the server for import progress
        pollProgress: async () => {
            try {
                const res = await fetch(`${ajaxurl}?action=get_import_progress`);
                const data = await res.json();
                if (!data.success) {
                    state.errorMessage = data.data?.error || 'An error occurred during import.';
                    state.importing = false;
                    return;
                }
                const status = data.data;
                // Update progress counts
                state.progressCount = status.count || 0;
                state.progressTotal = status.total || 0;
                if (status.finished) {
                    // Import finished - update status and stop polling
                    state.importing = false;
                    state.statusMessage = status.message || 'Import completed.';
                    // (At this point, the form will reappear due to state.importing = false)
                } else {
                    // Schedule the next poll after a short delay
                    setTimeout(() => { withScope(actions.pollProgress); }, 1000);
                }
            } catch (err) {
                state.errorMessage = 'Error fetching progress: ' + err.message;
                state.importing = false;
            }
        },
        // Cancel the ongoing import
        cancelImport: async () => {
            try {
                await fetch(ajaxurl, {
                    method: 'POST',
                    body: new URLSearchParams({ action: 'cancel_import' })
                });
            } finally {
                // Always update state to not importing
                state.importing = false;
                state.statusMessage = 'Import canceled.';
            }
        },
		setDownloadAttachments: (event) => {
			state.downloadAttachments = event.target.checked;
		}
    }
});
