<?php
/**
 * Verify that the native API extension is loaded and exposes the first classes.
 *
 * @package WordPress
 */

$allow_missing = in_array( '--allow-missing', $argv, true );
$required      = array(
	'WP_HTML_Native_Tag_Processor',
	'WP_HTML_Native_Processor',
	'WordPress\\DataLiberation\\URL\\NativeURLInTextProcessor',
	'WordPress\\XML\\NativeXMLProcessor',
);
$missing       = array();

foreach ( $required as $class_name ) {
	if ( ! class_exists( $class_name, false ) ) {
		$missing[] = $class_name;
	}
}

if ( $missing ) {
	$message = "Native API extension is not loaded or did not register expected classes:\n"
		. ' - ' . implode( "\n - ", $missing ) . "\n"
		. "Build with `cd extensions/native-apis && ./build-extension.sh`, then run:\n"
		. "php -d extension=extensions/native-apis/target/release/libwp_native_apis.so extensions/native-apis/tests/verify-native-apis.php\n";

	if ( $allow_missing ) {
		fwrite( STDERR, "Skipping native verification: {$message}" );
		exit( 0 );
	}

	fwrite( STDERR, $message );
	exit( 1 );
}

require_once dirname( __DIR__, 3 ) . '/vendor/autoload.php';
class_exists( 'WP_HTML_Doctype_Info' );

$tag_processor = new WP_HTML_Native_Tag_Processor( '<main data-id="7"><a href="/x?one=1&amp;two=2" title="A&#x2F;B&#47;C" data-label="A&nbsp;B&copy;&reg;&hellip;&mdash;&notin;">Link</a></main>' );
assert_false( $tag_processor->paused_at_incomplete_token(), 'Expected native HTML tag processor not to start paused at an incomplete token.' );
assert_true( $tag_processor->next_tag(), 'Expected first HTML tag.' );
assert_same( 'MAIN', $tag_processor->get_tag(), 'Expected first HTML tag name.' );
assert_same( 'html', $tag_processor->get_namespace(), 'Expected first native HTML tag namespace.' );
assert_true( $tag_processor->change_parsing_namespace( 'svg' ), 'Expected native HTML tag namespace change to SVG to succeed.' );
assert_same( 'svg', $tag_processor->get_namespace(), 'Expected native HTML tag namespace change to be reflected.' );
assert_false( $tag_processor->change_parsing_namespace( 'invalid' ), 'Expected invalid native HTML tag namespace change to fail.' );
assert_same( 'svg', $tag_processor->get_namespace(), 'Expected failed native HTML tag namespace change to preserve the previous namespace.' );
assert_true( $tag_processor->change_parsing_namespace( 'html' ), 'Expected native HTML tag namespace reset to HTML to succeed.' );
assert_same( 'MAIN', $tag_processor->get_qualified_tag_name(), 'Expected first native HTML qualified tag name.' );
assert_same( '7', $tag_processor->get_attribute( 'data-id' ), 'Expected HTML attribute value.' );
assert_same( 'data-id', $tag_processor->get_qualified_attribute_name( 'data-id' ), 'Expected native HTML qualified attribute name to preserve HTML attribute spelling.' );
assert_false( $tag_processor->has_self_closing_flag(), 'Expected regular HTML tag not to report a self-closing flag.' );
assert_true( $tag_processor->next_tag(), 'Expected second HTML tag.' );
assert_same( 'A', $tag_processor->get_tag(), 'Expected second HTML tag name.' );
assert_same( '/x?one=1&two=2', $tag_processor->get_attribute( 'href' ), 'Expected native HTML attribute entity decoding.' );
assert_same( 'A/B/C', $tag_processor->get_attribute( 'title' ), 'Expected native HTML numeric attribute entity decoding.' );
assert_same( "A\u{00a0}B\u{00a9}\u{00ae}\u{2026}\u{2014}\u{2209}", $tag_processor->get_attribute( 'data-label' ), 'Expected native HTML named attribute entity decoding.' );
assert_same( false, $tag_processor->next_tag(), 'Expected HTML next_tag() to skip closing tags.' );
assert_false( $tag_processor->paused_at_incomplete_token(), 'Expected native HTML tag processor not to pause at an incomplete token after complete input.' );

$comment_qualified_name_processor = new WP_HTML_Native_Tag_Processor( '<!--note-->' );
assert_true( $comment_qualified_name_processor->next_token(), 'Expected comment token before qualified name checks.' );
assert_same( null, $comment_qualified_name_processor->get_qualified_tag_name(), 'Expected native HTML qualified tag name to return null on comments.' );
assert_same( null, $comment_qualified_name_processor->get_qualified_attribute_name( 'class' ), 'Expected native HTML qualified attribute name to return null on comments.' );
assert_same( null, $comment_qualified_name_processor->class_list(), 'Expected native HTML class list to return null on comments.' );
assert_same( null, $comment_qualified_name_processor->has_class( 'note' ), 'Expected native HTML class lookup to return null on comments.' );

$class_tag_processor = new WP_HTML_Native_Tag_Processor( '<div class="free &lt;egg&lt; free lang-en"></div>' );
assert_true( $class_tag_processor->next_tag(), 'Expected class-list fixture tag.' );
assert_same( array( 'free', '<egg<', 'lang-en' ), $class_tag_processor->class_list(), 'Expected native HTML class list to decode and deduplicate classes.' );
assert_true( $class_tag_processor->has_class( '<egg<' ), 'Expected native HTML class lookup to find decoded classes.' );
assert_false( $class_tag_processor->has_class( 'missing' ), 'Expected native HTML class lookup to reject missing classes.' );

$self_closing_tag_processor = new WP_HTML_Native_Tag_Processor( '<main /><img data-src="/x"><br/>' );
assert_true( $self_closing_tag_processor->next_tag(), 'Expected self-closing flag fixture main tag.' );
assert_true( $self_closing_tag_processor->has_self_closing_flag(), 'Expected native HTML tag processor to detect the self-closing flag.' );
assert_true( $self_closing_tag_processor->next_tag(), 'Expected non-self-closing fixture img tag.' );
assert_false( $self_closing_tag_processor->has_self_closing_flag(), 'Expected native HTML tag processor to reject missing self-closing flag.' );
assert_true( $self_closing_tag_processor->next_tag(), 'Expected compact self-closing fixture br tag.' );
assert_true( $self_closing_tag_processor->has_self_closing_flag(), 'Expected native HTML tag processor to detect compact self-closing flag.' );

$bookmarked_tag_processor = new WP_HTML_Native_Tag_Processor( '<main><section><p>Text</p></section><footer></footer></main>' );
assert_false( $bookmarked_tag_processor->set_bookmark( 'before-token' ), 'Expected native HTML tag bookmarks to require a current token.' );
assert_true( $bookmarked_tag_processor->next_tag(), 'Expected bookmark fixture main tag.' );
assert_true( $bookmarked_tag_processor->next_tag(), 'Expected bookmark fixture section tag.' );
assert_true( $bookmarked_tag_processor->set_bookmark( 'section' ), 'Expected native HTML tag bookmark to be set.' );
assert_true( $bookmarked_tag_processor->has_bookmark( 'section' ), 'Expected native HTML tag bookmark to exist.' );
assert_true( $bookmarked_tag_processor->next_tag(), 'Expected bookmark fixture paragraph tag.' );
assert_same( 'P', $bookmarked_tag_processor->get_tag(), 'Expected native HTML tag cursor before seeking.' );
assert_true( $bookmarked_tag_processor->seek( 'section' ), 'Expected native HTML tag bookmark seek to succeed.' );
assert_same( 'SECTION', $bookmarked_tag_processor->get_tag(), 'Expected native HTML tag cursor after seeking.' );
assert_true( $bookmarked_tag_processor->release_bookmark( 'section' ), 'Expected native HTML tag bookmark release to succeed.' );
assert_false( $bookmarked_tag_processor->has_bookmark( 'section' ), 'Expected native HTML tag bookmark to be released.' );
assert_false( $bookmarked_tag_processor->seek( 'section' ), 'Expected native HTML tag released bookmark seek to fail.' );

$closing_tag_processor = new WP_HTML_Native_Tag_Processor( '<div data-id="1"></div>' );
assert_true( $closing_tag_processor->next_tag_any( true, 1 ), 'Expected native HTML opening tag in closer visit mode.' );
assert_same( array( 'data-id' ), $closing_tag_processor->get_attribute_names_with_prefix( 'data-' ), 'Expected native HTML prefix names on opening tag.' );
assert_same( 'data-id', $closing_tag_processor->get_attribute_names_with_prefix_string( 'data-' ), 'Expected native HTML compact prefix names on opening tag.' );
assert_same( 1, $closing_tag_processor->count_attribute_names_with_prefix( 'data-' ), 'Expected native HTML prefix-name count on opening tag.' );
assert_same( '', $closing_tag_processor->get_attribute_names_with_prefix_string( 'aria-' ), 'Expected native HTML compact prefix names to distinguish empty matches.' );
assert_same( 0, $closing_tag_processor->count_attribute_names_with_prefix( 'aria-' ), 'Expected native HTML prefix-name count to distinguish empty matches.' );
assert_true( $closing_tag_processor->next_tag_any( true, 1 ), 'Expected native HTML closing tag in closer visit mode.' );
assert_same( true, $closing_tag_processor->is_tag_closer(), 'Expected native HTML closing tag marker.' );
assert_same( null, $closing_tag_processor->get_attribute_names_with_prefix( 'data-' ), 'Expected native HTML prefix names to return null on closing tag.' );
assert_same( null, $closing_tag_processor->get_attribute_names_with_prefix_string( 'data-' ), 'Expected native HTML compact prefix names to return null on closing tag.' );
assert_same( null, $closing_tag_processor->count_attribute_names_with_prefix( 'data-' ), 'Expected native HTML prefix-name count to return null on closing tag.' );

$prefix_summary_processor = new WP_HTML_Native_Tag_Processor( '<main data-id="7"><a data-kind="nav" href="/x">Link</a><img data-src="/x"></main>' );
assert_same( "5\x1f3", $prefix_summary_processor->summarize_attribute_names_with_prefix( 'data-', true ), 'Expected native HTML document-level prefix summary to include closers.' );

$tag_inventory_processor = new WP_HTML_Native_Tag_Processor( '<main data-id="7"><a data-kind="nav" href="/x">Link</a><img data-src="/x"></main>' );
assert_same( "5\x1f3\x1f2\x1f4\x1f3", $tag_inventory_processor->summarize_tag_inventory( true ), 'Expected native HTML tag inventory summary to include closers and attributes.' );

$heading_inventory_processor = new WP_HTML_Native_Tag_Processor( '<main><h1>A</h1><section><h2>B</h2><h3>C</h3></section></main>' );
assert_same( "10\x1f3\x1f1\x1f1\x1f1\x1f0\x1f0\x1f0", $heading_inventory_processor->summarize_heading_inventory( true ), 'Expected native HTML heading inventory summary to include closers and heading levels.' );

$id_inventory_processor = new WP_HTML_Native_Tag_Processor( '<main id="root"><p id="intro">One</p><section id="intro"><span id></span></section></main>' );
assert_same( "8\x1f4\x1f2\x1f1\x1f14", $id_inventory_processor->summarize_id_inventory( true ), 'Expected native HTML ID inventory summary to include closers, duplicates, and decoded values.' );

$attribute_inventory_processor = new WP_HTML_Native_Tag_Processor( '<main data-id="7" hidden><p class="x" title="A &amp; B">Text</p></main>' );
assert_same( "4\x1f4\x1f4\x1f7", $attribute_inventory_processor->summarize_attribute_inventory( true ), 'Expected native HTML attribute inventory summary to include closers and decoded values.' );

$data_attribute_inventory_processor = new WP_HTML_Native_Tag_Processor( '<div data-id="1" data-kind="hero" data-empty data-value="A &amp; B"></div><p data-kind="copy"></p>' );
assert_same( "4\x1f2\x1f5\x1f4\x1f14", $data_attribute_inventory_processor->summarize_data_attribute_inventory( true ), 'Expected native HTML data-attribute inventory summary to include closers and decoded values.' );

$aria_attribute_inventory_processor = new WP_HTML_Native_Tag_Processor( '<button aria-label="Close" aria-expanded="false"></button><div aria-hidden aria-label="Panel" data-id="1"></div>' );
assert_same( "4\x1f2\x1f4\x1f3\x1f15", $aria_attribute_inventory_processor->summarize_aria_attribute_inventory( true ), 'Expected native HTML ARIA attribute inventory summary to include closers and decoded values.' );

$class_inventory_processor = new WP_HTML_Native_Tag_Processor( '<main class="wrap"><p class="lede entry">Text</p></main>' );
assert_same( "4\x1f2\x1f3\x1f3\x1f14", $class_inventory_processor->summarize_class_inventory( true ), 'Expected native HTML class inventory summary to include closers and decoded class names.' );

$resource_inventory_processor = new WP_HTML_Native_Tag_Processor( '<main><a href="/one">One</a><img src="/one.png"><script src="/app.js"></script></main>' );
assert_same( "6\x1f3\x1f3\x1f3\x1f19", $resource_inventory_processor->summarize_resource_inventory( true ), 'Expected native HTML resource inventory summary to include closers and resource values.' );

$image_inventory_processor = new WP_HTML_Native_Tag_Processor( '<img src="/a.png" alt=""><img src="/b.png" alt="Bee" width="10" height="20"><img alt><p></p>' );
assert_same( "5\x1f3\x1f2\x1f3\x1f2\x1f1\x1f12\x1f3", $image_inventory_processor->summarize_image_inventory( true ), 'Expected native HTML image inventory summary to include closers and image attributes.' );

$script_inventory_processor = new WP_HTML_Native_Tag_Processor( '<main><script src="/app.js" type="module" async></script><script>let a = 1;</script><script defer src="/legacy.js"></script></main>' );
assert_same( "5\x1f3\x1f2\x1f1\x1f1\x1f1\x1f10\x1f17", $script_inventory_processor->summarize_script_inventory( true ), 'Expected native HTML script inventory summary to include closers and script attributes.' );

$form_inventory_processor = new WP_HTML_Native_Tag_Processor( '<form><input name="q"><input name="page"><input><button name="go"></button></form>' );
assert_same( "7\x1f1\x1f4\x1f3\x1f3\x1f7", $form_inventory_processor->summarize_form_inventory( true ), 'Expected native HTML form inventory summary to include closers and control names.' );

$prefix_summary_batch_processor = new WP_HTML_Native_Tag_Processor( '<main data-id="7"><a data-kind="nav" href="/x">Link</a><img data-src="/x"></main>' );
assert_same( "MAIN\x1f0\x1f1\x1eA\x1f0\x1f1", $prefix_summary_batch_processor->next_tag_prefix_summary_batch( 'data-', 2, true ), 'Expected native HTML prefix summary batch to return the first chunk.' );
assert_same( "A\x1f1\x1f0\x1eIMG\x1f0\x1f1", $prefix_summary_batch_processor->next_tag_prefix_summary_batch( 'data-', 2, true ), 'Expected native HTML prefix summary batch to return the second chunk.' );
assert_same( "MAIN\x1f1\x1f0", $prefix_summary_batch_processor->next_tag_prefix_summary_batch( 'data-', 2, true ), 'Expected native HTML prefix summary batch to return the final chunk.' );
assert_same( null, $prefix_summary_batch_processor->next_tag_prefix_summary_batch( 'data-', 2, true ), 'Expected native HTML prefix summary batch to return null when exhausted.' );

$prefix_compact_summary_batch_processor = new WP_HTML_Native_Tag_Processor( '<main data-id="7"><a data-kind="nav" href="/x">Link</a><img data-src="/x"></main>' );
assert_same( "MAIN\x1f0\x1f1\x1eA\x1f0\x1f1", $prefix_compact_summary_batch_processor->next_tag_prefix_compact_summary_batch( 'data-', 2, true ), 'Expected native HTML compact prefix summary batch to return the first chunk.' );
assert_same( "A\x1f1\x1f0\x1eIMG\x1f0\x1f1", $prefix_compact_summary_batch_processor->next_tag_prefix_compact_summary_batch( 'data-', 2, true ), 'Expected native HTML compact prefix summary batch to return the second chunk.' );
assert_same( "MAIN\x1f1\x1f0", $prefix_compact_summary_batch_processor->next_tag_prefix_compact_summary_batch( 'data-', 2, true ), 'Expected native HTML compact prefix summary batch to return the final chunk.' );
assert_same( null, $prefix_compact_summary_batch_processor->next_tag_prefix_compact_summary_batch( 'data-', 2, true ), 'Expected native HTML compact prefix summary batch to return null when exhausted.' );

$prefix_count_batch_processor = new WP_HTML_Native_Tag_Processor( '<main data-id="7"><a data-kind="nav" href="/x">Link</a><img data-src="/x"></main>' );
assert_same( "2\x1f2", $prefix_count_batch_processor->next_tag_prefix_count_compact_batch( 'data-', 2, true ), 'Expected native HTML prefix count batch to return the first chunk.' );
assert_same( "2\x1f1", $prefix_count_batch_processor->next_tag_prefix_count_compact_batch( 'data-', 2, true ), 'Expected native HTML prefix count batch to return the second chunk.' );
assert_same( "1\x1f0", $prefix_count_batch_processor->next_tag_prefix_count_compact_batch( 'data-', 2, true ), 'Expected native HTML prefix count batch to return the final chunk.' );
assert_same( null, $prefix_count_batch_processor->next_tag_prefix_count_compact_batch( 'data-', 2, true ), 'Expected native HTML prefix count batch to return null when exhausted.' );

$tag_summary_batch_processor = new WP_HTML_Native_Tag_Processor( '<main><a>Link</a><img></main>' );
assert_same( "MAIN\x1f0\x1eA\x1f0", $tag_summary_batch_processor->next_tag_compact_summary_batch( 2, true ), 'Expected native HTML tag summary batch to return the first chunk.' );
assert_same( "A\x1f1\x1eIMG\x1f0", $tag_summary_batch_processor->next_tag_compact_summary_batch( 2, true ), 'Expected native HTML tag summary batch to return the second chunk.' );
assert_same( "MAIN\x1f1", $tag_summary_batch_processor->next_tag_compact_summary_batch( 2, true ), 'Expected native HTML tag summary batch to return the final chunk.' );
assert_same( null, $tag_summary_batch_processor->next_tag_compact_summary_batch( 2, true ), 'Expected native HTML tag summary batch to return null when exhausted.' );

$public_tag_summary_batch_processor = new WP_HTML_Native_Tag_Processor( '<main><a>Link</a><img></main>' );
assert_same(
	array(
		array(
			'tag_name'      => 'MAIN',
			'is_tag_closer' => false,
		),
		array(
			'tag_name'      => 'A',
			'is_tag_closer' => false,
		),
	),
	$public_tag_summary_batch_processor->next_tag_summary_batch( 2, true ),
	'Expected native HTML public tag summary batch to return the first chunk.'
);
assert_same(
	array(
		array(
			'tag_name'      => 'A',
			'is_tag_closer' => true,
		),
		array(
			'tag_name'      => 'IMG',
			'is_tag_closer' => false,
		),
	),
	$public_tag_summary_batch_processor->next_tag_summary_batch( 2, true ),
	'Expected native HTML public tag summary batch to return the second chunk.'
);
assert_same(
	array(
		array(
			'tag_name'      => 'MAIN',
			'is_tag_closer' => true,
		),
	),
	$public_tag_summary_batch_processor->next_tag_summary_batch( 2, true ),
	'Expected native HTML public tag summary batch to return the final chunk.'
);
assert_same( array(), $public_tag_summary_batch_processor->next_tag_summary_batch( 2, true ), 'Expected native HTML public tag summary batch to return an empty array when exhausted.' );

$matching_tag_summary_batch_processor = new WP_HTML_Native_Tag_Processor( '<main><a>One</a><span><A>Two</A></span><img></main>' );
assert_same( "A\x1f0\x1eA\x1f1", $matching_tag_summary_batch_processor->next_matching_tag_compact_summary_batch( 'a', 2, true ), 'Expected native HTML matching tag summary batch to return the first chunk.' );
assert_same( "A\x1f0\x1eA\x1f1", $matching_tag_summary_batch_processor->next_matching_tag_compact_summary_batch( 'A', 2, true ), 'Expected native HTML matching tag summary batch to match case-insensitively.' );
assert_same( null, $matching_tag_summary_batch_processor->next_matching_tag_compact_summary_batch( 'a', 2, true ), 'Expected native HTML matching tag summary batch to return null when exhausted.' );

