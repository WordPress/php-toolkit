<?php
// phpcs:disable Generic.Classes.DuplicateClassName.Found,Generic.Files.OneObjectStructurePerFile.MultipleFound
/**
 * Public HTML Processor native adapter.
 *
 * @package WordPress
 * @subpackage HTML-API
 */

if ( class_exists( 'WP_HTML_Native_Processor', false ) ) {
	class WP_HTML_Native_Processor_Wrapper extends WP_HTML_Native_Processor {}
} else {
	class WP_HTML_Native_Processor_Wrapper {}
}
