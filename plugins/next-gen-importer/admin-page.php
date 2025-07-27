<?php
/**
 * Plugin Name: Custom Importer UI
 * Description: Adds an interactive importer UI under Tools → Import using the Interactivity API.
 * Author: Your Name
 * Version: 1.0.0
 */

add_action('admin_init', function () {
    // Register a new importer in Tools -> Import
    register_importer(
        'custom-importer', 
        'Custom Importer', 
        'Import content from a custom format using an interactive progress UI.', 
        'custom_importer_admin_page'
    );
});

// Callback to render the Importer admin page
function custom_importer_admin_page() {
    if ( ! current_user_can('import') ) {
        wp_die(__('You are not allowed to import.', 'custom-importer'));
    }

    // Get PHP file upload limits
    $max_upload_size = wp_max_upload_size();
    $max_upload_size_formatted = size_format($max_upload_size);

    // Check for existing import state to restore UI after page refresh
    $current_file_details = get_option('custom_importer_current_file');
    $current_stage = 'select'; // default stage
    $file_details = null;
    $import_state = null;
    $authors_in_file = array();
    
    if ($current_file_details) {
        $file_details = $current_file_details;
        $import_state = $current_file_details['state'];
        
        // Determine current stage based on import state
        if ($import_state === 'indexing uploaded file') {
            $current_stage = 'indexing';
        } elseif ($import_state === 'awaiting author mapping' && !empty($current_file_details['authors'])) {
            $current_stage = 'configure';
            $authors_in_file = $current_file_details['authors'];
        }
    }

    // Enqueue the interactivity store script (as an ES module) for this page
    wp_enqueue_script_module(
        'custom-importer-ui',                                      // script handle
        plugin_dir_url(__FILE__) . 'importer-ui.js',               // module script file
        array('@wordpress/interactivity'),                        // ensure the Interactivity API is available
        '1.0.0'
    );
    
    // Pass initial state from PHP to JS via an inline script before the module
    $initial_state = array(
        'maxFileSize' => $max_upload_size,
        'maxFileSizeFormatted' => $max_upload_size_formatted,
        'uploadNonce' => wp_create_nonce('upload_import_file'),
        'importNonce' => wp_create_nonce('start_import'),
        'currentStage' => $current_stage,
        'fileDetails' => $file_details,
        'importState' => $import_state,
        'authorsInFile' => $authors_in_file,
    );
    // Output the admin page HTML (server-rendered)
    ?>
    <script>
        window.importerInitialState = <?php echo json_encode($initial_state); ?>;
    </script>
    <div class="wrap" data-wp-interactive="custom-importer">
        <h1><?php echo esc_html__('Import Content', 'custom-importer'); ?></h1>
        <p><?php echo esc_html__('Upload an export file to import content into this site.', 'custom-importer'); ?></p>

        <!-- Inline styles for the interface -->
        <style>
        .drag-drop-area {
            border: 2px dashed #aaa;
            padding: 40px 20px;
            text-align: center;
            cursor: pointer;
            margin-bottom: 1em;
            border-radius: 4px;
            background: #fafafa;
            transition: all 0.2s ease;
            min-height: 150px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }
        .drag-drop-area.drag-over {
            border-color: #2271b1;
            background: #f1f1f1;
        }
        .drag-drop-area.has-file {
            border-color: #00a32a;
            background: #f0f9ff;
            cursor: default;
        }
        .drop-zone-initial {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
        }
        .drop-zone-file-info {
            width: 100%;
        }
        .drop-zone-file-info h4 {
            margin: 0 0 15px 0;
            color: #1d2327;
            font-size: 16px;
        }
        .drop-zone-file-info p {
            margin: 8px 0;
            text-align: left;
            max-width: 400px;
            margin-left: auto;
            margin-right: auto;
        }
        .file-details {
            background: #e7f3ff;
            border: 1px solid #72aee6;
            border-radius: 4px;
            padding: 15px;
            margin: 15px 0;
        }
        .file-details h4 {
            margin: 0 0 10px 0;
            color: #1d2327;
        }
        .file-size-info {
            font-size: 13px;
            color: #646970;
            margin-bottom: 15px;
        }
        .upload-progress {
            margin: 15px 0;
        }
        .upload-progress progress {
            width: 100%;
            height: 20px;
        }
        .import-config {
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
            padding: 20px;
            margin: 20px 0;
        }
        .config-section {
            margin-bottom: 25px;
        }
        .config-section:last-child {
            margin-bottom: 0;
        }
        .config-section h4 {
            margin: 0 0 10px 0;
            font-size: 14px;
        }
        .author-mapping {
            background: #f6f7f7;
            border: 1px solid #dcdcde;
            border-radius: 4px;
            padding: 15px;
            margin: 10px 0;
        }
        .error-message {
            color: #d63638;
            background: #fcf0f1;
            border-left: 4px solid #d63638;
            padding: 12px;
            margin: 15px 0;
        }
        .success-message {
            color: #00a32a;
            background: #f0f6fc;
            border-left: 4px solid #00a32a;
            padding: 12px;
            margin: 15px 0;
        }
        .stage-actions {
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #dcdcde;
        }
        .change-file-link {
            font-size: 13px;
            margin-top: 10px;
        }
        /* Ensure hidden stages are properly hidden */
        [data-wp-bind--hidden][hidden] {
            display: none !important;
        }
        </style>

        <!-- Stage 1: File Selection -->
        <div id="file-selection-stage" <?php echo $current_stage !== 'select' ? 'hidden' : ''; ?> data-wp-bind--hidden="!state.isSelectStage">
            <!-- Drag-and-drop file upload area -->
            <div id="drop-zone" class="drag-drop-area" 
                 data-wp-class--has-file="state.hasSelectedFile"
                 data-wp-on--click="actions.triggerFileInput" 
                 data-wp-on--dragover="actions.handleDragOver" 
                 data-wp-on--dragleave="actions.handleDragLeave" 
                 data-wp-on--drop="actions.handleFileDrop">
                <input type="file" name="import_file" id="import_file" accept=".xml,.wxr" style="display:none;" data-wp-on--change="actions.handleFileInputChange">
                
                <!-- Initial state (no file selected) -->
                <div class="drop-zone-initial" data-wp-bind--hidden="state.hasSelectedFile">
                    <span class="dashicons dashicons-upload" style="font-size: 48px; color: #999;"></span>
                    <p style="margin: 10px 0 0 0; font-size: 16px; color: #50575e;">
                        <?php echo esc_html__('Drag & drop a file here, or click to select a file', 'custom-importer'); ?>
                    </p>
                </div>
                
                <!-- File selected state -->
                <div class="drop-zone-file-info" data-wp-bind--hidden="!state.hasSelectedFile">
                    <span class="dashicons dashicons-media-document" style="font-size: 48px; color: #00a32a;"></span>
                    <h4><?php echo esc_html__('File Selected', 'custom-importer'); ?></h4>
                    <p>
                        <strong><?php echo esc_html__('Name:', 'custom-importer'); ?></strong> 
                        <span data-wp-text="state.selectedFileName"></span>
                    </p>
                    <p>
                        <strong><?php echo esc_html__('Size:', 'custom-importer'); ?></strong> 
                        <span data-wp-text="state.selectedFileSizeFormatted"></span>
                    </p>
                    <p class="change-file-link">
                        <a href="#" onclick="event.stopPropagation(); document.getElementById('import_file').click(); return false;">
                            <?php echo esc_html__('Choose a different file', 'custom-importer'); ?>
                        </a>
                    </p>
                </div>
            </div>
            
            <div class="file-size-info">
                <?php printf(esc_html__('Maximum file size: %s', 'custom-importer'), $max_upload_size_formatted); ?>
            </div>

            <!-- File size warning -->
            <div class="error-message" data-wp-bind--hidden="!state.isFileTooBig">
                <?php printf(
                    esc_html__('This file is too large. Maximum allowed size is %s.', 'custom-importer'),
                    $max_upload_size_formatted
                ); ?>
            </div>

            <!-- Upload error -->
            <div class="error-message" data-wp-bind--hidden="!state.uploadError" data-wp-text="state.uploadError"></div>

            <!-- Upload button -->
            <div class="stage-actions" data-wp-bind--hidden="!state.hasSelectedFile">
                <button type="button" class="button button-primary button-large" 
                        data-wp-on--click="actions.uploadFile"
                        data-wp-bind--disabled="!state.canUpload">
                    <?php echo esc_html__('Upload File', 'custom-importer'); ?>
                </button>
            </div>
        </div>

        <!-- Stage 2: File Upload Progress -->
        <div id="upload-stage" hidden data-wp-bind--hidden="!state.isUploadStage">
            <h3><?php echo esc_html__('Uploading File', 'custom-importer'); ?></h3>
            
            <div class="upload-progress">
                <progress max="100" data-wp-bind--value="state.uploadProgress"></progress>
                <p>
                    <?php echo esc_html__('Upload progress:', 'custom-importer'); ?> 
                    <span data-wp-text="state.uploadProgress"></span>%
                </p>
            </div>
        </div>

        <!-- Stage 2.5: File Indexing -->
        <div id="indexing-stage" <?php echo $current_stage !== 'indexing' ? 'hidden' : ''; ?> data-wp-bind--hidden="!state.isIndexingStage">
            <h3><?php echo esc_html__('Processing File', 'custom-importer'); ?></h3>
            
            <div class="file-details">
                <h4><?php echo esc_html__('File Details', 'custom-importer'); ?></h4>
                <p>
                    <strong><?php echo esc_html__('Name:', 'custom-importer'); ?></strong> 
                    <span data-wp-text="state.selectedFileName"></span>
                </p>
                <p>
                    <strong><?php echo esc_html__('Size:', 'custom-importer'); ?></strong> 
                    <span data-wp-text="state.selectedFileSizeFormatted"></span>
                </p>
            </div>
            
            <div class="indexing-progress">
                <div style="display: flex; align-items: center; gap: 10px; margin: 15px 0;">
                    <span class="spinner is-active" style="float: none; margin: 0;"></span>
                    <span data-wp-text="state.importState"></span>
                </div>
                <p class="description"><?php echo esc_html__('Please wait while we analyze your file and extract information...', 'custom-importer'); ?></p>
            </div>
            
            <div class="stage-actions">
                <button type="button" class="button" data-wp-on--click="actions.cancelCurrentImport">
                    <?php echo esc_html__('Cancel', 'custom-importer'); ?>
                </button>
            </div>
        </div>

        <!-- Stage 3: Import Configuration -->
        <div id="config-stage" <?php echo $current_stage !== 'configure' ? 'hidden' : ''; ?> data-wp-bind--hidden="!state.isConfigureStage">
            <h3><?php echo esc_html__('Configure Import', 'custom-importer'); ?></h3>
            
            <div class="import-config">
                <!-- Download Attachments Section -->
                <div class="config-section">
                    <h4><?php echo esc_html__('Download Attachments', 'custom-importer'); ?></h4>
                    <p>
                        <label>
                            <input type="checkbox" 
                                   data-wp-bind--checked="state.downloadAttachments"
                                   data-wp-on--change="actions.setDownloadAttachments">
                            <?php echo esc_html__('Download and import file attachments', 'custom-importer'); ?>
                        </label>
                    </p>
                    
                    <div data-wp-bind--hidden="!state.downloadAttachments">
                        <label>
                            <?php echo esc_html__('Allowed Media Domains:', 'custom-importer'); ?>
                            <input type="text" class="regular-text" placeholder="example.com, cdn.example.com"
                                   data-wp-bind--value="state.allowedDomains"
                                   data-wp-on--input="actions.setAllowedDomains">
                        </label>
                        <p class="description"><?php echo esc_html__('Only download media from these domains (comma-separated).', 'custom-importer'); ?></p>
                    </div>
                </div>

                <!-- Author Assignment Section -->
                <div class="config-section">
                    <h4><?php echo esc_html__('Assign Authors', 'custom-importer'); ?></h4>
                    <p><?php esc_html_e( 'To make it simpler for you to edit and save the imported content, you may want to reassign the author of the imported item to an existing user of this site, such as your primary administrator account.', 'custom-importer' ); ?></p>
                    <p><?php esc_html_e( "If a new user is created by WordPress, a new password will be randomly generated and the new user's role will be set as subscriber. Manually changing the new user's details will be necessary.", 'custom-importer' ); ?></p>

                    <!-- Dynamic author mappings will be populated by JavaScript -->
                    <div id="author-mappings" data-wp-bind--hidden="!state.hasAuthorsInFile">
                        <!-- This will be populated dynamically based on state.authorsInFile -->
                    </div>
                </div>
            </div>

            <div class="stage-actions">
                <button type="button" class="button button-primary" data-wp-on--click="actions.startImport">
                    <?php echo esc_html__('Start Import', 'custom-importer'); ?>
                </button>
                <button type="button" class="button" data-wp-on--click="actions.resetImporter">
                    <?php echo esc_html__('Choose Different File', 'custom-importer'); ?>
                </button>
            </div>
        </div>

        <!-- Stage 4: Import Progress -->
        <div id="import-stage" hidden data-wp-bind--hidden="!state.isImportStage">
            <h3><?php echo esc_html__('Import Progress', 'custom-importer'); ?></h3>
            
            <!-- Stage indicator -->
            <div class="import-stage-indicator" data-wp-bind--hidden="state.importFinished">
                <p><strong><?php echo esc_html__('Current Stage:', 'custom-importer'); ?></strong> 
                    <span data-wp-text="state.importStageLabel"></span>
                </p>
            </div>
            
            <!-- Progress bar -->
            <div class="upload-progress" data-wp-bind--hidden="state.importFinished">
                <progress data-wp-bind--value="state.importProgress" data-wp-bind--max="state.importTotal"></progress>
                <p>
                    <span data-wp-text="state.importProgressLabel"></span>
                </p>
            </div>
            
            <!-- Error log -->
            <div class="import-errors" data-wp-bind--hidden="!state.hasImportErrors">
                <h4><?php echo esc_html__('Import Errors', 'custom-importer'); ?></h4>
                <div class="error-log" style="max-height: 200px; overflow-y: auto; background: #f6f7f7; border: 1px solid #dcdcde; padding: 10px; border-radius: 4px;">
                    <div id="import-error-list">
                        <!-- Errors will be populated dynamically -->
                    </div>
                </div>
            </div>
            
            <!-- Status and error messages -->
            <div class="error-message" data-wp-bind--hidden="!state.importError" data-wp-text="state.importError"></div>
            <div class="success-message" data-wp-bind--hidden="!state.importStatusMessage" data-wp-text="state.importStatusMessage"></div>
            
            <!-- Actions -->
            <div class="stage-actions" data-wp-bind--hidden="state.importFinished">
                <button type="button" class="button" data-wp-on--click="actions.cancelImport">
                    <?php echo esc_html__('Cancel Import', 'custom-importer'); ?>
                </button> 
                <?php
                // Trigger import step manually if cron is not working
                if ( isset($_GET['manual_import']) ) {
                    ?>
                    <button type="button" class="button" data-wp-on--click="actions.triggerImportStep">
                        <?php echo esc_html__('Trigger Next Step (Debug)', 'custom-importer'); ?>
                    </button>
                    <?php
                }
                ?>
            </div>
            
            <div class="stage-actions" data-wp-bind--hidden="!state.importFinished">
                <button type="button" class="button button-primary" data-wp-on--click="actions.resetImporter">
                    <?php echo esc_html__('Import Another File', 'custom-importer'); ?>
                </button>
            </div>
        </div>

    </div>  <!-- end .wrap -->
    <?php
}

