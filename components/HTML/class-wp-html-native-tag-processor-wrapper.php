<?php
// phpcs:disable Generic.Classes.DuplicateClassName.Found,Generic.Files.OneObjectStructurePerFile.MultipleFound
/**
 * Public HTML Tag Processor native adapter.
 *
 * @package WordPress
 * @subpackage HTML-API
 */

if ( class_exists( 'WP_HTML_Native_Tag_Processor', false ) ) {
	class WP_HTML_Native_Tag_Processor_Wrapper extends WP_HTML_Native_Tag_Processor {}
} else {
	class WP_HTML_Native_Tag_Processor_Wrapper {}
}
