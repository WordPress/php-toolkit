<?php
// phpcs:disable Generic.Classes.DuplicateClassName.Found,Generic.Files.OneObjectStructurePerFile.MultipleFound

namespace WordPress\DataLiberation\URL;

if ( class_exists( 'WordPress\\DataLiberation\\URL\\NativeURLInTextProcessor', false ) ) {
	class NativeURLInTextProcessorWrapper extends NativeURLInTextProcessor {}
} else {
	require_once __DIR__ . '/PHP/class-phpurlintextprocessor.php';

	class NativeURLInTextProcessorWrapper extends PHPURLInTextProcessor {}
}
