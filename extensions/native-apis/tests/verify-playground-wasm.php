<?php
/**
 * Verify the PHP.wasm native API extension loads in Playground CLI.
 *
 * @package WordPress
 */

$required = array(
	'WP_HTML_Native_Tag_Processor',
	'WP_HTML_Native_Processor',
	'WordPress\\DataLiberation\\URL\\NativeURLInTextProcessor',
	'WordPress\\XML\\NativeXMLProcessor',
);

foreach ( $required as $class_name ) {
	if ( ! class_exists( $class_name, false ) ) {
		fwrite( STDERR, "Missing native class in PHP.wasm runtime: {$class_name}\n" );
		exit( 1 );
	}
}

$html = new WP_HTML_Native_Tag_Processor( '<main data-id="7"><a href="/x">Link</a></main>' );
if ( ! $html->next_tag() || 'MAIN' !== $html->get_tag() || '7' !== $html->get_attribute( 'data-id' ) ) {
	fwrite( STDERR, "Native HTML tag processor did not work in PHP.wasm.\n" );
	exit( 1 );
}

$xml_class = 'WordPress\\XML\\NativeXMLProcessor';
$xml       = $xml_class::create_from_string( '<root id="7"><child>Text</child></root>' );
if ( ! $xml->next_tag() || 'root' !== $xml->get_tag_local_name() || '7' !== $xml->get_attribute( 'id' ) ) {
	fwrite( STDERR, "Native XML processor did not work in PHP.wasm.\n" );
	exit( 1 );
}

$url_class = 'WordPress\\DataLiberation\\URL\\NativeURLInTextProcessor';
$url_text  = new $url_class( 'Visit https://wordpress.org/plugins, then example.com/docs.' );
if (
	! $url_text->next_url() ||
	'https://wordpress.org/plugins' !== $url_text->get_raw_url() ||
	! $url_text->had_protocol()
) {
	fwrite( STDERR, "Native URL-in-text processor did not find the expected URL in PHP.wasm.\n" );
	exit( 1 );
}

echo "Native API PHP.wasm extension verification passed.\n";
