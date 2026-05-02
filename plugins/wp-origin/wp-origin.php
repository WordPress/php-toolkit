<?php
/**
 * Plugin Name: WP Origin
 * Description: Expose WordPress posts and pages as a Git remote backed by Markdown files.
 */

if ( file_exists( __DIR__ . '/php-toolkit.phar' ) ) {
	require_once __DIR__ . '/php-toolkit.phar';
} else {
	require_once __DIR__ . '/wp-origin-dev-bootstrap.php';
}

require_once __DIR__ . '/class-wp-origin-plugin.php';
require_once __DIR__ . '/class-wp-origin-buffering-response.php';

add_filter( 'wp_is_application_passwords_available', '__return_true' );
add_filter( 'wp_is_application_passwords_available_for_user', '__return_true', 10, 2 );

WP_Origin_Plugin::bootstrap();
