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

    // Check if an import is currently in progress (placeholder backend function)
    $status       = get_current_import_status();  // e.g. returns ['running'=>bool, 'progress'=>X, 'total'=>Y, 'error'=> '...']
    $importing    = $status && ! empty($status['running']);
    $progressCount = $importing ? intval($status['progress'] ?? 0) : 0;
    $progressTotal = $importing ? intval($status['total'] ?? 0) : 0;
    $errorMessage  = $importing && ! empty($status['error']) ? $status['error'] : '';

    // Enqueue the interactivity store script (as an ES module) for this page
    wp_enqueue_script_module(
        'custom-importer-ui',                                      // script handle
        plugin_dir_url(__FILE__) . 'importer-ui.js',               // module script file
        array('@wordpress/interactivity'),                        // ensure the Interactivity API is available
        '1.0.0'
    );
    // Pass initial state from PHP to JS via an inline script before the module
    $initial_state = array(
        'importing'     => $importing,
        'progressCount' => $progressCount,
        'progressTotal' => $progressTotal,
        'errorMessage'  => $errorMessage,
        'statusMessage' => ''
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

        <!-- Inline styles for drag-drop area (using WP admin color scheme, minimal custom CSS) -->
        <style>
        .drag-drop-area {
            border: 2px dashed #aaa;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            margin-bottom: 1em;
        }
        .drag-drop-area.drag-over {
            border-color: #2271b1;  /* highlight border on drag */
            background: #f1f1f1;
        }
        </style>

        <!-- File upload form (shown when not currently importing) -->
        <div id="importer-form" <?php echo $importing ? 'hidden' : ''; ?> data-wp-bind--hidden="state.importing">
            <form id="import-form" method="post" enctype="multipart/form-data" data-wp-on--submit="actions.startImport">
                <!-- Hidden action field for admin-ajax -->
                <input type="hidden" name="action" value="start_import">
                
                <!-- Drag-and-drop file upload field -->
                <div id="drop-zone" class="drag-drop-area" 
                     data-wp-on--click="actions.triggerFileInput" 
                     data-wp-on--dragover="actions.handleDragOver" 
                     data-wp-on--dragleave="actions.handleDragLeave" 
                     data-wp-on--drop="actions.handleFileDrop">
                    <input type="file" name="import_file" id="import_file" accept=".xml,.wxr" style="display:none;">
                    <p><?php echo esc_html__('Drag & drop a file here, or click to select a file', 'custom-importer'); ?></p>
                </div>

                <!-- Import settings options -->
                <h3><?php echo esc_html__('Import Settings', 'custom-importer'); ?></h3>
				<h4><?php echo esc_html__('Download Attachments', 'custom-importer'); ?></h4>
				<p>
					<label>
						<input
							type="checkbox"
							name="download_attachments"
							value="1"
							checked
							data-wp-bind--checked="state.downloadAttachments"
							data-wp-on--change="actions.setDownloadAttachments">
						<?php echo esc_html__('Download and import file attachments', 'custom-importer'); ?>
					</label>
				</p>
				<!-- TODO: Add an "advanced" checkbox to show/hide the next few media-related rows -->
				<div data-wp-class--hidden="!state.downloadAttachments">
					<h4><?php echo esc_html__('Allowed Media Domains', 'custom-importer'); ?></h4>
					<p>
						<input type="text" name="allowed_domains" class="regular-text" placeholder="example.com, cdn.example.com">
						<p class="description"><?php echo esc_html__('Only download media from these domains (comma-separated).', 'custom-importer'); ?></p>
					</p>
				</div>

				<h4><?php echo esc_html__('Assign Authors', 'custom-importer'); ?></h4>
				<?php
				// Assume
				$authors_in_wxr = [ ['author_login' => 'john', 'author_display_name' => 'John Doe'] ];
				// $authors_in_wxr = get_wxr_authors(); // Replace with your own extraction function
				$users = get_users([ 'fields' => [ 'ID', 'display_name', 'user_login' ] ]);
				?>
				<p><?php esc_html_e( 'To make it simpler for you to edit and save the imported content, you may want to reassign the author of the imported item to an existing user of this site, such as your primary administrator account.', 'custom-importer' ); ?></p>
				<p><?php esc_html_e( "If a new user is created by WordPress, a new password will be randomly generated and the new user's role will be set as subscriber. Manually changing the new user's details will be necessary.", 'custom-importer' ); ?></p>

				<ol class="import-authors">
					<?php foreach ( $authors_in_wxr as $i => $author ): ?>
						<li>
							<p>
								<?php esc_html_e( 'Import author:', 'custom-importer' ); ?>
								<strong><?php echo esc_html( $author['author_display_name'] ); ?> (<?php echo esc_html( $author['author_login'] ); ?>)</strong>
							</p>

							<label>
								<?php esc_html_e( 'or create new user with login name:', 'custom-importer' ); ?>
								<input type="text"
									name="new_user[<?php echo esc_attr( $author['author_login'] ); ?>]"
									class="regular-text" />
							</label>
							<br/>
							<label>
								<?php esc_html_e( 'or assign posts to an existing user:', 'custom-importer' ); ?>
								<!-- TODO: can author_login contain the "]" character? -->
								<select name="existing_user[<?php echo esc_attr( $author['author_login'] ); ?>]">
									<option value=""><?php esc_html_e( '— Select —', 'custom-importer' ); ?></option>
									<?php foreach ( $users as $user ): ?>
										<option value="<?php echo esc_attr( $user->ID ); ?>">
											<?php echo esc_html( $user->display_name ); ?> (<?php echo esc_html( $user->user_login ); ?>)
										</option>
									<?php endforeach; ?>
								</select>
							</label>
						</li>
					<?php endforeach; ?>
				</ol>

                <!-- Submit button -->
                <p><button type="submit" class="button button-primary"><?php echo esc_html__('Start Import', 'custom-importer'); ?></button></p>
            </form>
        </div>  <!-- end importer-form div -->

        <!-- Progress UI (shown when an import is active) -->
        <div id="importer-progress" <?php echo !$importing ? 'hidden' : ''; ?> data-wp-bind--hidden="state.isNotImporting">
            <h3><?php echo esc_html__('Import Progress', 'custom-importer'); ?></h3>
            
            <!-- Progress bar element -->
            <progress id="import-progress-bar" 
                      max="<?php echo esc_attr($progressTotal); ?>" 
                      value="<?php echo esc_attr($progressCount); ?>" 
                      data-wp-bind--value="state.progressCount" 
                      data-wp-bind--max="state.progressTotal"></progress>
            
            <p>
                <?php echo esc_html__('Processed', 'custom-importer'); ?> 
                <span data-wp-bind--text="state.progressCount"><?php echo esc_html($progressCount); ?></span> / 
                <span data-wp-bind--text="state.progressTotal"><?php echo esc_html($progressTotal); ?></span> 
                <?php echo esc_html__('items', 'custom-importer'); ?>.
            </p>
            
            <!-- Status and error messages -->
            <p class="notice notice-error" data-wp-bind--text="state.errorMessage" data-wp-bind--hidden="!state.errorMessage"></p>
            <p class="notice notice-info" data-wp-bind--text="state.statusMessage" data-wp-bind--hidden="!state.statusMessage"></p>
            
            <!-- Cancel button and log link -->
            <p>
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
            </p>
        </div>  <!-- end importer-progress div -->

    </div>  <!-- end .wrap -->
    <?php
}

