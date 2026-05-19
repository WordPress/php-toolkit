<?php
// phpcs:disable Generic.Classes.DuplicateClassName.Found,Generic.Files.OneObjectStructurePerFile.MultipleFound

namespace WordPress\DataLiberation\URL;

$wp_url_in_text_use_native =
	class_exists( 'WordPress\\DataLiberation\\URL\\NativeURLInTextProcessor', false ) &&
	method_exists( 'WordPress\\DataLiberation\\URL\\NativeURLInTextProcessor', 'supports_public_api' ) &&
	( ! defined( 'WP_NATIVE_APIS_DISABLE_DEFAULTS' ) || ! WP_NATIVE_APIS_DISABLE_DEFAULTS );

$wp_url_in_text_use_native_scanner =
	class_exists( 'WordPress\\DataLiberation\\URL\\NativeURLInTextProcessor', false ) &&
	method_exists( 'WordPress\\DataLiberation\\URL\\NativeURLInTextProcessor', 'next_url' ) &&
	method_exists( 'WordPress\\DataLiberation\\URL\\NativeURLInTextProcessor', 'get_raw_url' ) &&
	( ! defined( 'WP_NATIVE_APIS_DISABLE_DEFAULTS' ) || ! WP_NATIVE_APIS_DISABLE_DEFAULTS );

if ( $wp_url_in_text_use_native ) {
	class URLInTextProcessor extends NativeURLInTextProcessor {}
} elseif ( $wp_url_in_text_use_native_scanner ) {
	require_once __DIR__ . '/PHP/class-phpurlintextprocessor.php';
	require_once __DIR__ . '/class-nativeurlintextprocessorwrapper.php';

	class URLInTextProcessor extends NativeURLInTextProcessorWrapper {}
} else {
	require_once __DIR__ . '/PHP/class-phpurlintextprocessor.php';

	class URLInTextProcessor extends PHPURLInTextProcessor {}
}

unset( $wp_url_in_text_use_native );
unset( $wp_url_in_text_use_native_scanner );
