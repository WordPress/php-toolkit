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

function default_importer_state() {
    return array(
        'importState' => 'idle',
        'fileDetails' => null,
        'authorsInFile' => array(),
        'importProgress' => 0,
        'importTotal' => 0,
        'importErrors' => array(),
        'importStatusMessage' => '',
        'importError' => ''
    );
}

/**
 * Get or initialize the complete importer state
 */
function custom_importer_get_state() {
    $state = get_option('custom_importer_state', default_importer_state());
    
    return $state;
}

/**
 * Update the importer state
 */
function update_custom_importer_update_state($updates) {
    $state = custom_importer_get_state();
    $state = array_merge($state, $updates);
    update_option('custom_importer_state', $state);
    return $state;
}

// Callback to render the Importer admin page
function custom_importer_admin_page() {
    if ( ! current_user_can('import') ) {
        wp_die(__('You are not allowed to import.', 'custom-importer'));
    }

    // Get PHP file upload limits
    $max_upload_size = wp_max_upload_size();
    $max_upload_size_formatted = size_format($max_upload_size);

    // Get current state using centralized function
    $current_state = custom_importer_get_state();

    // Enqueue the interactivity store script (as an ES module) for this page
    wp_enqueue_script_module(
        'custom-importer-ui',                                      // script handle
        plugin_dir_url(__FILE__) . 'importer-ui.js',               // module script file
        array('@wordpress/interactivity'),                        // ensure the Interactivity API is available
        '1.0.0'
    );
    
    // Pass initial state from PHP to JS via an inline script before the module
    $initial_state = array_merge(
        array(
            'maxFileSize' => $max_upload_size,
            'maxFileSizeFormatted' => $max_upload_size_formatted,
            'restUrl' => rest_url('custom-importer/v1/'),
            'restNonce' => wp_create_nonce('wp_rest'),
        ),
        $current_state
    );

	wp_interactivity_state(
		'custom-importer',
		array_merge(
			$initial_state,
			array(
                'isSelectStage' => function () {
					$state = custom_importer_get_state();
					return $state['importState'] === 'idle';
				},
                'isUploadStage' => function () {
                    // Upload stage is handled by JavaScript, not server-side state
                    return false;
                },
				'isIndexingStage' => function () {
					$state = custom_importer_get_state();
					return $state['importState'] === 'indexing';
				},
                'isConfigureStage' => function () {
                    $state = custom_importer_get_state();
                    return $state['importState'] === 'configure';
                },
				'isImportStage' => function () {
					$state = custom_importer_get_state();
					return in_array($state['importState'], ['downloading', 'inserting', 'completed']);
				},
			)
		)
	);

    // Output the admin page HTML (server-rendered)
    ob_start();
    ?>
    <script>
        window.importerInitialState = <?php echo json_encode($initial_state); ?>;
    </script>
    <div class="wrap" data-wp-interactive="custom-importer" data-wp-init--refresh="callbacks.continuouslyTriggerNextImportStep">
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
        [data-wp-class--hidden][hidden] {
            display: none !important;
        }
        </style>

        <!-- Stage 1: File Selection -->
        <div id="file-selection-stage" data-wp-class--hidden="!state.isSelectStage">
            <!-- Drag-and-drop file upload area -->
            <div id="drop-zone" class="drag-drop-area" 
                 data-wp-class--has-file="state.hasSelectedFile"
                 data-wp-on--click="actions.triggerFileInput" 
                 data-wp-on--dragover="actions.handleDragOver" 
                 data-wp-on--dragleave="actions.handleDragLeave" 
                 data-wp-on--drop="actions.handleFileDrop">
                <input type="file" name="import_file" id="import_file" accept=".xml,.wxr" style="display:none;" data-wp-on--change="actions.handleFileInputChange">
                
                <!-- Initial state (no file selected) -->
                <div class="drop-zone-initial" data-wp-class--hidden="state.hasSelectedFile">
                    <span class="dashicons dashicons-upload" style="font-size: 48px; color: #999;"></span>
                    <p style="margin: 10px 0 0 0; font-size: 16px; color: #50575e;">
                        <?php echo esc_html__('Drag & drop a file here, or click to select a file', 'custom-importer'); ?>
                    </p>
                </div>
                
                <!-- File selected state -->
                <div class="drop-zone-file-info" data-wp-class--hidden="!state.hasSelectedFile">
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
            <div class="error-message" data-wp-class--hidden="!state.isFileTooBig">
                <?php printf(
                    esc_html__('This file is too large. Maximum allowed size is %s.', 'custom-importer'),
                    $max_upload_size_formatted
                ); ?>
            </div>

            <!-- Upload error -->
            <div class="error-message" data-wp-class--hidden="!state.uploadError" data-wp-text="state.uploadError"></div>

            <!-- Upload button -->
            <div class="stage-actions" data-wp-class--hidden="!state.hasSelectedFile">
                <button type="button" class="button button-primary button-large" 
                        data-wp-on--click="actions.uploadFile"
                        data-wp-bind--disabled="!state.canUpload">
                    <?php echo esc_html__('Upload File', 'custom-importer'); ?>
                </button>
            </div>
        </div>

        <!-- Stage 2: File Upload Progress -->
        <div id="upload-stage" hidden data-wp-class--hidden="!state.isUploadStage">
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
        <div id="indexing-stage" data-wp-class--hidden="!state.isIndexingStage">
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
        <div id="config-stage" data-wp-class--hidden="!state.isConfigureStage">
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
                    
                    <div data-wp-class--hidden="!state.downloadAttachments">
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
                    <div id="author-mappings" data-wp-class--hidden="!state.hasAuthorsInFile">
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
        <div id="import-stage" hidden data-wp-class--hidden="!state.isImportStage">
            <h3><?php echo esc_html__('Import Progress', 'custom-importer'); ?></h3>
            
            <!-- Stage indicator -->
            <div class="import-stage-indicator" data-wp-class--hidden="state.importState === 'completed'">
                <p><strong><?php echo esc_html__('Current Stage:', 'custom-importer'); ?></strong> 
                    <span data-wp-text="state.importStageLabel"></span>
                </p>
            </div>
            
            <!-- Progress bar -->
            <div class="upload-progress" data-wp-class--hidden="state.importState === 'completed'">
                <progress data-wp-bind--value="state.importProgress" data-wp-bind--max="state.importTotal"></progress>
                <p>
                    <span data-wp-text="state.importProgressLabel"></span>
                </p>
            </div>
            
            <!-- Error log -->
            <div class="import-errors" data-wp-class--hidden="!state.hasImportErrors">
                <h4><?php echo esc_html__('Import Errors', 'custom-importer'); ?></h4>
                <div class="error-log" style="max-height: 200px; overflow-y: auto; background: #f6f7f7; border: 1px solid #dcdcde; padding: 10px; border-radius: 4px;">
                    <div id="import-error-list">
                        <!-- Errors will be populated dynamically -->
                    </div>
                </div>
            </div>
            
            <!-- Status and error messages -->
            <div class="error-message" data-wp-class--hidden="!state.importError" data-wp-text="state.importError"></div>
            <div class="success-message" data-wp-class--hidden="!state.importStatusMessage" data-wp-text="state.importStatusMessage"></div>
            
            <!-- Actions -->
            <div class="stage-actions" data-wp-class--hidden="state.importState === 'completed'">
                <button type="button" class="button" data-wp-on--click="actions.cancelImport">
                    <?php echo esc_html__('Cancel Import', 'custom-importer'); ?>
                </button>
            </div>
            
            <div class="stage-actions" data-wp-class--hidden="state.importState !== 'completed'">
                <button type="button" class="button button-primary" data-wp-on--click="actions.resetImporter">
                    <?php echo esc_html__('Import Another File', 'custom-importer'); ?>
                </button>
            </div>
        </div>

    </div>  <!-- end .wrap -->
    <?php
	$html = ob_get_clean();
	echo wp_interactivity_process_directives( $html );
}