$public_matching_tag_summary_batch_processor = new WP_HTML_Native_Tag_Processor( '<main><a>One</a><span><A>Two</A></span><img></main>' );
assert_same(
	array(
		array(
			'tag_name'      => 'A',
			'is_tag_closer' => false,
		),
		array(
			'tag_name'      => 'A',
			'is_tag_closer' => true,
		),
	),
	$public_matching_tag_summary_batch_processor->next_matching_tag_summary_batch( 'a', 2, true ),
	'Expected native HTML public matching-tag summary batch to return the first chunk.'
);
assert_same(
	array(
		array(
			'tag_name'      => 'A',
			'is_tag_closer' => false,
		),
		array(
			'tag_name'      => 'A',
			'is_tag_closer' => true,
		),
	),
	$public_matching_tag_summary_batch_processor->next_matching_tag_summary_batch( 'A', 2, true ),
	'Expected native HTML public matching-tag summary batch to match case-insensitively.'
);
assert_same( array(), $public_matching_tag_summary_batch_processor->next_matching_tag_summary_batch( 'a', 2, true ), 'Expected native HTML public matching-tag summary batch to return an empty array when exhausted.' );

$matching_tag_attribute_summary_batch_processor = new WP_HTML_Native_Tag_Processor( '<main><a href="/one">One</a><span><A href="/two?x=1&amp;y=2">Two</A></span><a>No href</a></main>' );
assert_same( "A\x1f0\x1f1/one\x1eA\x1f1\x1f0", $matching_tag_attribute_summary_batch_processor->next_matching_tag_attribute_compact_summary_batch( 'a', 'href', 2, true ), 'Expected native HTML matching tag attribute summary batch to return the first chunk.' );
assert_same( "A\x1f0\x1f1/two?x=1&y=2\x1eA\x1f1\x1f0", $matching_tag_attribute_summary_batch_processor->next_matching_tag_attribute_compact_summary_batch( 'A', 'href', 2, true ), 'Expected native HTML matching tag attribute summary batch to decode attributes and match case-insensitively.' );
assert_same( "A\x1f0\x1f0\x1eA\x1f1\x1f0", $matching_tag_attribute_summary_batch_processor->next_matching_tag_attribute_compact_summary_batch( 'a', 'href', 2, true ), 'Expected native HTML matching tag attribute summary batch to mark missing attributes.' );
assert_same( null, $matching_tag_attribute_summary_batch_processor->next_matching_tag_attribute_compact_summary_batch( 'a', 'href', 2, true ), 'Expected native HTML matching tag attribute summary batch to return null when exhausted.' );

$public_matching_tag_attribute_summary_batch_processor = new WP_HTML_Native_Tag_Processor( '<main><a href="/one">One</a><span><A href="/two?x=1&amp;y=2">Two</A></span><a>No href</a></main>' );
assert_same(
	array(
		array(
			'tag_name'        => 'A',
			'is_tag_closer'   => false,
			'attribute_value' => '/one',
		),
		array(
			'tag_name'        => 'A',
			'is_tag_closer'   => true,
			'attribute_value' => null,
		),
	),
	$public_matching_tag_attribute_summary_batch_processor->next_matching_tag_attribute_summary_batch( 'a', 'href', 2, true ),
	'Expected native HTML public matching-tag attribute summary batch to return the first chunk.'
);
assert_same(
	array(
		array(
			'tag_name'        => 'A',
			'is_tag_closer'   => false,
			'attribute_value' => '/two?x=1&y=2',
		),
		array(
			'tag_name'        => 'A',
			'is_tag_closer'   => true,
			'attribute_value' => null,
		),
	),
	$public_matching_tag_attribute_summary_batch_processor->next_matching_tag_attribute_summary_batch( 'A', 'href', 2, true ),
	'Expected native HTML public matching-tag attribute summary batch to decode attributes and match case-insensitively.'
);
assert_same(
	array(
		array(
			'tag_name'        => 'A',
			'is_tag_closer'   => false,
			'attribute_value' => null,
		),
		array(
			'tag_name'        => 'A',
			'is_tag_closer'   => true,
			'attribute_value' => null,
		),
	),
	$public_matching_tag_attribute_summary_batch_processor->next_matching_tag_attribute_summary_batch( 'a', 'href', 2, true ),
	'Expected native HTML public matching-tag attribute summary batch to mark missing attributes.'
);
assert_same( array(), $public_matching_tag_attribute_summary_batch_processor->next_matching_tag_attribute_summary_batch( 'a', 'href', 2, true ), 'Expected native HTML public matching-tag attribute summary batch to return an empty array when exhausted.' );

$matching_tag_attributes_summary_batch_processor = new WP_HTML_Native_Tag_Processor( '<main><a href="/one" title="One &amp; two">One</a><span><A href="/two" rel>Two</A></span><a title="Three">Three</a></main>' );
assert_same( "A\x1f0\x1f1/one\x1f1One & two\x1f0\x1eA\x1f1\x1f0\x1f0\x1f0", $matching_tag_attributes_summary_batch_processor->next_matching_tag_attributes_compact_summary_batch( 'a', "href\x1ftitle\x1frel", 2, true ), 'Expected native HTML matching tag attributes summary batch to return the first chunk.' );
assert_same( "A\x1f0\x1f1/two\x1f0\x1f1\x1eA\x1f1\x1f0\x1f0\x1f0", $matching_tag_attributes_summary_batch_processor->next_matching_tag_attributes_compact_summary_batch( 'A', "href\x1ftitle\x1frel", 2, true ), 'Expected native HTML matching tag attributes summary batch to decode multiple attributes and match case-insensitively.' );
assert_same( "A\x1f0\x1f0\x1f1Three\x1f0\x1eA\x1f1\x1f0\x1f0\x1f0", $matching_tag_attributes_summary_batch_processor->next_matching_tag_attributes_compact_summary_batch( 'a', "href\x1ftitle\x1frel", 2, true ), 'Expected native HTML matching tag attributes summary batch to mark missing multi-attributes.' );
assert_same( null, $matching_tag_attributes_summary_batch_processor->next_matching_tag_attributes_compact_summary_batch( 'a', "href\x1ftitle\x1frel", 2, true ), 'Expected native HTML matching tag attributes summary batch to return null when exhausted.' );

$public_matching_tag_attributes_summary_batch_processor = new WP_HTML_Native_Tag_Processor( '<main><a href="/one" title="One &amp; two">One</a><span><A href="/two" rel>Two</A></span><a title="Three">Three</a></main>' );
assert_same(
	array(
		array(
			'tag_name'         => 'A',
			'is_tag_closer'    => false,
			'attribute_values' => array(
				'href'  => '/one',
				'title' => 'One & two',
				'rel'   => null,
			),
		),
		array(
			'tag_name'         => 'A',
			'is_tag_closer'    => true,
			'attribute_values' => array(
				'href'  => null,
				'title' => null,
				'rel'   => null,
			),
		),
	),
	$public_matching_tag_attributes_summary_batch_processor->next_matching_tag_attributes_summary_batch( 'a', array( 'href', 'title', 'rel' ), 2, true ),
	'Expected native HTML public matching-tag attributes summary batch to return the first chunk.'
);
assert_same(
	array(
		array(
			'tag_name'         => 'A',
			'is_tag_closer'    => false,
			'attribute_values' => array(
				'href'  => '/two',
				'title' => null,
				'rel'   => '',
			),
		),
		array(
			'tag_name'         => 'A',
			'is_tag_closer'    => true,
			'attribute_values' => array(
				'href'  => null,
				'title' => null,
				'rel'   => null,
			),
		),
	),
	$public_matching_tag_attributes_summary_batch_processor->next_matching_tag_attributes_summary_batch( 'A', array( 'href', 'title', 'rel' ), 2, true ),
	'Expected native HTML public matching-tag attributes summary batch to decode multiple attributes and match case-insensitively.'
);
assert_same(
	array(
		array(
			'tag_name'         => 'A',
			'is_tag_closer'    => false,
			'attribute_values' => array(
				'href'  => null,
				'title' => 'Three',
				'rel'   => null,
			),
		),
		array(
			'tag_name'         => 'A',
			'is_tag_closer'    => true,
			'attribute_values' => array(
				'href'  => null,
				'title' => null,
				'rel'   => null,
			),
		),
	),
	$public_matching_tag_attributes_summary_batch_processor->next_matching_tag_attributes_summary_batch( 'a', array( 'href', 'title', 'rel' ), 2, true ),
	'Expected native HTML public matching-tag attributes summary batch to mark missing multi-attributes.'
);
assert_same( array(), $public_matching_tag_attributes_summary_batch_processor->next_matching_tag_attributes_summary_batch( 'a', array( 'href', 'title', 'rel' ), 2, true ), 'Expected native HTML public matching-tag attributes summary batch to return an empty array when exhausted.' );

$matching_tag_attributes_summary_processor = new WP_HTML_Native_Tag_Processor( '<main><a href="/one" title="One &amp; two">One</a><span><A href="/two" rel>Two</A></span><a title="Three">Three</a></main>' );
assert_same( "6\x1f5\x1f22", $matching_tag_attributes_summary_processor->summarize_matching_tag_attributes( 'a', "href\x1ftitle\x1frel", true ), 'Expected native HTML matching tag attributes summary to aggregate decoded attribute bytes.' );

$document_removal_processor = new WP_HTML_Native_Tag_Processor( '<main data-id="7" class="entry"><a DATA-kind="nav" data-track="1" href="/x">Link</a><img data-src="/x"></main>' );
assert_same(
	"5\x1f4\x1f" . '<main  class="entry"><a   href="/x">Link</a><img ></main>',
	$document_removal_processor->remove_attributes_with_prefix_from_document( 'data-', true ),
	'Expected native HTML document-level prefix removal to include updated HTML.'
);

$attribute_removal_processor = new WP_HTML_Native_Tag_Processor( '<main data-id="7" DATA-id="duplicate" class="entry"><a DATA-kind="nav" data-track="1" href="/x">Link</a></main>' );
assert_true( $attribute_removal_processor->next_tag(), 'Expected native HTML tag before removing attributes.' );
assert_true( $attribute_removal_processor->remove_attribute( 'data-id' ), 'Expected native HTML duplicate attribute removal to be queued.' );
assert_same( null, $attribute_removal_processor->get_attribute( 'data-id' ), 'Expected native HTML removed attribute reads to return null before serialization.' );
assert_same( '<main   class="entry"><a DATA-kind="nav" data-track="1" href="/x">Link</a></main>', $attribute_removal_processor->get_updated_html(), 'Expected native HTML updated output after removing duplicate attributes.' );
assert_true( $attribute_removal_processor->next_tag(), 'Expected native HTML nested tag after removing attributes.' );
assert_same( 2, $attribute_removal_processor->remove_attributes_with_prefix( 'data-' ), 'Expected native HTML nested prefix attribute removal count.' );
assert_same( null, $attribute_removal_processor->get_attribute( 'data-kind' ), 'Expected native HTML prefix-removed attribute reads to return null before serialization.' );
assert_same( 0, $attribute_removal_processor->remove_attributes_with_prefix( 'data-' ), 'Expected native HTML repeated prefix removal to find no remaining attributes.' );
assert_same( '<main   class="entry"><a   href="/x">Link</a></main>', $attribute_removal_processor->get_updated_html(), 'Expected native HTML updated output after nested prefix attribute removal.' );
assert_same( '<main   class="entry"><a   href="/x">Link</a></main>', (string) $attribute_removal_processor, 'Expected native HTML string cast to return updated output.' );

$attribute_update_processor = new WP_HTML_Native_Tag_Processor( '<main data-id="7" class="entry"><p>Text</p></main><aside></aside>' );
assert_true( $attribute_update_processor->next_tag(), 'Expected native HTML tag before setting attributes.' );
assert_true( $attribute_update_processor->set_attribute( 'data-id', '8 & more' ), 'Expected native HTML attribute replacement to be queued.' );
assert_same( '8 & more', $attribute_update_processor->get_attribute( 'data-id' ), 'Expected native HTML updated attribute reads before serialization.' );
assert_true( $attribute_update_processor->set_attribute( 'hidden', true ), 'Expected native HTML boolean attribute insertion to be queued.' );
assert_same( true, $attribute_update_processor->get_attribute( 'hidden' ), 'Expected native HTML boolean attribute reads before serialization.' );
assert_same( '<main hidden data-id="8 &amp; more" class="entry"><p>Text</p></main><aside></aside>', $attribute_update_processor->get_updated_html(), 'Expected native HTML updated output after setting attributes.' );
assert_true( $attribute_update_processor->set_attribute( 'data-id', false ), 'Expected native HTML false attribute value to remove the attribute.' );
assert_same( null, $attribute_update_processor->get_attribute( 'data-id' ), 'Expected native HTML false-set attribute reads to return null.' );
assert_same( '<main hidden  class="entry"><p>Text</p></main><aside></aside>', $attribute_update_processor->get_updated_html(), 'Expected native HTML updated output after setting an attribute to false.' );
assert_true( $attribute_update_processor->next_tag(), 'Expected native HTML nested tag after setting attributes.' );
assert_true( $attribute_update_processor->next_tag(), 'Expected native HTML tag without existing attributes.' );
assert_true( $attribute_update_processor->set_attribute( 'data-state', 'new' ), 'Expected native HTML new attribute insertion to be queued.' );
assert_same( 'new', $attribute_update_processor->get_attribute( 'data-state' ), 'Expected native HTML inserted attribute reads before serialization.' );
assert_same( '<main hidden  class="entry"><p>Text</p></main><aside data-state="new"></aside>', $attribute_update_processor->get_updated_html(), 'Expected native HTML updated output after inserting an attribute.' );

$class_addition_processor = new WP_HTML_Native_Tag_Processor( '<main class="entry"><p>Text</p></main><aside></aside>' );
assert_true( $class_addition_processor->next_tag(), 'Expected native HTML tag before adding a class.' );
assert_true( $class_addition_processor->add_class( 'featured' ), 'Expected native HTML class addition to be queued.' );
assert_same( 'entry featured', $class_addition_processor->get_attribute( 'class' ), 'Expected native HTML updated class reads before serialization.' );
assert_true( $class_addition_processor->add_class( 'featured' ), 'Expected native HTML duplicate class addition to be accepted.' );
assert_true( $class_addition_processor->remove_class( 'entry' ), 'Expected native HTML class removal to be queued.' );
assert_same( 'featured', $class_addition_processor->get_attribute( 'class' ), 'Expected native HTML removed class reads before serialization.' );
assert_same( '<main class="featured"><p>Text</p></main><aside></aside>', $class_addition_processor->get_updated_html(), 'Expected native HTML updated output after class removal.' );
assert_true( $class_addition_processor->next_tag(), 'Expected native HTML next tag after class addition.' );
assert_true( $class_addition_processor->next_tag(), 'Expected native HTML tag without a class attribute.' );
assert_true( $class_addition_processor->add_class( 'secondary' ), 'Expected native HTML class insertion to be queued.' );
assert_same( 'secondary', $class_addition_processor->get_attribute( 'class' ), 'Expected native HTML inserted class reads before serialization.' );
assert_same( '<main class="featured"><p>Text</p></main><aside class="secondary"></aside>', $class_addition_processor->get_updated_html(), 'Expected native HTML updated output after class insertion.' );

$compact_tag_processor = new WP_HTML_Native_Tag_Processor( '<div data-id="1" aria-label="x"></div>' );
assert_same( 37, $compact_tag_processor->next_tag_any_kind_and_attribute_name_initials( true, 1 ), 'Expected compact native HTML tag row to include opener kind and attribute initials.' );
assert_same( 2, $compact_tag_processor->next_tag_any_kind_and_attribute_name_initials( true, 1 ), 'Expected compact native HTML tag row to include closer kind.' );

$compact_tag_processor = new WP_HTML_Native_Tag_Processor( '<div data-id="1" aria-label="x"></div>' );
assert_same( 37, $compact_tag_processor->next_tag_any_kind_and_attribute_name_initials_visit(), 'Expected no-argument compact native HTML tag row to include opener kind and attribute initials.' );
assert_same( 2, $compact_tag_processor->next_tag_any_kind_and_attribute_name_initials_visit(), 'Expected no-argument compact native HTML tag row to include closer kind.' );

$compact_tag_processor = new WP_HTML_Native_Tag_Processor( '<div data-id="1" aria-label="x"></div>' );
assert_same( 37, $compact_tag_processor->next_tag_any_kind_and_attribute_name_initials_skip(), 'Expected no-argument compact native HTML skip row to include opener kind and attribute initials.' );
assert_same( false, (bool) $compact_tag_processor->next_tag_any_kind_and_attribute_name_initials_skip(), 'Expected no-argument compact native HTML skip row to skip closers.' );

$html_full_parser = WP_HTML_Native_Processor::create_full_parser( '<html><body><main>Text</main></body></html>' );
assert_true( $html_full_parser instanceof WP_HTML_Native_Processor, 'Expected native HTML full parser factory to create a processor with the default UTF-8 encoding.' );
assert_true( $html_full_parser->next_tag(), 'Expected native HTML full parser to scan tags.' );
assert_same( 'HTML', $html_full_parser->get_tag(), 'Expected native HTML full parser to start at the document HTML tag.' );
assert_same( null, WP_HTML_Native_Processor::create_full_parser( '<html></html>', 'ISO-8859-1' ), 'Expected native HTML full parser factory to reject unsupported encodings.' );

$html_step_processor = WP_HTML_Native_Processor::create_fragment( '<section><p>Text</p></section>' );
assert_true( $html_step_processor->step(), 'Expected native HTML processor step() to advance by default.' );
assert_same( 'SECTION', $html_step_processor->get_tag(), 'Expected native HTML processor default step() to reach the first tag.' );
assert_true( $html_step_processor->step( 'process-next-node' ), 'Expected native HTML processor step() to accept explicit next-node mode.' );
assert_same( 'P', $html_step_processor->get_tag(), 'Expected native HTML processor explicit next-node step() to reach the nested tag.' );
assert_true( $html_step_processor->step( 'reprocess-current-node' ), 'Expected native HTML processor step() to accept current-node reprocessing while positioned on a token.' );
assert_same( 'P', $html_step_processor->get_tag(), 'Expected native HTML processor current-node reprocessing to keep the current tag.' );
assert_false( $html_step_processor->step( 'invalid' ), 'Expected native HTML processor step() to reject unknown processing modes.' );

$compact_html_processor = WP_HTML_Native_Processor::create_fragment( '<section><p>Text</p></section>' );
assert_same(
	"t\x1fSECTION\x1f0\x1f3\x1fHTML\x1dBODY\x1dSECTION\x1et\x1fP\x1f0\x1f4\x1fHTML\x1dBODY\x1dSECTION\x1dP",
	$compact_html_processor->next_token_compact_summary_batch( 2 ),
	'Expected native HTML processor compact token batch to return the first chunk.'
);
assert_same(
	"s\x1f#text\x1f0\x1f5\x1fHTML\x1dBODY\x1dSECTION\x1dP\x1d#text\x1et\x1fP\x1f1\x1f3\x1fHTML\x1dBODY\x1dSECTION",
	$compact_html_processor->next_token_compact_summary_batch( 2 ),
	'Expected native HTML processor compact token batch to return the second chunk.'
);

$html_token_summary_processor = WP_HTML_Native_Processor::create_fragment( '<section><p>Text</p></section>' );
assert_same(
	array(
		array(
			'token_type'    => '#tag',
			'token_name'    => 'SECTION',
			'is_tag_closer' => false,
			'current_depth' => 3,
			'breadcrumbs'   => array( 'HTML', 'BODY', 'SECTION' ),
		),
		array(
			'token_type'    => '#tag',
			'token_name'    => 'P',
			'is_tag_closer' => false,
			'current_depth' => 4,
			'breadcrumbs'   => array( 'HTML', 'BODY', 'SECTION', 'P' ),
		),
	),
	$html_token_summary_processor->next_token_summary_batch( 2 ),
	'Expected native HTML processor token summary batch to return the first chunk.'
);
assert_same(
	array(
		array(
			'token_type'    => '#text',
			'token_name'    => '#text',
			'is_tag_closer' => false,
			'current_depth' => 5,
			'breadcrumbs'   => array( 'HTML', 'BODY', 'SECTION', 'P', '#text' ),
		),
		array(
			'token_type'    => '#tag',
			'token_name'    => 'P',
			'is_tag_closer' => true,
			'current_depth' => 3,
			'breadcrumbs'   => array( 'HTML', 'BODY', 'SECTION' ),
		),
	),
	$html_token_summary_processor->next_token_summary_batch( 2 ),
	'Expected native HTML processor token summary batch to return the second chunk.'
);