// AJAX handler for file upload (separate from import)
add_action('wp_ajax_upload_import_file', 'custom_importer_upload_file');
function custom_importer_upload_file() {
    // Nonce verification disabled for now
    // if ( ! isset($_POST['_ajax_nonce']) ) {
    //     wp_send_json_error(array('error' => 'Missing nonce'), 403);
    // }
    // if ( ! wp_verify_nonce($_POST['_ajax_nonce'], 'upload_import_file') ) {
    //     wp_send_json_error(array('error' => 'Invalid or expired nonce'), 403);
    // }
    if ( ! current_user_can('import') ) {
        wp_send_json_error(array('error' => 'Unauthorized'), 403);
    }
    if ( empty($_FILES['import_file']) ) {
        wp_send_json_error(array('error' => __('No file uploaded.', 'custom-importer')));
    }
    
    $file = $_FILES['import_file'];
    
    // Validate file size
    $max_size = wp_max_upload_size();
    if ( $file['size'] > $max_size ) {
        wp_send_json_error(array('error' => sprintf(
            __('File is too large. Maximum size is %s.', 'custom-importer'),
            size_format($max_size)
        )));
    }
    
    // Validate file type
    $allowed_types = array('text/xml', 'application/xml');
    $file_type = wp_check_filetype($file['name']);
    if ( ! in_array($file_type['type'], $allowed_types) && ! in_array($file_type['ext'], array('xml', 'wxr')) ) {
        // @TODO: this doesn't work that well. It rejected a valid WXR file with xml extension.
        // wp_send_json_error(array('error' => __('Invalid file type. Please upload an XML or WXR file.', 'custom-importer')));
    }
    
    // Move uploaded file to plugin directory as current_import.php
    $plugin_dir = dirname(__FILE__);
    $target_file = $plugin_dir . '/current_import.php';
    $php_guard = "<?php !(); ?>\n";
    $uploaded_content = file_get_contents($file['tmp_name']);
    $result = file_put_contents($target_file, $php_guard . $uploaded_content);
    if ( $result === false ) {
        wp_send_json_error(array('error' => __('Failed to save uploaded file.', 'custom-importer')));
    }
    
    // Store file details and state
    $details = array(
        'name' => $file['name'],
        'size' => $file['size'],
        'uploaded_at' => time(),
        'state' => 'indexing uploaded file',
        'path' => $target_file,
    );
    update_option('custom_importer_current_file', $details);
    error_log(print_r($details, true));
    
    // Trigger wp-cron to kick off the import process. Do not wait for the request
    // to complete.
    if ( ! wp_next_scheduled('ng_importer__import') ) {
        error_log('scheduling fake indexing');
        wp_schedule_event(time() - 61, 'ng_importer__every_1_minute', 'ng_importer__import');
    }

    // Respond with state and file details (not authors yet)
    wp_send_json_success(array(
        'file' => $details,
        'state' => 'indexing uploaded file',
        'message' => __('File uploaded. Indexing in progress.', 'custom-importer')
    ));
}