// Register REST API routes for the importer
add_action('rest_api_init', function () {
    // Upload file endpoint
    register_rest_route('custom-importer/v1', '/upload', array(
        'methods' => 'POST',
        'callback' => 'custom_importer_upload_file_rest',
        'permission_callback' => function () {
            return current_user_can('import');
        }
    ));

    // Start import endpoint
    register_rest_route('custom-importer/v1', '/start', array(
        'methods' => 'POST',
        'callback' => 'custom_importer_start_import_rest',
        'permission_callback' => function () {
            return current_user_can('import');
        }
    ));

    // Consolidated next import step endpoint
    register_rest_route('custom-importer/v1', '/next-step', array(
        'methods' => 'GET',
        'callback' => 'custom_importer_next_step_rest',
        'permission_callback' => function () {
            return current_user_can('import');
        }
    ));

    // Cancel import endpoint
    register_rest_route('custom-importer/v1', '/cancel', array(
        'methods' => 'POST',
        'callback' => 'custom_importer_cancel_import_rest',
        'permission_callback' => function () {
            return current_user_can('import');
        }
    ));

    // Cancel current import endpoint
    register_rest_route('custom-importer/v1', '/cancel-current', array(
        'methods' => 'POST',
        'callback' => 'custom_importer_cancel_current_import_rest',
        'permission_callback' => function () {
            return current_user_can('import');
        }
    ));

    // Get WordPress users endpoint
    register_rest_route('custom-importer/v1', '/users', array(
        'methods' => 'GET',
        'callback' => 'custom_importer_get_wordpress_users_rest',
        'permission_callback' => function () {
            return current_user_can('import');
        }
    ));
});

