<?php
/**
 * Plugin Name: Custom Importer UI
 * Description: Adds an interactive importer UI under Tools → Import using the Interactivity API.
 * Author: Your Name
 * Version: 1.0.0
 */

use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\ByteStream\WriteStream\FileWriteStream;
use WordPress\DataLiberation\Importer\ImportSession;
use WordPress\DataLiberation\Importer\StreamImporter;

use function WordPress\Filesystem\pipe_stream;

add_action('admin_init', function () {
    // Register a new importer in Tools -> Import
    register_importer(
        'custom-importer', 
        'Custom Importer', 
        'Import content from a custom format using an interactive progress UI.', 
        'custom_importer_admin_page'
    );
});

add_filter('cron_schedules', function($s) {
    $s['ng_importer__every_1_minute'] = [
      'interval' => 1,
      'display'  => 'Every 1 minute'
    ];
    return $s;
});

register_activation_hook(__FILE__, function() {
    if (! wp_next_scheduled('ng_importer_next_import_step')) {
        wp_schedule_event(time() - 1, 'ng_importer__every_1_minute', 'ng_importer_next_import_step');
    }
});
  
register_deactivation_hook(__FILE__, function() {
    $ts = wp_next_scheduled('ng_importer_next_import_step');
    if ($ts) {
        wp_unschedule_event($ts, 'ng_importer_next_import_step');
    }
});

/**
 * Get or initialize the complete importer state
 */