if(array_key_exists('trigger_import', $_GET)) {
    error_log('triggering import via GET');
    do_action('ng_importer__import');
}


// in your plugin/theme

add_filter('cron_schedules', function($s) {
    $s['ng_importer__every_1_minute'] = [
      'interval' => 1,
      'display'  => 'Every 1 minute'
    ];
    return $s;
});
  
// Hook to log error on init for debugging
add_action('init', 'custom_importer_debug_init');
function custom_importer_debug_init() {
    if(str_ends_with($_SERVER['REQUEST_URI'], '/wp-cron.php')) {
        error_log('Custom Importer: Init hook triggered' . $_SERVER['REQUEST_URI']);
        // $crons = wp_get_ready_cron_jobs();
        // error_log(var_dump(isset($crons), true));
        // $crons = _get_cron_array();
        // error_log(print_r($crons, true));

    }
}


register_activation_hook(__FILE__, function() {
    if (! wp_next_scheduled('ng_importer__import')) {
        wp_schedule_event(time() - 1, 'ng_importer__every_1_minute', 'ng_importer__import');
    }
});
  
register_deactivation_hook(__FILE__, function() {
    $ts = wp_next_scheduled('ng_importer__import');
    if ($ts) wp_unschedule_event($ts, 'ng_importer__import');
});
  