// REST API handler for file upload
function custom_importer_upload_file_rest($request) {
    if ( empty($_FILES['import_file']) ) {
        return new WP_Error('no_file', __('No file uploaded.', 'custom-importer'), array('status' => 400));
    }
    
    $file = $_FILES['import_file'];
    
    // Validate file size
    $max_size = wp_max_upload_size();
    if ( $file['size'] > $max_size ) {
        return new WP_Error('file_too_large', sprintf(
            __('File is too large. Maximum size is %s.', 'custom-importer'),
            size_format($max_size)
        ), array('status' => 400));
    }
    
    // Validate file type
    $allowed_types = array('text/xml', 'application/xml');
    $file_type = wp_check_filetype($file['name']);
    if ( ! in_array($file_type['type'], $allowed_types) && ! in_array($file_type['ext'], array('xml', 'wxr')) ) {
        // @TODO: this doesn't work that well. It rejected a valid WXR file with xml extension.
        // return new WP_Error('invalid_file_type', __('Invalid file type. Please upload an XML or WXR file.', 'custom-importer'), array('status' => 400));
    }
    
    // Move uploaded file to plugin directory as current_import.php
    $plugin_dir = dirname(__FILE__);
    $target_file = $plugin_dir . '/current_import.php';
    $php_guard = "<?php !(); ?>\n";
    $uploaded_content = file_get_contents($file['tmp_name']);
    $result = file_put_contents($target_file, $php_guard . $uploaded_content);
    if ( $result === false ) {
        return new WP_Error('save_failed', __('Failed to save uploaded file.', 'custom-importer'), array('status' => 500));
    }
    
    // Update state with file details
    $file_details = array(
        'name' => $file['name'],
        'size' => $file['size'],
        'uploaded_at' => time(),
        'path' => $target_file,
    );
    
    $updated_state = update_custom_importer_update_state(array(
        'importState' => 'indexing',
        'fileDetails' => $file_details,
        'authorsInFile' => array(),
        'importProgress' => 0,
        'importTotal' => 0,
        'importErrors' => array(),
        'importStatusMessage' => '',
        'importError' => ''
    ));
    
    error_log(print_r($updated_state, true));
    
    // Trigger wp-cron to kick off the import process
    if ( ! wp_next_scheduled('ng_importer__import') ) {
        error_log('scheduling fake indexing');
        wp_schedule_event(time() - 61, 'ng_importer__every_1_minute', 'ng_importer__import');
    }

    // Return the complete updated state
    return rest_ensure_response($updated_state);
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
    $state = custom_importer_get_state();
    
    if ($state['importState'] !== 'indexing') {
        return;
    }
    
    // Add fake authors and advance to configure stage
    update_custom_importer_update_state(array(
        'importState' => 'configure',
        'authorsInFile' => array(
            array('author_login' => 'alice', 'author_display_name' => 'Alice Example'),
            array('author_login' => 'bob', 'author_display_name' => 'Bob Example'),
            array('author_login' => 'carol', 'author_display_name' => 'Carol Example'),
        )
    ));
}
add_action('ng_importer__import', 'ng_importer__import');