function custom_importer_get_state() {
    $session = ImportSession::get_active();
    if ( ! $session ) {
        return array(
            'importState' => 'idle',
            'authorsInFile' => array(),
            'downloadAttachments' => false,
            'allowedDomains' => '',
            'authorMappings' => array(),
        );
    }
    $logs = [];
    $log_stream = $session->stream_log_file();
    if($log_stream) {
        // Get the first 64KB of the log file.
        $bytes_to_read = $log_stream->pull(64 * 1024);
        $log_content = $log_stream->consume($bytes_to_read);
        $logs = explode("\n", $log_content);
        if($log_stream->length() > $log_stream->tell()) {
            $logs[] = "... " . ($log_stream->length() - $log_stream->tell()) . " bytes more ...";
            // @TODO: Add a button to download the entire log
        }
        $log_stream->close_reading();
    }
    $state = array(
        'authorsInFile' => array_values($session->get_meta_by_key('authorsInFile')),
        'downloadAttachments' => $session->get_meta_by_key('downloadAttachments'),
        'allowedDomains' => $session->get_meta_by_key('allowedDomains'),
        'authorMappings' => $session->get_meta_by_key('authorMappings') ?? [],
        'logs' => $logs,
        // ...$session->get_metadata(),
    );
    switch ($session->get_stage()) {
        case StreamImporter::STAGE_INITIAL:
        case StreamImporter::STAGE_INDEX_ENTITIES:
            $state['importState'] = 'indexing';
            return $state;
        case StreamImporter::STAGE_CONFIGURE_IMPORT:
            $state['importState'] = 'configure';
            return $state;
        case StreamImporter::STAGE_FRONTLOAD_ASSETS:
            $state['importState'] = 'downloading';
            $state['importProgress'] = $session->count_unfinished_frontloading_stubs();
            $state['importTotal'] = $session->count_frontloading_stubs();
            return $state;
        case StreamImporter::STAGE_IMPORT_ENTITIES:
            $state['importState'] = 'inserting';
            $state['importProgress'] = $session->count_imported_entities();
            $state['importTotal'] = $session->get_total_number_of_entities();
            return $state;
        case StreamImporter::STAGE_FINISHED:
            $state['importState'] = 'completed';
            // Stats
            $state['entitiesCounts'] = $session->count_imported_entities();
            $state['frontloadingStats'] = $session->get_frontloading_stats();
            return $state;
    }
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
        'custom-importer-ui',
        plugin_dir_url(__FILE__) . 'importer-ui.js',
        array('@wordpress/interactivity'),
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

    // @TODO: What if we have 100s of users? Do an autocompleted input field.
    $wp_users = get_users(array(
        'fields' => array('ID', 'display_name', 'user_login'),
        'role__in' => array('editor', 'administrator'),
    ));

	wp_interactivity_state(
		'custom-importer',
		array_merge(
			$initial_state,
			array(
                'wordpressUsers' => array_map(function($user) {
                    return array('ID' => $user->ID, 'label' => $user->display_name . ' (' . $user->user_login . ')');
                }, $wp_users),
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
					return in_array($state['importState'], ['downloading', 'inserting']);
				},
                'isCompletedStage' => function () {
                    $state = custom_importer_get_state();
                    return $state['importState'] === 'completed';
                },

                'hasAuthorsInFile' => function () {
                    $state = custom_importer_get_state();
                    return count($state['authorsInFile']) > 0;
                },
                'isAuthorMappingKeep' => function () {
                    $state = custom_importer_get_state();
					$context = wp_interactivity_get_context();
                    $login = $context['author']['author_login'];
                    if(!isset($state['authorMappings'][$login])) {
                        // Default to "keep" if no mapping is found.
                        return true;
                    }
                    return $state['authorMappings'][$login]['type'] === 'keep';
                },
                'isAuthorMappingNew' => function () {
                    $state = custom_importer_get_state();
					$context = wp_interactivity_get_context();
                    $login = $context['author']['author_login'];
                    if(!isset($state['authorMappings'][$login])) {
                        return false;
                    }
                    return $state['authorMappings'][$login]['type'] === 'new';
                },
                'isAuthorMappingExisting' => function () {
                    $state = custom_importer_get_state();
					$context = wp_interactivity_get_context();
                    $login = $context['author']['author_login'];
                    if(!isset($state['authorMappings'][$login])) {
                        return false;
                    }
                    return $state['authorMappings'][$login]['type'] === 'existing';
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
        <div data-wp-class--hidden="state.isCompletedStage">
            <h1><?php echo esc_html__('Import Content', 'custom-importer'); ?></h1>
            <p><?php echo esc_html__('Upload an export file to import content into this site.', 'custom-importer'); ?></p>
        </div>

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
        [data-wp-class--hidden].hidden {
            display: none !important;
        }
        </style>

        <!-- Stage 1: File Selection -->
        <div id="file-selection-stage" data-wp-class--hidden="!state.isSelectStage">
            <form
                data-wp-on--submit="actions.uploadFile"
                method="post"
                enctype="multipart/form-data"
                action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
            >
                <?php wp_nonce_field( 'custom_importer_upload_file' ); ?>
                <input type="hidden" name="action" value="custom_importer_upload_file">

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
                        <h4>
                            <span class="dashicons dashicons-media-document" style="font-size: 24px; width: auto; color: #00a32a;"></span>
                            <?php echo esc_html__('File Selected', 'custom-importer'); ?>
                        </h4>
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
                    <button type="submit" class="button button-primary button-large" 
                            data-wp-on--click="actions.uploadFile"
                            data-wp-bind--disabled="!state.canUpload">
                        <?php echo esc_html__('Upload File', 'custom-importer'); ?>
                    </button>
                </div>
            </form>
        </div>

        <!-- Stage 2: File Upload Progress -->
        <div id="upload-stage" data-wp-class--hidden="!state.isUploadStage">
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
            <form
                data-wp-on--submit="actions.startImport"
                method="post"
                enctype="multipart/form-data"
                action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
            >
                <?php wp_nonce_field( 'custom_importer_start_import' ); ?>
                <input type="hidden" name="action" value="custom_importer_start_import">
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
                                    data-wp-on--input="actions.setAllowedDomains"
                                    name="allowed_domains">
                            </label>
                            <p class="description"><?php echo esc_html__('Only download media from these domains (comma-separated).', 'custom-importer'); ?></p>
                        </div>
                    </div>

                    <!-- Author Assignment Section -->
                    <div class="config-section">
                        <h4><?php echo esc_html__('Assign Authors', 'custom-importer'); ?></h4>
                        <p><?php esc_html_e( 'To make it simpler for you to edit and save the imported content, you may want to reassign the author of the imported item to an existing user of this site, such as your primary administrator account.', 'custom-importer' ); ?></p>
                        <p><?php esc_html_e( "If a new user is created by WordPress, a new password will be randomly generated and the new user's role will be set as subscriber. Manually changing the new user's details will be necessary.", 'custom-importer' ); ?></p>

                        <!-- Author mappings using Interactivity API -->
                        <div id="author-mappings" data-wp-class--hidden="!state.hasAuthorsInFile">
                            <ol class="import-authors">
                                <template data-wp-each--author="state.authorsInFile">
                                    <li class="author-mapping">
                                        <p>
                                            <strong>Import author:</strong>
                                            <span data-wp-text="context.author.author_display_name"></span> 
                                            (<span data-wp-text="context.author.author_login"></span>)
                                        </p>
                                        
                                        <p>
                                            <label data-wp-context='{ "mappingType": "keep"}'>
                                                <input type="radio" 
                                                    value="keep" 
                                                    data-wp-bind--name="author_mapping_type"
                                                    data-wp-bind--checked="state.isAuthorMappingKeep"
                                                    data-wp-on--change="actions.setAuthorMappingType">
                                                Keep original author
                                            </label>
                                        </p>
                                        
                                        <p>
                                            <label data-wp-context='{ "mappingType": "new" }'>
                                                <input type="radio" 
                                                    value="new"
                                                    data-wp-bind--name="author_mapping_type"
                                                    data-wp-bind--checked="state.isAuthorMappingNew"
                                                    data-wp-on--change="actions.setAuthorMappingType">
                                                Create new user with login name:
                                                <input type="text" 
                                                    class="regular-text" 
                                                    style="margin-left: 10px;"
                                                    data-wp-bind--value="state.getAuthorMappingNewLogin"
                                                    data-wp-on--input="actions.setAuthorMappingNewLogin"
                                                    placeholder="New username">
                                            </label>
                                        </p>
                                        
                                        <p>
                                            <label data-wp-context='{"mappingType": "existing"}'>
                                                <input type="radio" 
                                                    value="existing"
                                                    data-wp-bind--name="author_mapping_type"
                                                    data-wp-bind--checked="state.isAuthorMappingExisting"
                                                    data-wp-on--change="actions.setAuthorMappingType">
                                                Assign posts to an existing user:
                                                <select style="margin-left: 10px;"
                                                        data-wp-bind--value="state.getAuthorMappingUserId"
                                                        data-wp-on--change="actions.setAuthorMappingUserId"
                                                        data-wp-context='{"authorLogin": context.author.author_login}'>
                                                    <option value="">— Select —</option>
                                                    <template data-wp-each--user="state.wordpressUsers">
                                                        <option data-wp-bind--value="context.user.ID"
                                                                data-wp-text="context.user.label"></option>
                                                    </template>
                                                </select>
                                            </label>
                                        </p>
                                    </li>
                                </template>
                            </ol>
                        </div>
                    </div>
                </div>

                <div class="stage-actions">
                    <button type="submit" class="button button-primary" data-wp-on--click="actions.startImport">
                        <?php echo esc_html__('Start Import', 'custom-importer'); ?>
                    </button>
                    <button type="button" class="button" data-wp-on--click="actions.cancelCurrentImport">
                        <?php echo esc_html__('Choose Different File', 'custom-importer'); ?>
                    </button>
                </div>
            </form>
        </div>

        <!-- Stage 4: Import Progress -->
        <div id="import-stage" data-wp-class--hidden="!state.isImportStage">            
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
            
            <!-- Actions -->
            <button type="button" class="button" data-wp-on--click="actions.cancelCurrentImport">
                <?php echo esc_html__('Cancel Import', 'custom-importer'); ?>
            </button>
            
            <!-- Status and error messages -->
            <div class="error-message" data-wp-class--hidden="!state.importError" data-wp-text="state.importError"></div>
            <div class="success-message" data-wp-class--hidden="!state.importStatusMessage" data-wp-text="state.importStatusMessage"></div>

            <!-- Error log -->
            <div class="import-errors">
                <h4><?php echo esc_html__('Import Logs', 'custom-importer'); ?></h4>
                <div class="error-log" style="max-height: 200px; overflow-y: auto; background: #f6f7f7; border: 1px solid #dcdcde; padding: 10px; border-radius: 4px;">
                    <ul id="import-error-list">
                        <template data-wp-each--log="state.logs">
                            <li data-wp-text="context.log"></li>
                        </template>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Stage 5: Import Completed -->
        <div id="import-completed-stage" data-wp-class--hidden="!state.isCompletedStage">
            <h1><?php echo esc_html__('Import Completed', 'custom-importer'); ?></h1>
            <p>
                <?php echo esc_html__('The import has completed successfully.', 'custom-importer'); ?>
            </p>
            <p>
                <?php echo esc_html__('Import summary:', 'custom-importer'); ?>
            </p>
            <p>
                <ul>
                    <li>
                        <strong><?php echo esc_html__('Media files:', 'custom-importer'); ?></strong>
                        <ul style="margin-left: 20px;margin-top: 5px;">
                            <li>
                                <span data-wp-text="state.frontloadingStats.succeeded"></span>
                                <?php echo esc_html__('downloaded', 'custom-importer'); ?>
                            </li>
                            <li>
                                <span data-wp-text="state.frontloadingStats.unfinished"></span>
                                <?php echo esc_html__('failed to download', 'custom-importer'); ?>
                            </li>
                        </ul>
                    </li>
                    <li>
                        <strong><?php echo esc_html__('Imported entities:', 'custom-importer'); ?></strong>
                        <ul style="margin-left: 20px;margin-top: 5px;">
                            <template data-wp-each--entity="state.entitiesCounts">
                                <li>
                                    <span data-wp-text="context.entity.label"></span>:
                                    <span data-wp-text="context.entity.imported"></span>
                                </li>
                            </template>
                        </ul>
                    </li>
                    <!-- TODO: Display all the errors. Potentially paginated. -->
                </ul>
            </p>

            <div class="import-errors">
                <h4><?php echo esc_html__('Import Logs', 'custom-importer'); ?></h4>
                <div class="error-log" style="max-height: 200px; overflow-y: auto; background: #f6f7f7; border: 1px solid #dcdcde; padding: 10px; border-radius: 4px;">
                    <ul id="import-error-list">
                        <template data-wp-each--log="state.logs">
                            <li data-wp-text="context.log"></li>
                        </template>
                    </ul>
                </div>
            </div>
            
            <div class="stage-actions">
                <button type="button" class="button button-primary" data-wp-on--click="actions.cancelCurrentImport">
                    <?php echo esc_html__('Start a new import', 'custom-importer'); ?>
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
    // Get state endpoint
    register_rest_route('custom-importer/v1', '/state', array(
        'methods' => 'GET',
        'callback' => 'custom_importer_get_state_rest',
        'permission_callback' => function () {
            return current_user_can('import');
        }
    ));

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
        'callback' => 'custom_importer_save_config',
        'permission_callback' => function () {
            return current_user_can('import');
        }
    ));

    // Consolidated next import step endpoint
    register_rest_route('custom-importer/v1', '/next-step', array(
        'methods' => 'GET',
        'callback' => 'ng_importer_next_import_step',
        'permission_callback' => function () {
            return current_user_can('import');
        }
    ));

    // Cancel current import endpoint
    register_rest_route('custom-importer/v1', '/cancel', array(
        'methods' => 'POST',
        'callback' => 'custom_importer_cancel_current_import_rest',
        'permission_callback' => function () {
            return current_user_can('import');
        }
    ));
});