// AJAX handlers for start, progress polling, and cancel (using admin-ajax)
add_action('wp_ajax_start_import', 'custom_importer_start_import');
function custom_importer_start_import() {
    check_admin_referer( 'import_nonce' );  // (nonce check if you add one in form)
    if ( ! current_user_can('import') ) {
        wp_send_json_error(array('error' => 'Unauthorized'), 403);
    }
    if ( empty($_FILES['import_file']) ) {
        wp_send_json_error(array('error' => __('No file uploaded.', 'custom-importer')));
    }
    // Collect form inputs
    $file              = $_FILES['import_file'];
    $assign_authors    = isset($_POST['assign_authors']);
    $download_attach   = isset($_POST['download_attachments']);
    $allowed_domains   = isset($_POST['allowed_domains']) ? wp_strip_all_tags($_POST['allowed_domains']) : '';
    $url_from          = isset($_POST['url_from']) ? esc_url_raw($_POST['url_from']) : '';
    $url_to            = isset($_POST['url_to']) ? esc_url_raw($_POST['url_to']) : '';

    // Start the import process (placeholder function call)
    start_import_process($file['tmp_name'], array(
        'assign_authors'      => $assign_authors,
        'download_attachments'=> $download_attach,
        'allowed_domains'     => $allowed_domains,
        'url_from'            => $url_from,
        'url_to'              => $url_to
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
    if ( ! current_user_can('import') ) {
        wp_send_json_error(array('error' => 'Unauthorized'), 403);
    }
    // Stop/cancel the import (placeholder function)
    cancel_import_process();
    wp_send_json_success();
}


///


function get_current_import_status() {
    return array(
        'running' => true,
        'progress' => 10,
        'total' => 100,
        'error' => null,
        'message' => 'Importing...',
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