// Cron job: run the actual import process (download images, insert entities)
function ng_importer__run_import() {
    error_log('[ng_importer__run_import] running import job');
    
    $lock_key = 'ng_importer__run_import_lock';
    if ( get_transient($lock_key) ) return;
    set_transient($lock_key, 1, 25);
    
    try {
        $state = custom_importer_get_state();
        
        if ( ! in_array($state['importState'], ['downloading', 'inserting']) ) {
            error_log('No active import found');
            return;
        }
        
        // Simulate import stages
        if ( $state['importState'] === 'downloading' ) {
            // Simulate downloading images
            $progress = $state['importProgress'] + 10;
            
            // Add some sample errors for demo
            if ( $progress === 30 || $progress === 70 ) {
                $errors = $state['importErrors'];
                $errors[] = array(
                    'type' => 'download_failed',
                    'message' => sprintf('Failed to download image: https://example.com/image%d.jpg - Connection timeout', $progress),
                    'timestamp' => time()
                );
                
                update_custom_importer_update_state(array(
                    'importProgress' => $progress,
                    'importErrors' => $errors
                ));
            } else {
                if ( $progress >= 100 ) {
                    // Move to next stage
                    update_custom_importer_update_state(array(
                        'importState' => 'inserting',
                        'importProgress' => 0,
                        'importTotal' => 150,
                    ));
                } else {
                    update_custom_importer_update_state(array(
                        'importProgress' => $progress
                    ));
                }
            }
        } elseif ( $state['importState'] === 'inserting' ) {
            $progress = $state['importProgress'] + 15;
            
            // Add some sample errors for demo
            if ( $progress === 45 || $progress === 90 ) {
                $errors = $state['importErrors'];
                $errors[] = array(
                    'type' => 'insert_failed',
                    'message' => sprintf('Failed to insert post ID %d: Duplicate title detected', $progress),
                    'timestamp' => time()
                );
                
                update_custom_importer_update_state(array(
                    'importProgress' => $progress,
                    'importErrors' => $errors
                ));
            } else {
                if ( $progress >= $state['importTotal'] ) {
                    // Import complete
                    update_custom_importer_update_state(array(
                        'importProgress' => $state['importTotal'],
                        'importState' => 'completed',
                        'importStatusMessage' => sprintf('Import completed successfully! Processed %d items with %d errors.', 
                            $state['importTotal'], 
                            count($state['importErrors'])
                        )
                    ));
                    
                    // Clear the cron job
                    wp_clear_scheduled_hook('ng_importer__run_import');
                } else {
                    update_custom_importer_update_state(array(
                        'importProgress' => $progress
                    ));
                }
            }
        }
        
    } finally {
        delete_transient($lock_key);
    }
}
add_action('ng_importer__run_import', 'ng_importer__run_import');


