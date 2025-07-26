<?php
/**
 * Plugin Name: Next gen importer
 * Description: A next generation importer for WordPress.
 *
 * @TODO
 * - Get nonces to work
 * - Visually appealing UI with smooth transitions between state updates. Right
 *   now the UI is jerky. We could have beautiful CSS transitions that would
 *   make the import process feel much smoother.
 * - Delete frontloading placeholders that have been successfully downloaded.
 *   Still keep track of the number of total and successful downloads.
 */

if(file_exists(__DIR__ . '/php-toolkit.phar')) {
    // Production – built and installed plugin
	require_once __DIR__ . '/php-toolkit.phar';
} else {
	// Development – plugin mounted in WordPress via Playground CLI mounts
	require_once __DIR__ . '/../../vendor/autoload.php';
}

include __DIR__ . '/admin-page.php';