add_action('ng_importer__import', function() {
    error_log('[ng_importer__import] running cron job');

    $lock_key = 'ng_importer__import_lock';
    if ( get_transient($lock_key) ) return;
    set_transient($lock_key, 1, 25);

    try {
        error_log('running cron job');
    } finally {
        delete_transient($lock_key);
    }
});



// Cron job: add fake authors and advance state
function ng_importer__import() {
    error_log('running fake indexing');
    $details = get_option('custom_importer_current_file');
    if ( ! $details ) {
        return;
    }
    $details['authors'] = array(
        array('author_login' => 'alice', 'author_display_name' => 'Alice Example'),
        array('author_login' => 'bob', 'author_display_name' => 'Bob Example'),
        array('author_login' => 'carol', 'author_display_name' => 'Carol Example'),
    );
    $details['state'] = 'awaiting author mapping';
    update_option('custom_importer_current_file', $details);
}
add_action('ng_importer__import', 'ng_importer__import');

// Cron job: run the actual import process (download images, insert entities)
function ng_importer__run_import() {
    error_log('[ng_importer__run_import] running import job');
    
    $lock_key = 'ng_importer__run_import_lock';
    if ( get_transient($lock_key) ) return;
    set_transient($lock_key, 1, 25);
    
    try {
        $import_status = get_option('custom_importer_import_status');
        $file_details = get_option('custom_importer_current_file');
        
        if ( ! $import_status || empty($import_status['running']) ) {
            error_log('No active import found');
            return;
        }
        
        // Simulate import stages
        if ( $import_status['stage'] === 'downloading' ) {
            // Simulate downloading images
            $progress = $import_status['progress'] ?? 0;
            $progress += 10;
            
            // Add some sample errors for demo
            if ( $progress === 30 || $progress === 70 ) {
                $import_status['errors'][] = array(
                    'type' => 'download_failed',
                    'message' => sprintf('Failed to download image: https://example.com/image%d.jpg - Connection timeout', $progress),
                    'timestamp' => time()
                );
            }
            
            if ( $progress >= 100 ) {
                // Move to next stage
                $import_status['stage'] = 'inserting';
                $import_status['progress'] = 0;
                $import_status['total'] = 150; // Number of entities to insert
                $file_details['current_stage'] = 'inserting';
                $file_details['state'] = 'inserting entities';
            } else {
                $import_status['progress'] = $progress;
            }
        } elseif ( $import_status['stage'] === 'inserting' ) {
            // Simulate inserting entities
            $progress = $import_status['progress'] ?? 0;
            $progress += 15;
            
            // Add some sample errors for demo
            if ( $progress === 45 || $progress === 90 ) {
                $import_status['errors'][] = array(
                    'type' => 'insert_failed',
                    'message' => sprintf('Failed to insert post ID %d: Duplicate title detected', $progress),
                    'timestamp' => time()
                );
            }
            
            if ( $progress >= $import_status['total'] ) {
                // Import complete
                $import_status['finished'] = true;
                $import_status['running'] = false;
                $import_status['message'] = sprintf('Import completed successfully! Processed %d items with %d errors.', 
                    $import_status['total'], 
                    count($import_status['errors'])
                );
                $file_details['state'] = 'import completed';
                
                // Clear the cron job
                wp_clear_scheduled_hook('ng_importer__run_import');
            } else {
                $import_status['progress'] = $progress;
            }
        }
        
        // Update both options
        update_option('custom_importer_import_status', $import_status);
        update_option('custom_importer_current_file', $file_details);
        
    } finally {
        delete_transient($lock_key);
    }
}
add_action('ng_importer__run_import', 'ng_importer__run_import');


