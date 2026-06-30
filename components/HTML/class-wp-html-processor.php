<?php
// phpcs:disable Generic.Classes.DuplicateClassName.Found,Generic.Files.OneObjectStructurePerFile.MultipleFound
/**
 * Public HTML processor loader.
 *
 * @package WordPress
 * @subpackage HTML-API
 */

if ( ! class_exists( 'WP_HTML_Tag_Processor', false ) ) {
	require_once __DIR__ . '/class-wp-html-tag-processor.php';
}

$wp_html_use_native_processor =
	class_exists( 'WP_HTML_Native_Processor', false ) &&
	class_exists( 'WP_HTML_Native_Tag_Processor', false ) &&
	method_exists( 'WP_HTML_Native_Processor', 'supports_public_api' ) &&
	method_exists( 'WP_HTML_Native_Tag_Processor', 'supports_public_api' ) &&
	( ! defined( 'WP_NATIVE_APIS_DISABLE_DEFAULTS' ) || ! WP_NATIVE_APIS_DISABLE_DEFAULTS );

if ( $wp_html_use_native_processor ) {
	require_once __DIR__ . '/class-wp-html-native-processor-wrapper.php';

	class WP_HTML_Processor extends WP_HTML_Native_Processor_Wrapper {}
} else {
	require_once __DIR__ . '/PHP/class-wp-html-php-processor.php';

	class WP_HTML_Processor extends WP_HTML_PHP_Processor {}
}

unset( $wp_html_use_native_processor );