// REST API handler for getting state
function custom_importer_get_state_rest() {
    return rest_ensure_response(custom_importer_get_state());
}

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
    if ( 
        (false !== $file_type['type'] && ! in_array($file_type['type'], $allowed_types)) && 
        (false !== $file_type['ext'] && ! in_array($file_type['ext'], array('xml', 'wxr')))
    ) {
        // @TODO: this doesn't work that well. It rejected a valid WXR file with xml extension.
        return new WP_Error('invalid_file_type', __('Invalid file type. Please upload an XML or WXR file.', 'custom-importer'), array('status' => 400));
    }
    
    // Move uploaded file to plugin directory as last-uploaded-import-file.php
    $plugin_dir = dirname(__FILE__);
    $target_file = $plugin_dir . '/last-uploaded-import-file.php';
    
    $uploaded_data_stream = FileReadStream::from_path($file['tmp_name']);
    $target_file_stream = FileWriteStream::from_path($target_file);
    $php_guard = "<?php !(); ?>\n";
    $target_file_stream->append_bytes($php_guard);
    try {
        pipe_stream($uploaded_data_stream, $target_file_stream);
    } catch (Exception $e) {
        return new WP_Error('save_failed', __('Failed to save uploaded file.', 'custom-importer'), array('status' => 500));
    } finally {
        $target_file_stream->close_writing();
        $uploaded_data_stream->close_reading();
    }
    
    $log_file = $plugin_dir . '/last-import-session-log.php';
    if(file_exists($log_file)) {
        @unlink($log_file);
    }

    // Create an import session for the uploaded file – to be used by the ng-import-next-step.php job.
    // @TODO: Harmonize this with the interactivity API state
    $session = ImportSession::create(array(
        'data_source' => 'wxr_file',
        'file_name' => $target_file,
        'log_file' => $log_file,
    ));
    $session->set_meta_by_key('authorsInFile', array());
    
    // Update state with file details
    $file_details = array(
        'name' => $file['name'],
        'size' => $file['size'],
        'uploaded_at' => time(),
        'path' => $target_file,
    );
    $session->set_meta_by_key('fileDetails', $file_details);
    
    // Return the complete updated state
    return rest_ensure_response(custom_importer_get_state());
}


