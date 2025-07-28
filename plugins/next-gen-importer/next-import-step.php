<?php
/**
 * Manual trigger for import steps - useful for debugging when wp-cron is not working
 */

// Load WordPress
require_once(dirname(__FILE__) . '/../../../wp-load.php');

// Check if user is logged in and has import capability
if (!is_user_logged_in() || !current_user_can('import')) {
    wp_die('Unauthorized', 'Unauthorized', 403);
}

// Check action parameter
$action = isset($_GET['action']) ? $_GET['action'] : '';

ng_importer_next_import_step();
echo json_encode(custom_importer_get_state());