// AJAX handlers for import process
add_action('wp_ajax_start_import', 'custom_importer_start_import');
function custom_importer_start_import() {
    // Nonce verification disabled for now (same as upload handler)
    // check_ajax_referer('start_import');
    
    if ( ! current_user_can('import') ) {
        wp_send_json_error(array('error' => 'Unauthorized'), 403);
    }
    
    // Get current file details from the database
    $current_file_details = get_option('custom_importer_current_file');
    if ( ! $current_file_details || empty($current_file_details['path']) ) {
        wp_send_json_error(array('error' => __('No file available for import.', 'custom-importer')));
    }
    
    $file_path = $current_file_details['path'];
    if ( ! file_exists($file_path) ) {
        wp_send_json_error(array('error' => __('Import file not found.', 'custom-importer')));
    }
    
    // Collect form inputs
    $download_attach   = isset($_POST['download_attachments']) && $_POST['download_attachments'] === '1';
    $allowed_domains   = isset($_POST['allowed_domains']) ? wp_strip_all_tags($_POST['allowed_domains']) : '';
    
    // Process author mappings
    $author_mappings = isset($_POST['author_mappings']) ? json_decode(stripslashes($_POST['author_mappings']), true) : array();
    
    // Update file details with import configuration
    $current_file_details['import_config'] = array(
        'download_attachments' => $download_attach,
        'allowed_domains'      => $allowed_domains,
        'author_mappings'      => $author_mappings
    );
    $current_file_details['state'] = 'downloading images';
    $current_file_details['import_progress'] = 0;
    $current_file_details['import_total'] = 0;
    $current_file_details['import_errors'] = array();
    $current_file_details['current_stage'] = 'downloading';
    
    update_option('custom_importer_current_file', $current_file_details);
    
    // Create import status tracking
    $import_status = array(
        'running' => true,
        'stage' => 'downloading',
        'progress' => 0,
        'total' => 100, // Will be updated by the actual import process
        'errors' => array(),
        'started_at' => time()
    );
    update_option('custom_importer_import_status', $import_status);
    
    // Trigger the import process via cron
    if ( ! wp_next_scheduled('ng_importer__run_import') ) {
        wp_schedule_event(time(), 'ng_importer__every_1_minute', 'ng_importer__run_import');
    }
    
    wp_send_json_success(array(
        'message' => __('Import started successfully.', 'custom-importer'),
        'stage' => 'downloading',
        'progress' => 0,
        'total' => 100
    ));
}