add_action('ng_importer_next_import_step', 'ng_importer_next_import_step');
function ng_importer_next_import_step() {
    error_log('[ng_importer_next_import_step] running cron job');

    $lock_key = 'ng_importer_next_import_step_lock';
    if ( get_transient($lock_key) ) return;
    set_transient($lock_key, 1, 25);

    try {
        do_ng_importer_next_import_step();
    } finally {
        delete_transient($lock_key);
    }
    return custom_importer_get_state();
}



// REST API handler for starting import
function custom_importer_save_config($request) {
    $active_import = ng_importer_get_active();
    if ( ! $active_import ) {
        return new WP_Error('no_active_import', __('No active import session.', 'custom-importer'), array('status' => 400));
    }
    $importer = $active_import['importer'];
    $session = $active_import['session'];
    
    // Check if we have a file and are in configure stage
    if ($session->get_stage() !== StreamImporter::STAGE_CONFIGURE_IMPORT) {
        return new WP_Error('invalid_state', __('Not in configure stage. ' . $session->get_stage(), 'custom-importer'), array('status' => 400));
    }
    
    $file_path = $session->get_meta_by_key('fileDetails')['path'];
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
    
    $session->set_meta_by_key('downloadAttachments', $download_attach);
    $session->set_meta_by_key('allowedDomains', $allowed_domains);
    $session->set_meta_by_key('authorMappings', $author_mappings);

    // No-op for STAGE_CONFIGURE_IMPORT. It only tells the importer what's the
    // next stage.
    $importer->next_step();
    if ( ! $importer->advance_to_next_stage() ) {
        return new WP_Error('advance_to_next_stage_failed', __('Failed to advance to next import stage.', 'custom-importer'), array('status' => 500));
    }
    $session->set_stage( $importer->get_stage() );
    $session->set_reentrancy_cursor( $importer->get_reentrancy_cursor() );

    return rest_ensure_response(custom_importer_get_state());
}

// REST API handler for canceling current import
function custom_importer_cancel_current_import_rest() {
    $state = custom_importer_get_state();
    
    // Delete the uploaded file if it exists
    if (isset($state['fileDetails']) && !empty($state['fileDetails']['path'])) {
        $file_path = $state['fileDetails']['path'];
        if (file_exists($file_path)) {
            unlink($file_path);
        }
    }
    
    // Reset to initial state
    $session = ImportSession::get_active();
    if($session) {
        $session->archive();
    }

    return rest_ensure_response(custom_importer_get_state());
}