$html_processor_tag_summary_batch = WP_HTML_Native_Processor::create_fragment( '<section><p>Text</p></section>' );
assert_same(
	array(
		array(
			'tag_name'      => 'SECTION',
			'is_tag_closer' => false,
		),
		array(
			'tag_name'      => 'P',
			'is_tag_closer' => false,
		),
	),
	$html_processor_tag_summary_batch->next_tag_summary_batch( 2, true ),
	'Expected native HTML processor tag summary batch to return the first chunk.'
);
assert_same(
	array(
		array(
			'tag_name'      => 'P',
			'is_tag_closer' => true,
		),
		array(
			'tag_name'      => 'SECTION',
			'is_tag_closer' => true,
		),
	),
	$html_processor_tag_summary_batch->next_tag_summary_batch( 2, true ),
	'Expected native HTML processor tag summary batch to return the second chunk.'
);
assert_same( array(), $html_processor_tag_summary_batch->next_tag_summary_batch( 2, true ), 'Expected native HTML processor tag summary batch to return an empty array when exhausted.' );

$html_processor_compact_tag_summary_batch = WP_HTML_Native_Processor::create_fragment( '<section><p>Text</p></section>' );
assert_same(
	"SECTION\x1f0\x1eP\x1f0",
	$html_processor_compact_tag_summary_batch->next_tag_compact_summary_batch( 2, true ),
	'Expected native HTML processor compact tag summary batch to return the first chunk.'
);
assert_same(
	"P\x1f1\x1eSECTION\x1f1",
	$html_processor_compact_tag_summary_batch->next_tag_compact_summary_batch( 2, true ),
	'Expected native HTML processor compact tag summary batch to return the second chunk.'
);
assert_same( null, $html_processor_compact_tag_summary_batch->next_tag_compact_summary_batch( 2, true ), 'Expected native HTML processor compact tag summary batch to return null when exhausted.' );

$html_processor_matching_tag_summary_batch = WP_HTML_Native_Processor::create_fragment( '<section><a>One</a><span><a>Two</a></span></section>' );
assert_same(
	"A\x1f0\x1eA\x1f1",
	$html_processor_matching_tag_summary_batch->next_matching_tag_compact_summary_batch( 'a', 2, true ),
	'Expected native HTML processor compact matching-tag summary batch to return the first chunk.'
);
assert_same(
	"A\x1f0\x1eA\x1f1",
	$html_processor_matching_tag_summary_batch->next_matching_tag_compact_summary_batch( 'A', 2, true ),
	'Expected native HTML processor compact matching-tag summary batch to match case-insensitively.'
);
assert_same( null, $html_processor_matching_tag_summary_batch->next_matching_tag_compact_summary_batch( 'a', 2, true ), 'Expected native HTML processor compact matching-tag summary batch to return null when exhausted.' );

$html_processor_matching_tag_attribute_summary_batch = WP_HTML_Native_Processor::create_fragment( '<section><a href="/one">One</a><span><a href="/two?x=1&amp;y=2">Two</a></span><a>No href</a></section>' );
assert_same(
	"A\x1f0\x1f1/one\x1eA\x1f1\x1f0",
	$html_processor_matching_tag_attribute_summary_batch->next_matching_tag_attribute_compact_summary_batch( 'a', 'href', 2, true ),
	'Expected native HTML processor compact matching-tag attribute summary batch to return the first chunk.'
);
assert_same(
	"A\x1f0\x1f1/two?x=1&y=2\x1eA\x1f1\x1f0",
	$html_processor_matching_tag_attribute_summary_batch->next_matching_tag_attribute_compact_summary_batch( 'A', 'href', 2, true ),
	'Expected native HTML processor compact matching-tag attribute summary batch to decode attributes and match case-insensitively.'
);
assert_same(
	"A\x1f0\x1f0\x1eA\x1f1\x1f0",
	$html_processor_matching_tag_attribute_summary_batch->next_matching_tag_attribute_compact_summary_batch( 'a', 'href', 2, true ),
	'Expected native HTML processor compact matching-tag attribute summary batch to mark missing attributes.'
);
assert_same( null, $html_processor_matching_tag_attribute_summary_batch->next_matching_tag_attribute_compact_summary_batch( 'a', 'href', 2, true ), 'Expected native HTML processor compact matching-tag attribute summary batch to return null when exhausted.' );

$html_processor_matching_tag_attributes_summary_batch = WP_HTML_Native_Processor::create_fragment( '<section><a href="/one" title="One &amp; two">One</a><span><a href="/two" rel>Two</a></span><a title="Three">No href</a></section>' );
assert_same(
	"A\x1f0\x1f1/one\x1f1One & two\x1f0\x1eA\x1f1\x1f0\x1f0\x1f0",
	$html_processor_matching_tag_attributes_summary_batch->next_matching_tag_attributes_compact_summary_batch( 'a', "href\x1ftitle\x1frel", 2, true ),
	'Expected native HTML processor compact matching-tag attributes summary batch to return the first chunk.'
);
assert_same(
	"A\x1f0\x1f1/two\x1f0\x1f1\x1eA\x1f1\x1f0\x1f0\x1f0",
	$html_processor_matching_tag_attributes_summary_batch->next_matching_tag_attributes_compact_summary_batch( 'A', "href\x1ftitle\x1frel", 2, true ),
	'Expected native HTML processor compact matching-tag attributes summary batch to decode attributes and match case-insensitively.'
);
assert_same(
	"A\x1f0\x1f0\x1f1Three\x1f0\x1eA\x1f1\x1f0\x1f0\x1f0",
	$html_processor_matching_tag_attributes_summary_batch->next_matching_tag_attributes_compact_summary_batch( 'a', "href\x1ftitle\x1frel", 2, true ),
	'Expected native HTML processor compact matching-tag attributes summary batch to mark missing multi-attributes.'
);
assert_same( null, $html_processor_matching_tag_attributes_summary_batch->next_matching_tag_attributes_compact_summary_batch( 'a', "href\x1ftitle\x1frel", 2, true ), 'Expected native HTML processor compact matching-tag attributes summary batch to return null when exhausted.' );

$html_processor_public_matching_tag_summary_batch = WP_HTML_Native_Processor::create_fragment( '<section><a>One</a><span><a>Two</a></span></section>' );
assert_same(
	array(
		array(
			'tag_name'      => 'A',
			'is_tag_closer' => false,
		),
		array(
			'tag_name'      => 'A',
			'is_tag_closer' => true,
		),
	),
	$html_processor_public_matching_tag_summary_batch->next_matching_tag_summary_batch( 'a', 2, true ),
	'Expected native HTML processor matching-tag summary batch to return the first chunk.'
);
assert_same(
	array(
		array(
			'tag_name'      => 'A',
			'is_tag_closer' => false,
		),
		array(
			'tag_name'      => 'A',
			'is_tag_closer' => true,
		),
	),
	$html_processor_public_matching_tag_summary_batch->next_matching_tag_summary_batch( 'A', 2, true ),
	'Expected native HTML processor matching-tag summary batch to match case-insensitively.'
);
assert_same( array(), $html_processor_public_matching_tag_summary_batch->next_matching_tag_summary_batch( 'a', 2, true ), 'Expected native HTML processor matching-tag summary batch to return an empty array when exhausted.' );

$html_processor_public_matching_tag_attribute_summary_batch = WP_HTML_Native_Processor::create_fragment( '<section><a href="/one">One</a><span><a href="/two?x=1&amp;y=2">Two</a></span><a>No href</a></section>' );
assert_same(
	array(
		array(
			'tag_name'        => 'A',
			'is_tag_closer'   => false,
			'attribute_value' => '/one',
		),
		array(
			'tag_name'        => 'A',
			'is_tag_closer'   => true,
			'attribute_value' => null,
		),
	),
	$html_processor_public_matching_tag_attribute_summary_batch->next_matching_tag_attribute_summary_batch( 'a', 'href', 2, true ),
	'Expected native HTML processor matching-tag attribute summary batch to return the first chunk.'
);
assert_same(
	array(
		array(
			'tag_name'        => 'A',
			'is_tag_closer'   => false,
			'attribute_value' => '/two?x=1&y=2',
		),
		array(
			'tag_name'        => 'A',
			'is_tag_closer'   => true,
			'attribute_value' => null,
		),
	),
	$html_processor_public_matching_tag_attribute_summary_batch->next_matching_tag_attribute_summary_batch( 'A', 'href', 2, true ),
	'Expected native HTML processor matching-tag attribute summary batch to decode attributes and match case-insensitively.'
);
assert_same(
	array(
		array(
			'tag_name'        => 'A',
			'is_tag_closer'   => false,
			'attribute_value' => null,
		),
		array(
			'tag_name'        => 'A',
			'is_tag_closer'   => true,
			'attribute_value' => null,
		),
	),
	$html_processor_public_matching_tag_attribute_summary_batch->next_matching_tag_attribute_summary_batch( 'a', 'href', 2, true ),
	'Expected native HTML processor matching-tag attribute summary batch to mark missing attributes.'
);
assert_same( array(), $html_processor_public_matching_tag_attribute_summary_batch->next_matching_tag_attribute_summary_batch( 'a', 'href', 2, true ), 'Expected native HTML processor matching-tag attribute summary batch to return an empty array when exhausted.' );

$html_processor_public_matching_tag_attributes_summary_batch = WP_HTML_Native_Processor::create_fragment( '<section><a href="/one" title="One &amp; two">One</a><span><a href="/two" rel>Two</a></span><a title="Three">No href</a></section>' );
assert_same(
	array(
		array(
			'tag_name'         => 'A',
			'is_tag_closer'    => false,
			'attribute_values' => array(
				'href'  => '/one',
				'title' => 'One & two',
				'rel'   => null,
			),
		),
		array(
			'tag_name'         => 'A',
			'is_tag_closer'    => true,
			'attribute_values' => array(
				'href'  => null,
				'title' => null,
				'rel'   => null,
			),
		),
	),
	$html_processor_public_matching_tag_attributes_summary_batch->next_matching_tag_attributes_summary_batch( 'a', array( 'href', 'title', 'rel' ), 2, true ),
	'Expected native HTML processor matching-tag attributes summary batch to return the first chunk.'
);
assert_same(
	array(
		array(
			'tag_name'         => 'A',
			'is_tag_closer'    => false,
			'attribute_values' => array(
				'href'  => '/two',
				'title' => null,
				'rel'   => '',
			),
		),
		array(
			'tag_name'         => 'A',
			'is_tag_closer'    => true,
			'attribute_values' => array(
				'href'  => null,
				'title' => null,
				'rel'   => null,
			),
		),
	),
	$html_processor_public_matching_tag_attributes_summary_batch->next_matching_tag_attributes_summary_batch( 'A', array( 'href', 'title', 'rel' ), 2, true ),
	'Expected native HTML processor matching-tag attributes summary batch to decode attributes and match case-insensitively.'
);
assert_same(
	array(
		array(
			'tag_name'         => 'A',
			'is_tag_closer'    => false,
			'attribute_values' => array(
				'href'  => null,
				'title' => 'Three',
				'rel'   => null,
			),
		),
		array(
			'tag_name'         => 'A',
			'is_tag_closer'    => true,
			'attribute_values' => array(
				'href'  => null,
				'title' => null,
				'rel'   => null,
			),
		),
	),
	$html_processor_public_matching_tag_attributes_summary_batch->next_matching_tag_attributes_summary_batch( 'a', array( 'href', 'title', 'rel' ), 2, true ),
	'Expected native HTML processor matching-tag attributes summary batch to mark missing multi-attributes.'
);
assert_same( array(), $html_processor_public_matching_tag_attributes_summary_batch->next_matching_tag_attributes_summary_batch( 'a', array( 'href', 'title', 'rel' ), 2, true ), 'Expected native HTML processor matching-tag attributes summary batch to return an empty array when exhausted.' );

$html_processor_matching_tag_attributes_summary = WP_HTML_Native_Processor::create_fragment( '<section><a href="/one" title="One &amp; two">One</a><span><a href="/two" rel>Two</a></span><a title="Three">No href</a></section>' );
assert_same( "6\x1f5\x1f22", $html_processor_matching_tag_attributes_summary->summarize_matching_tag_attributes( 'a', "href\x1ftitle\x1frel", true ), 'Expected native HTML processor matching-tag attributes summary to aggregate decoded attribute bytes.' );

$legacy_character_reference_processor = new WP_HTML_Native_Tag_Processor( '<a title="&#0 &#xD800 &#128 &#x85 &#x41 &copy &notin &ampx &notit;"></a><title>&notin &ampx &copyx &#x110000</title>' );
assert_true( $legacy_character_reference_processor->next_tag(), 'Expected native HTML legacy reference anchor tag.' );
assert_same(
	"\u{fffd} \u{fffd} \u{20ac} \u{2026} A \u{00a9} &notin &ampx &notit;",
	$legacy_character_reference_processor->get_attribute( 'title' ),
	'Expected native HTML numeric and legacy attribute reference decoding.'
);
assert_true( $legacy_character_reference_processor->next_tag(), 'Expected native HTML legacy reference title tag.' );
assert_same( 'TITLE', $legacy_character_reference_processor->get_token_name(), 'Expected native HTML legacy reference title token.' );
assert_same(
	"\u{00ac}in &x \u{00a9}x \u{fffd}",
	$legacy_character_reference_processor->get_modifiable_text(),
	'Expected native HTML RCDATA legacy reference decoding.'
);

$doctype_processor = new WP_HTML_Native_Tag_Processor( '<!DOCTYPE html><p>Text</p>' );
assert_true( $doctype_processor->next_token(), 'Expected native HTML DOCTYPE token.' );
assert_same( '#doctype', $doctype_processor->get_token_type(), 'Expected native HTML DOCTYPE token type.' );
assert_same( 'html', $doctype_processor->get_token_name(), 'Expected native HTML DOCTYPE token name.' );
assert_same( ' html', $doctype_processor->get_modifiable_text(), 'Expected native HTML DOCTYPE text.' );
$doctype_info = $doctype_processor->get_doctype_info();
assert_true( $doctype_info instanceof WP_HTML_Doctype_Info, 'Expected native HTML DOCTYPE info object.' );
assert_same( 'html', $doctype_info->name, 'Expected native HTML DOCTYPE info name.' );
assert_same( null, $doctype_info->public_identifier, 'Expected native HTML DOCTYPE info to omit a public identifier.' );
assert_same( null, $doctype_info->system_identifier, 'Expected native HTML DOCTYPE info to omit a system identifier.' );
assert_same( 'no-quirks', $doctype_info->indicated_compatability_mode, 'Expected native HTML DOCTYPE info to report no-quirks mode.' );
assert_true( $doctype_processor->next_tag(), 'Expected native HTML next_tag() to skip DOCTYPE.' );
assert_same( 'P', $doctype_processor->get_tag(), 'Expected native HTML tag after DOCTYPE.' );
assert_same( null, $doctype_processor->get_doctype_info(), 'Expected native HTML DOCTYPE info to return null away from DOCTYPE tokens.' );

$comment_processor = new WP_HTML_Native_Tag_Processor( '<!--note--><p>Text</p>' );
assert_true( $comment_processor->next_token(), 'Expected native HTML comment token.' );
assert_same( '#comment', $comment_processor->get_token_type(), 'Expected native HTML comment token type.' );
assert_same( '#comment', $comment_processor->get_token_name(), 'Expected native HTML comment token name.' );
assert_same( 'note', $comment_processor->get_modifiable_text(), 'Expected native HTML comment text.' );
assert_same( 'COMMENT_AS_HTML_COMMENT', $comment_processor->get_comment_type(), 'Expected native HTML comment type.' );
assert_same( 'note', $comment_processor->get_full_comment_text(), 'Expected native HTML full comment text.' );
assert_true( $comment_processor->set_modifiable_text( 'updated note' ), 'Expected native HTML comment text update to be queued.' );
assert_same( 'updated note', $comment_processor->get_modifiable_text(), 'Expected native HTML comment text reads to reflect queued updates.' );
assert_same( 'updated note', $comment_processor->get_full_comment_text(), 'Expected native HTML full comment text reads to reflect queued updates.' );
assert_same( '<!--updated note--><p>Text</p>', $comment_processor->get_updated_html(), 'Expected native HTML comment text update to serialize.' );
assert_false( $comment_processor->set_modifiable_text( 'bad --> close' ), 'Expected native HTML comment text update to reject premature closers.' );
assert_true( $comment_processor->next_tag(), 'Expected native HTML next_tag() to skip comment.' );
assert_same( 'P', $comment_processor->get_tag(), 'Expected native HTML tag after comment.' );
assert_same( null, $comment_processor->get_comment_type(), 'Expected native HTML tag to have no comment type.' );
assert_same( null, $comment_processor->get_full_comment_text(), 'Expected native HTML tag to have no full comment text.' );
assert_false( $comment_processor->set_modifiable_text( 'tag text' ), 'Expected native HTML text update to reject non-text tag tokens.' );

$funky_comment_processor = new WP_HTML_Native_Tag_Processor( '<?pi data?><!notdoctype><p>Text</p>' );
assert_true( $funky_comment_processor->next_token(), 'Expected native HTML processing-instruction-looking comment.' );
assert_same( '#comment', $funky_comment_processor->get_token_type(), 'Expected native HTML PI-looking token type.' );
assert_same( '#comment', $funky_comment_processor->get_token_name(), 'Expected native HTML PI-looking token name.' );
assert_same( ' data', $funky_comment_processor->get_modifiable_text(), 'Expected native HTML PI-looking comment text.' );
assert_same( 'COMMENT_AS_PI_NODE_LOOKALIKE', $funky_comment_processor->get_comment_type(), 'Expected native HTML PI-looking comment type.' );
assert_same( '?pi data?', $funky_comment_processor->get_full_comment_text(), 'Expected native HTML PI-looking full comment text.' );
assert_true( $funky_comment_processor->next_token(), 'Expected native HTML invalid declaration comment.' );
assert_same( '#comment', $funky_comment_processor->get_token_type(), 'Expected native HTML invalid declaration token type.' );
assert_same( '#comment', $funky_comment_processor->get_token_name(), 'Expected native HTML invalid declaration token name.' );
assert_same( 'notdoctype', $funky_comment_processor->get_modifiable_text(), 'Expected native HTML invalid declaration comment text.' );
assert_same( 'COMMENT_AS_INVALID_HTML', $funky_comment_processor->get_comment_type(), 'Expected native HTML invalid declaration comment type.' );
assert_same( 'notdoctype', $funky_comment_processor->get_full_comment_text(), 'Expected native HTML invalid declaration full comment text.' );
assert_true( $funky_comment_processor->next_tag(), 'Expected native HTML next_tag() to skip funky comments.' );
assert_same( 'P', $funky_comment_processor->get_tag(), 'Expected native HTML tag after funky comments.' );

$invalid_opening_text_processor = new WP_HTML_Native_Tag_Processor( 'before &amp; <1><%bad><_x><:x><.x><-x>< p><p>Text</p>' );
assert_true( $invalid_opening_text_processor->next_token(), 'Expected native HTML invalid opening tags to produce text.' );
assert_same( '#text', $invalid_opening_text_processor->get_token_type(), 'Expected native HTML invalid opening text token type.' );
assert_same( 'before & <1><%bad><_x><:x><.x><-x>< p>', $invalid_opening_text_processor->get_modifiable_text(), 'Expected native HTML invalid opening text to include decoded leading text.' );
assert_true( $invalid_opening_text_processor->next_tag(), 'Expected native HTML next_tag() to skip invalid opening text.' );
assert_same( 'P', $invalid_opening_text_processor->get_tag(), 'Expected native HTML tag after invalid opening text.' );

$adjacent_invalid_opening_text_processor = new WP_HTML_Native_Tag_Processor( '<<p>Text</p><' );
assert_true( $adjacent_invalid_opening_text_processor->next_token(), 'Expected native HTML adjacent invalid opening text.' );
assert_same( '#text', $adjacent_invalid_opening_text_processor->get_token_type(), 'Expected native HTML adjacent invalid opening token type.' );
assert_same( '<', $adjacent_invalid_opening_text_processor->get_modifiable_text(), 'Expected native HTML adjacent invalid opening text.' );
assert_true( $adjacent_invalid_opening_text_processor->next_tag(), 'Expected native HTML valid tag after adjacent invalid opening text.' );
assert_same( 'P', $adjacent_invalid_opening_text_processor->get_tag(), 'Expected native HTML adjacent valid tag name.' );

$consecutive_invalid_opening_text_processor = new WP_HTML_Native_Tag_Processor( '<< <<p>Text</p>' );
assert_true( $consecutive_invalid_opening_text_processor->next_token(), 'Expected native HTML consecutive invalid opening text.' );
assert_same( '#text', $consecutive_invalid_opening_text_processor->get_token_type(), 'Expected native HTML consecutive invalid opening token type.' );
assert_same( '<< <', $consecutive_invalid_opening_text_processor->get_modifiable_text(), 'Expected native HTML consecutive invalid opening text to coalesce.' );
assert_true( $consecutive_invalid_opening_text_processor->next_tag(), 'Expected native HTML valid tag after consecutive invalid opening text.' );
assert_same( 'P', $consecutive_invalid_opening_text_processor->get_tag(), 'Expected native HTML consecutive invalid opening valid tag name.' );

