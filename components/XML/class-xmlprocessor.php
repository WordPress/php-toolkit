<?php
// phpcs:disable Generic.Classes.DuplicateClassName.Found,Generic.Files.OneObjectStructurePerFile.MultipleFound

namespace WordPress\XML;

$wp_xml_use_native_processor =
	class_exists( 'WordPress\\XML\\NativeXMLProcessor', false ) &&
	method_exists( 'WordPress\\XML\\NativeXMLProcessor', 'supports_public_api' ) &&
	( ! defined( 'WP_NATIVE_APIS_DISABLE_DEFAULTS' ) || ! WP_NATIVE_APIS_DISABLE_DEFAULTS );

if ( $wp_xml_use_native_processor ) {
	class XMLProcessor extends NativeXMLProcessor {}
} else {
	require_once __DIR__ . '/PHP/class-phpxmlprocessor.php';

	class XMLProcessor extends PHPXMLProcessor {}
}

unset( $wp_xml_use_native_processor );