// REST API handler for starting import
function custom_importer_start_import_rest($request) {
    $state = custom_importer_get_state();
    
    // Check if we have a file and are in configure stage
    if (!$state['fileDetails'] || $state['importState'] !== 'configure') {
        return new WP_Error('invalid_state', __('No file available for import or not in configure stage.', 'custom-importer'), array('status' => 400));
    }
    
    $file_path = $state['fileDetails']['path'];
    if ( ! file_exists($file_path) ) {
        return new WP_Error('file_not_found', __('Import file not found.', 'custom-importer'), array('status' => 404));
    }
    
    // Collect form inputs
    $download_attach   = $request->get_param('download_attachments') === '1' || $request->get_param('download_attachments') === true;
    $allowed_domains   = sanitize_text_field($request->get_param('allowed_domains'));
    
    // Process author mappings
    $author_mappings_raw = $request->get_param('author_mappings');
    $author_mappings = array();
    if (is_string($author_mappings_raw)) {
        $author_mappings = json_decode(stripslashes($author_mappings_raw), true);
    } elseif (is_array($author_mappings_raw)) {
        $author_mappings = $author_mappings_raw;
    }
    
    // Update state to import stage
    $updated_state = update_custom_importer_update_state(array(
        'importState' => 'downloading',
        'importProgress' => 0,
        'importTotal' => 100,
        'importErrors' => array(),
        'importStatusMessage' => __('Import started successfully.', 'custom-importer'),
        'importError' => '',
        'importConfig' => array(
            'download_attachments' => $download_attach,
            'allowed_domains'      => $allowed_domains,
            'author_mappings'      => $author_mappings
        )
    ));
    
    // Trigger the import process via cron
    if ( ! wp_next_scheduled('ng_importer__run_import') ) {
        wp_schedule_event(time(), 'ng_importer__every_1_minute', 'ng_importer__run_import');
    }
    
    return rest_ensure_response($updated_state);
}

// Consolidated REST API handler for next import step
function custom_importer_next_step_rest($request) {
    // Get current state and return it directly
    $current_state = custom_importer_get_state();
    return rest_ensure_response($current_state);
}

// REST API handler for canceling import
function custom_importer_cancel_import_rest($request) {
    // Update state to cancelled
    $updated_state = update_custom_importer_update_state(array(
        'importState' => 'completed',
        'importStatusMessage' => 'Import cancelled by user.'
    ));
    
    // Clear the import cron job
    wp_clear_scheduled_hook('ng_importer__run_import');
    
    return rest_ensure_response($updated_state);
}

// REST API handler for canceling current import
function custom_importer_cancel_current_import_rest($request) {
    $state = custom_importer_get_state();
    
    // Delete the uploaded file if it exists
    if ($state['fileDetails'] && !empty($state['fileDetails']['path'])) {
        $file_path = $state['fileDetails']['path'];
        if (file_exists($file_path)) {
            unlink($file_path);
        }
    }
    
    // Reset to initial state
    $reset_state = update_custom_importer_update_state(array(
        'importState' => 'idle',
        'fileDetails' => null,
        'authorsInFile' => array(),
        'importProgress' => 0,
        'importTotal' => 0,
        'importErrors' => array(),
        'importStatusMessage' => 'Import canceled successfully.',
        'importError' => ''
    ));
    
    // Cancel any scheduled cron jobs
    error_log('clearing scheduled cron jobs');
    wp_clear_scheduled_hook('ng_importer__import');
    wp_clear_scheduled_hook('ng_importer__run_import');
    
    return rest_ensure_response($reset_state);
}

// REST API handler to get WordPress users for author mapping
function custom_importer_get_wordpress_users_rest($request) {
    $users = get_users(array(
        'fields' => array('ID', 'display_name', 'user_login'),
        'orderby' => 'display_name',
        'order' => 'ASC'
    ));
    
    return rest_ensure_response(array('users' => $users));
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