$raw_text_processor = new WP_HTML_Native_Tag_Processor( '<script>if (a < b) { c(); }</script><iframe>A&amp;<b>C</b></iframe><noembed>D&amp;<i>E</i></noembed><noframes>F&amp;<u>G</u></noframes><xmp>H&amp;<q>I</q></xmp><title>A&amp;B&nbsp;&copy;</title><textarea>C&lt;D&hellip;</textarea><p>x</p>' );
assert_true( $raw_text_processor->next_token(), 'Expected native HTML raw-text script token.' );
assert_same( '#tag', $raw_text_processor->get_token_type(), 'Expected native HTML raw-text token type.' );
assert_same( 'SCRIPT', $raw_text_processor->get_token_name(), 'Expected native HTML raw-text token name.' );
assert_same( 'if (a < b) { c(); }', $raw_text_processor->get_modifiable_text(), 'Expected native HTML raw-text content.' );
assert_true( $raw_text_processor->next_token(), 'Expected native HTML iframe token after script.' );
assert_same( 'IFRAME', $raw_text_processor->get_token_name(), 'Expected native HTML iframe token name.' );
assert_same( 'A&amp;<b>C</b>', $raw_text_processor->get_modifiable_text(), 'Expected native HTML iframe contents to stay raw.' );
assert_true( $raw_text_processor->next_token(), 'Expected native HTML noembed token after iframe.' );
assert_same( 'NOEMBED', $raw_text_processor->get_token_name(), 'Expected native HTML noembed token name.' );
assert_same( 'D&amp;<i>E</i>', $raw_text_processor->get_modifiable_text(), 'Expected native HTML noembed contents to stay raw.' );
assert_true( $raw_text_processor->next_token(), 'Expected native HTML noframes token after noembed.' );
assert_same( 'NOFRAMES', $raw_text_processor->get_token_name(), 'Expected native HTML noframes token name.' );
assert_same( 'F&amp;<u>G</u>', $raw_text_processor->get_modifiable_text(), 'Expected native HTML noframes contents to stay raw.' );
assert_true( $raw_text_processor->next_token(), 'Expected native HTML xmp token after noframes.' );
assert_same( 'XMP', $raw_text_processor->get_token_name(), 'Expected native HTML xmp token name.' );
assert_same( 'H&amp;<q>I</q>', $raw_text_processor->get_modifiable_text(), 'Expected native HTML xmp contents to stay raw.' );
assert_true( $raw_text_processor->next_token(), 'Expected native HTML title token after raw-text script.' );
assert_same( 'TITLE', $raw_text_processor->get_token_name(), 'Expected native HTML title token name.' );
assert_same( "A&B\u{00a0}\u{00a9}", $raw_text_processor->get_modifiable_text(), 'Expected native HTML title RCDATA decoding.' );
assert_true( $raw_text_processor->next_token(), 'Expected native HTML textarea token after title.' );
assert_same( 'TEXTAREA', $raw_text_processor->get_token_name(), 'Expected native HTML textarea token name.' );
assert_same( "C<D\u{2026}", $raw_text_processor->get_modifiable_text(), 'Expected native HTML textarea RCDATA decoding.' );
assert_true( $raw_text_processor->next_token(), 'Expected native HTML token after raw-text script.' );
assert_same( 'P', $raw_text_processor->get_token_name(), 'Expected native HTML tag after raw-text script.' );
assert_same( false, $raw_text_processor->is_tag_closer(), 'Expected native HTML raw-text closer to be skipped.' );

$html_processor_prefix_summary = WP_HTML_Native_Processor::create_fragment( '<main data-one="1"><span data-two="2" aria-label="x"></span></main>' );
assert_same( "4\x1f2", $html_processor_prefix_summary->summarize_attribute_names_with_prefix( 'data-', true ), 'Expected HTML processor document-level prefix summary to include closers.' );

$html_processor_prefix_summary_batch = WP_HTML_Native_Processor::create_fragment( '<main data-id="7"><a data-kind="nav" href="/x">Link</a><img data-src="/x"></main>' );
assert_same( "MAIN\x1f0\x1f1\x1eA\x1f0\x1f1", $html_processor_prefix_summary_batch->next_tag_prefix_summary_batch( 'data-', 2, true ), 'Expected HTML processor prefix summary batch to return the first chunk.' );
assert_same( "A\x1f1\x1f0\x1eIMG\x1f0\x1f1", $html_processor_prefix_summary_batch->next_tag_prefix_summary_batch( 'data-', 2, true ), 'Expected HTML processor prefix summary batch to return the second chunk.' );
assert_same( "MAIN\x1f1\x1f0", $html_processor_prefix_summary_batch->next_tag_prefix_summary_batch( 'data-', 2, true ), 'Expected HTML processor prefix summary batch to return the final chunk.' );
assert_same( null, $html_processor_prefix_summary_batch->next_tag_prefix_summary_batch( 'data-', 2, true ), 'Expected HTML processor prefix summary batch to return null when exhausted.' );

$html_processor_prefix_compact_summary_batch = WP_HTML_Native_Processor::create_fragment( '<main data-id="7"><a data-kind="nav" href="/x">Link</a><img data-src="/x"></main>' );
assert_same( "MAIN\x1f0\x1f1\x1eA\x1f0\x1f1", $html_processor_prefix_compact_summary_batch->next_tag_prefix_compact_summary_batch( 'data-', 2, true ), 'Expected HTML processor compact prefix summary batch to return the first chunk.' );
assert_same( "A\x1f1\x1f0\x1eIMG\x1f0\x1f1", $html_processor_prefix_compact_summary_batch->next_tag_prefix_compact_summary_batch( 'data-', 2, true ), 'Expected HTML processor compact prefix summary batch to return the second chunk.' );
assert_same( "MAIN\x1f1\x1f0", $html_processor_prefix_compact_summary_batch->next_tag_prefix_compact_summary_batch( 'data-', 2, true ), 'Expected HTML processor compact prefix summary batch to return the final chunk.' );
assert_same( null, $html_processor_prefix_compact_summary_batch->next_tag_prefix_compact_summary_batch( 'data-', 2, true ), 'Expected HTML processor compact prefix summary batch to return null when exhausted.' );

$html_processor_prefix_count_batch = WP_HTML_Native_Processor::create_fragment( '<main data-id="7"><a data-kind="nav" href="/x">Link</a><img data-src="/x"></main>' );
assert_same( "2\x1f2", $html_processor_prefix_count_batch->next_tag_prefix_count_compact_batch( 'data-', 2, true ), 'Expected HTML processor prefix count batch to return the first chunk.' );
assert_same( "2\x1f1", $html_processor_prefix_count_batch->next_tag_prefix_count_compact_batch( 'data-', 2, true ), 'Expected HTML processor prefix count batch to return the second chunk.' );
assert_same( "1\x1f0", $html_processor_prefix_count_batch->next_tag_prefix_count_compact_batch( 'data-', 2, true ), 'Expected HTML processor prefix count batch to return the final chunk.' );
assert_same( null, $html_processor_prefix_count_batch->next_tag_prefix_count_compact_batch( 'data-', 2, true ), 'Expected HTML processor prefix count batch to return null when exhausted.' );

$html_processor_tag_inventory = WP_HTML_Native_Processor::create_fragment( '<main data-id="7"><a data-kind="nav" href="/x">Link</a><img data-src="/x"></main>' );
assert_same( "5\x1f3\x1f2\x1f4\x1f3", $html_processor_tag_inventory->summarize_tag_inventory( true ), 'Expected HTML processor tag inventory summary to include closers and attributes.' );

$html_processor_heading_inventory = WP_HTML_Native_Processor::create_fragment( '<main><h1>A</h1><section><h2>B</h2><h3>C</h3></section></main>' );
assert_same( "10\x1f3\x1f1\x1f1\x1f1\x1f0\x1f0\x1f0", $html_processor_heading_inventory->summarize_heading_inventory( true ), 'Expected HTML processor heading inventory summary to include closers and heading levels.' );

$html_processor_id_inventory = WP_HTML_Native_Processor::create_fragment( '<main id="root"><p id="intro">One</p><section id="intro"><span id></span></section></main>' );
assert_same( "8\x1f4\x1f2\x1f1\x1f14", $html_processor_id_inventory->summarize_id_inventory( true ), 'Expected HTML processor ID inventory summary to include closers, duplicates, and decoded values.' );

$html_processor_attribute_inventory = WP_HTML_Native_Processor::create_fragment( '<main data-id="7" hidden><p class="x" title="A &amp; B">Text</p></main>' );
assert_same( "4\x1f4\x1f4\x1f7", $html_processor_attribute_inventory->summarize_attribute_inventory( true ), 'Expected HTML processor attribute inventory summary to include closers and decoded values.' );

$html_processor_data_attribute_inventory = WP_HTML_Native_Processor::create_fragment( '<div data-id="1" data-kind="hero" data-empty data-value="A &amp; B"></div><p data-kind="copy"></p>' );
assert_same( "4\x1f2\x1f5\x1f4\x1f14", $html_processor_data_attribute_inventory->summarize_data_attribute_inventory( true ), 'Expected HTML processor data-attribute inventory summary to include closers and decoded values.' );

$html_processor_aria_attribute_inventory = WP_HTML_Native_Processor::create_fragment( '<button aria-label="Close" aria-expanded="false"></button><div aria-hidden aria-label="Panel" data-id="1"></div>' );
assert_same( "4\x1f2\x1f4\x1f3\x1f15", $html_processor_aria_attribute_inventory->summarize_aria_attribute_inventory( true ), 'Expected HTML processor ARIA attribute inventory summary to include closers and decoded values.' );

$html_processor_class_inventory = WP_HTML_Native_Processor::create_fragment( '<main class="wrap"><p class="lede entry">Text</p></main>' );
assert_same( "4\x1f2\x1f3\x1f3\x1f14", $html_processor_class_inventory->summarize_class_inventory( true ), 'Expected HTML processor class inventory summary to include closers and decoded class names.' );

$html_processor_resource_inventory = WP_HTML_Native_Processor::create_fragment( '<main><a href="/one">One</a><img src="/one.png"><script src="/app.js"></script></main>' );
assert_same( "6\x1f3\x1f3\x1f3\x1f19", $html_processor_resource_inventory->summarize_resource_inventory( true ), 'Expected HTML processor resource inventory summary to include closers and resource values.' );

$html_processor_image_inventory = WP_HTML_Native_Processor::create_fragment( '<img src="/a.png" alt=""><img src="/b.png" alt="Bee" width="10" height="20"><img alt><p></p>' );
assert_same( "5\x1f3\x1f2\x1f3\x1f2\x1f1\x1f12\x1f3", $html_processor_image_inventory->summarize_image_inventory( true ), 'Expected HTML processor image inventory summary to include closers and image attributes.' );

$html_processor_script_inventory = WP_HTML_Native_Processor::create_fragment( '<main><script src="/app.js" type="module" async></script><script>let a = 1;</script><script defer src="/legacy.js"></script></main>' );
assert_same( "5\x1f3\x1f2\x1f1\x1f1\x1f1\x1f10\x1f17", $html_processor_script_inventory->summarize_script_inventory( true ), 'Expected HTML processor script inventory summary to include closers and script attributes.' );

$html_processor_form_inventory = WP_HTML_Native_Processor::create_fragment( '<form><input name="q"><input name="page"><input><button name="go"></button></form>' );
assert_same( "7\x1f1\x1f4\x1f3\x1f3\x1f7", $html_processor_form_inventory->summarize_form_inventory( true ), 'Expected HTML processor form inventory summary to include closers and control names.' );

$html_processor_prefix_removal = WP_HTML_Native_Processor::create_fragment( '<section data-id="7" data-kind="hero" aria-label="Hero">Text</section>' );
assert_true( $html_processor_prefix_removal->next_token(), 'Expected HTML processor prefix-removal opening token.' );
assert_same( 2, $html_processor_prefix_removal->remove_attributes_with_prefix( 'data-' ), 'Expected HTML processor prefix removal to remove matching attributes.' );
assert_same( 0, $html_processor_prefix_removal->remove_attributes_with_prefix( 'data-' ), 'Expected HTML processor repeated prefix removal to find no matching attributes.' );
assert_same( '<section   aria-label="Hero">Text</section>', $html_processor_prefix_removal->get_updated_html(), 'Expected HTML processor updated output after prefix removal.' );
assert_same( '<section   aria-label="Hero">Text</section>', (string) $html_processor_prefix_removal, 'Expected HTML processor string cast to return updated output after prefix removal.' );
assert_true( $html_processor_prefix_removal->next_token(), 'Expected HTML processor prefix-removal text token.' );
assert_same( null, $html_processor_prefix_removal->remove_attributes_with_prefix( 'data-' ), 'Expected HTML processor prefix removal to return null on text tokens.' );

$html_processor_document_removal = WP_HTML_Native_Processor::create_fragment( '<main data-id="7" class="entry"><a DATA-kind="nav" data-track="1" href="/x">Link</a><img data-src="/x"></main>' );
assert_same(
	"5\x1f4\x1f" . '<main  class="entry"><a   href="/x">Link</a><img ></main>',
	$html_processor_document_removal->remove_attributes_with_prefix_from_document( 'data-', true ),
	'Expected HTML processor document-level prefix removal to include updated HTML.'
);

$html_processor = WP_HTML_Native_Processor::create_fragment( '<section data-id="7"><p>Hello<!--note--><em>World</em></p></section>' );
assert_true( WP_HTML_Native_Processor::is_void( 'img' ), 'Expected native HTML processor static void check to match img.' );
assert_true( WP_HTML_Native_Processor::is_void( 'BASEFONT' ), 'Expected native HTML processor static void check to match obsolete void elements.' );
assert_false( WP_HTML_Native_Processor::is_void( 'section' ), 'Expected native HTML processor static void check to reject regular elements.' );
assert_true( WP_HTML_Native_Processor::is_special( 'section' ), 'Expected native HTML processor static special check to match section.' );
assert_true( WP_HTML_Native_Processor::is_special( 'IMG' ), 'Expected native HTML processor static special check to match uppercase names.' );
assert_false( WP_HTML_Native_Processor::is_special( 'span' ), 'Expected native HTML processor static special check to reject regular phrasing elements.' );
assert_same( 2, $html_processor->get_current_depth(), 'Expected initial HTML fragment depth.' );
assert_same( array( 'HTML', 'BODY' ), $html_processor->get_breadcrumbs(), 'Expected initial HTML fragment breadcrumbs.' );
assert_true( $html_processor->next_token(), 'Expected first HTML token.' );
assert_same( '#tag', $html_processor->get_token_type(), 'Expected HTML token type.' );
assert_same( 'SECTION', $html_processor->get_token_name(), 'Expected HTML token name.' );
assert_same( 'SECTION', $html_processor->get_tag(), 'Expected HTML processor tag name.' );
assert_same( array( 'data-id' ), $html_processor->get_attribute_names_with_prefix( 'data-' ), 'Expected HTML processor prefix names on opening tag.' );
assert_same( 1, $html_processor->count_attribute_names_with_prefix( 'data-' ), 'Expected HTML processor prefix-name count on opening tag.' );
assert_same( 0, $html_processor->count_attribute_names_with_prefix( 'aria-' ), 'Expected HTML processor prefix-name count to distinguish empty matches.' );
assert_same( '7', $html_processor->get_attribute( 'data-id' ), 'Expected HTML processor attribute before removal.' );
assert_same( 'SECTION', $html_processor->get_qualified_tag_name(), 'Expected HTML processor qualified tag name.' );
assert_same( 'data-id', $html_processor->get_qualified_attribute_name( 'data-id' ), 'Expected HTML processor qualified attribute name to preserve HTML spelling.' );
assert_true( $html_processor->add_class( 'hero' ), 'Expected HTML processor class addition to be queued.' );
assert_same( 'hero', $html_processor->get_attribute( 'class' ), 'Expected HTML processor inserted class reads before serialization.' );
assert_same( '<section class="hero" data-id="7"><p>Hello<!--note--><em>World</em></p></section>', $html_processor->get_updated_html(), 'Expected HTML processor updated output after class addition.' );
assert_true( $html_processor->remove_class( 'hero' ), 'Expected HTML processor class removal to be queued.' );
assert_same( null, $html_processor->get_attribute( 'class' ), 'Expected HTML processor removed class reads before serialization.' );
assert_same( '<section data-id="7"><p>Hello<!--note--><em>World</em></p></section>', $html_processor->get_updated_html(), 'Expected HTML processor updated output after class removal.' );
assert_true( $html_processor->set_attribute( 'data-id', '8' ), 'Expected HTML processor attribute replacement to be queued.' );
assert_same( '8', $html_processor->get_attribute( 'data-id' ), 'Expected HTML processor updated attribute reads before serialization.' );
assert_same( '<section data-id="8"><p>Hello<!--note--><em>World</em></p></section>', $html_processor->get_updated_html(), 'Expected HTML processor updated output after setting an attribute.' );
assert_true( $html_processor->set_attribute( 'hidden', true ), 'Expected HTML processor boolean attribute insertion to be queued.' );
assert_same( true, $html_processor->get_attribute( 'hidden' ), 'Expected HTML processor boolean attribute reads before serialization.' );
assert_same( '<section hidden data-id="8"><p>Hello<!--note--><em>World</em></p></section>', $html_processor->get_updated_html(), 'Expected HTML processor updated output after setting a boolean attribute.' );
assert_true( $html_processor->remove_attribute( 'data-id' ), 'Expected HTML processor attribute removal to be queued.' );
assert_same( null, $html_processor->get_attribute( 'data-id' ), 'Expected HTML processor removed attribute reads to return null.' );
assert_same( 'html', $html_processor->get_namespace(), 'Expected first native HTML processor token namespace.' );
assert_true( $html_processor->change_parsing_namespace( 'math' ), 'Expected native HTML processor namespace change to MathML to succeed.' );
assert_same( 'math', $html_processor->get_namespace(), 'Expected native HTML processor namespace change to be reflected.' );
assert_false( $html_processor->change_parsing_namespace( 'invalid' ), 'Expected invalid native HTML processor namespace change to fail.' );
assert_same( 'math', $html_processor->get_namespace(), 'Expected failed native HTML processor namespace change to preserve the previous namespace.' );
assert_true( $html_processor->change_parsing_namespace( 'html' ), 'Expected native HTML processor namespace reset to HTML to succeed.' );
assert_same( false, $html_processor->is_tag_closer(), 'Expected opening HTML token.' );
assert_false( $html_processor->is_virtual(), 'Expected native HTML processor token not to be virtual.' );
assert_true( $html_processor->expects_closer(), 'Expected native HTML processor regular element to expect a closer.' );
assert_same( null, $html_processor->get_last_error(), 'Expected native HTML processor to report no parse error.' );
assert_same( null, $html_processor->get_unsupported_exception(), 'Expected native HTML processor to report no unsupported exception.' );
assert_false( $html_processor->has_self_closing_flag(), 'Expected regular HTML processor token not to report a self-closing flag.' );
assert_false( $html_processor->paused_at_incomplete_token(), 'Expected native HTML processor not to pause at an incomplete token after complete input.' );
assert_same( 3, $html_processor->get_current_depth(), 'Expected first HTML token depth.' );
assert_same( array( 'HTML', 'BODY', 'SECTION' ), $html_processor->get_breadcrumbs(), 'Expected first HTML token breadcrumbs.' );
assert_true( $html_processor->matches_breadcrumbs( array( 'section' ) ), 'Expected native HTML processor breadcrumbs to match a suffix.' );
assert_true( $html_processor->matches_breadcrumbs( array( 'body', 'section' ) ), 'Expected native HTML processor breadcrumbs to match a nested suffix.' );
assert_true( $html_processor->matches_breadcrumbs( array( '*', 'SECTION' ) ), 'Expected native HTML processor breadcrumbs to match wildcard path components.' );
assert_false( $html_processor->matches_breadcrumbs( array( 'main', 'section' ) ), 'Expected native HTML processor breadcrumbs not to match a different path.' );
assert_same( "#tag\x1fSECTION\x1f0\x1fHTML\x1fBODY\x1fSECTION", $html_processor->current_token_metadata(), 'Expected batched native HTML token metadata.' );
assert_true( $html_processor->next_token(), 'Expected second HTML token.' );
assert_same( '#tag', $html_processor->get_token_type(), 'Expected paragraph token type.' );
assert_same( 'P', $html_processor->get_token_name(), 'Expected paragraph token name.' );
assert_true( $html_processor->next_token(), 'Expected HTML text token.' );
assert_same( '#text', $html_processor->get_token_type(), 'Expected HTML text token type.' );
assert_same( '#text', $html_processor->get_token_name(), 'Expected HTML text token name.' );
assert_same( null, $html_processor->get_tag(), 'Expected HTML processor text token to have no tag name.' );
assert_same( null, $html_processor->get_attribute_names_with_prefix( 'data-' ), 'Expected HTML processor text token to have no prefix names.' );
assert_same( null, $html_processor->count_attribute_names_with_prefix( 'data-' ), 'Expected HTML processor text token to have no prefix-name count.' );
assert_false( $html_processor->remove_attribute( 'data-id' ), 'Expected HTML processor text token attribute removal to fail.' );
assert_false( $html_processor->expects_closer(), 'Expected native HTML processor text token not to expect a closer.' );
assert_same( 'Hello', $html_processor->get_modifiable_text(), 'Expected HTML text content.' );
assert_same( array( 'HTML', 'BODY', 'SECTION', 'P', '#text' ), $html_processor->get_breadcrumbs(), 'Expected HTML text breadcrumbs.' );
assert_false( $html_processor->matches_breadcrumbs( array( '#text' ) ), 'Expected native HTML processor breadcrumbs not to match non-tag tokens.' );
assert_true( $html_processor->next_token(), 'Expected HTML comment token.' );
assert_same( '#comment', $html_processor->get_token_type(), 'Expected HTML comment token type.' );
assert_same( '#comment', $html_processor->get_token_name(), 'Expected HTML comment token name.' );
assert_same( null, $html_processor->get_qualified_tag_name(), 'Expected HTML processor qualified tag name to return null on comments.' );
assert_same( null, $html_processor->get_qualified_attribute_name( 'class' ), 'Expected HTML processor qualified attribute name to return null on comments.' );
assert_same( 'note', $html_processor->get_modifiable_text(), 'Expected HTML comment content.' );
assert_same( 'COMMENT_AS_HTML_COMMENT', $html_processor->get_comment_type(), 'Expected HTML comment type.' );
assert_same( 'note', $html_processor->get_full_comment_text(), 'Expected HTML full comment text.' );
assert_same( array( 'HTML', 'BODY', 'SECTION', 'P', '#comment' ), $html_processor->get_breadcrumbs(), 'Expected HTML comment breadcrumbs.' );

