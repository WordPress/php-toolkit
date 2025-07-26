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
    );
    wp_add_inline_script(
        'custom-importer-ui',
        'window.importerInitialState = ' . json_encode($initial_state) . ';',
        'before'
    );

    // Output the admin page HTML (server-rendered)
    ?>
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
        </style>

        <!-- Stage 1: File Selection -->
        <div id="file-selection-stage" data-wp-bind--hidden="state.currentStage !== 'select'">
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
        <div id="upload-stage" data-wp-bind--hidden="!state.showUploadProgress">
            <h3><?php echo esc_html__('Uploading File', 'custom-importer'); ?></h3>
            
            <div class="upload-progress">
                <progress max="100" data-wp-bind--value="state.uploadProgress"></progress>
                <p>
                    <?php echo esc_html__('Upload progress:', 'custom-importer'); ?> 
                    <span data-wp-text="state.uploadProgress"></span>%
                </p>
            </div>
        </div>

        <!-- Stage 3: Import Configuration -->
        <div id="config-stage" data-wp-bind--hidden="!state.showImportConfiguration">
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
                    <div id="author-mappings" data-wp-bind--hidden="state.authorsInFile.length === 0">
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
        <div id="import-stage" data-wp-bind--hidden="!state.showImportProgress">
            <h3><?php echo esc_html__('Import Progress', 'custom-importer'); ?></h3>
            
            <div class="upload-progress">
                <progress data-wp-bind--value="state.importProgress" data-wp-bind--max="state.importTotal"></progress>
                <p>
                    <?php echo esc_html__('Processed', 'custom-importer'); ?> 
                    <span data-wp-text="state.importProgress"></span> / 
                    <span data-wp-text="state.importTotal"></span> 
                    <?php echo esc_html__('items', 'custom-importer'); ?>.
                </p>
            </div>
            
            <!-- Status and error messages -->
            <div class="error-message" data-wp-bind--hidden="!state.importError" data-wp-text="state.importError"></div>
            <div class="success-message" data-wp-bind--hidden="!state.importStatusMessage" data-wp-text="state.importStatusMessage"></div>
            
            <!-- Actions -->
            <div class="stage-actions" data-wp-bind--hidden="!state.importing">
                <button type="button" class="button" data-wp-on--click="actions.cancelImport">
                    <?php echo esc_html__('Cancel Import', 'custom-importer'); ?>
                </button> 
                <?php
                $log_url = get_import_log_url();  // placeholder for log file URL
                if ( $log_url ) {
                    ?>
                    <a href="<?php echo esc_url($log_url); ?>" class="button" target="_blank">
                        <?php echo esc_html__('View Log', 'custom-importer'); ?>
                    </a>
                    <?php
                }
                ?>
            </div>
            
            <div class="stage-actions" data-wp-bind--hidden="state.importing">
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
    check_ajax_referer('upload_import_file');
    
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
        wp_send_json_error(array('error' => __('Invalid file type. Please upload an XML or WXR file.', 'custom-importer')));
    }
    
    // Move uploaded file to a temporary location
    $upload_dir = wp_upload_dir();
    $temp_dir = $upload_dir['basedir'] . '/temp-imports/';
    if ( ! file_exists($temp_dir) ) {
        wp_mkdir_p($temp_dir);
    }
    
    $file_id = uniqid('import_');
    $temp_file = $temp_dir . $file_id . '.xml';
    
    if ( ! move_uploaded_file($file['tmp_name'], $temp_file) ) {
        wp_send_json_error(array('error' => __('Failed to save uploaded file.', 'custom-importer')));
    }
    
    // Parse the file to extract authors (simplified example)
    $authors = extract_authors_from_wxr($temp_file);
    
    wp_send_json_success(array(
        'file_id' => $file_id,
        'authors' => $authors,
        'message' => __('File uploaded successfully.', 'custom-importer')
    ));
}

// AJAX handlers for import process
add_action('wp_ajax_start_import', 'custom_importer_start_import');
function custom_importer_start_import() {
    check_ajax_referer('start_import');
    
    if ( ! current_user_can('import') ) {
        wp_send_json_error(array('error' => 'Unauthorized'), 403);
    }
    
    if ( empty($_POST['file_id']) ) {
        wp_send_json_error(array('error' => __('No file specified for import.', 'custom-importer')));
    }
    
    $file_id = sanitize_text_field($_POST['file_id']);
    $upload_dir = wp_upload_dir();
    $temp_file = $upload_dir['basedir'] . '/temp-imports/' . $file_id . '.xml';
    
    if ( ! file_exists($temp_file) ) {
        wp_send_json_error(array('error' => __('Import file not found.', 'custom-importer')));
    }
    
    // Collect form inputs
    $download_attach   = isset($_POST['download_attachments']) && $_POST['download_attachments'] === '1';
    $allowed_domains   = isset($_POST['allowed_domains']) ? wp_strip_all_tags($_POST['allowed_domains']) : '';
    
    // Process author mappings
    $existing_users = isset($_POST['existing_user']) ? $_POST['existing_user'] : array();
    $new_users = isset($_POST['new_user']) ? $_POST['new_user'] : array();

    // Start the import process
    start_import_process($temp_file, array(
        'download_attachments' => $download_attach,
        'allowed_domains'      => $allowed_domains,
        'existing_users'       => $existing_users,
        'new_users'           => $new_users
    ));
    
    // Get initial status after starting
    $status = get_current_import_status();
    if ( $status && ! empty($status['running']) ) {
        wp_send_json_success(array(
            'total'    => intval($status['total'] ?? 0),
            'progress' => intval($status['progress'] ?? 0)
        ));
    } else {
        wp_send_json_error(array('error' => __('Failed to start import.', 'custom-importer')));
    }
}

add_action('wp_ajax_get_import_progress', 'custom_importer_get_progress');
function custom_importer_get_progress() {
    if ( ! current_user_can('import') ) {
        wp_send_json_error(array('error' => 'Unauthorized'), 403);
    }
    $status = get_current_import_status();
    if ( $status && ! empty($status['running']) ) {
        if ( ! empty($status['error']) ) {
            wp_send_json_error(array('error' => $status['error']));
        }
        $response = array(
            'count'    => intval($status['progress'] ?? 0),
            'total'    => intval($status['total'] ?? 0),
            'finished' => ! empty($status['finished'])
        );
        if ( ! empty($status['message']) ) {
            $response['message'] = $status['message'];
        }
        wp_send_json_success($response);
    } else {
        wp_send_json_error(array('error' => __('No import in progress.', 'custom-importer')));
    }
}

add_action('wp_ajax_cancel_import', 'custom_importer_cancel_import');
function custom_importer_cancel_import() {
    check_ajax_referer('start_import');  // Using same nonce as start_import
    
    if ( ! current_user_can('import') ) {
        wp_send_json_error(array('error' => 'Unauthorized'), 403);
    }
    // Stop/cancel the import (placeholder function)
    cancel_import_process();
    wp_send_json_success();
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