add_action('wp_ajax_get_import_progress', 'custom_importer_get_progress');
function custom_importer_get_progress() {
    if ( ! current_user_can('import') ) {
        wp_send_json_error(array('error' => 'Unauthorized'), 403);
    }
    
    $import_status = get_option('custom_importer_import_status');
    $file_details = get_option('custom_importer_current_file');
    
    if ( $import_status && ! empty($import_status['running']) ) {
        $response = array(
            'stage'    => $import_status['stage'] ?? 'unknown',
            'progress' => intval($import_status['progress'] ?? 0),
            'total'    => intval($import_status['total'] ?? 0),
            'errors'   => $import_status['errors'] ?? array(),
            'finished' => ! empty($import_status['finished'])
        );
        
        if ( ! empty($import_status['message']) ) {
            $response['message'] = $import_status['message'];
        }
        
        // Add current stage from file details if available
        if ( $file_details && ! empty($file_details['current_stage']) ) {
            $response['current_stage'] = $file_details['current_stage'];
        }
        
        wp_send_json_success($response);
    } else {
        wp_send_json_error(array('error' => __('No import in progress.', 'custom-importer')));
    }
}

add_action('wp_ajax_cancel_import', 'custom_importer_cancel_import');
function custom_importer_cancel_import() {
    // Nonce verification disabled for now (same as other handlers)
    // check_ajax_referer('start_import');
    
    if ( ! current_user_can('import') ) {
        wp_send_json_error(array('error' => 'Unauthorized'), 403);
    }
    
    // Update import status to cancelled
    $import_status = get_option('custom_importer_import_status');
    if ($import_status) {
        $import_status['running'] = false;
        $import_status['finished'] = true;
        $import_status['message'] = 'Import cancelled by user.';
        update_option('custom_importer_import_status', $import_status);
    }
    
    // Clear the import cron job
    wp_clear_scheduled_hook('ng_importer__run_import');
    
    wp_send_json_success(array('message' => __('Import cancelled successfully.', 'custom-importer')));
}