$html_self_closing_processor = WP_HTML_Native_Processor::create_fragment( '<img /><span></span>' );
assert_true( $html_self_closing_processor->next_tag(), 'Expected HTML processor self-closing fixture image tag.' );
assert_true( $html_self_closing_processor->has_self_closing_flag(), 'Expected native HTML processor to detect the self-closing flag.' );
assert_false( $html_self_closing_processor->expects_closer(), 'Expected native HTML processor self-closing fixture image tag not to expect a closer.' );
assert_true( $html_self_closing_processor->next_tag(), 'Expected HTML processor non-self-closing fixture span tag.' );
assert_false( $html_self_closing_processor->has_self_closing_flag(), 'Expected native HTML processor to reject missing self-closing flag.' );
assert_true( $html_self_closing_processor->expects_closer(), 'Expected native HTML processor span tag to expect a closer.' );

$html_bookmark_processor = WP_HTML_Native_Processor::create_fragment( '<main><section><p>Text</p></section><footer></footer></main>' );
assert_false( $html_bookmark_processor->set_bookmark( 'before-token' ), 'Expected native HTML processor bookmarks to require a current token.' );
assert_true( $html_bookmark_processor->next_tag(), 'Expected HTML processor bookmark fixture main tag.' );
assert_true( $html_bookmark_processor->next_tag(), 'Expected HTML processor bookmark fixture section tag.' );
assert_true( $html_bookmark_processor->set_bookmark( 'section' ), 'Expected native HTML processor bookmark to be set.' );
assert_true( $html_bookmark_processor->has_bookmark( 'section' ), 'Expected native HTML processor bookmark to exist.' );
assert_true( $html_bookmark_processor->next_tag(), 'Expected HTML processor bookmark fixture paragraph tag.' );
assert_same( 'P', $html_bookmark_processor->get_tag(), 'Expected native HTML processor cursor before seeking.' );
assert_true( $html_bookmark_processor->seek( 'section' ), 'Expected native HTML processor bookmark seek to succeed.' );
assert_same( 'SECTION', $html_bookmark_processor->get_tag(), 'Expected native HTML processor cursor after seeking.' );
assert_same( array( 'HTML', 'BODY', 'MAIN', 'SECTION' ), $html_bookmark_processor->get_breadcrumbs(), 'Expected native HTML processor breadcrumbs after seeking.' );
assert_true( $html_bookmark_processor->release_bookmark( 'section' ), 'Expected native HTML processor bookmark release to succeed.' );
assert_false( $html_bookmark_processor->has_bookmark( 'section' ), 'Expected native HTML processor bookmark to be released.' );
assert_false( $html_bookmark_processor->seek( 'section' ), 'Expected native HTML processor released bookmark seek to fail.' );

$html_class_processor = WP_HTML_Native_Processor::create_fragment( '<div class="free &lt;egg&lt; free lang-en">Text</div>' );
assert_true( $html_class_processor->next_tag(), 'Expected HTML processor class-list fixture tag.' );
assert_same( array( 'free', '<egg<', 'lang-en' ), $html_class_processor->class_list(), 'Expected native HTML processor class list to decode and deduplicate classes.' );
assert_true( $html_class_processor->has_class( '<egg<' ), 'Expected native HTML processor class lookup to find decoded classes.' );
assert_false( $html_class_processor->has_class( 'missing' ), 'Expected native HTML processor class lookup to reject missing classes.' );
assert_true( $html_class_processor->next_token(), 'Expected HTML processor class-list fixture text token.' );
assert_same( null, $html_class_processor->class_list(), 'Expected native HTML processor class list to return null on text tokens.' );
assert_same( null, $html_class_processor->has_class( 'free' ), 'Expected native HTML processor class lookup to return null on text tokens.' );
assert_true( $html_class_processor->set_modifiable_text( 'Updated & <text>' ), 'Expected native HTML processor text update to be queued.' );
assert_same( 'Updated & <text>', $html_class_processor->get_modifiable_text(), 'Expected native HTML processor text reads to reflect queued updates.' );
assert_same( '<div class="free &lt;egg&lt; free lang-en">Updated &amp; &lt;text&gt;</div>', $html_class_processor->get_updated_html(), 'Expected native HTML processor text update to serialize escaped text.' );

$html_null_text_processor = WP_HTML_Native_Processor::create_fragment( "\0\0Text" );
assert_true( $html_null_text_processor->next_token(), 'Expected native HTML processor text token after leading null bytes.' );
assert_same( 'Text', $html_null_text_processor->get_modifiable_text(), 'Expected native HTML processor to omit leading null bytes from text.' );
assert_false( $html_null_text_processor->subdivide_text_appropriately(), 'Expected native HTML processor generic text not to subdivide after null omission.' );

$html_whitespace_text_processor = WP_HTML_Native_Processor::create_fragment( " \r\n\tMore" );
assert_true( $html_whitespace_text_processor->next_token(), 'Expected native HTML processor whitespace-prefix text token.' );
assert_true( $html_whitespace_text_processor->subdivide_text_appropriately(), 'Expected native HTML processor to split leading whitespace text.' );
assert_same( " \n\t", $html_whitespace_text_processor->get_modifiable_text(), 'Expected native HTML processor whitespace-prefix text to normalize newlines.' );
assert_true( $html_whitespace_text_processor->next_token(), 'Expected native HTML processor text remainder after whitespace subdivision.' );
assert_same( 'More', $html_whitespace_text_processor->get_modifiable_text(), 'Expected native HTML processor text remainder after whitespace subdivision.' );

$html_serializer_processor = WP_HTML_Native_Processor::create_fragment( '<a href=#anchor v=5 href="/" enabled>One</a another v=5><!--' );
assert_same(
	'<a href="#anchor" v="5" enabled>One</a>',
	$html_serializer_processor->serialize(),
	'Expected native HTML processor serialize() to return normalized PHP serializer output.'
);
assert_false( $html_serializer_processor->next_token(), 'Expected native HTML processor to be exhausted after serialize().' );
assert_same(
	'<a href="#anchor" v="5" enabled>One</a>',
	WP_HTML_Native_Processor::normalize( '<a href=#anchor v=5 href="/" enabled>One</a another v=5><!--' ),
	'Expected native HTML processor normalize() to delegate to the PHP fragment serializer.'
);
$html_started_serializer_processor = WP_HTML_Native_Processor::create_fragment( '<p>Hi</p>' );
assert_true( $html_started_serializer_processor->next_token(), 'Expected native HTML processor serialize fixture to start scanning.' );
assert_same( null, $html_started_serializer_processor->serialize(), 'Expected native HTML processor serialize() to reject already-started processors.' );

$html_funky_comments = WP_HTML_Native_Processor::create_fragment( '<?pi data?><!notdoctype><p>Text</p>' );
assert_true( $html_funky_comments->next_token(), 'Expected HTML processor PI-looking comment.' );
assert_same( '#comment', $html_funky_comments->get_token_type(), 'Expected HTML processor PI-looking token type.' );
assert_same( ' data', $html_funky_comments->get_modifiable_text(), 'Expected HTML processor PI-looking comment text.' );
assert_same( 'COMMENT_AS_PI_NODE_LOOKALIKE', $html_funky_comments->get_comment_type(), 'Expected HTML processor PI-looking comment type.' );
assert_same( '?pi data?', $html_funky_comments->get_full_comment_text(), 'Expected HTML processor PI-looking full comment text.' );
assert_same( array( 'HTML', 'BODY', '#comment' ), $html_funky_comments->get_breadcrumbs(), 'Expected HTML processor PI-looking breadcrumbs.' );
assert_true( $html_funky_comments->next_token(), 'Expected HTML processor invalid declaration comment.' );
assert_same( '#comment', $html_funky_comments->get_token_type(), 'Expected HTML processor invalid declaration token type.' );
assert_same( 'notdoctype', $html_funky_comments->get_modifiable_text(), 'Expected HTML processor invalid declaration comment text.' );
assert_same( 'COMMENT_AS_INVALID_HTML', $html_funky_comments->get_comment_type(), 'Expected HTML processor invalid declaration comment type.' );
assert_same( 'notdoctype', $html_funky_comments->get_full_comment_text(), 'Expected HTML processor invalid declaration full comment text.' );
assert_true( $html_funky_comments->next_tag(), 'Expected HTML processor next_tag() to skip funky comments.' );
assert_same( 'P', $html_funky_comments->get_token_name(), 'Expected HTML processor tag after funky comments.' );

while ( $html_processor->next_token() && ! $html_processor->is_tag_closer() ) {
	continue;
}
assert_same( true, $html_processor->is_tag_closer(), 'Expected HTML next_token() to expose closing tags.' );
assert_same( 'EM', $html_processor->get_token_name(), 'Expected HTML closing token name.' );
assert_same( 4, $html_processor->get_current_depth(), 'Expected HTML closer depth.' );
assert_same( array( 'HTML', 'BODY', 'SECTION', 'P' ), $html_processor->get_breadcrumbs(), 'Expected HTML closer breadcrumbs.' );

$xml_class = 'WordPress\\XML\\NativeXMLProcessor';
$xml_streaming = $xml_class::create_for_streaming( '<root><item /></root>', null, 'UTF-8', array() );
assert_same( $xml_class, get_class( $xml_streaming ), 'Expected native XML streaming factory to return a native processor for supported UTF-8 input.' );
assert_true( $xml_streaming->next_tag(), 'Expected native XML streaming factory processor to expose the root tag.' );
assert_same( 'root', $xml_streaming->get_token_name(), 'Expected native XML streaming factory root tag name.' );
assert_same( null, $xml_class::create_for_streaming( '<root />', null, 'ISO-8859-1', array() ), 'Expected native XML streaming factory to reject unsupported encodings.' );

$xml_streaming_incomplete = $xml_class::create_for_streaming( '<root', null, 'UTF-8', array() );
assert_false( $xml_streaming_incomplete->next_token(), 'Expected incomplete native XML streaming input to pause token scanning.' );
assert_same( null, $xml_streaming_incomplete->get_last_error(), 'Expected incomplete native XML streaming input not to report a syntax error before input is finished.' );
assert_true( $xml_streaming_incomplete->is_paused_at_incomplete_input(), 'Expected native XML streaming processor to pause at incomplete input.' );
assert_true( $xml_streaming_incomplete->is_expecting_more_input(), 'Expected native XML streaming processor to expect more input.' );
assert_true( $xml_streaming_incomplete->append_bytes( '><item id="1" /></root>' ), 'Expected native XML streaming processor to accept appended bytes.' );
assert_true( $xml_streaming_incomplete->next_token(), 'Expected native XML streaming processor to resume at root after append.' );
assert_same( 'root', $xml_streaming_incomplete->get_token_name(), 'Expected native XML streaming resumed root tag.' );
assert_true( $xml_streaming_incomplete->next_token(), 'Expected native XML streaming processor to expose appended item.' );
assert_same( 'item', $xml_streaming_incomplete->get_token_name(), 'Expected native XML streaming appended item tag.' );
assert_same( '1', $xml_streaming_incomplete->get_attribute( 'id' ), 'Expected native XML streaming appended item attribute.' );
assert_true( $xml_streaming_incomplete->next_token(), 'Expected native XML streaming processor to expose appended root closer.' );
assert_true( $xml_streaming_incomplete->is_tag_closer(), 'Expected native XML streaming appended root closer.' );

$xml_streaming_prior_token_incomplete = $xml_class::create_for_streaming( '<root><item', null, 'UTF-8', array() );
assert_true( $xml_streaming_prior_token_incomplete->next_token(), 'Expected native XML streaming prior-token source root.' );
assert_same( 'root', $xml_streaming_prior_token_incomplete->get_token_name(), 'Expected native XML streaming prior-token root tag.' );
assert_false( $xml_streaming_prior_token_incomplete->next_token(), 'Expected native XML streaming input to pause at an incomplete child tag.' );
assert_same( null, $xml_streaming_prior_token_incomplete->get_last_error(), 'Expected incomplete native XML streaming child tag not to report a syntax error before input is finished.' );
assert_true( $xml_streaming_prior_token_incomplete->is_paused_at_incomplete_input(), 'Expected native XML streaming processor to pause at incomplete child input.' );
assert_true( $xml_streaming_prior_token_incomplete->append_bytes( ' id="1" /></root>' ), 'Expected native XML streaming processor to accept bytes after prior tokens.' );
assert_true( $xml_streaming_prior_token_incomplete->next_token(), 'Expected native XML streaming processor to resume at the incomplete child tag.' );
assert_same( 'item', $xml_streaming_prior_token_incomplete->get_token_name(), 'Expected native XML streaming resumed child tag.' );
assert_same( '1', $xml_streaming_prior_token_incomplete->get_attribute( 'id' ), 'Expected native XML streaming resumed child attribute.' );
assert_true( $xml_streaming_prior_token_incomplete->next_token(), 'Expected native XML streaming processor to expose root closer after resumed child.' );
assert_true( $xml_streaming_prior_token_incomplete->is_tag_closer(), 'Expected native XML streaming resumed root closer.' );

$xml_streaming_batch_cases = array(
	array( 'next_token_compact_summary_batch', array( 10 ), false ),
	array( 'next_tag_compact_summary_batch', array( 10, 'id' ), false ),
	array( 'next_tag_count_batch', array( 10, 'id' ), false ),
	array( 'next_matching_tag_compact_summary_batch', array( 10, '', 'item', 'id' ), true ),
	array( 'next_matching_tag_count_batch', array( 10, '', 'item', 'id' ), false ),
);
foreach ( $xml_streaming_batch_cases as $xml_streaming_batch_case ) {
	$xml_streaming_batch = $xml_class::create_for_streaming( '<root><item', null, 'UTF-8', array() );
	$xml_batch_result    = call_user_func_array( array( $xml_streaming_batch, $xml_streaming_batch_case[0] ), $xml_streaming_batch_case[1] );
	if ( $xml_streaming_batch_case[2] ) {
		assert_same( null, $xml_batch_result, 'Expected native XML streaming batch case to return no rows before the matching tag is complete.' );
	} else {
		assert_true( is_string( $xml_batch_result ) && '' !== $xml_batch_result, 'Expected native XML streaming batch case to expose prior complete tokens.' );
	}
	assert_same( null, $xml_streaming_batch->get_last_error(), 'Expected native XML streaming batch case not to report an incomplete input error.' );
	assert_true( $xml_streaming_batch->is_paused_at_incomplete_input(), 'Expected native XML streaming batch case to pause at incomplete input.' );
	assert_true( $xml_streaming_batch->append_bytes( ' id="1" /></root>' ), 'Expected native XML streaming batch case to accept appended bytes.' );
	assert_true( $xml_streaming_batch->next_token(), 'Expected native XML streaming batch case to resume at the incomplete child tag.' );
	assert_same( 'item', $xml_streaming_batch->get_token_name(), 'Expected native XML streaming batch case resumed child tag.' );
	assert_same( '1', $xml_streaming_batch->get_attribute( 'id' ), 'Expected native XML streaming batch case resumed child attribute.' );
}

$xml_streaming_finished_incomplete = $xml_class::create_for_streaming( '<root><item', null, 'UTF-8', array() );
assert_true( $xml_streaming_finished_incomplete->next_token(), 'Expected native XML streaming finished-incomplete source root.' );
assert_false( $xml_streaming_finished_incomplete->next_token(), 'Expected native XML streaming finished-incomplete source to pause at child tag.' );
assert_same( null, $xml_streaming_finished_incomplete->get_last_error(), 'Expected native XML streaming finished-incomplete source to defer syntax errors while paused.' );
assert_true( $xml_streaming_finished_incomplete->is_paused_at_incomplete_input(), 'Expected native XML streaming finished-incomplete source to pause before input_finished().' );
$xml_streaming_finished_incomplete->input_finished();
assert_same( null, $xml_streaming_finished_incomplete->get_last_error(), 'Expected native XML streaming finished-incomplete source to defer syntax errors until the next scan after input_finished().' );
assert_false( $xml_streaming_finished_incomplete->is_paused_at_incomplete_input(), 'Expected native XML streaming finished-incomplete source to clear pause state after input_finished().' );
assert_false( $xml_streaming_finished_incomplete->is_expecting_more_input(), 'Expected native XML streaming finished-incomplete source not to expect more input after input_finished().' );
assert_false( $xml_streaming_finished_incomplete->is_finished(), 'Expected native XML streaming finished-incomplete source not to finish before reporting the syntax error.' );
assert_false( $xml_streaming_finished_incomplete->next_token(), 'Expected native XML streaming finished-incomplete source to report a syntax error on the next scan.' );
assert_same( 'Unclosed XML tag.', $xml_streaming_finished_incomplete->get_last_error(), 'Expected native XML streaming finished-incomplete source syntax error.' );
assert_false( $xml_streaming_finished_incomplete->is_finished(), 'Expected native XML streaming finished-incomplete source not to be marked finished after syntax error.' );

$xml_without_document_element_cases = array(
	'',
	" \n\t",
	'<!--c-->',
	'<?xml version="1.0"?>',
);
foreach ( $xml_without_document_element_cases as $xml_without_document_element ) {
	$xml_no_document_element = $xml_class::create_from_string( $xml_without_document_element );
	while ( $xml_no_document_element->next_token() ) {
		continue;
	}
	assert_same( 'Missing XML document element.', $xml_no_document_element->get_last_error(), 'Expected native XML documents without a document element to report an error.' );
}

$xml_leading_whitespace = $xml_class::create_from_string( " \n\t<root />" );
assert_true( $xml_leading_whitespace->next_token(), 'Expected native XML leading-whitespace case to expose root first.' );
assert_same( 'root', $xml_leading_whitespace->get_token_name(), 'Expected native XML leading whitespace to be skipped before root.' );

$xml_comment_leading_whitespace = $xml_class::create_from_string( "<!--c-->\n<root />" );
assert_true( $xml_comment_leading_whitespace->next_token(), 'Expected native XML comment before root.' );
assert_same( '#comment', $xml_comment_leading_whitespace->get_token_name(), 'Expected native XML comment before root token.' );
assert_true( $xml_comment_leading_whitespace->next_token(), 'Expected native XML root after leading comment whitespace.' );
assert_same( 'root', $xml_comment_leading_whitespace->get_token_name(), 'Expected native XML whitespace after leading comment to be skipped before root.' );

