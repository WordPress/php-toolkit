<?php
/**
 * Detects whether `require vendor/autoload.php` causes any WordPress-core
 * class to be eagerly declared. If it does, the package will fatal as soon
 * as a downstream consumer (Jetpack, wpcomsh, any plugin's PHPUnit run)
 * also boots WordPress, because WP core re-declares the same class without
 * a guard.
 *
 * Reproduces the regression seen in
 * https://github.com/Automattic/jetpack/pull/48424:
 *
 *   PHP Fatal error: Cannot declare class WP_Token_Map, because the name is
 *   already in use in /tmp/wordpress-latest/src/wp-includes/class-wp-token-map.php
 *
 * The toolkit ships pure-PHP polyfills of several WP-core classes
 * (WP_Token_Map, WP_HTML_Processor, WP_HTML_Decoder, etc.) for non-WordPress
 * environments. Those classes must NEVER be declared by Composer's bootstrap
 * (autoload.files) because consumers cannot know whether WordPress will load
 * before or after their `vendor/autoload.php`.
 *
 * Usage: php bin/check-wp-coexistence.php
 *
 * Run from a scratch directory that already has the package installed.
 */

$autoload = getcwd() . '/vendor/autoload.php';
if ( ! is_file( $autoload ) ) {
	fwrite( STDERR, "vendor/autoload.php not found in {$autoload}\n" );
	exit( 2 );
}
require $autoload;

// Class names shipped by WordPress core that the toolkit also defines.
// Keep alphabetical and add new entries as the toolkit grows.
$wp_core_classes = array(
	'WP_Block_Parser',
	'WP_Block_Parser_Block',
	'WP_Block_Parser_Frame',
	'WP_Error',
	'WP_HTML_Active_Formatting_Elements',
	'WP_HTML_Attribute_Token',
	'WP_HTML_Decoder',
	'WP_HTML_Open_Elements',
	'WP_HTML_Processor',
	'WP_HTML_Processor_State',
	'WP_HTML_Span',
	'WP_HTML_Stack_Event',
	'WP_HTML_Tag_Processor',
	'WP_HTML_Text_Replacement',
	'WP_HTML_Token',
	'WP_HTML_Unsupported_Exception',
	'WP_Token_Map',
);

$leaked = array();
foreach ( $wp_core_classes as $cls ) {
	if ( class_exists( $cls, false ) || interface_exists( $cls, false ) || trait_exists( $cls, false ) ) {
		$leaked[] = $cls;
	}
}

if ( empty( $leaked ) ) {
	echo "OK no WP-core classes leaked through autoload.php\n";
	exit( 0 );
}

fwrite( STDERR, "FAIL WordPress-coexistence: autoload.php eagerly declared WP-core classes:\n" );
foreach ( $leaked as $cls ) {
	fwrite( STDERR, "  - {$cls}\n" );
}
fwrite( STDERR, "These will fatal when WordPress core later loads its own copy.\n" );
exit( 1 );