add_action('wp_ajax_cancel_current_import', 'custom_importer_cancel_current_import');
function custom_importer_cancel_current_import() {
    // Nonce verification disabled for now (same as upload handler)
    // check_ajax_referer('start_import');
    
    if ( ! current_user_can('import') ) {
        wp_send_json_error(array('error' => 'Unauthorized'), 403);
    }
    
    // Get current file details to find the file path
    $current_file_details = get_option('custom_importer_current_file');
    
    // Delete the uploaded file if it exists
    if ($current_file_details && !empty($current_file_details['path'])) {
        $file_path = $current_file_details['path'];
        if (file_exists($file_path)) {
            unlink($file_path);
        }
    }
    
    // Clear the stored file details
    delete_option('custom_importer_current_file');
    
    // Cancel any scheduled cron jobs
    error_log('clearing scheduled cron jobs');
    wp_clear_scheduled_hook('ng_importer__import');
    
    wp_send_json_success(array('message' => __('Import canceled successfully.', 'custom-importer')));
}

// AJAX handler to get WordPress users for author mapping
add_action('wp_ajax_get_wordpress_users', 'custom_importer_get_wordpress_users');
function custom_importer_get_wordpress_users() {
    if ( ! current_user_can('import') ) {
        wp_send_json_error(array('error' => 'Unauthorized'), 403);
    }
    
    $users = get_users(array(
        'fields' => array('ID', 'display_name', 'user_login'),
        'orderby' => 'display_name',
        'order' => 'ASC'
    ));
    
    wp_send_json_success(array('users' => $users));
}