$xml_tag_modifiable_text = $xml_class::create_from_string( '<root><item id="1">Text &amp; More</item></root>' );
assert_true( $xml_tag_modifiable_text->next_token(), 'Expected native XML root tag before checking tag modifiable text.' );
assert_same( '<item id="1">Text & More</item></', $xml_tag_modifiable_text->get_modifiable_text(), 'Expected native XML root modifiable text to include decoded inner source.' );
assert_true( $xml_tag_modifiable_text->next_token(), 'Expected native XML item tag before checking tag modifiable text.' );
assert_same( 'Text & More</', $xml_tag_modifiable_text->get_modifiable_text(), 'Expected native XML item modifiable text to include decoded inner text.' );

$xml_streaming_incomplete_token_cases = array(
	array( '<root><!--note', '--></root>' ),
	array( '<root><![CDATA[x', ']]></root>' ),
	array( '<!DOCTYPE root', '><root />' ),
	array( '<?xml version="1.0"', '?><root />' ),
	array( '<root><?xml-stylesheet href="x"', '?></root>' ),
	array( '<root><!ENTITY x', '><child /></root>' ),
	array( '<', 'root />' ),
	array( '<root a="x', '" />' ),
	array( '<root a', '="x" />' ),
	array( '<root a=', '"x" />' ),
);
foreach ( $xml_streaming_incomplete_token_cases as $xml_streaming_incomplete_token_case ) {
	$xml_streaming_incomplete_token = $xml_class::create_for_streaming( $xml_streaming_incomplete_token_case[0], null, 'UTF-8', array() );
	while ( $xml_streaming_incomplete_token->next_token() ) {
		// Advance to the incomplete token.
	}
	assert_true( $xml_streaming_incomplete_token->is_paused_at_incomplete_input(), 'Expected native XML streaming incomplete token case to pause.' );
	assert_true( $xml_streaming_incomplete_token->append_bytes( $xml_streaming_incomplete_token_case[1] ), 'Expected native XML streaming incomplete token case to accept appended bytes.' );
	assert_true( $xml_streaming_incomplete_token->next_token(), 'Expected native XML streaming incomplete token case to resume after appended bytes.' );
}

$xml_streaming_resume_source = '<root><item id="7">Text</item></root>';
$xml_streaming_resume = $xml_class::create_for_streaming( $xml_streaming_resume_source, null, 'UTF-8', array() );
assert_true( $xml_streaming_resume->next_tag(), 'Expected native XML streaming resume source root tag.' );
assert_true( $xml_streaming_resume->next_tag(), 'Expected native XML streaming resume source item tag.' );
$xml_streaming_resume_offset = $xml_streaming_resume->get_token_byte_offset_in_the_input_stream();
$xml_streaming_resume_cursor = $xml_streaming_resume->get_reentrancy_cursor();
$xml_streaming_resumed = $xml_class::create_for_streaming( substr( $xml_streaming_resume_source, $xml_streaming_resume_offset ), $xml_streaming_resume_cursor, 'UTF-8', array() );
assert_same( $xml_class, get_class( $xml_streaming_resumed ), 'Expected native XML streaming cursor to create a native processor.' );
assert_true( $xml_streaming_resumed->next_tag(), 'Expected native XML streaming cursor to resume at the sliced item tag.' );
assert_same( 'item', $xml_streaming_resumed->get_token_name(), 'Expected native XML streaming cursor item tag name.' );
assert_same( '7', $xml_streaming_resumed->get_attribute( 'id' ), 'Expected native XML streaming cursor item attribute.' );
assert_same( null, $xml_class::create_for_streaming( '<item />', 'not-a-native-cursor', 'UTF-8', array() ), 'Expected native XML streaming factory to reject unknown cursor formats.' );

$xml_streaming_sibling_resume_source = '<root><item id="7">Text</item><tail /></root>';
$xml_streaming_sibling_resume = $xml_class::create_for_streaming( $xml_streaming_sibling_resume_source, null, 'UTF-8', array() );
assert_true( $xml_streaming_sibling_resume->next_tag(), 'Expected native XML streaming sibling resume source root tag.' );
assert_true( $xml_streaming_sibling_resume->next_tag(), 'Expected native XML streaming sibling resume source item tag.' );
$xml_streaming_sibling_resume_offset = $xml_streaming_sibling_resume->get_token_byte_offset_in_the_input_stream();
$xml_streaming_sibling_resume_cursor = $xml_streaming_sibling_resume->get_reentrancy_cursor();
$xml_streaming_sibling_resumed = $xml_class::create_for_streaming( substr( $xml_streaming_sibling_resume_source, $xml_streaming_sibling_resume_offset ), $xml_streaming_sibling_resume_cursor, 'UTF-8', array() );
assert_true( $xml_streaming_sibling_resumed->next_tag(), 'Expected native XML streaming cursor to resume at the sliced sibling item tag.' );
assert_same( 'item', $xml_streaming_sibling_resumed->get_token_name(), 'Expected native XML streaming sibling cursor item tag.' );
assert_true( $xml_streaming_sibling_resumed->next_token(), 'Expected native XML streaming sibling cursor item text.' );
assert_same( 'Text', $xml_streaming_sibling_resumed->get_modifiable_text(), 'Expected native XML streaming sibling cursor text.' );
assert_true( $xml_streaming_sibling_resumed->next_token(), 'Expected native XML streaming sibling cursor item closer.' );
assert_true( $xml_streaming_sibling_resumed->is_tag_closer(), 'Expected native XML streaming sibling cursor item closer.' );
assert_true( $xml_streaming_sibling_resumed->next_token(), 'Expected native XML streaming sibling cursor tail tag.' );
assert_same( 'tail', $xml_streaming_sibling_resumed->get_token_name(), 'Expected native XML streaming sibling cursor tail tag name.' );
assert_true( $xml_streaming_sibling_resumed->is_empty_element(), 'Expected native XML streaming sibling cursor tail to remain empty.' );
assert_false( $xml_streaming_sibling_resumed->next_token(), 'Expected native XML streaming sibling cursor to reject the pre-cursor parent closer.' );
assert_same( 'syntax', $xml_streaming_sibling_resumed->get_last_error(), 'Expected native XML streaming sibling cursor parent closer syntax error.' );

$xml_prolog = $xml_class::create_from_string( '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE root><root />' );
assert_true( $xml_prolog->next_token(), 'Expected XML declaration token.' );
assert_same( '#xml-declaration', $xml_prolog->get_token_type(), 'Expected XML declaration token type.' );
assert_same( '#xml-declaration', $xml_prolog->get_token_name(), 'Expected XML declaration token name.' );
assert_same( 'xml version="1.0" encoding="UTF-8"', $xml_prolog->get_modifiable_text(), 'Expected XML declaration text.' );
assert_same( '1.0', $xml_prolog->get_attribute( 'version' ), 'Expected XML declaration version attribute.' );
assert_same( 'UTF-8', $xml_prolog->get_attribute( 'encoding' ), 'Expected XML declaration encoding attribute.' );
assert_same( 0, $xml_prolog->get_current_depth(), 'Expected XML declaration depth.' );
assert_same( array(), $xml_prolog->get_breadcrumbs(), 'Expected XML declaration breadcrumbs.' );
assert_true( $xml_prolog->next_token(), 'Expected XML DOCTYPE token.' );
assert_same( '#doctype', $xml_prolog->get_token_type(), 'Expected XML DOCTYPE token type.' );
assert_same( '#doctype', $xml_prolog->get_token_name(), 'Expected XML DOCTYPE token name.' );
assert_same( '', $xml_prolog->get_modifiable_text(), 'Expected XML DOCTYPE text.' );
assert_same( 'root', $xml_prolog->get_doctype_name(), 'Expected XML DOCTYPE name.' );
assert_same( null, $xml_prolog->get_system_literal(), 'Expected XML DOCTYPE without SYSTEM literal.' );
assert_same( null, $xml_prolog->get_pubid_literal(), 'Expected XML DOCTYPE without PUBLIC literal.' );
assert_true( $xml_prolog->next_tag(), 'Expected XML next_tag() to skip prolog tokens.' );
assert_same( 'root', $xml_prolog->get_token_name(), 'Expected XML root token after prolog.' );

$xml_doctype_system = $xml_class::create_from_string( '<!DOCTYPE root SYSTEM "https://example.com/root.dtd"><root />' );
assert_true( $xml_doctype_system->next_token(), 'Expected XML SYSTEM DOCTYPE token.' );
assert_same( 'root', $xml_doctype_system->get_doctype_name(), 'Expected XML SYSTEM DOCTYPE name.' );
assert_same( 'https://example.com/root.dtd', $xml_doctype_system->get_system_literal(), 'Expected XML SYSTEM literal.' );
assert_same( null, $xml_doctype_system->get_pubid_literal(), 'Expected no XML PUBLIC literal in SYSTEM DOCTYPE.' );

$xml_doctype_public = $xml_class::create_from_string( '<!DOCTYPE root PUBLIC "-//Example//DTD Root 1.0//EN" "https://example.com/root.dtd"><root />' );
assert_true( $xml_doctype_public->next_token(), 'Expected XML PUBLIC DOCTYPE token.' );
assert_same( 'root', $xml_doctype_public->get_doctype_name(), 'Expected XML PUBLIC DOCTYPE name.' );
assert_same( 'https://example.com/root.dtd', $xml_doctype_public->get_system_literal(), 'Expected XML PUBLIC system literal.' );
assert_same( '-//Example//DTD Root 1.0//EN', $xml_doctype_public->get_pubid_literal(), 'Expected XML PUBLIC identifier literal.' );

$xml_source = '<root xmlns:wp="https://wordpress.org"><wp:item wp:id="7" /></root>';
$xml        = $xml_class::create_from_string( $xml_source );
assert_true( $xml->next_token(), 'Expected first XML token.' );
assert_same( 'root', $xml->get_token_name(), 'Expected first XML token name.' );
assert_same( "#tag\x1froot\x1froot\x1f\x1froot\x1f0\x1f0\x1f1", $xml->current_token_metadata(), 'Expected batched native XML root token metadata.' );
assert_same( 'root', $xml->get_tag_local_name(), 'Expected first XML local name.' );
assert_same( 'root', $xml->get_tag_namespace_and_local_name(), 'Expected first XML namespace/local name.' );
assert_same( 0, $xml->get_token_byte_offset_in_the_input_stream(), 'Expected XML root token byte offset.' );
assert_true( $xml->expects_closer(), 'Expected XML root opener to expect a closer.' );
assert_true( $xml->is_tag_opener(), 'Expected XML root token to be a tag opener.' );
assert_same( 1, $xml->get_current_depth(), 'Expected first XML token depth.' );
assert_same( array( array( '', 'root' ) ), $xml->get_breadcrumbs(), 'Expected first XML breadcrumbs.' );
assert_true( $xml->next_tag(), 'Expected second XML token.' );
assert_same( 'item', $xml->get_token_name(), 'Expected second XML local token name.' );
assert_same( 'item', $xml->get_tag_local_name(), 'Expected second XML local name.' );
assert_same( 'https://wordpress.org', $xml->get_tag_namespace(), 'Expected second XML namespace.' );
assert_same( '{https://wordpress.org}item', $xml->get_tag_namespace_and_local_name(), 'Expected second XML namespace/local name.' );
assert_same( strpos( $xml_source, '<wp:item' ), $xml->get_token_byte_offset_in_the_input_stream(), 'Expected XML item token byte offset.' );
assert_same( '7', $xml->get_attribute( '{https://wordpress.org}id' ), 'Expected XML namespaced attribute value.' );
assert_same( '7', $xml->get_attribute( 'https://wordpress.org', 'id' ), 'Expected XML namespaced attribute value through the public two-argument shape.' );
assert_same( true, $xml->is_empty_element(), 'Expected XML empty-element marker.' );
assert_false( $xml->expects_closer(), 'Expected XML empty element not to expect a closer.' );
assert_false( $xml->is_tag_opener(), 'Expected XML empty element not to be a tag opener.' );
assert_same( 2, $xml->get_current_depth(), 'Expected second XML token depth.' );
assert_same(
	array( array( '', 'root' ), array( 'https://wordpress.org', 'item' ) ),
	$xml->get_breadcrumbs(),
	'Expected second XML breadcrumbs.'
);
assert_true( $xml->matches_breadcrumbs( array( 'root', 'item' ) ), 'Expected XML breadcrumbs to match the full path.' );
assert_true( $xml->matches_breadcrumbs( array( '*', 'item' ) ), 'Expected XML breadcrumbs to match wildcard path components.' );
assert_true( $xml->matches_breadcrumbs( array( 'item' ) ), 'Expected XML breadcrumbs to match the current tag suffix.' );
assert_false( $xml->matches_breadcrumbs( array( 'root', 'missing' ) ), 'Expected XML breadcrumbs not to match a different path.' );
assert_same(
	"#tag\x1fitem\x1fitem\x1fhttps://wordpress.org\x1f{https://wordpress.org}item\x1f0\x1f1\x1f2\x1f{https://wordpress.org}id\x1f7",
	$xml->current_token_metadata(),
	'Expected batched native XML namespaced token metadata.'
);
assert_same( null, $xml->get_last_error(), 'Expected no XML parse error.' );
assert_same( null, $xml->get_exception(), 'Expected no native XML unsupported exception.' );
assert_false( $xml->set_modifiable_text( 'updated' ), 'Expected read-only native XML mutation to reject text updates.' );
assert_true( $xml->set_attribute( '', 'id', 'updated & more' ), 'Expected native XML unprefixed attribute updates to be queued.' );
assert_same( 'updated & more', $xml->get_attribute( '', 'id' ), 'Expected native XML updated unprefixed attribute read.' );
assert_true( $xml->remove_attribute( 'https://wordpress.org', 'id' ), 'Expected native XML namespaced attribute removal.' );
assert_same(
	'<root xmlns:wp="https://wordpress.org"><wp:item id="updated &amp; more"  /></root>',
	$xml->get_updated_xml(),
	'Expected native XML updated serialization to include unprefixed updates and namespaced removal.'
);

$xml_attribute_update = $xml_class::create_from_string( '<root><item id="1" data-old="x">One</item><tail /></root>' );
assert_true( $xml_attribute_update->next_tag(), 'Expected native XML mutation fixture root tag.' );
assert_true( $xml_attribute_update->next_tag(), 'Expected native XML mutation fixture item tag.' );
assert_true( $xml_attribute_update->set_attribute( '', 'data-new', 'yes' ), 'Expected native XML unprefixed attribute insertion.' );
assert_true( $xml_attribute_update->set_attribute( '', 'id', '2 & more' ), 'Expected native XML unprefixed attribute replacement.' );
assert_true( $xml_attribute_update->remove_attribute( '', 'data-old' ), 'Expected native XML unprefixed attribute removal.' );
assert_same( 'yes', $xml_attribute_update->get_attribute( '', 'data-new' ), 'Expected native XML inserted attribute read.' );
assert_same( '2 & more', $xml_attribute_update->get_attribute( '', 'id' ), 'Expected native XML replaced attribute read.' );
assert_same(
	'<root><item data-new="yes" id="2 &amp; more" >One</item><tail /></root>',
	$xml_attribute_update->get_updated_xml(),
	'Expected native XML updated serialization to apply unprefixed attribute edits.'
);
assert_true( $xml_attribute_update->next_tag(), 'Expected native XML to continue after current-tag attribute edits.' );
assert_same( 'tail', $xml_attribute_update->get_token_name(), 'Expected native XML continuation after current-tag attribute edits.' );

$xml_namespaced_attribute_update = $xml_class::create_from_string( '<root xmlns:wp="https://wordpress.org"><item wp:id="1" /></root>' );
assert_true( $xml_namespaced_attribute_update->next_tag(), 'Expected native XML namespaced mutation fixture root tag.' );
assert_true( $xml_namespaced_attribute_update->next_tag(), 'Expected native XML namespaced mutation fixture item tag.' );
assert_true( $xml_namespaced_attribute_update->set_attribute( 'https://wordpress.org', 'id', '2 & more' ), 'Expected native XML namespaced attribute replacement.' );
assert_same( '2 & more', $xml_namespaced_attribute_update->get_attribute( 'https://wordpress.org', 'id' ), 'Expected native XML replaced namespaced attribute read.' );
assert_true( $xml_namespaced_attribute_update->set_attribute( 'https://wordpress.org', 'slug', 'post-2' ), 'Expected native XML namespaced attribute insertion.' );
assert_same( 'post-2', $xml_namespaced_attribute_update->get_attribute( 'https://wordpress.org', 'slug' ), 'Expected native XML inserted namespaced attribute read.' );
assert_true( $xml_namespaced_attribute_update->remove_attribute( 'https://wordpress.org', 'id' ), 'Expected native XML namespaced attribute removal.' );
assert_same( null, $xml_namespaced_attribute_update->get_attribute( 'https://wordpress.org', 'id' ), 'Expected native XML removed namespaced attribute read.' );
assert_false( $xml_namespaced_attribute_update->set_attribute( 'https://missing.example', 'id', '3' ), 'Expected native XML to reject namespaced attribute insertion without an in-scope prefix.' );
assert_same(
	'<root xmlns:wp="https://wordpress.org"><item wp:slug="post-2"  /></root>',
	$xml_namespaced_attribute_update->get_updated_xml(),
	'Expected native XML updated serialization to apply namespaced attribute edits.'
);
assert_same(
	'<root xmlns:wp="https://wordpress.org"><wp:item id="updated &amp; more"  /></root>',
	(string) $xml,
	'Expected native XML string cast to include unprefixed updates and namespaced removal.'
);

$xml_text_update = $xml_class::create_from_string( '<root>One<!--note--><![CDATA[raw]]><tail /></root>' );
assert_true( $xml_text_update->next_token(), 'Expected native XML text mutation fixture root tag.' );
assert_true( $xml_text_update->next_token(), 'Expected native XML text mutation fixture text token.' );
assert_true( $xml_text_update->set_modifiable_text( 'Two & <three>' ), 'Expected native XML text mutation to succeed.' );
assert_same( 'Two & <three>', $xml_text_update->get_modifiable_text(), 'Expected native XML updated text read.' );
assert_true( $xml_text_update->next_token(), 'Expected native XML text mutation fixture comment token.' );
assert_true( $xml_text_update->set_modifiable_text( 'comment & <tag>' ), 'Expected native XML comment mutation to succeed.' );
assert_same( 'comment & <tag>', $xml_text_update->get_modifiable_text(), 'Expected native XML updated comment read.' );
assert_true( $xml_text_update->next_token(), 'Expected native XML text mutation fixture CDATA token.' );
assert_true( $xml_text_update->set_modifiable_text( 'inside ]]> cdata' ), 'Expected native XML CDATA mutation to succeed.' );
assert_same( 'inside ]]> cdata', $xml_text_update->get_modifiable_text(), 'Expected native XML updated CDATA read.' );
assert_same(
	'<root>Two &amp; &lt;three&gt;<!--comment &amp; &lt;tag&gt;--><![CDATA[inside ]]&gt; cdata]]><tail /></root>',
	$xml_text_update->get_updated_xml(),
	'Expected native XML updated serialization to apply text, comment, and CDATA edits.'
);
assert_true( $xml_text_update->next_tag(), 'Expected native XML to continue after text mutations.' );
assert_same( 'tail', $xml_text_update->get_token_name(), 'Expected native XML continuation after text mutations.' );
assert_false( $xml_text_update->set_modifiable_text( 'nope' ), 'Expected native XML text mutation to reject tag tokens.' );

$xml_bookmark = $xml_class::create_from_string( '<root><item id="1" /><tail /></root>' );
assert_false( $xml_bookmark->set_bookmark( 'before-token' ), 'Expected native XML bookmarks to require a current token.' );
assert_true( $xml_bookmark->next_tag(), 'Expected XML bookmark fixture root tag.' );
assert_true( $xml_bookmark->next_tag(), 'Expected XML bookmark fixture item tag.' );
assert_true( $xml_bookmark->set_bookmark( 'item' ), 'Expected native XML bookmark to be set.' );
assert_true( $xml_bookmark->has_bookmark( 'item' ), 'Expected native XML bookmark to exist.' );
assert_true( $xml_bookmark->next_tag(), 'Expected XML bookmark fixture tail tag.' );
assert_same( 'tail', $xml_bookmark->get_token_name(), 'Expected native XML cursor before seeking.' );
assert_true( $xml_bookmark->seek( 'item' ), 'Expected native XML bookmark seek to succeed.' );
assert_same( 'item', $xml_bookmark->get_token_name(), 'Expected native XML cursor after seeking.' );
assert_same(
	array( array( '', 'root' ), array( '', 'item' ) ),
	$xml_bookmark->get_breadcrumbs(),
	'Expected native XML breadcrumbs after seeking.'
);
assert_true( $xml_bookmark->release_bookmark( 'item' ), 'Expected native XML bookmark release to succeed.' );
assert_false( $xml_bookmark->has_bookmark( 'item' ), 'Expected native XML bookmark to be released.' );
assert_false( $xml_bookmark->seek( 'item' ), 'Expected native XML released bookmark seek to fail.' );

