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

if ($action === 'run_import') {
    // Trigger the import cron job manually
    do_action('ng_importer__run_import');
    echo json_encode(array('success' => true, 'message' => 'Import step triggered'));
} else {
    // Trigger the indexing cron job
    do_action('ng_importer__import');
    echo json_encode(array('success' => true, 'message' => 'Indexing step triggered'));
}