// AJAX handler to get current import state and file details
add_action('wp_ajax_get_import_state', 'custom_importer_get_import_state');
function custom_importer_get_import_state() {
    if ( ! current_user_can('import') ) {
        wp_send_json_error(array('error' => 'Unauthorized'), 403);
    }
    $details = get_option('custom_importer_current_file');
    if ( ! $details ) {
        wp_send_json_error(array('error' => 'No import in progress.'));
    }
    $response = array(
        'file' => $details,
        'state' => $details['state'],
    );
    // If authors are available, add them
    if ( ! empty($details['authors']) ) {
        $response['authors'] = $details['authors'];
    }
    wp_send_json_success($response);
}

// Helper function to extract authors from WXR file
function extract_authors_from_wxr($file_path) {
    // This is a simplified example - you'd want more robust XML parsing
    $content = file_get_contents($file_path);
    $authors = array();
    
    // Use SimpleXML or DOMDocument for proper parsing in real implementation
    if (preg_match_all('/<wp:author_login><!\[CDATA\[(.*?)\]\]><\/wp:author_login>/', $content, $login_matches) &&
        preg_match_all('/<wp:author_display_name><!\[CDATA\[(.*?)\]\]><\/wp:author_display_name>/', $content, $name_matches)) {
        
        $logins = array_unique($login_matches[1]);
        $names = array_unique($name_matches[1]);
        
        foreach ($logins as $i => $login) {
            $authors[] = array(
                'author_login' => $login,
                'author_display_name' => isset($names[$i]) ? $names[$i] : $login
            );
        }
    }
    
    return $authors;
}

///

function get_current_import_status() {
    return array(
        'running' => false,
        'progress' => 0,
        'total' => 100,
        'error' => null,
        'message' => 'Import completed successfully!',
        'finished' => true,
    );
}

function start_import_process() {
    return true;
}

function cancel_import_process() {
    return true;
}

function get_import_log_url() {
	return 'https://example.com/import-log';
}