$xml_compact = $xml_class::create_from_string( '<root id="root"><item /></root>' );
assert_false( $xml_compact->is_expecting_more_input(), 'Expected native XML processor to have complete input.' );
assert_false( $xml_compact->is_paused_at_incomplete_input(), 'Expected native XML processor not to be paused on incomplete input.' );
assert_false( $xml_compact->append_bytes( '<extra />' ), 'Expected native XML complete-input processor to reject appended bytes.' );
$xml_compact->input_finished();
assert_false( $xml_compact->is_expecting_more_input(), 'Expected native XML input_finished() to keep complete-input state.' );
assert_same( "t\x1froot\x1f\x1f00\x1f1\x1f1root", $xml_compact->next_token_compact_summary(), 'Expected compact native XML token summary with cached id attribute.' );
assert_same( "t\x1fitem\x1f\x1f01\x1f2\x1f0", $xml_compact->next_token_compact_summary(), 'Expected compact native XML token summary without cached id attribute.' );

$xml_token_summary_batch = $xml_class::create_from_string( '<root id="root"><item id="7" /></root>' );
assert_same(
	array(
		array(
			'token_type'                   => '#tag',
			'token_name'                   => 'root',
			'tag_local_name'               => 'root',
			'tag_namespace'                => '',
			'tag_namespace_and_local_name' => 'root',
			'is_tag_closer'                => false,
			'is_empty_element'             => false,
			'current_depth'                => 1,
			'id'                           => 'root',
		),
		array(
			'token_type'                   => '#tag',
			'token_name'                   => 'item',
			'tag_local_name'               => 'item',
			'tag_namespace'                => '',
			'tag_namespace_and_local_name' => 'item',
			'is_tag_closer'                => false,
			'is_empty_element'             => true,
			'current_depth'                => 2,
			'id'                           => '7',
		),
	),
	$xml_token_summary_batch->next_token_summary_batch( 2 ),
	'Expected native XML public token summary batch rows.'
);

$xml_tag_summary_batch = $xml_class::create_from_string( '<root id="root"><item id="7" /><empty /></root>' );
assert_same(
	array(
		array(
			'tag_local_name'               => 'root',
			'tag_namespace'                => '',
			'tag_namespace_and_local_name' => 'root',
			'is_empty_element'             => false,
			'current_depth'                => 1,
			'id'                           => 'root',
		),
		array(
			'tag_local_name'               => 'item',
			'tag_namespace'                => '',
			'tag_namespace_and_local_name' => 'item',
			'is_empty_element'             => true,
			'current_depth'                => 2,
			'id'                           => '7',
		),
	),
	$xml_tag_summary_batch->next_tag_summary_batch( 2, 'id' ),
	'Expected native XML public tag summary batch rows.'
);

$xml_attribute_inventory_array = $xml_class::create_from_string( '<root a="1" xmlns:wp="u" wp:b="2"><item id="7" /><item id="7" /></root>' );
assert_same(
	array(
		'token_count'                  => 4,
		'tag_count'                    => 3,
		'attribute_count'              => 4,
		'namespaced_attribute_count'   => 1,
		'tags_with_attributes_count'   => 3,
		'max_attribute_count'          => 2,
	),
	$xml_attribute_inventory_array->summarize_attribute_inventory_array(),
	'Expected native XML attribute inventory array summary.'
);

$xml_id_inventory_array = $xml_class::create_from_string( '<root id="r"><item id="7" /><item id="7" /></root>' );
assert_same(
	array(
		'token_count'         => 4,
		'tag_count'           => 3,
		'id_attribute_count'  => 3,
		'unique_id_count'     => 2,
		'duplicate_id_count'  => 1,
		'id_value_bytes'      => 3,
	),
	$xml_id_inventory_array->summarize_id_inventory_array(),
	'Expected native XML ID inventory array summary.'
);

$xml_compact_batch = $xml_class::create_from_string( '<root id="root"><item id="7" /></root>' );
assert_false( $xml_compact_batch->is_finished(), 'Expected native XML processor to start unfinished.' );
assert_same(
	"t\x1froot\x1f\x1f00\x1f1\x1f1root\x1et\x1fitem\x1f\x1f01\x1f2\x1f17\x1et\x1froot\x1f\x1f10\x1f0\x1f0",
	$xml_compact_batch->next_token_compact_summary_batch( 8 ),
	'Expected batched compact native XML token summaries.'
);
assert_same( null, $xml_compact_batch->next_token_compact_summary_batch( 8 ), 'Expected exhausted batched compact XML token summaries to return null.' );
assert_true( $xml_compact_batch->is_finished(), 'Expected native XML processor to report finished after exhaustion.' );

$xml_hot_compact_batch = $xml_class::create_from_string( '<root id="root"><item id="7" /></root>' );
assert_same(
	"t\x1froot\x1f\x1f1root\x1f00\x1f1\x1et\x1fitem\x1f\x1f17\x1f01\x1f2\x1et\x1froot\x1f\x1f0\x1f10\x1f0",
	$xml_hot_compact_batch->next_token_hot_compact_summary_batch( 8 ),
	'Expected hot-path batched compact native XML token summaries.'
);
assert_same( null, $xml_hot_compact_batch->next_token_hot_compact_summary_batch( 8 ), 'Expected exhausted hot-path compact XML token summaries to return null.' );

$xml_tag_compact_batch = $xml_class::create_from_string( '<?xml version="1.0"?><root id="root"><item id="7">Text</item><empty /></root>' );
assert_same(
	"2\x1froot\x1f\x1f0\x1f1\x1f1root\x1e3\x1fitem\x1f\x1f0\x1f2\x1f17",
	$xml_tag_compact_batch->next_tag_compact_summary_batch( 2, 'id' ),
	'Expected batched compact native XML tag summaries.'
);
assert_same(
	"3\x1fempty\x1f\x1f1\x1f2\x1f0",
	$xml_tag_compact_batch->next_tag_compact_summary_batch( 2, 'id' ),
	'Expected compact native XML tag summaries to skip non-tag and closing tokens.'
);
assert_same( null, $xml_tag_compact_batch->next_tag_compact_summary_batch( 2, 'id' ), 'Expected exhausted batched compact XML tag summaries to return null.' );

$xml_matching_tag_compact_batch = $xml_class::create_from_string( '<?xml version="1.0"?><wp:root xmlns:wp="https://wordpress.org"><wp:item id="7"><wp:title>Title</wp:title></wp:item><item id="plain" /><wp:item id="8" /></wp:root>' );
assert_same(
	"3\x1fitem\x1fhttps://wordpress.org\x1f0\x1f2\x1f17",
	$xml_matching_tag_compact_batch->next_matching_tag_compact_summary_batch( 1, 'https://wordpress.org', 'item', 'id' ),
	'Expected native XML matching tag summary batch to return the first matching tag.'
);
assert_same(
	"6\x1fitem\x1fhttps://wordpress.org\x1f1\x1f2\x1f18",
	$xml_matching_tag_compact_batch->next_matching_tag_compact_summary_batch( 1, 'https://wordpress.org', 'item', 'id' ),
	'Expected native XML matching tag summary batch to skip non-matching tags.'
);
assert_same( null, $xml_matching_tag_compact_batch->next_matching_tag_compact_summary_batch( 1, 'https://wordpress.org', 'item', 'id' ), 'Expected exhausted matching XML tag summaries to return null.' );

$xml_matching_tag_summary_batch = $xml_class::create_from_string( '<?xml version="1.0"?><wp:root xmlns:wp="https://wordpress.org"><wp:item id="7"><wp:title>Title</wp:title></wp:item><item id="plain" /><wp:item id="8" /></wp:root>' );
assert_same(
	array(
		array(
			'tag_local_name'               => 'item',
			'tag_namespace'                => 'https://wordpress.org',
			'tag_namespace_and_local_name' => '{https://wordpress.org}item',
			'is_empty_element'             => false,
			'current_depth'                => 2,
			'id'                           => '7',
		),
		array(
			'tag_local_name'               => 'item',
			'tag_namespace'                => 'https://wordpress.org',
			'tag_namespace_and_local_name' => '{https://wordpress.org}item',
			'is_empty_element'             => true,
			'current_depth'                => 2,
			'id'                           => '8',
		),
	),
	$xml_matching_tag_summary_batch->next_matching_tag_summary_batch( 2, 'https://wordpress.org', 'item', 'id' ),
	'Expected native XML public matching tag summary batch rows.'
);
assert_same( array(), $xml_matching_tag_summary_batch->next_matching_tag_summary_batch( 1, 'https://wordpress.org', 'missing', 'id' ), 'Expected native XML public matching tag summary batch to return an empty array for missing tags.' );

$xml_matching_tag_count_batch = $xml_class::create_from_string( '<?xml version="1.0"?><wp:root xmlns:wp="https://wordpress.org"><wp:item id="7"><wp:title>Title</wp:title></wp:item><item id="plain" /><wp:item id="8" /></wp:root>' );
assert_same(
	"3\x1f1\x1f1",
	$xml_matching_tag_count_batch->next_matching_tag_count_batch( 1, 'https://wordpress.org', 'item', 'id' ),
	'Expected native XML matching tag count batch to count the first matching tag.'
);
assert_same(
	"6\x1f1\x1f1",
	$xml_matching_tag_count_batch->next_matching_tag_count_batch( 1, 'https://wordpress.org', 'item', 'id' ),
	'Expected native XML matching tag count batch to skip non-matching tags.'
);
assert_same(
	"1\x1f0\x1f0",
	$xml_matching_tag_count_batch->next_matching_tag_count_batch( 1, 'https://wordpress.org', 'item', 'id' ),
	'Expected native XML matching tag count batch to report trailing non-matching tokens.'
);
assert_same( null, $xml_matching_tag_count_batch->next_matching_tag_count_batch( 1, 'https://wordpress.org', 'item', 'id' ), 'Expected exhausted matching XML tag count batch to return null.' );

$xml_matching_tag_count_compact_batch = $xml_class::create_from_string( '<?xml version="1.0"?><wp:root xmlns:wp="https://wordpress.org"><wp:item id="7"><wp:title>Title</wp:title></wp:item><item id="plain" /><wp:item id="8" /></wp:root>' );
assert_same(
	"3\x1f1\x1f1",
	$xml_matching_tag_count_compact_batch->next_matching_tag_count_compact_batch( 1, 'https://wordpress.org', 'item', 'id' ),
	'Expected native XML matching tag compact count batch to count the first matching tag.'
);
assert_same(
	"6\x1f1\x1f1",
	$xml_matching_tag_count_compact_batch->next_matching_tag_count_compact_batch( 1, 'https://wordpress.org', 'item', 'id' ),
	'Expected native XML matching tag compact count batch to skip non-matching tags.'
);
assert_same(
	"1\x1f0\x1f0",
	$xml_matching_tag_count_compact_batch->next_matching_tag_count_compact_batch( 1, 'https://wordpress.org', 'item', 'id' ),
	'Expected native XML matching tag compact count batch to report trailing non-matching tokens.'
);
assert_same( null, $xml_matching_tag_count_compact_batch->next_matching_tag_count_compact_batch( 1, 'https://wordpress.org', 'item', 'id' ), 'Expected exhausted matching XML tag compact count batch to return null.' );

$xml_matching_tag_summary = $xml_class::create_from_string( '<?xml version="1.0"?><wp:root xmlns:wp="https://wordpress.org"><wp:item id="7"><wp:title>Title</wp:title></wp:item><item id="plain" /><wp:item id="8" /></wp:root>' );
assert_same( "10\x1f2\x1f2", $xml_matching_tag_summary->summarize_matching_tag_stream( 'https://wordpress.org', 'item', 'id' ), 'Expected native XML matching tag stream summary.' );

$xml_matching_tag_summary_remaining = $xml_class::create_from_string( '<?xml version="1.0"?><wp:root xmlns:wp="https://wordpress.org"><wp:item id="7"><wp:title>Title</wp:title></wp:item><item id="plain" /><wp:item id="8" /></wp:root>' );
assert_true( $xml_matching_tag_summary_remaining->next_tag(), 'Expected XML root tag before summarizing remaining matching XML tags.' );
assert_same( "8\x1f2\x1f2", $xml_matching_tag_summary_remaining->summarize_matching_tag_stream( 'https://wordpress.org', 'item', 'id' ), 'Expected native XML remaining matching tag stream summary.' );

$xml_matching_tag_attributes_summary = $xml_class::create_from_string( '<?xml version="1.0"?><wp:root xmlns:wp="https://wordpress.org"><wp:item id="7" slug="first"><wp:title>Title</wp:title></wp:item><item id="plain" slug="skip" /><wp:item id="8" status="draft" /></wp:root>' );
assert_same( "10\x1f2\x1f4", $xml_matching_tag_attributes_summary->summarize_matching_tag_attributes_stream( 'https://wordpress.org', 'item', "id\x1fslug\x1fstatus\x1fmissing" ), 'Expected native XML matching tag attributes stream summary.' );

$xml_tag_count_batch = $xml_class::create_from_string( '<?xml version="1.0"?><root id="root"><item id="7">Text</item><empty /></root>' );
assert_same(
	"3\x1f2\x1f2",
	$xml_tag_count_batch->next_tag_count_batch( 2, 'id' ),
	'Expected native XML tag count batches.'
);
assert_same(
	"4\x1f1\x1f0",
	$xml_tag_count_batch->next_tag_count_batch( 2, 'id' ),
	'Expected native XML tag count batches to skip non-tag and closing tokens.'
);
assert_same( null, $xml_tag_count_batch->next_tag_count_batch( 2, 'id' ), 'Expected exhausted XML tag count batch to return null.' );

$xml_tag_count_compact_batch = $xml_class::create_from_string( '<?xml version="1.0"?><root id="root"><item id="7">Text</item><empty /></root>' );
assert_same(
	"3\x1f2\x1f2",
	$xml_tag_count_compact_batch->next_tag_count_compact_batch( 2, 'id' ),
	'Expected native XML tag compact count batches.'
);
assert_same(
	"4\x1f1\x1f0",
	$xml_tag_count_compact_batch->next_tag_count_compact_batch( 2, 'id' ),
	'Expected native XML tag compact count batches to skip non-tag and closing tokens.'
);
assert_same( null, $xml_tag_count_compact_batch->next_tag_count_compact_batch( 2, 'id' ), 'Expected exhausted XML tag compact count batch to return null.' );

$xml_token_summary = $xml_class::create_from_string( '<?xml version="1.0" encoding="UTF-8"?><root id="root"><item id="7">Text</item><empty /></root>' );
assert_same( "7\x1f3\x1f2", $xml_token_summary->summarize_token_stream( 'id' ), 'Expected native XML token stream summary.' );

$xml_token_summary_remaining = $xml_class::create_from_string( '<?xml version="1.0" encoding="UTF-8"?><root id="root"><item id="7">Text</item><empty /></root>' );
assert_true( $xml_token_summary_remaining->next_token(), 'Expected XML declaration before summarizing remaining XML tokens.' );
assert_same( "6\x1f3\x1f2", $xml_token_summary_remaining->summarize_token_stream( 'id' ), 'Expected native XML remaining token stream summary.' );

$xml_document_inventory = $xml_class::create_from_string( '<?xml version="1.0"?><root><!-- note --><item>Text</item><empty /><data><![CDATA[x]]></data></root>' );
assert_same( "11\x1f4\x1f3\x1f1\x1f1\x1f1\x1f2\x1f1", $xml_document_inventory->summarize_document_inventory(), 'Expected native XML document inventory summary.' );

$xml_document_inventory_remaining = $xml_class::create_from_string( '<?xml version="1.0"?><root><!-- note --><item>Text</item><empty /><data><![CDATA[x]]></data></root>' );
assert_true( $xml_document_inventory_remaining->next_tag(), 'Expected XML root tag before summarizing remaining XML inventory.' );
assert_same( "9\x1f3\x1f3\x1f1\x1f1\x1f1\x1f2\x1f1", $xml_document_inventory_remaining->summarize_document_inventory(), 'Expected native XML remaining document inventory summary.' );

$xml_element_inventory = $xml_class::create_from_string( '<?xml version="1.0"?><wp:root xmlns:wp="https://wordpress.org"><wp:item /><wp:item><plain /></wp:item><plain /></wp:root>' );
assert_same( "8\x1f5\x1f2\x1f3\x1f2\x1f3\x1f3", $xml_element_inventory->summarize_element_inventory(), 'Expected native XML element inventory summary.' );

$xml_element_inventory_remaining = $xml_class::create_from_string( '<?xml version="1.0"?><wp:root xmlns:wp="https://wordpress.org"><wp:item /><wp:item><plain /></wp:item><plain /></wp:root>' );
assert_true( $xml_element_inventory_remaining->next_tag(), 'Expected XML root tag before summarizing remaining XML elements.' );
assert_same( "6\x1f4\x1f2\x1f2\x1f2\x1f2\x1f3", $xml_element_inventory_remaining->summarize_element_inventory(), 'Expected native XML remaining element inventory summary.' );

$xml_depth_inventory = $xml_class::create_from_string( '<?xml version="1.0"?><root><section><item /><item><child /></item></section><single /></root>' );
assert_same( "10\x1f6\x1f3\x1f3\x1f1\x1f3\x1f15\x1f4", $xml_depth_inventory->summarize_depth_inventory(), 'Expected native XML depth inventory summary.' );

$xml_depth_inventory_remaining = $xml_class::create_from_string( '<?xml version="1.0"?><root><section><item /><item><child /></item></section><single /></root>' );
assert_true( $xml_depth_inventory_remaining->next_tag(), 'Expected XML root tag before summarizing remaining XML depth.' );
assert_same( "8\x1f5\x1f3\x1f3\x1f0\x1f3\x1f14\x1f4", $xml_depth_inventory_remaining->summarize_depth_inventory(), 'Expected native XML remaining depth inventory summary.' );

$xml_leaf_inventory = $xml_class::create_from_string( '<?xml version="1.0"?><root><section><item /><item><child /></item></section><single /></root>' );
assert_same( "10\x1f6\x1f3\x1f3\x1f3\x1f3\x1f2", $xml_leaf_inventory->summarize_leaf_inventory(), 'Expected native XML leaf inventory summary.' );

$xml_leaf_inventory_remaining = $xml_class::create_from_string( '<?xml version="1.0"?><root><section><item /><item><child /></item></section><single /></root>' );
assert_true( $xml_leaf_inventory_remaining->next_tag(), 'Expected XML root tag before summarizing remaining XML leaf inventory.' );
assert_same( "8\x1f5\x1f3\x1f3\x1f3\x1f2\x1f2", $xml_leaf_inventory_remaining->summarize_leaf_inventory(), 'Expected native XML remaining leaf inventory summary.' );

$xml_structural_inventory = $xml_class::create_from_string( '<?xml version="1.0"?><wp:root xmlns:wp="https://wordpress.org"><wp:section><wp:item /><wp:item><child /></wp:item></wp:section><single /></wp:root>' );
assert_same( "10\x1f6\x1f3\x1f5\x1f1\x1f4\x1f3\x1f1\x1f3\x1f15\x1f4\x1f3\x1f3\x1f2", $xml_structural_inventory->summarize_structural_inventory(), 'Expected native XML structural inventory summary.' );

$xml_structural_inventory_remaining = $xml_class::create_from_string( '<?xml version="1.0"?><wp:root xmlns:wp="https://wordpress.org"><wp:section><wp:item /><wp:item><child /></wp:item></wp:section><single /></wp:root>' );
assert_true( $xml_structural_inventory_remaining->next_tag(), 'Expected XML root tag before summarizing remaining XML structural inventory.' );
assert_same( "8\x1f5\x1f3\x1f4\x1f1\x1f3\x1f3\x1f0\x1f3\x1f14\x1f4\x1f3\x1f2\x1f2", $xml_structural_inventory_remaining->summarize_structural_inventory(), 'Expected native XML remaining structural inventory summary.' );

$xml_attribute_inventory = $xml_class::create_from_string( '<?xml version="1.0"?><wp:root xmlns:wp="https://wordpress.org" id="root"><wp:item id="7" wp:slug="first"><wp:title>Title</wp:title><empty data-id="x" /></wp:item></wp:root>' );
assert_same( "9\x1f4\x1f4\x1f1\x1f3\x1f2", $xml_attribute_inventory->summarize_attribute_inventory(), 'Expected native XML attribute inventory summary.' );

