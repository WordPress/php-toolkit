<?php
// phpcs:disable Generic.Classes.DuplicateClassName.Found,Generic.Files.OneObjectStructurePerFile.MultipleFound

namespace WordPress\XML;

if ( class_exists( 'WordPress\\XML\\NativeXMLProcessor', false ) ) {
	class XMLNativeCursorProcessor extends NativeXMLProcessor {}
} else {
	require_once __DIR__ . '/PHP/class-phpxmlprocessor.php';

	class XMLNativeCursorProcessor extends PHPXMLProcessor {}
}