$xml_attribute_inventory_remaining = $xml_class::create_from_string( '<?xml version="1.0"?><wp:root xmlns:wp="https://wordpress.org" id="root"><wp:item id="7" wp:slug="first"><wp:title>Title</wp:title><empty data-id="x" /></wp:item></wp:root>' );
assert_true( $xml_attribute_inventory_remaining->next_tag(), 'Expected XML root tag before summarizing remaining XML attributes.' );
assert_same( "7\x1f3\x1f3\x1f1\x1f2\x1f2", $xml_attribute_inventory_remaining->summarize_attribute_inventory(), 'Expected native XML remaining attribute inventory summary.' );

$xml_id_inventory = $xml_class::create_from_string( '<?xml version="1.0"?><wp:root xmlns:wp="https://wordpress.org" id="root"><wp:item id="7" wp:id="ignored"><wp:title id="title">Title</wp:title><empty id="7" /><plain /></wp:item></wp:root>' );
assert_same( "10\x1f5\x1f4\x1f3\x1f1\x1f11", $xml_id_inventory->summarize_id_inventory(), 'Expected native XML ID inventory summary.' );

$xml_id_inventory_remaining = $xml_class::create_from_string( '<?xml version="1.0"?><wp:root xmlns:wp="https://wordpress.org" id="root"><wp:item id="7" wp:id="ignored"><wp:title id="title">Title</wp:title><empty id="7" /><plain /></wp:item></wp:root>' );
assert_true( $xml_id_inventory_remaining->next_tag(), 'Expected XML root tag before summarizing remaining XML IDs.' );
assert_same( "8\x1f4\x1f3\x1f2\x1f1\x1f7", $xml_id_inventory_remaining->summarize_id_inventory(), 'Expected native XML remaining ID inventory summary.' );

$xml_namespace_inventory = $xml_class::create_from_string( '<?xml version="1.0"?><wp:root xmlns:wp="https://wordpress.org" xmlns:media="https://example.com/media" id="root"><wp:item id="7" wp:slug="first" media:type="image"><media:title>Title</media:title><empty data-id="x" /></wp:item></wp:root>' );
assert_same( "9\x1f4\x1f3\x1f5\x1f2\x1f2", $xml_namespace_inventory->summarize_namespace_inventory(), 'Expected native XML namespace inventory summary.' );

$xml_namespace_inventory_remaining = $xml_class::create_from_string( '<?xml version="1.0"?><wp:root xmlns:wp="https://wordpress.org" xmlns:media="https://example.com/media" id="root"><wp:item id="7" wp:slug="first" media:type="image"><media:title>Title</media:title><empty data-id="x" /></wp:item></wp:root>' );
assert_true( $xml_namespace_inventory_remaining->next_tag(), 'Expected XML root tag before summarizing remaining XML namespaces.' );
assert_same( "7\x1f3\x1f2\x1f4\x1f2\x1f2", $xml_namespace_inventory_remaining->summarize_namespace_inventory(), 'Expected native XML remaining namespace inventory summary.' );

$xml_text_inventory = $xml_class::create_from_string( '<?xml version="1.0"?><root> Alpha <item>Two</item><data><![CDATA[raw]]></data><space>   </space></root>' );
assert_same( "13\x1f3\x1f1\x1f3\x1f1\x1f16\x1f7", $xml_text_inventory->summarize_text_inventory(), 'Expected native XML text inventory summary.' );

$xml_text_inventory_remaining = $xml_class::create_from_string( '<?xml version="1.0"?><root> Alpha <item>Two</item><data><![CDATA[raw]]></data><space>   </space></root>' );
assert_true( $xml_text_inventory_remaining->next_tag(), 'Expected XML root tag before summarizing remaining XML text.' );
assert_same( "11\x1f3\x1f1\x1f3\x1f1\x1f16\x1f7", $xml_text_inventory_remaining->summarize_text_inventory(), 'Expected native XML remaining text inventory summary.' );

$xml_processing_instruction_inventory = $xml_class::create_from_string( '<?xml version="1.0"?><root><?xml-stylesheet type="text/xsl"?><?xml audit data?><item /></root><?xml trailing?>' );
assert_same( "7\x1f3\x1f1\x1f4\x1f64\x1f27", $xml_processing_instruction_inventory->summarize_processing_instruction_inventory(), 'Expected native XML processing instruction inventory summary.' );

$xml_processing_instruction_inventory_remaining = $xml_class::create_from_string( '<?xml version="1.0"?><root><?xml-stylesheet type="text/xsl"?><?xml audit data?><item /></root><?xml trailing?>' );
assert_true( $xml_processing_instruction_inventory_remaining->next_tag(), 'Expected XML root tag before summarizing remaining XML processing instructions.' );
assert_same( "5\x1f3\x1f0\x1f3\x1f47\x1f27", $xml_processing_instruction_inventory_remaining->summarize_processing_instruction_inventory(), 'Expected native XML remaining processing instruction inventory summary.' );

$xml_comment_inventory = $xml_class::create_from_string( '<?xml version="1.0"?><root><!-- lead --><item><!-- --></item><empty><!--x--></empty><!--   --></root><!--trailer-->' );
assert_same( "12\x1f5\x1f3\x1f2\x1f18\x1f7", $xml_comment_inventory->summarize_comment_inventory(), 'Expected native XML comment inventory summary.' );

$xml_comment_inventory_remaining = $xml_class::create_from_string( '<?xml version="1.0"?><root><!-- lead --><item><!-- --></item><empty><!--x--></empty><!--   --></root><!--trailer-->' );
assert_true( $xml_comment_inventory_remaining->next_tag(), 'Expected XML root tag before summarizing remaining XML comments.' );
assert_same( "10\x1f5\x1f3\x1f2\x1f18\x1f7", $xml_comment_inventory_remaining->summarize_comment_inventory(), 'Expected native XML remaining comment inventory summary.' );

$xml_payload_inventory = $xml_class::create_from_string( '<?xml version="1.0"?><root>Alpha<!-- note --><item><![CDATA[raw]]><?xml audit data?></item><space>   </space></root><?xml trailing?>' );
assert_same( "13\x1f2\x1f1\x1f1\x1f2\x1f37\x1f11", $xml_payload_inventory->summarize_payload_inventory(), 'Expected native XML payload inventory summary.' );

$xml_payload_inventory_remaining = $xml_class::create_from_string( '<?xml version="1.0"?><root>Alpha<!-- note --><item><![CDATA[raw]]><?xml audit data?></item><space>   </space></root><?xml trailing?>' );
assert_true( $xml_payload_inventory_remaining->next_tag(), 'Expected XML root tag before summarizing remaining XML payload.' );
assert_same( "11\x1f2\x1f1\x1f1\x1f2\x1f37\x1f11", $xml_payload_inventory_remaining->summarize_payload_inventory(), 'Expected native XML remaining payload inventory summary.' );

$xml_content_inventory = $xml_class::create_from_string( '<?xml version="1.0"?><root id="root"><item data-kind="post">Title<!-- note --><![CDATA[raw]]><?xml audit data?></item><empty data-id="x" /></root><?xml trailing?>' );
assert_same( "11\x1f3\x1f3\x1f1\x1f1\x1f1\x1f2\x1f9\x1f4\x1f34\x1f11", $xml_content_inventory->summarize_content_inventory(), 'Expected native XML content inventory summary.' );

$xml_content_inventory_remaining = $xml_class::create_from_string( '<?xml version="1.0"?><root id="root"><item data-kind="post">Title<!-- note --><![CDATA[raw]]><?xml audit data?></item><empty data-id="x" /></root><?xml trailing?>' );
assert_true( $xml_content_inventory_remaining->next_tag(), 'Expected XML root tag before summarizing remaining XML content.' );
assert_same( "9\x1f2\x1f2\x1f1\x1f1\x1f1\x1f2\x1f5\x1f4\x1f34\x1f11", $xml_content_inventory_remaining->summarize_content_inventory(), 'Expected native XML remaining content inventory summary.' );

$xml_import_inventory = $xml_class::create_from_string( '<?xml version="1.0"?><root id="root"><item data-kind="post">Title<!-- note --><![CDATA[raw]]><?xml audit data?></item><empty data-id="x" /></root><?xml trailing?>' );
assert_same( "11\x1f3\x1f2\x1f3\x1f0\x1f0\x1f1\x1f1\x1f0\x1f5\x1f2\x1f2\x1f1\x1f2\x1f3\x1f1\x1f1\x1f1\x1f2\x1f9\x1f4\x1f34\x1f11", $xml_import_inventory->summarize_import_inventory(), 'Expected native XML import inventory summary.' );

$xml_import_inventory_remaining = $xml_class::create_from_string( '<?xml version="1.0"?><root id="root"><item data-kind="post">Title<!-- note --><![CDATA[raw]]><?xml audit data?></item><empty data-id="x" /></root><?xml trailing?>' );
assert_true( $xml_import_inventory_remaining->next_tag(), 'Expected XML root tag before summarizing remaining XML import inventory.' );
assert_same( "9\x1f2\x1f2\x1f2\x1f0\x1f0\x1f1\x1f0\x1f0\x1f4\x1f2\x1f2\x1f0\x1f0\x1f2\x1f1\x1f1\x1f1\x1f2\x1f5\x1f4\x1f34\x1f11", $xml_import_inventory_remaining->summarize_import_inventory(), 'Expected native XML remaining import inventory summary.' );

$xml_tag_summary = $xml_class::create_from_string( '<?xml version="1.0" encoding="UTF-8"?><root id="root"><item id="7">Text</item><empty /></root>' );
assert_same( "7\x1f3\x1f2", $xml_tag_summary->summarize_tag_stream( 'id' ), 'Expected native XML tag stream summary.' );

$xml_tag_summary_remaining = $xml_class::create_from_string( '<?xml version="1.0" encoding="UTF-8"?><root id="root"><item id="7">Text</item><empty /></root>' );
assert_true( $xml_tag_summary_remaining->next_tag(), 'Expected root tag before summarizing remaining XML tags.' );
assert_same( "5\x1f2\x1f1", $xml_tag_summary_remaining->summarize_tag_stream( 'id' ), 'Expected native XML remaining tag stream summary.' );

$xml_summary = $xml_class::create_from_string( '<root xmlns:wp="https://wordpress.org"><wp:item wp:data-id="7" data-kind="post" /><item data-id="8" /></root>' );
assert_same( "4\x1f3\x1f2", $xml_summary->summarize_attribute_names_with_prefix( null, 'data-' ), 'Expected native XML document-level no-namespace prefix summary.' );

$xml_summary_remaining = $xml_class::create_from_string( '<root xmlns:wp="https://wordpress.org"><wp:item wp:data-id="7" data-kind="post" /><item data-id="8" /></root>' );
assert_true( $xml_summary_remaining->next_tag(), 'Expected XML root tag before summarizing remaining XML tags.' );
assert_same( "3\x1f2\x1f1", $xml_summary_remaining->summarize_attribute_names_with_prefix( 'https://wordpress.org', 'data-' ), 'Expected native XML remaining namespaced prefix summary.' );

$xml_removal = $xml_class::create_from_string( '<root xmlns:wp="https://wordpress.org" data-root="1"><wp:item wp:data-id="7" data-kind="post" keep="x" /><item data-id="8" /></root>' );
assert_same(
	"3\x1f3\x1f" . '<root xmlns:wp="https://wordpress.org" ><wp:item wp:data-id="7"  keep="x" /><item  /></root>',
	$xml_removal->remove_attributes_with_prefix_from_document( null, 'data-' ),
	'Expected native XML document-level no-namespace prefix removal.'
);

$xml_namespaced_removal = $xml_class::create_from_string( '<root xmlns:wp="https://wordpress.org" data-root="1"><wp:item wp:data-id="7" data-kind="post" keep="x" /><item data-id="8" /></root>' );
assert_same(
	"3\x1f1\x1f" . '<root xmlns:wp="https://wordpress.org" data-root="1"><wp:item  data-kind="post" keep="x" /><item data-id="8" /></root>',
	$xml_namespaced_removal->remove_attributes_with_prefix_from_document( 'https://wordpress.org', 'data-' ),
	'Expected native XML document-level namespaced prefix removal.'
);

$xml_references = $xml_class::create_from_string( '<root a="&amp; &amp;amp; &lt; &gt; &quot; &apos;&#65;&#x42; &#0; &unknown;">&amp; &amp;amp; &lt; &gt; &quot; &apos;&#67;&#x44; &#xD800; &unknown;</root>' );
assert_true( $xml_references->next_token(), 'Expected XML reference root token.' );
assert_same( '& &amp; < > " \'AB &#0; &unknown;', $xml_references->get_attribute( 'a' ), 'Expected XML attribute character reference decoding.' );
assert_true( $xml_references->next_token(), 'Expected XML reference text token.' );
assert_same( '& &amp; < > " \'CD &#xD800; &unknown;', $xml_references->get_modifiable_text(), 'Expected XML text character reference decoding.' );
assert_same( null, $xml_references->get_last_error(), 'Expected no XML parse error for character references.' );

$xml_tokens = $xml_class::create_from_string( '<root xmlns:wp="w.org"><wp:text>Hello<!--note--><post />World</wp:text></root>' );
assert_true( $xml_tokens->next_token(), 'Expected XML root token.' );
assert_true( $xml_tokens->next_token(), 'Expected XML text element token.' );
assert_true( $xml_tokens->next_token(), 'Expected XML text node token.' );
assert_same( '#text', $xml_tokens->get_token_type(), 'Expected XML text token type.' );
assert_same( '#text', $xml_tokens->get_token_name(), 'Expected XML text token name.' );
assert_same( 'Hello', $xml_tokens->get_modifiable_text(), 'Expected XML text token content.' );
assert_same( 2, $xml_tokens->get_current_depth(), 'Expected XML text token depth.' );
assert_same(
	array( array( '', 'root' ), array( 'w.org', 'text' ) ),
	$xml_tokens->get_breadcrumbs(),
	'Expected XML text token breadcrumbs.'
);
assert_true( $xml_tokens->next_token(), 'Expected XML comment token.' );
assert_same( '#comment', $xml_tokens->get_token_type(), 'Expected XML comment token type.' );
assert_same( '#comment', $xml_tokens->get_token_name(), 'Expected XML comment token name.' );
assert_same( 'note', $xml_tokens->get_modifiable_text(), 'Expected XML comment token content.' );
assert_true( $xml_tokens->next_tag(), 'Expected XML next_tag() to skip text/comment tokens.' );
assert_same( 'post', $xml_tokens->get_token_name(), 'Expected XML next tag after comment.' );

$xml_cdata = $xml_class::create_from_string( '<root xmlns:wp="w.org"><wp:text>before<![CDATA[<b>&c]]>after</wp:text></root>' );
assert_true( $xml_cdata->next_token(), 'Expected XML CDATA root token.' );
assert_true( $xml_cdata->next_token(), 'Expected XML CDATA parent token.' );
assert_true( $xml_cdata->next_token(), 'Expected XML text token before CDATA.' );
assert_same( '#text', $xml_cdata->get_token_type(), 'Expected XML text token type before CDATA.' );
assert_same( 'before', $xml_cdata->get_modifiable_text(), 'Expected XML text content before CDATA.' );
assert_true( $xml_cdata->next_token(), 'Expected XML CDATA token.' );
assert_same( '#cdata-section', $xml_cdata->get_token_type(), 'Expected XML CDATA token type.' );
assert_same( '#cdata-section', $xml_cdata->get_token_name(), 'Expected XML CDATA token name.' );
assert_same( '<b>&c', $xml_cdata->get_modifiable_text(), 'Expected XML CDATA token content.' );
assert_same( 2, $xml_cdata->get_current_depth(), 'Expected XML CDATA token depth.' );
assert_same(
	array( array( '', 'root' ), array( 'w.org', 'text' ) ),
	$xml_cdata->get_breadcrumbs(),
	'Expected XML CDATA token breadcrumbs.'
);
assert_true( $xml_cdata->next_token(), 'Expected XML text token after CDATA.' );
assert_same( '#text', $xml_cdata->get_token_type(), 'Expected XML text token type after CDATA.' );
assert_same( 'after', $xml_cdata->get_modifiable_text(), 'Expected XML text content after CDATA.' );

$broken = $xml_class::create_from_string( '<root><item></root>' );
assert_true( null !== $broken->get_last_error(), 'Expected XML parse error.' );
assert_true( null !== $broken->get_exception(), 'Expected malformed native XML to report an exception diagnostic.' );

$invalid_closer = $xml_class::create_from_string( '<content>Test</content post-type="test">' );
assert_true( $invalid_closer->next_token(), 'Expected XML token before invalid closer.' );
assert_same( 'content', $invalid_closer->get_token_name(), 'Expected XML root before invalid closer.' );
assert_true( $invalid_closer->next_token(), 'Expected XML text before invalid closer.' );
assert_same( 'Test', $invalid_closer->get_modifiable_text(), 'Expected XML text before invalid closer.' );
assert_false( $invalid_closer->next_token(), 'Expected invalid XML closer to stop token scanning.' );
assert_true( null !== $invalid_closer->get_last_error(), 'Expected invalid XML closer parse error.' );

$invalid_attribute_value = $xml_class::create_from_string( '<root enabled="I love <3 this" />' );
assert_false( $invalid_attribute_value->next_token(), 'Expected invalid XML attribute value to stop token scanning.' );
assert_true( null !== $invalid_attribute_value->get_last_error(), 'Expected invalid XML attribute value parse error.' );
assert_true( null !== $invalid_attribute_value->get_exception(), 'Expected invalid XML attribute value exception diagnostic.' );

$duplicate_attribute = $xml_class::create_from_string( '<root id="first" id="second" />' );
assert_false( $duplicate_attribute->next_token(), 'Expected duplicate XML attribute to stop token scanning.' );
assert_true( null !== $duplicate_attribute->get_last_error(), 'Expected duplicate XML attribute parse error.' );
assert_true( null !== $duplicate_attribute->get_exception(), 'Expected duplicate XML attribute exception diagnostic.' );

$empty_prefixed_namespace = $xml_class::create_from_string( '<root xmlns:a="" />' );
assert_false( $empty_prefixed_namespace->next_token(), 'Expected empty prefixed XML namespace to stop token scanning.' );
assert_true( null !== $empty_prefixed_namespace->get_last_error(), 'Expected empty prefixed XML namespace parse error.' );
assert_true( null !== $empty_prefixed_namespace->get_exception(), 'Expected empty prefixed XML namespace exception diagnostic.' );

$xml_processing_instruction = $xml_class::create_from_string( '<root><?pi data?><child /></root>' );
while ( $xml_processing_instruction->next_token() ) {
	continue;
}
assert_true( null !== $xml_processing_instruction->get_last_error(), 'Expected XML processing instruction parse error.' );

$url_text_class     = 'WordPress\\DataLiberation\\URL\\NativeURLInTextProcessor';
$url_text_processor = new $url_text_class( 'Visit https://WordPress.org/plugins, then example.com/docs.' );
assert_true( $url_text_processor->next_url(), 'Expected native URL-in-text processor to find the first URL.' );
assert_same( 'https://WordPress.org/plugins', $url_text_processor->get_raw_url(), 'Expected native URL-in-text processor to trim trailing punctuation.' );
assert_same( 6, $url_text_processor->get_url_starts_at(), 'Expected native URL-in-text processor to expose byte offset.' );
assert_true( $url_text_processor->had_protocol(), 'Expected native URL-in-text processor to mark explicit protocols.' );
assert_true( $url_text_processor->next_url(), 'Expected native URL-in-text processor to find the second URL.' );
assert_same( 'example.com/docs', $url_text_processor->get_raw_url(), 'Expected native URL-in-text processor to find bare-domain URLs.' );
assert_false( $url_text_processor->had_protocol(), 'Expected native URL-in-text processor to mark bare domains.' );
assert_true( $url_text_processor->set_raw_url( 'example.org/handbook' ), 'Expected native URL-in-text processor to replace current URL.' );
assert_same( 'Visit https://WordPress.org/plugins, then example.org/handbook.', $url_text_processor->get_updated_text(), 'Expected native URL-in-text replacement serialization.' );

fwrite( STDOUT, "Native API extension verification passed.\n" );

/**
 * Assert strict equality.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $message  Failure message.
 */
function assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite(
			STDERR,
			$message . ' Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ".\n"
		);
		exit( 1 );
	}
}

/**
 * Assert truthy value.
 *
 * @param mixed  $actual  Actual value.
 * @param string $message Failure message.
 */
function assert_true( $actual, $message ) {
	if ( true !== $actual ) {
		fwrite( STDERR, $message . ' Got ' . var_export( $actual, true ) . ".\n" );
		exit( 1 );
	}
}

/**
 * Assert false.
 *
 * @param mixed  $actual  Actual value.
 * @param string $message Failure message.
 */
function assert_false( $actual, $message ) {
	if ( false !== $actual ) {
		fwrite( STDERR, $message . ' Got ' . var_export( $actual, true ) . ".\n" );
		exit( 1 );
	}
}
