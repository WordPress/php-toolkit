<?php
/**
 * Shared conformance tests for PHP and native HTML processors.
 *
 * @package WordPress
 * @subpackage HTML-API
 */

use PHPUnit\Framework\TestCase;

/**
 * @group html-api
 * @group native-api
 */
class NativeHTMLConformanceTest extends TestCase {
	/**
	 * Verifies public HTML classes default to native delegates when available.
	 */
	public function test_public_html_classes_use_native_processors_when_available() {
		if ( ! class_exists( 'WP_HTML_Native_Tag_Processor', false ) || ! method_exists( 'WP_HTML_Native_Tag_Processor', 'supports_public_api' ) || ! class_exists( 'WP_HTML_Native_Processor', false ) || ! method_exists( 'WP_HTML_Native_Processor', 'supports_public_api' ) ) {
			$this->markTestSkipped( 'Native HTML classes are not registered; load the native API extension to run this case.' );
		}

		$tag_processor = new WP_HTML_Tag_Processor( '<p data-id="1">Text</p>' );
		$this->assertSame( 'WP_HTML_Native_Tag_Processor', get_class( $this->get_native_delegate( $tag_processor ) ) );
		$this->assertTrue( $tag_processor->next_tag() );
		$this->assertSame( 'P', $tag_processor->get_tag() );
		$this->assertSame( '1', $tag_processor->get_attribute( 'data-id' ) );
		$this->assertTrue( $tag_processor->remove_attribute( 'data-id' ) );
		$this->assertNull( $tag_processor->get_attribute( 'data-id' ) );
		$this->assertSame( '<p >Text</p>', $tag_processor->get_updated_html() );

		$tag_processor = new WP_HTML_Tag_Processor( '<p><br></p>' );
		$this->assertTrue( $tag_processor->next_tag( array( 'tag_closers' => 'visit' ) ) );
		$this->assertSame( 'P', $tag_processor->get_tag() );
		$this->assertFalse( $tag_processor->is_tag_closer() );
		$this->assertTrue( $tag_processor->next_tag( array( 'tag_closers' => 'visit' ) ) );
		$this->assertSame( 'BR', $tag_processor->get_tag() );
		$this->assertFalse( $tag_processor->is_tag_closer() );
		$this->assertTrue( $tag_processor->next_tag( array( 'tag_closers' => 'visit' ) ) );
		$this->assertSame( 'P', $tag_processor->get_tag() );
		$this->assertTrue( $tag_processor->is_tag_closer() );
		$this->assertNull( $tag_processor->get_attribute_names_with_prefix( 'data-' ) );
		$this->assertFalse( $tag_processor->has_class( 'anything' ) );

		$tag_processor = new WP_HTML_Tag_Processor( '<p>One</p>' );
		$this->assertSame( 'WP_HTML_Native_Tag_Processor', get_class( $this->get_native_delegate( $tag_processor ) ) );
		$this->assertTrue( $tag_processor->next_token() );
		$this->assertTrue( $tag_processor->next_token() );
		$this->assertSame( '#text', $tag_processor->get_token_type() );
		$this->assertTrue( $tag_processor->set_modifiable_text( 'Two & Three' ) );
		$this->assertSame( '<p>Two &amp; Three</p>', $tag_processor->get_updated_html() );

		$tag_processor = new WP_HTML_Tag_Processor( "  \tMore" );
		$this->assertSame( 'WP_HTML_Native_Tag_Processor', get_class( $this->get_native_delegate( $tag_processor ) ) );
		$this->assertTrue( $tag_processor->next_token() );
		$this->assertTrue( $tag_processor->subdivide_text_appropriately() );
		$this->assertSame( "  \t", $tag_processor->get_modifiable_text() );
		$this->assertTrue( $tag_processor->next_token() );
		$this->assertSame( 'More', $tag_processor->get_modifiable_text() );

		$tag_processor = new WP_HTML_Tag_Processor( '<p class="a b" data-kind="intro"><br></p>' );
		$this->assertSame( 'WP_HTML_Native_Tag_Processor', get_class( $this->get_native_delegate( $tag_processor ) ) );
		$this->assertSame(
			array(
				array(
					'tag_name'      => 'P',
					'is_tag_closer' => false,
				),
			),
			$tag_processor->next_tag_summary_batch( 1 )
		);
		$this->assertSame( array( 'a', 'b' ), iterator_to_array( $tag_processor->class_list() ) );
		$this->assertSame( 'intro', $tag_processor->get_attribute( 'data-kind' ) );

		$tag_processor = new WP_HTML_Tag_Processor( '<p class="a b" data-kind="intro"><br></p>' );
		$this->assertSame( 'WP_HTML_Native_Tag_Processor', get_class( $this->get_native_delegate( $tag_processor ) ) );
		$this->assertSame(
			array(
				array(
					'tag_name'        => 'P',
					'is_tag_closer'   => false,
					'attribute_count' => 1,
				),
			),
			$tag_processor->next_tag_prefix_summary_batch( 'data-', 1 )
		);
		$this->assertSame( array( 'a', 'b' ), iterator_to_array( $tag_processor->class_list() ) );
		$this->assertSame( 'intro', $tag_processor->get_attribute( 'data-kind' ) );

		$tag_processor = new WP_HTML_Tag_Processor( '<p class="a b" data-kind="intro"><br></p>' );
		$this->assertSame( 'WP_HTML_Native_Tag_Processor', get_class( $this->get_native_delegate( $tag_processor ) ) );
		$this->assertSame( "1\x1f1", $tag_processor->next_tag_prefix_count_compact_batch( 'data-', 1 ) );
		$this->assertSame( array( 'a', 'b' ), iterator_to_array( $tag_processor->class_list() ) );
		$this->assertSame( 'intro', $tag_processor->get_attribute( 'data-kind' ) );

		$html_processor = WP_HTML_Processor::create_fragment( '<p>Text</p>' );
		$this->assertSame( 'WP_HTML_Native_Processor', get_class( $this->get_native_delegate( $html_processor ) ) );
		$this->assertTrue( $html_processor->next_token() );
		$this->assertSame( 'P', $html_processor->get_token_name() );
		$this->assertSame( array( 'HTML', 'BODY', 'P' ), $html_processor->get_breadcrumbs() );

		$list_processor = WP_HTML_Processor::create_fragment( '<ul><li>one<li>two</ul>' );
		$this->assertSame( 'WP_HTML_Native_Processor', get_class( $this->get_native_delegate( $list_processor ) ) );
		$this->assertTrue( $list_processor->next_token() );
		$this->assertSame( 'UL', $list_processor->get_token_name() );
		$this->assertTrue( $list_processor->next_token() );
		$this->assertSame( 'LI', $list_processor->get_token_name() );
		$this->assertFalse( $list_processor->is_tag_closer() );
		$this->assertTrue( $list_processor->next_token() );
		$this->assertSame( 'one', $list_processor->get_modifiable_text() );
		$this->assertTrue( $list_processor->next_token() );
		$this->assertSame( 'LI', $list_processor->get_token_name() );
		$this->assertTrue( $list_processor->is_tag_closer() );

		$description_list_processor = WP_HTML_Processor::create_fragment( '<dl><dt>A<dd>B</dl>' );
		$this->assertSame( 'WP_HTML_Native_Processor', get_class( $this->get_native_delegate( $description_list_processor ) ) );
		$this->assertTrue( $description_list_processor->next_token() );
		$this->assertSame( 'DL', $description_list_processor->get_token_name() );
		$this->assertTrue( $description_list_processor->next_token() );
		$this->assertSame( 'DT', $description_list_processor->get_token_name() );
		$this->assertFalse( $description_list_processor->is_tag_closer() );
		$this->assertTrue( $description_list_processor->next_token() );
		$this->assertSame( 'A', $description_list_processor->get_modifiable_text() );
		$this->assertTrue( $description_list_processor->next_token() );
		$this->assertSame( 'DT', $description_list_processor->get_token_name() );
		$this->assertTrue( $description_list_processor->is_tag_closer() );

		$select_processor = WP_HTML_Processor::create_fragment( '<select><option>A<option>B</select>' );
		$this->assertSame( 'WP_HTML_Native_Processor', get_class( $this->get_native_delegate( $select_processor ) ) );
		$this->assertTrue( $select_processor->next_token() );
		$this->assertSame( 'SELECT', $select_processor->get_token_name() );
		$this->assertTrue( $select_processor->next_token() );
		$this->assertSame( 'OPTION', $select_processor->get_token_name() );
		$this->assertFalse( $select_processor->is_tag_closer() );
		$this->assertTrue( $select_processor->next_token() );
		$this->assertSame( 'A', $select_processor->get_modifiable_text() );
		$this->assertTrue( $select_processor->next_token() );
		$this->assertSame( 'OPTION', $select_processor->get_token_name() );
		$this->assertTrue( $select_processor->is_tag_closer() );

		$optgroup_processor = WP_HTML_Processor::create_fragment( '<select><optgroup label="a"><option>A<optgroup label="b"><option>B</select>' );
		$this->assertSame( 'WP_HTML_Native_Processor', get_class( $this->get_native_delegate( $optgroup_processor ) ) );
		$this->assertTrue( $optgroup_processor->next_token() );
		$this->assertSame( 'SELECT', $optgroup_processor->get_token_name() );
		$this->assertTrue( $optgroup_processor->next_token() );
		$this->assertSame( 'OPTGROUP', $optgroup_processor->get_token_name() );
		$this->assertFalse( $optgroup_processor->is_tag_closer() );
		$this->assertTrue( $optgroup_processor->next_tag( array( 'tag_closers' => 'visit' ) ) );
		$this->assertSame( 'OPTION', $optgroup_processor->get_token_name() );
		$this->assertFalse( $optgroup_processor->is_tag_closer() );
		$this->assertTrue( $optgroup_processor->next_tag( array( 'tag_closers' => 'visit' ) ) );
		$this->assertSame( 'OPTION', $optgroup_processor->get_token_name() );
		$this->assertTrue( $optgroup_processor->is_tag_closer() );
		$this->assertTrue( $optgroup_processor->next_token() );
		$this->assertSame( 'OPTGROUP', $optgroup_processor->get_token_name() );
		$this->assertTrue( $optgroup_processor->is_tag_closer() );

		$ruby_processor = WP_HTML_Processor::create_fragment( '<ruby>a<rt>b<rp>(<rt>c</ruby>' );
		$this->assertSame( 'WP_HTML_Native_Processor', get_class( $this->get_native_delegate( $ruby_processor ) ) );
		$this->assertTrue( $ruby_processor->next_token() );
		$this->assertSame( 'RUBY', $ruby_processor->get_token_name() );
		$this->assertTrue( $ruby_processor->next_token() );
		$this->assertSame( 'a', $ruby_processor->get_modifiable_text() );
		$this->assertTrue( $ruby_processor->next_token() );
		$this->assertSame( 'RT', $ruby_processor->get_token_name() );
		$this->assertFalse( $ruby_processor->is_tag_closer() );
		$this->assertTrue( $ruby_processor->next_token() );
		$this->assertSame( 'b', $ruby_processor->get_modifiable_text() );
		$this->assertTrue( $ruby_processor->next_token() );
		$this->assertSame( 'RT', $ruby_processor->get_token_name() );
		$this->assertTrue( $ruby_processor->is_tag_closer() );

		$paragraph_processor = WP_HTML_Processor::create_fragment( '<p>one<div>two</div>' );
		$this->assertSame( 'WP_HTML_Native_Processor', get_class( $this->get_native_delegate( $paragraph_processor ) ) );
		$this->assertTrue( $paragraph_processor->next_token() );
		$this->assertSame( 'P', $paragraph_processor->get_token_name() );
		$this->assertFalse( $paragraph_processor->is_tag_closer() );
		$this->assertTrue( $paragraph_processor->next_token() );
		$this->assertSame( 'one', $paragraph_processor->get_modifiable_text() );
		$this->assertTrue( $paragraph_processor->next_token() );
		$this->assertSame( 'P', $paragraph_processor->get_token_name() );
		$this->assertTrue( $paragraph_processor->is_tag_closer() );
		$this->assertTrue( $paragraph_processor->next_token() );
		$this->assertSame( 'DIV', $paragraph_processor->get_token_name() );
		$this->assertSame( array( 'HTML', 'BODY', 'DIV' ), $paragraph_processor->get_breadcrumbs() );

		$table_processor = WP_HTML_Processor::create_fragment( '<table><tr><td>A<td>B</tr></table>' );
		$this->assertSame( 'WP_HTML_Native_Processor', get_class( $this->get_native_delegate( $table_processor ) ) );
		$this->assertTrue( $table_processor->next_token() );
		$this->assertSame( 'TABLE', $table_processor->get_token_name() );
		$this->assertTrue( $table_processor->next_token() );
		$this->assertSame( 'TBODY', $table_processor->get_token_name() );
		$this->assertFalse( $table_processor->is_tag_closer() );

		$full_processor = WP_HTML_Processor::create_full_parser( '<html><body><main>Text</main></body></html>' );
		$this->assertNull( $this->get_native_delegate( $full_processor ) );
		$this->assertTrue( $full_processor->next_token() );
		$this->assertSame( 'HTML', $full_processor->get_token_name() );
	}

	/**
	 * Verifies public native-backed tag queries match PHP fallback match-offset parsing.
	 */
	public function test_public_html_native_defaults_ignore_non_integer_match_offsets() {
		if ( ! class_exists( 'WP_HTML_Native_Tag_Processor', false ) || ! method_exists( 'WP_HTML_Native_Tag_Processor', 'supports_public_api' ) ) {
			$this->markTestSkipped( 'Native HTML classes are not registered; load the native API extension to run this case.' );
		}

		$tag_processor = new WP_HTML_Tag_Processor( '<p id="one"></p><p id="two"></p>' );
		$this->assertSame( 'WP_HTML_Native_Tag_Processor', get_class( $this->get_native_delegate( $tag_processor ) ) );
		$this->assertTrue( $tag_processor->next_tag( array( 'tag_name' => 'p', 'match_offset' => '2' ) ) );
		$this->assertSame( 'one', $tag_processor->get_attribute( 'id' ) );

		$tag_processor = new WP_HTML_Tag_Processor( '<p id="one"></p><p id="two"></p>' );
		$this->assertSame( 'WP_HTML_Native_Tag_Processor', get_class( $this->get_native_delegate( $tag_processor ) ) );
		$this->assertTrue( $tag_processor->next_tag( array( 'tag_name' => 'p', 'match_offset' => 2.0 ) ) );
		$this->assertSame( 'one', $tag_processor->get_attribute( 'id' ) );

		$tag_processor = new WP_HTML_Tag_Processor( '<p id="one"></p><p id="two"></p>' );
		$this->assertSame( 'WP_HTML_Native_Tag_Processor', get_class( $this->get_native_delegate( $tag_processor ) ) );
		$this->assertTrue( $tag_processor->next_tag( array( 'tag_name' => 'p', 'match_offset' => 2 ) ) );
		$this->assertSame( 'two', $tag_processor->get_attribute( 'id' ) );
	}

	/**
	 * Verifies public native-backed unrestricted closer fast paths do not swallow
	 * tag-name queries that also visit closers.
	 */
	public function test_public_html_native_defaults_honor_tag_name_when_visiting_closers() {
		if ( ! class_exists( 'WP_HTML_Native_Tag_Processor', false ) || ! method_exists( 'WP_HTML_Native_Tag_Processor', 'supports_public_api' ) ) {
			$this->markTestSkipped( 'Native HTML classes are not registered; load the native API extension to run this case.' );
		}

		$tag_processor = new WP_HTML_Tag_Processor( '<div></div><p id="target"></p>' );
		$this->assertSame( 'WP_HTML_Native_Tag_Processor', get_class( $this->get_native_delegate( $tag_processor ) ) );
		$this->assertTrue(
			$tag_processor->next_tag(
				array(
					'tag_name'    => 'p',
					'tag_closers' => 'visit',
				)
			)
		);
		$this->assertSame( 'P', $tag_processor->get_tag() );
		$this->assertFalse( $tag_processor->is_tag_closer() );
		$this->assertSame( 'target', $tag_processor->get_attribute( 'id' ) );
	}

	/**
	 * Verifies public native-backed tag processors handle invalid query types like PHP fallback.
	 */
	public function test_public_html_native_defaults_treat_invalid_query_types_as_unrestricted() {
		if ( ! class_exists( 'WP_HTML_Native_Tag_Processor', false ) || ! method_exists( 'WP_HTML_Native_Tag_Processor', 'supports_public_api' ) ) {
			$this->markTestSkipped( 'Native HTML classes are not registered; load the native API extension to run this case.' );
		}

		$tag_processor = new WP_HTML_Tag_Processor( '<p id="one"></p>' );
		$this->assertSame( 'WP_HTML_Native_Tag_Processor', get_class( $this->get_native_delegate( $tag_processor ) ) );
		$this->assertTrue( $tag_processor->next_tag( 42 ) );
		$this->assertSame( 'P', $tag_processor->get_tag() );
		$this->assertSame( 'one', $tag_processor->get_attribute( 'id' ) );
	}

	/**
	 * Verifies HTML native defaults can be disabled by constant.
	 */
	public function test_public_html_classes_can_disable_native_defaults_by_constant() {
		if ( ! class_exists( 'WP_HTML_Native_Tag_Processor', false ) || ! method_exists( 'WP_HTML_Native_Tag_Processor', 'supports_public_api' ) || ! class_exists( 'WP_HTML_Native_Processor', false ) || ! method_exists( 'WP_HTML_Native_Processor', 'supports_public_api' ) ) {
			$this->markTestSkipped( 'Native HTML classes are not registered; load the native API extension to run this case.' );
		}

		$this->assertSame(
			array(
				'tag:php',
				'tag-id:1',
				'tree:php',
				'tree-token:P',
				'full:php',
				'full-token:HTML',
			),
			$this->run_html_native_defaults_constant_probe( "define( 'WP_NATIVE_APIS_DISABLE_DEFAULTS', true );" )
		);
	}

	/**
	 * Verifies public native-backed HTML processors apply attribute and class updates.
	 */
	public function test_public_html_mutations_with_native_defaults() {
		if ( ! class_exists( 'WP_HTML_Native_Tag_Processor', false ) || ! method_exists( 'WP_HTML_Native_Tag_Processor', 'supports_public_api' ) || ! class_exists( 'WP_HTML_Native_Processor', false ) || ! method_exists( 'WP_HTML_Native_Processor', 'supports_public_api' ) ) {
			$this->markTestSkipped( 'Native HTML classes are not registered; load the native API extension to run this case.' );
		}

		$tag_processor = new WP_HTML_Tag_Processor( '<p class="a">Text</p>' );
		$this->assertSame( 'WP_HTML_Native_Tag_Processor', get_class( $this->get_native_delegate( $tag_processor ) ) );
		$this->assertTrue( $tag_processor->next_tag( 'p' ) );
		$this->assertTrue( $tag_processor->set_attribute( 'data-id', '7' ) );
		$this->assertSame( '7', $tag_processor->get_attribute( 'data-id' ) );
		$this->assertTrue( $tag_processor->add_class( 'b' ) );
		$this->assertSame( 'a b', $tag_processor->get_attribute( 'class' ) );
		$this->assertSame( '<p data-id="7" class="a b">Text</p>', $tag_processor->get_updated_html() );

		$tag_processor = new WP_HTML_Tag_Processor( '<img>' );
		$this->assertTrue( $tag_processor->next_tag( 'img' ) );
		$this->assertTrue( $tag_processor->set_attribute( 'src', 'https://w.org/logo.png' ) );
		$this->assertTrue( $tag_processor->set_attribute( 'alt', 'An image' ) );
		$this->assertSame( 'https://w.org/logo.png', $tag_processor->get_attribute( 'src' ) );
		$this->assertSame( 'An image', $tag_processor->get_attribute( 'alt' ) );
		$this->assertSame( '<img alt="An image" src="https://w.org/logo.png">', $tag_processor->get_updated_html() );

		$tag_processor = new WP_HTML_Tag_Processor( '<p hidden>Text</p>' );
		$this->assertTrue( $tag_processor->next_tag( 'p' ) );
		$this->assertTrue( $tag_processor->get_attribute( 'hidden' ) );
		$this->assertTrue( $tag_processor->set_attribute( 'hidden', true ) );
		$this->assertTrue( $tag_processor->get_attribute( 'hidden' ) );
		$this->assertSame( '<p hidden>Text</p>', $tag_processor->get_updated_html() );

		$tag_processor = new WP_HTML_Tag_Processor( '<p class="a b">Text</p>' );
		$this->assertTrue( $tag_processor->next_tag( 'p' ) );
		$this->assertTrue( $tag_processor->remove_class( 'a' ) );
		$this->assertSame( 'b', $tag_processor->get_attribute( 'class' ) );
		$this->assertSame( '<p class="b">Text</p>', $tag_processor->get_updated_html() );

		$tag_processor = new WP_HTML_Tag_Processor( '<p>Text</p>' );
		$this->assertTrue( $tag_processor->next_tag( 'p' ) );
		$this->assertTrue( $tag_processor->set_attribute( 'data-id', '7' ) );
		$this->assertFalse( $tag_processor->remove_attribute( 'data-id' ) );
		$this->assertNull( $tag_processor->get_attribute( 'data-id' ) );
		$this->assertSame( '<p>Text</p>', $tag_processor->get_updated_html() );

		$tag_processor = new WP_HTML_Tag_Processor( '<p>Text</p>' );
		$this->assertTrue( $tag_processor->next_tag( 'p' ) );
		$this->assertTrue( $tag_processor->add_class( 'a' ) );
		$this->assertTrue( $tag_processor->remove_class( 'a' ) );
		$this->assertNull( $tag_processor->get_attribute( 'class' ) );
		$this->assertSame( '<p>Text</p>', $tag_processor->get_updated_html() );

		$tag_processor = new WP_HTML_Tag_Processor( '<p class="a b">Text</p>' );
		$this->assertTrue( $tag_processor->next_tag( 'p' ) );
		$this->assertTrue( $tag_processor->remove_class( 'a' ) );
		$this->assertTrue( $tag_processor->add_class( 'a' ) );
		$this->assertSame( 'a b', $tag_processor->get_attribute( 'class' ) );
		$this->assertSame( '<p class="a b">Text</p>', $tag_processor->get_updated_html() );

		$tag_processor = new WP_HTML_Tag_Processor( '<p class="a">Text</p>' );
		$this->assertTrue( $tag_processor->next_tag( 'p' ) );
		$this->assertTrue( $tag_processor->remove_class( 'a' ) );
		$this->assertTrue( $tag_processor->add_class( 'b' ) );
		$this->assertSame( 'b', $tag_processor->get_attribute( 'class' ) );
		$this->assertSame( '<p class="b">Text</p>', $tag_processor->get_updated_html() );

		$html_processor = WP_HTML_Processor::create_fragment( '<section><p class="a">Text</p></section>' );
		$this->assertSame( 'WP_HTML_Native_Processor', get_class( $this->get_native_delegate( $html_processor ) ) );
		$this->assertTrue( $html_processor->next_tag( 'P' ) );
		$this->assertTrue( $html_processor->set_attribute( 'data-id', '7' ) );
		$this->assertSame( '7', $html_processor->get_attribute( 'data-id' ) );
		$this->assertTrue( $html_processor->add_class( 'b' ) );
		$this->assertSame( 'a b', $html_processor->get_attribute( 'class' ) );
		$this->assertSame( '<section><p data-id="7" class="a b">Text</p></section>', $html_processor->get_updated_html() );

		$html_processor = WP_HTML_Processor::create_fragment( '<section><p hidden>Text</p></section>' );
		$this->assertTrue( $html_processor->next_tag( 'P' ) );
		$this->assertTrue( $html_processor->get_attribute( 'hidden' ) );
		$this->assertTrue( $html_processor->set_attribute( 'hidden', true ) );
		$this->assertTrue( $html_processor->get_attribute( 'hidden' ) );
		$this->assertSame( '<section><p hidden>Text</p></section>', $html_processor->get_updated_html() );

		$html_processor = WP_HTML_Processor::create_fragment( '<section><p>Text</p></section>' );
		$this->assertTrue( $html_processor->next_tag( 'P' ) );
		$this->assertTrue( $html_processor->set_attribute( 'data-id', '7' ) );
		$this->assertFalse( $html_processor->remove_attribute( 'data-id' ) );
		$this->assertNull( $html_processor->get_attribute( 'data-id' ) );
		$this->assertSame( '<section><p>Text</p></section>', $html_processor->get_updated_html() );
	}

	/**
	 * Verifies public native-backed tag processors preserve bookmark lifecycle behavior.
	 */
	public function test_public_html_tag_processor_bookmarks_with_native_defaults() {
		if ( ! class_exists( 'WP_HTML_Native_Tag_Processor', false ) || ! method_exists( 'WP_HTML_Native_Tag_Processor', 'supports_public_api' ) ) {
			$this->markTestSkipped( 'Native HTML classes are not registered; load the native API extension to run this case.' );
		}

		$tag_processor = new WP_HTML_Tag_Processor( '<div id="one"><span id="two"></span><p id="three"></p></div>' );
		$this->assertSame( 'WP_HTML_Native_Tag_Processor', get_class( $this->get_native_delegate( $tag_processor ) ) );

		$this->assertTrue( $tag_processor->next_tag( 'span' ) );
		$this->assertSame( 'SPAN', $tag_processor->get_tag() );
		$this->assertSame( 'two', $tag_processor->get_attribute( 'id' ) );
		$this->assertTrue( $tag_processor->set_bookmark( 'saved-span' ) );
		$this->assertTrue( $tag_processor->has_bookmark( 'saved-span' ) );

		$this->assertTrue( $tag_processor->next_tag( 'p' ) );
		$this->assertSame( 'P', $tag_processor->get_tag() );
		$this->assertSame( 'three', $tag_processor->get_attribute( 'id' ) );

		$this->assertTrue( $tag_processor->seek( 'saved-span' ) );
		$this->assertSame( 'SPAN', $tag_processor->get_tag() );
		$this->assertSame( 'two', $tag_processor->get_attribute( 'id' ) );
		$this->assertTrue( $tag_processor->remove_attribute( 'id' ) );
		$this->assertSame( '<div id="one"><span ></span><p id="three"></p></div>', $tag_processor->get_updated_html() );
		$this->assertTrue( $tag_processor->release_bookmark( 'saved-span' ) );
		$this->assertFalse( $tag_processor->has_bookmark( 'saved-span' ) );

		$tag_processor = new WP_HTML_Tag_Processor( '<div><span class="b">Text</span><p>More</p></div>' );
		$this->assertSame( 'WP_HTML_Native_Tag_Processor', get_class( $this->get_native_delegate( $tag_processor ) ) );
		$this->assertTrue( $tag_processor->next_tag( 'span' ) );
		$this->assertTrue( $tag_processor->set_bookmark( 'saved-class' ) );
		$this->assertTrue( $tag_processor->next_tag( 'p' ) );
		$this->assertTrue( $tag_processor->seek( 'saved-class' ) );
		$this->assertTrue( $tag_processor->remove_class( 'b' ) );
		$this->assertTrue( $tag_processor->add_class( 'c' ) );
		$this->assertSame( 'c', $tag_processor->get_attribute( 'class' ) );
		$this->assertSame( '<div><span class="c">Text</span><p>More</p></div>', $tag_processor->get_updated_html() );
	}

	/**
	 * Verifies public native-backed HTML processors preserve bookmark lifecycle behavior.
	 */
	public function test_public_html_processor_bookmarks_with_native_defaults() {
		if ( ! class_exists( 'WP_HTML_Native_Processor', false ) || ! method_exists( 'WP_HTML_Native_Processor', 'supports_public_api' ) ) {
			$this->markTestSkipped( 'WP_HTML_Native_Processor is not registered; load the native API extension to run this case.' );
		}

		$processor = WP_HTML_Processor::create_fragment( '<section><p id="one">One</p><p id="two">Two</p></section>' );
		$this->assertSame( 'WP_HTML_Native_Processor', get_class( $this->get_native_delegate( $processor ) ) );

		$this->assertTrue( $processor->next_tag( 'P' ) );
		$this->assertSame( 'P', $processor->get_tag() );
		$this->assertSame( 'one', $processor->get_attribute( 'id' ) );
		$this->assertTrue( $processor->set_bookmark( 'saved-paragraph' ) );
		$this->assertTrue( $processor->has_bookmark( 'saved-paragraph' ) );

		$this->assertTrue( $processor->next_tag( 'P' ) );
		$this->assertSame( 'P', $processor->get_tag() );
		$this->assertSame( 'two', $processor->get_attribute( 'id' ) );

		$this->assertTrue( $processor->seek( 'saved-paragraph' ) );
		$this->assertSame( 'P', $processor->get_tag() );
		$this->assertSame( 'one', $processor->get_attribute( 'id' ) );
		$this->assertSame( array( 'HTML', 'BODY', 'SECTION', 'P' ), $processor->get_breadcrumbs() );

		$this->assertTrue( $processor->release_bookmark( 'saved-paragraph' ) );
		$this->assertFalse( $processor->has_bookmark( 'saved-paragraph' ) );
	}

	/**
	 * Verifies public HTML normalization keeps PHP serializer semantics with native defaults.
	 */
	public function test_public_html_normalize_uses_php_serializer_with_native_defaults() {
		if ( ! class_exists( 'WP_HTML_Native_Processor', false ) || ! method_exists( 'WP_HTML_Native_Processor', 'supports_public_api' ) ) {
			$this->markTestSkipped( 'WP_HTML_Native_Processor is not registered; load the native API extension to run this case.' );
		}

		$html      = '<a href=#anchor v=5 href="/" enabled>One</a another v=5><!--';
		$expected  = '<a href="#anchor" v="5" enabled>One</a>';
		$processor = WP_HTML_Processor::create_fragment( $html );

		$this->assertSame( 'WP_HTML_Native_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertSame( $expected, $processor->serialize() );
		$this->assertSame( $expected, WP_HTML_Processor::normalize( $html ) );
	}

	/**
	 * Verifies public HTML serialization rejects already-started native defaults.
	 */
	public function test_public_html_serialize_rejects_started_processor_with_native_defaults() {
		if ( ! class_exists( 'WP_HTML_Native_Processor', false ) || ! method_exists( 'WP_HTML_Native_Processor', 'supports_public_api' ) ) {
			$this->markTestSkipped( 'WP_HTML_Native_Processor is not registered; load the native API extension to run this case.' );
		}

		$processor = WP_HTML_Processor::create_fragment( '<p>Text</p>' );
		$this->assertSame( 'WP_HTML_Native_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertTrue( $processor->next_token() );
		$this->assert_serialize_rejects_started_processor( $processor );

		$processor = WP_HTML_Processor::create_fragment( '<p>Text</p>' );
		$this->assertSame( 'WP_HTML_Native_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertCount( 1, $processor->next_token_summary_batch( 1 ) );
		$this->assert_serialize_rejects_started_processor( $processor );
	}

	/**
	 * Verifies remaining-document native aggregates leave public tag processors complete.
	 */
	public function test_public_html_tag_aggregates_complete_native_default_processors() {
		if ( ! class_exists( 'WP_HTML_Native_Tag_Processor', false ) || ! method_exists( 'WP_HTML_Native_Tag_Processor', 'supports_public_api' ) ) {
			$this->markTestSkipped( 'WP_HTML_Native_Tag_Processor is not registered; load the native API extension to run this case.' );
		}

		$processor = $this->create_tag_processor_state_probe( '<p class="a" data-kind="intro"><a href="/x" rel="next">Link</a></p>' );
		$this->assertSame( 'WP_HTML_Native_Tag_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertSame(
			array(
				'tag_count'       => 2,
				'attribute_count' => 1,
			),
			$processor->summarize_attribute_names_with_prefix( 'data-' )
		);
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $processor->get_parser_state() );

		$processor = $this->create_tag_processor_state_probe( '<p class="a" data-kind="intro"><a href="/x" rel="next">Link</a></p>' );
		$this->assertSame( 'WP_HTML_Native_Tag_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertTrue( $processor->next_tag() );
		$this->assertSame(
			array(
				'tag_count'       => 1,
				'attribute_count' => 0,
			),
			$processor->summarize_attribute_names_with_prefix( 'data-' )
		);
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $processor->get_parser_state() );

		$processor = $this->create_tag_processor_state_probe( '<p class="a" data-kind="intro"><a href="/x" rel="next">Link</a></p>' );
		$this->assertSame( 'WP_HTML_Native_Tag_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertSame(
			array(
				'tag_count'             => 2,
				'open_tag_count'        => 2,
				'closing_tag_count'     => 0,
				'attribute_count'       => 4,
				'unique_tag_name_count' => 2,
			),
			$processor->summarize_tag_inventory()
		);
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $processor->get_parser_state() );

		$processor = $this->create_tag_processor_state_probe( '<p class="a" data-kind="intro"><a href="/x" rel="next">Link</a></p>' );
		$this->assertSame( 'WP_HTML_Native_Tag_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertTrue( $processor->next_tag() );
		$this->assertSame(
			array(
				'tag_count'             => 1,
				'open_tag_count'        => 1,
				'closing_tag_count'     => 0,
				'attribute_count'       => 2,
				'unique_tag_name_count' => 1,
			),
			$processor->summarize_tag_inventory()
		);
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $processor->get_parser_state() );

		$processor = $this->create_tag_processor_state_probe( '<p><a href="/x" rel="next">Link</a></p>' );
		$this->assertSame( 'WP_HTML_Native_Tag_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertSame(
			array(
				'tag_count'             => 1,
				'attribute_count'       => 2,
				'attribute_value_bytes' => 6,
			),
			$processor->summarize_matching_tag_attributes( 'a', array( 'href', 'rel' ) )
		);
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $processor->get_parser_state() );

		$processor = $this->create_tag_processor_state_probe( '<p><a href="/x" rel="next">Link</a></p>' );
		$this->assertSame( 'WP_HTML_Native_Tag_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertTrue( $processor->next_tag() );
		$this->assertSame(
			array(
				'tag_count'             => 1,
				'attribute_count'       => 2,
				'attribute_value_bytes' => 6,
			),
			$processor->summarize_matching_tag_attributes( 'a', array( 'href', 'rel' ) )
		);
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $processor->get_parser_state() );

		$processor = $this->create_tag_processor_state_probe( '<p data-kind="intro"><a href="/x" data-kind="link">Link</a></p>' );
		$this->assertSame( 'WP_HTML_Native_Tag_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertSame(
			array(
				'tag_count'     => 2,
				'removed_count' => 2,
				'html'          => '<p ><a href="/x" >Link</a></p>',
			),
			$processor->remove_attributes_with_prefix_from_document( 'data-' )
		);
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $processor->get_parser_state() );

		$processor = $this->create_tag_processor_state_probe( '<p data-kind="intro"><a href="/x" data-kind="link">Link</a></p>' );
		$this->assertSame( 'WP_HTML_Native_Tag_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertTrue( $processor->next_tag() );
		$this->assertSame(
			array(
				'tag_count'     => 1,
				'removed_count' => 1,
				'html'          => '<p data-kind="intro"><a href="/x" >Link</a></p>',
			),
			$processor->remove_attributes_with_prefix_from_document( 'data-' )
		);
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $processor->get_parser_state() );
	}

	/**
	 * Verifies short native tag batches complete public tag processors.
	 */
	public function test_public_html_tag_batches_complete_native_default_processors() {
		if ( ! class_exists( 'WP_HTML_Native_Tag_Processor', false ) || ! method_exists( 'WP_HTML_Native_Tag_Processor', 'supports_public_api' ) ) {
			$this->markTestSkipped( 'WP_HTML_Native_Tag_Processor is not registered; load the native API extension to run this case.' );
		}

		$processor = new WP_HTML_Tag_Processor( '<main><a href="/one">One</a><img src="/x"></main>' );
		$this->assertSame( 'WP_HTML_Native_Tag_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertCount( 2, $processor->next_tag_summary_batch( 2 ) );
		$this->assertCount( 1, $processor->next_tag_summary_batch( 2 ) );
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $this->get_parser_state( $processor ) );
		$this->assertNull( $processor->get_tag() );

		$processor = new WP_HTML_Tag_Processor( '<main><a data-id="one">One</a><img src="/x"></main>' );
		$this->assertSame( 'WP_HTML_Native_Tag_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertCount( 2, $processor->next_tag_prefix_summary_batch( 'data-', 2 ) );
		$this->assertCount( 1, $processor->next_tag_prefix_summary_batch( 'data-', 2 ) );
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $this->get_parser_state( $processor ) );
		$this->assertNull( $processor->get_tag() );

		$processor = new WP_HTML_Tag_Processor( '<main><a data-id="one">One</a><img src="/x"></main>' );
		$this->assertSame( 'WP_HTML_Native_Tag_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertSame( "2\x1f1", $processor->next_tag_prefix_count_compact_batch( 'data-', 2 ) );
		$this->assertSame( "1\x1f0", $processor->next_tag_prefix_count_compact_batch( 'data-', 2 ) );
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $this->get_parser_state( $processor ) );
		$this->assertNull( $processor->get_tag() );

		$processor = new WP_HTML_Tag_Processor( '<main><a href="/one">One</a><img src="/x"></main>' );
		$this->assertSame( 'WP_HTML_Native_Tag_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertCount( 1, $processor->next_matching_tag_summary_batch( 'A', 2 ) );
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $this->get_parser_state( $processor ) );
		$this->assertNull( $processor->get_tag() );

		$processor = new WP_HTML_Tag_Processor( '<main><a href="/one">One</a><img src="/x"></main>' );
		$this->assertSame( 'WP_HTML_Native_Tag_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertCount( 1, $processor->next_matching_tag_attribute_summary_batch( 'A', 'href', 2 ) );
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $this->get_parser_state( $processor ) );
		$this->assertNull( $processor->get_tag() );

		$processor = new WP_HTML_Tag_Processor( '<main><a href="/one">One</a><img src="/x"></main>' );
		$this->assertSame( 'WP_HTML_Native_Tag_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertCount( 1, $processor->next_matching_tag_attributes_summary_batch( 'A', array( 'href', 'data-id' ), 2 ) );
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $this->get_parser_state( $processor ) );
		$this->assertNull( $processor->get_tag() );
	}

	/**
	 * Verifies short native compact tag batches complete public tag processors.
	 */
	public function test_public_html_compact_tag_batches_complete_native_default_processors() {
		if ( ! class_exists( 'WP_HTML_Native_Tag_Processor', false ) || ! method_exists( 'WP_HTML_Native_Tag_Processor', 'supports_public_api' ) ) {
			$this->markTestSkipped( 'WP_HTML_Native_Tag_Processor is not registered; load the native API extension to run this case.' );
		}

		$processor = new WP_HTML_Tag_Processor( '<main><a href="/one">One</a><img src="/x"></main>' );
		$this->assertSame( 'WP_HTML_Native_Tag_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertCount( 2, explode( "\x1e", $processor->next_tag_compact_summary_batch( 2 ) ) );
		$this->assertCount( 1, explode( "\x1e", $processor->next_tag_compact_summary_batch( 2 ) ) );
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $this->get_parser_state( $processor ) );
		$this->assertNull( $processor->get_tag() );

		$processor = new WP_HTML_Tag_Processor( '<main><a data-id="one">One</a><img src="/x"></main>' );
		$this->assertSame( 'WP_HTML_Native_Tag_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertCount( 2, explode( "\x1e", $processor->next_tag_prefix_compact_summary_batch( 'data-', 2 ) ) );
		$this->assertCount( 1, explode( "\x1e", $processor->next_tag_prefix_compact_summary_batch( 'data-', 2 ) ) );
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $this->get_parser_state( $processor ) );
		$this->assertNull( $processor->get_tag() );

		$processor = new WP_HTML_Tag_Processor( '<main><a href="/one">One</a><img src="/x"></main>' );
		$this->assertSame( 'WP_HTML_Native_Tag_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertCount( 1, explode( "\x1e", $processor->next_matching_tag_compact_summary_batch( 'A', 2 ) ) );
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $this->get_parser_state( $processor ) );
		$this->assertNull( $processor->get_tag() );

		$processor = new WP_HTML_Tag_Processor( '<main><a href="/one">One</a><img src="/x"></main>' );
		$this->assertSame( 'WP_HTML_Native_Tag_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertCount( 1, explode( "\x1e", $processor->next_matching_tag_attribute_compact_summary_batch( 'A', 'href', 2 ) ) );
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $this->get_parser_state( $processor ) );
		$this->assertNull( $processor->get_tag() );

		$processor = new WP_HTML_Tag_Processor( '<main><a href="/one">One</a><img src="/x"></main>' );
		$this->assertSame( 'WP_HTML_Native_Tag_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertCount( 1, explode( "\x1e", $processor->next_matching_tag_attributes_compact_summary_batch( 'A', array( 'href', 'data-id' ), 2 ) ) );
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $this->get_parser_state( $processor ) );
		$this->assertNull( $processor->get_tag() );
	}

	/**
	 * Verifies inherited aggregate scans complete public HTML processors too.
	 */
	public function test_public_html_processor_aggregates_complete_native_default_processors() {
		if ( ! class_exists( 'WP_HTML_Native_Processor', false ) || ! method_exists( 'WP_HTML_Native_Processor', 'supports_public_api' ) ) {
			$this->markTestSkipped( 'WP_HTML_Native_Processor is not registered; load the native API extension to run this case.' );
		}

		$processor = WP_HTML_Processor::create_fragment( '<p data-kind="intro"><a href="/x" data-kind="link">Link</a></p>' );
		$this->assertSame( 'WP_HTML_Native_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertSame(
			array(
				'tag_count'       => 2,
				'attribute_count' => 2,
			),
			$processor->summarize_attribute_names_with_prefix( 'data-' )
		);
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $this->get_parser_state( $processor ) );

		$processor = WP_HTML_Processor::create_fragment( '<p class="a" data-kind="intro"><a href="/x" rel="next">Link</a></p>' );
		$this->assertSame( 'WP_HTML_Native_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertTrue( $processor->next_tag() );
		$this->assertSame(
			array(
				'tag_count'       => 1,
				'attribute_count' => 0,
			),
			$processor->summarize_attribute_names_with_prefix( 'data-' )
		);
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $this->get_parser_state( $processor ) );

		$processor = WP_HTML_Processor::create_fragment( '<p class="a" data-kind="intro"><a href="/x" rel="next">Link</a></p>' );
		$this->assertSame( 'WP_HTML_Native_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertTrue( $processor->next_tag() );
		$this->assertSame(
			array(
				'tag_count'             => 1,
				'open_tag_count'        => 1,
				'closing_tag_count'     => 0,
				'attribute_count'       => 2,
				'unique_tag_name_count' => 1,
			),
			$processor->summarize_tag_inventory()
		);
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $this->get_parser_state( $processor ) );

		$processor = WP_HTML_Processor::create_fragment( '<p><a href="/x" rel="next">Link</a></p>' );
		$this->assertSame( 'WP_HTML_Native_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertTrue( $processor->next_tag() );
		$this->assertSame(
			array(
				'tag_count'             => 1,
				'attribute_count'       => 2,
				'attribute_value_bytes' => 6,
			),
			$processor->summarize_matching_tag_attributes( 'a', array( 'href', 'rel' ) )
		);
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $this->get_parser_state( $processor ) );

		$processor = WP_HTML_Processor::create_fragment( '<p data-kind="intro"><a href="/x" data-kind="link">Link</a></p>' );
		$this->assertSame( 'WP_HTML_Native_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertTrue( $processor->next_tag() );
		$this->assertSame(
			array(
				'tag_count'     => 1,
				'removed_count' => 1,
				'html'          => '<p data-kind="intro"><a href="/x" >Link</a></p>',
			),
			$processor->remove_attributes_with_prefix_from_document( 'data-' )
		);
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $this->get_parser_state( $processor ) );
	}

	/**
	 * Verifies native token summary batches complete public HTML processors.
	 */
	public function test_public_html_processor_token_batches_complete_native_default_processors() {
		if ( ! class_exists( 'WP_HTML_Native_Processor', false ) || ! method_exists( 'WP_HTML_Native_Processor', 'supports_public_api' ) ) {
			$this->markTestSkipped( 'WP_HTML_Native_Processor is not registered; load the native API extension to run this case.' );
		}

		$processor = WP_HTML_Processor::create_fragment( '<section><p>Text</p></section>' );
		$this->assertSame( 'WP_HTML_Native_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertCount( 5, $processor->next_token_summary_batch( 64 ) );
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $this->get_parser_state( $processor ) );
		$this->assertNull( $processor->get_token_type() );

		$processor = WP_HTML_Processor::create_fragment( '<section><p>Text</p></section>' );
		$this->assertSame( 'WP_HTML_Native_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertCount( 2, $processor->next_token_summary_batch( 2 ) );
		$this->assertCount( 2, $processor->next_token_summary_batch( 2 ) );
		$this->assertCount( 1, $processor->next_token_summary_batch( 2 ) );
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $this->get_parser_state( $processor ) );
		$this->assertNull( $processor->get_token_type() );

		$processor = WP_HTML_Processor::create_fragment( '<section><p>Text</p></section>' );
		$this->assertSame( 'WP_HTML_Native_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$batch = $processor->next_token_compact_summary_batch( 64 );
		$this->assertIsString( $batch );
		$this->assertCount( 5, explode( "\x1e", $batch ) );
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $this->get_parser_state( $processor ) );
		$this->assertNull( $processor->get_token_type() );

		$processor = WP_HTML_Processor::create_fragment( '<section><p>Text</p></section>' );
		$this->assertSame( 'WP_HTML_Native_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertCount( 2, explode( "\x1e", $processor->next_token_compact_summary_batch( 2 ) ) );
		$this->assertCount( 2, explode( "\x1e", $processor->next_token_compact_summary_batch( 2 ) ) );
		$this->assertCount( 1, explode( "\x1e", $processor->next_token_compact_summary_batch( 2 ) ) );
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $this->get_parser_state( $processor ) );
		$this->assertNull( $processor->get_token_type() );
	}

	/**
	 * Verifies inherited native tag batches complete public HTML processors.
	 */
	public function test_public_html_processor_tag_batches_complete_native_default_processors() {
		if ( ! class_exists( 'WP_HTML_Native_Processor', false ) || ! method_exists( 'WP_HTML_Native_Processor', 'supports_public_api' ) ) {
			$this->markTestSkipped( 'WP_HTML_Native_Processor is not registered; load the native API extension to run this case.' );
		}

		$html = '<main><a href="/one" data-id="one">One</a><img src="/x"></main>';

		$processor = WP_HTML_Processor::create_fragment( $html );
		$this->assertSame( 'WP_HTML_Native_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertCount( 2, $processor->next_tag_summary_batch( 2 ) );
		$this->assertCount( 1, $processor->next_tag_summary_batch( 2 ) );
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $this->get_parser_state( $processor ) );
		$this->assertNull( $processor->get_tag() );

		$processor = WP_HTML_Processor::create_fragment( $html );
		$this->assertSame( 'WP_HTML_Native_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertCount( 2, $processor->next_tag_prefix_summary_batch( 'data-', 2 ) );
		$this->assertCount( 1, $processor->next_tag_prefix_summary_batch( 'data-', 2 ) );
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $this->get_parser_state( $processor ) );
		$this->assertNull( $processor->get_tag() );

		$processor = WP_HTML_Processor::create_fragment( $html );
		$this->assertSame( 'WP_HTML_Native_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertSame( "2\x1f1", $processor->next_tag_prefix_count_compact_batch( 'data-', 2 ) );
		$this->assertSame( "1\x1f0", $processor->next_tag_prefix_count_compact_batch( 'data-', 2 ) );
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $this->get_parser_state( $processor ) );
		$this->assertNull( $processor->get_tag() );

		$processor = WP_HTML_Processor::create_fragment( $html );
		$this->assertSame( 'WP_HTML_Native_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertCount( 1, $processor->next_matching_tag_summary_batch( 'A', 2 ) );
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $this->get_parser_state( $processor ) );
		$this->assertNull( $processor->get_tag() );

		$processor = WP_HTML_Processor::create_fragment( $html );
		$this->assertSame( 'WP_HTML_Native_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertCount( 1, $processor->next_matching_tag_attribute_summary_batch( 'A', 'href', 2 ) );
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $this->get_parser_state( $processor ) );
		$this->assertNull( $processor->get_tag() );

		$processor = WP_HTML_Processor::create_fragment( $html );
		$this->assertSame( 'WP_HTML_Native_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertCount( 1, $processor->next_matching_tag_attributes_summary_batch( 'A', array( 'href', 'data-id' ), 2 ) );
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $this->get_parser_state( $processor ) );
		$this->assertNull( $processor->get_tag() );
	}

	/**
	 * Verifies inherited native compact tag batches complete public HTML processors.
	 */
	public function test_public_html_processor_compact_tag_batches_complete_native_default_processors() {
		if ( ! class_exists( 'WP_HTML_Native_Processor', false ) || ! method_exists( 'WP_HTML_Native_Processor', 'supports_public_api' ) ) {
			$this->markTestSkipped( 'WP_HTML_Native_Processor is not registered; load the native API extension to run this case.' );
		}

		$html = '<main><a href="/one" data-id="one">One</a><img src="/x"></main>';

		$processor = WP_HTML_Processor::create_fragment( $html );
		$this->assertSame( 'WP_HTML_Native_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertCount( 2, explode( "\x1e", $processor->next_tag_compact_summary_batch( 2 ) ) );
		$this->assertCount( 1, explode( "\x1e", $processor->next_tag_compact_summary_batch( 2 ) ) );
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $this->get_parser_state( $processor ) );
		$this->assertNull( $processor->get_tag() );

		$processor = WP_HTML_Processor::create_fragment( $html );
		$this->assertSame( 'WP_HTML_Native_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertCount( 2, explode( "\x1e", $processor->next_tag_prefix_compact_summary_batch( 'data-', 2 ) ) );
		$this->assertCount( 1, explode( "\x1e", $processor->next_tag_prefix_compact_summary_batch( 'data-', 2 ) ) );
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $this->get_parser_state( $processor ) );
		$this->assertNull( $processor->get_tag() );

		$processor = WP_HTML_Processor::create_fragment( $html );
		$this->assertSame( 'WP_HTML_Native_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertCount( 1, explode( "\x1e", $processor->next_matching_tag_compact_summary_batch( 'A', 2 ) ) );
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $this->get_parser_state( $processor ) );
		$this->assertNull( $processor->get_tag() );

		$processor = WP_HTML_Processor::create_fragment( $html );
		$this->assertSame( 'WP_HTML_Native_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertCount( 1, explode( "\x1e", $processor->next_matching_tag_attribute_compact_summary_batch( 'A', 'href', 2 ) ) );
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $this->get_parser_state( $processor ) );
		$this->assertNull( $processor->get_tag() );

		$processor = WP_HTML_Processor::create_fragment( $html );
		$this->assertSame( 'WP_HTML_Native_Processor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertCount( 1, explode( "\x1e", $processor->next_matching_tag_attributes_compact_summary_batch( 'A', array( 'href', 'data-id' ), 2 ) ) );
		$this->assertSame( WP_HTML_Tag_Processor::STATE_COMPLETE, $this->get_parser_state( $processor ) );
		$this->assertNull( $processor->get_tag() );
	}

	/**
	 * Asserts that a started public HTML processor rejects serialization.
	 *
	 * @param WP_HTML_Processor $processor Started processor.
	 */
	private function assert_serialize_rejects_started_processor( $processor ) {
		$warning = null;
		set_error_handler(
			function ( $error_number, $error_string ) use ( &$warning ) {
				if ( E_USER_WARNING !== $error_number ) {
					return false;
				}

				$warning = $error_string;

				return true;
			},
			E_USER_WARNING
		);

		try {
			$this->assertNull( $processor->serialize() );
		} finally {
			restore_error_handler();
		}

		$this->assertStringContainsString( 'already started processing cannot serialize', $warning );
	}

	/**
	 * Creates a public tag processor exposing its parser state for conformance checks.
	 *
	 * @param string $html HTML to parse.
	 * @return WP_HTML_Tag_Processor Tag processor with `get_parser_state()`.
	 */
	private function create_tag_processor_state_probe( $html ) {
		return new class( $html ) extends WP_HTML_Tag_Processor {
			public function get_parser_state() {
				return $this->parser_state;
			}
		};
	}

	/**
	 * Verifies the first native slice matches the PHP tag processor behavior.
	 *
	 * @dataProvider data_tag_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_tag_processor_reads_tags_and_attributes( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Tag_Processor' );

		$processor = $this->create_tag_processor(
			$implementation,
			'<!--comment--><MAIN data-z="7" DATA-a="post"><a href="/x?one=1&amp;two=2" title="A&#x2F;B&#47;C" data-label="A&nbsp;B&copy;&reg;&hellip;&mdash;&notin;">Link</a></MAIN>'
		);

		$this->assertTrue( $processor->next_tag(), 'Expected the first HTML tag.' );
		$this->assertSame( 'MAIN', $processor->get_tag() );
		$this->assertSame( '7', $processor->get_attribute( 'data-z' ) );
		$this->assertSame( 'post', $processor->get_attribute( 'data-a' ) );
		$this->assertSame(
			array( 'data-z', 'data-a' ),
			$processor->get_attribute_names_with_prefix( 'data-' )
		);
		$this->skip_if_html_method_is_unavailable( $processor, 'count_attribute_names_with_prefix' );
		$this->assertSame( 2, $processor->count_attribute_names_with_prefix( 'data-' ) );
		$this->assertSame( 0, $processor->count_attribute_names_with_prefix( 'aria-' ) );

		$this->assertTrue( $processor->next_tag(), 'Expected the nested anchor tag.' );
		$this->assertSame( 'A', $processor->get_tag() );
		$this->assertSame( '/x?one=1&two=2', $processor->get_attribute( 'href' ) );
		$this->assertSame( 'A/B/C', $processor->get_attribute( 'title' ) );
		$this->assertSame( "A\u{00a0}B\u{00a9}\u{00ae}\u{2026}\u{2014}\u{2209}", $processor->get_attribute( 'data-label' ) );
		$this->assertFalse( $processor->next_tag(), 'next_tag() should skip closing tags by default.' );

		$processor = $this->create_tag_processor(
			$implementation,
			'<p .x="dot" @x="at" data-x="ok">Text</p>'
		);

		$this->assertTrue( $processor->next_tag(), 'Expected the paragraph tag with unusual attributes.' );
		$this->assertSame( array( '.x', '@x', 'data-x' ), $processor->get_attribute_names_with_prefix( '' ) );
		$this->assertSame( 'dot', $processor->get_attribute( '.x' ) );
		$this->assertSame( 'at', $processor->get_attribute( '@x' ) );
		$this->assertNull( $processor->get_attribute( 'x' ) );

		$processor = $this->create_tag_processor(
			$implementation,
			'<p =b a/=c data-x="ok">Text</p>'
		);

		$this->assertTrue( $processor->next_tag(), 'Expected the paragraph tag with equals-start attributes.' );
		$this->assertSame( array( '=b', 'a', '=c', 'data-x' ), $processor->get_attribute_names_with_prefix( '' ) );
		$this->assertTrue( $processor->get_attribute( '=b' ) );
		$this->assertTrue( $processor->get_attribute( '=c' ) );
		$this->assertNull( $processor->get_attribute( 'b' ) );
		$this->assertSame( array( '=b', '=c' ), $processor->get_attribute_names_with_prefix( '=' ) );
		$this->assertSame( 2, $processor->count_attribute_names_with_prefix( '=' ) );

		$processor = $this->create_tag_processor(
			$implementation,
			'<div data-id="1"></div>'
		);

		$this->assertTrue( $this->next_tag_visiting_closers( $processor ), 'Expected the opening tag.' );
		$this->assertSame( array( 'data-id' ), $processor->get_attribute_names_with_prefix( 'data-' ) );
		$this->assertSame( 1, $processor->count_attribute_names_with_prefix( 'data-' ) );
		$this->assertTrue( $this->next_tag_visiting_closers( $processor ), 'Expected the closing tag.' );
		$this->assertTrue( $processor->is_tag_closer() );
		$this->assertNull( $processor->get_attribute_names_with_prefix( 'data-' ) );
		$this->assertNull( $processor->count_attribute_names_with_prefix( 'data-' ) );
	}

	/**
	 * Verifies attribute removal works for the shared sanitization workflow.
	 *
	 * @dataProvider data_tag_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_tag_processor_removes_attributes( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Tag_Processor' );

		$processor = $this->create_tag_processor(
			$implementation,
			'<main data-id="7" DATA-id="duplicate" class="entry"><a DATA-kind="nav" data-track="1" href="/x">Link</a></main>'
		);

		$this->assertTrue( $processor->next_tag(), 'Expected the main tag.' );
		$this->assertSame( array( 'data-id' ), $processor->get_attribute_names_with_prefix( 'data-' ) );
		$this->assertTrue( $processor->remove_attribute( 'data-id' ) );
		$this->assertNull( $processor->get_attribute( 'data-id' ) );
		$this->assertSame(
			'<main   class="entry"><a DATA-kind="nav" data-track="1" href="/x">Link</a></main>',
			$processor->get_updated_html()
		);

		$this->assertTrue( $processor->next_tag(), 'Expected the anchor tag after removing from the main tag.' );
		$this->skip_if_html_method_is_unavailable( $processor, 'remove_attributes_with_prefix' );
		$this->assertSame( 2, $processor->remove_attributes_with_prefix( 'data-' ) );
		$this->assertNull( $processor->get_attribute( 'data-kind' ) );
		$this->assertSame( 0, $processor->remove_attributes_with_prefix( 'data-' ) );
		$this->assertSame(
			'<main   class="entry"><a   href="/x">Link</a></main>',
			$processor->get_updated_html()
		);

		$processor = $this->create_tag_processor(
			$implementation,
			'<p data-x disabled data-y="1">Text</p>'
		);

		$this->assertTrue( $processor->next_tag(), 'Expected the paragraph tag with boolean attributes.' );
		$this->assertTrue( $processor->remove_attribute( 'disabled' ) );
		$this->assertSame( '<p data-x  data-y="1">Text</p>', $processor->get_updated_html() );

		$processor = $this->create_tag_processor(
			$implementation,
			'<p data-x disabled data-y="1">Text</p>'
		);

		$this->assertTrue( $processor->next_tag(), 'Expected the paragraph tag with boolean attributes.' );
		$this->assertSame( 3, $processor->remove_attributes_with_prefix( 'd' ) );
		$this->assertSame( '<p   >Text</p>', $processor->get_updated_html() );
	}

	/**
	 * Verifies document-level prefix removals match the per-tag workflow.
	 *
	 * @dataProvider data_tag_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_tag_processor_removes_prefixed_attributes_from_document( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Tag_Processor' );

		$processor = $this->create_tag_processor(
			$implementation,
			'<main data-id="7" class="entry"><a DATA-kind="nav" data-track="1" href="/x">Link</a><img data-src="/x"></main>'
		);

		$this->assertSame(
			array(
				'tag_count'     => 5,
				'removed_count' => 4,
				'html'          => '<main  class="entry"><a   href="/x">Link</a><img ></main>',
			),
			$this->remove_attributes_with_prefix_from_document( $processor, 'data-', true )
		);

		$processor = $this->create_tag_processor(
			$implementation,
			'<main data-id="7"><a data-kind="nav"></a></main>'
		);

		$this->assertTrue( $this->next_tag_visiting_closers( $processor ), 'Expected the opening main tag.' );
		$this->assertSame(
			array(
				'tag_count'     => 1,
				'removed_count' => 1,
				'html'          => '<main data-id="7"><a ></a></main>',
			),
			$this->remove_attributes_with_prefix_from_document( $processor, 'data-', false )
		);
	}

	/**
	 * Verifies document-level prefix summaries match the per-tag workflow.
	 *
	 * @dataProvider data_tag_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_tag_processor_summarizes_prefixed_attributes( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Tag_Processor' );

		$processor = $this->create_tag_processor(
			$implementation,
			'<main data-id="7"><a data-kind="nav" href="/x">Link</a><img data-src="/x"></main>'
		);

		$this->assertSame(
			array(
				'tag_count'       => 5,
				'attribute_count' => 3,
			),
			$this->summarize_attribute_names_with_prefix( $processor, 'data-', true )
		);

		$processor = $this->create_tag_processor(
			$implementation,
			'<main data-id="7"><a data-kind="nav" href="/x">Link</a><img data-src="/x"></main>'
		);

		$this->assertSame(
			array(
				'tag_count'       => 3,
				'attribute_count' => 3,
			),
			$this->summarize_attribute_names_with_prefix( $processor, 'data-', false )
		);
	}

	/**
	 * Verifies document-level tag inventory summaries match the per-tag workflow.
	 *
	 * @dataProvider data_tag_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_tag_processor_summarizes_tag_inventory( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Tag_Processor' );

		$processor = $this->create_tag_processor(
			$implementation,
			'<main data-id="7"><a data-kind="nav" href="/x">Link</a><img data-src="/x"></main>'
		);

		$this->assertSame(
			array(
				'tag_count'             => 5,
				'open_tag_count'        => 3,
				'closing_tag_count'     => 2,
				'attribute_count'       => 4,
				'unique_tag_name_count' => 3,
			),
			$this->summarize_tag_inventory( $processor, true )
		);

		$processor = $this->create_tag_processor(
			$implementation,
			'<main data-id="7"><a data-kind="nav" href="/x">Link</a><img data-src="/x"></main>'
		);

		$this->assertSame(
			array(
				'tag_count'             => 3,
				'open_tag_count'        => 3,
				'closing_tag_count'     => 0,
				'attribute_count'       => 4,
				'unique_tag_name_count' => 3,
			),
			$this->summarize_tag_inventory( $processor, false )
		);
	}

	/**
	 * Verifies heading inventory summaries match the per-tag workflow.
	 *
	 * @dataProvider data_tag_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_tag_processor_summarizes_heading_inventory( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Tag_Processor' );

		$html = '<main><h1>A</h1><section><h2>B</h2><h3>C</h3></section></main>';

		$processor = $this->create_tag_processor( $implementation, $html );
		$this->assertSame(
			array(
				'tag_count'     => 10,
				'heading_count' => 3,
				'h1_count'      => 1,
				'h2_count'      => 1,
				'h3_count'      => 1,
				'h4_count'      => 0,
				'h5_count'      => 0,
				'h6_count'      => 0,
			),
			$this->summarize_heading_inventory( $processor, true )
		);

		$processor = $this->create_tag_processor( $implementation, $html );
		$this->assertSame(
			array(
				'tag_count'     => 5,
				'heading_count' => 3,
				'h1_count'      => 1,
				'h2_count'      => 1,
				'h3_count'      => 1,
				'h4_count'      => 0,
				'h5_count'      => 0,
				'h6_count'      => 0,
			),
			$this->summarize_heading_inventory( $processor, false )
		);
	}

	/**
	 * Verifies ID inventory summaries match the per-tag workflow.
	 *
	 * @dataProvider data_tag_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_tag_processor_summarizes_id_inventory( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Tag_Processor' );

		$html = '<main id="root"><p id="intro">One</p><section id="intro"><span id></span></section></main>';

		$processor = $this->create_tag_processor( $implementation, $html );
		$this->assertSame(
			array(
				'tag_count'          => 8,
				'id_tag_count'       => 4,
				'unique_id_count'    => 2,
				'duplicate_id_count' => 1,
				'id_value_bytes'     => 14,
			),
			$this->summarize_id_inventory( $processor, true )
		);

		$processor = $this->create_tag_processor( $implementation, $html );
		$this->assertSame(
			array(
				'tag_count'          => 4,
				'id_tag_count'       => 4,
				'unique_id_count'    => 2,
				'duplicate_id_count' => 1,
				'id_value_bytes'     => 14,
			),
			$this->summarize_id_inventory( $processor, false )
		);
	}

	/**
	 * Verifies attribute inventory summaries match the per-tag workflow.
	 *
	 * @dataProvider data_tag_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_tag_processor_summarizes_attribute_inventory( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Tag_Processor' );

		$html = '<main data-id="7" hidden><p class="x" title="A &amp; B">Text</p></main>';

		$processor = $this->create_tag_processor( $implementation, $html );
		$this->assertSame(
			array(
				'tag_count'                   => 4,
				'attribute_count'             => 4,
				'unique_attribute_name_count' => 4,
				'attribute_value_bytes'       => 7,
			),
			$this->summarize_attribute_inventory( $processor, true )
		);

		$processor = $this->create_tag_processor( $implementation, $html );
		$this->assertSame(
			array(
				'tag_count'                   => 2,
				'attribute_count'             => 4,
				'unique_attribute_name_count' => 4,
				'attribute_value_bytes'       => 7,
			),
			$this->summarize_attribute_inventory( $processor, false )
		);
	}

	/**
	 * Verifies data-attribute inventory summaries match the per-tag workflow.
	 *
	 * @dataProvider data_tag_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_tag_processor_summarizes_data_attribute_inventory( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Tag_Processor' );

		$html = '<div data-id="1" data-kind="hero" data-empty data-value="A &amp; B"></div><p data-kind="copy"></p>';

		$processor = $this->create_tag_processor( $implementation, $html );
		$this->assertSame(
			array(
				'tag_count'                        => 4,
				'data_attribute_tag_count'         => 2,
				'data_attribute_count'             => 5,
				'unique_data_attribute_name_count' => 4,
				'data_attribute_value_bytes'       => 14,
			),
			$this->summarize_data_attribute_inventory( $processor, true )
		);

		$processor = $this->create_tag_processor( $implementation, $html );
		$this->assertSame(
			array(
				'tag_count'                        => 2,
				'data_attribute_tag_count'         => 2,
				'data_attribute_count'             => 5,
				'unique_data_attribute_name_count' => 4,
				'data_attribute_value_bytes'       => 14,
			),
			$this->summarize_data_attribute_inventory( $processor, false )
		);
	}

	/**
	 * Verifies ARIA attribute inventory summaries match the per-tag workflow.
	 *
	 * @dataProvider data_tag_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_tag_processor_summarizes_aria_attribute_inventory( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Tag_Processor' );

		$html = '<button aria-label="Close" aria-expanded="false"></button><div aria-hidden aria-label="Panel" data-id="1"></div>';

		$processor = $this->create_tag_processor( $implementation, $html );
		$this->assertSame(
			array(
				'tag_count'                        => 4,
				'aria_attribute_tag_count'         => 2,
				'aria_attribute_count'             => 4,
				'unique_aria_attribute_name_count' => 3,
				'aria_attribute_value_bytes'       => 15,
			),
			$this->summarize_aria_attribute_inventory( $processor, true )
		);

		$processor = $this->create_tag_processor( $implementation, $html );
		$this->assertSame(
			array(
				'tag_count'                        => 2,
				'aria_attribute_tag_count'         => 2,
				'aria_attribute_count'             => 4,
				'unique_aria_attribute_name_count' => 3,
				'aria_attribute_value_bytes'       => 15,
			),
			$this->summarize_aria_attribute_inventory( $processor, false )
		);
	}

	/**
	 * Verifies class inventory summaries match the per-tag workflow.
	 *
	 * @dataProvider data_tag_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_tag_processor_summarizes_class_inventory( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Tag_Processor' );

		$html = '<main class="wrap entry"><p class="lede entry">Text</p><span class>Bool</span><div class="card primary primary"></div></main>';

		$processor = $this->create_tag_processor( $implementation, $html );
		$this->assertSame(
			array(
				'tag_count'               => 8,
				'class_attribute_count'   => 4,
				'class_name_count'        => 6,
				'unique_class_name_count' => 5,
				'class_value_bytes'       => 40,
			),
			$this->summarize_class_inventory( $processor, true )
		);

		$processor = $this->create_tag_processor( $implementation, $html );
		$this->assertSame(
			array(
				'tag_count'               => 4,
				'class_attribute_count'   => 4,
				'class_name_count'        => 6,
				'unique_class_name_count' => 5,
				'class_value_bytes'       => 40,
			),
			$this->summarize_class_inventory( $processor, false )
		);
	}

	/**
	 * Verifies resource inventory summaries match the per-tag workflow.
	 *
	 * @dataProvider data_tag_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_tag_processor_summarizes_resource_inventory( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Tag_Processor' );

		$html = '<main><a href="/one">One</a><img src="/one.png" alt=""><script src="/app.js"></script><link href="/main.css" rel="stylesheet"><source src="/clip.webm"><a>No href</a></main>';

		$processor = $this->create_tag_processor( $implementation, $html );
		$this->assertSame(
			array(
				'tag_count'                      => 10,
				'resource_tag_count'             => 5,
				'resource_attribute_count'       => 5,
				'unique_resource_tag_name_count' => 5,
				'resource_value_bytes'           => 38,
			),
			$this->summarize_resource_inventory( $processor, true )
		);

		$processor = $this->create_tag_processor( $implementation, $html );
		$this->assertSame(
			array(
				'tag_count'                      => 7,
				'resource_tag_count'             => 5,
				'resource_attribute_count'       => 5,
				'unique_resource_tag_name_count' => 5,
				'resource_value_bytes'           => 38,
			),
			$this->summarize_resource_inventory( $processor, false )
		);
	}

	/**
	 * Verifies image inventory summaries match the per-tag workflow.
	 *
	 * @dataProvider data_tag_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_tag_processor_summarizes_image_inventory( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Tag_Processor' );

		$html = '<img src="/a.png" alt=""><img src="/b.png" alt="Bee" width="10" height="20"><img alt><p></p>';

		$processor = $this->create_tag_processor( $implementation, $html );
		$this->assertSame(
			array(
				'tag_count'       => 5,
				'image_count'     => 3,
				'src_count'       => 2,
				'alt_count'       => 3,
				'empty_alt_count' => 2,
				'dimension_count' => 1,
				'src_value_bytes' => 12,
				'alt_value_bytes' => 3,
			),
			$this->summarize_image_inventory( $processor, true )
		);

		$processor = $this->create_tag_processor( $implementation, $html );
		$this->assertSame(
			array(
				'tag_count'       => 4,
				'image_count'     => 3,
				'src_count'       => 2,
				'alt_count'       => 3,
				'empty_alt_count' => 2,
				'dimension_count' => 1,
				'src_value_bytes' => 12,
				'alt_value_bytes' => 3,
			),
			$this->summarize_image_inventory( $processor, false )
		);
	}

	/**
	 * Verifies script inventory summaries match the per-tag workflow.
	 *
	 * @dataProvider data_tag_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_tag_processor_summarizes_script_inventory( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Tag_Processor' );

		$html = '<main><script src="/app.js" type="module" async></script><script>let a = 1;</script><script defer src="/legacy.js"></script></main>';

		$processor = $this->create_tag_processor( $implementation, $html );
		$this->assertSame(
			array(
				'tag_count'           => 5,
				'script_count'        => 3,
				'src_count'           => 2,
				'module_count'        => 1,
				'async_count'         => 1,
				'defer_count'         => 1,
				'inline_script_bytes' => 10,
				'src_value_bytes'     => 17,
			),
			$this->summarize_script_inventory( $processor, true )
		);

		$processor = $this->create_tag_processor( $implementation, $html );
		$this->assertSame(
			array(
				'tag_count'           => 4,
				'script_count'        => 3,
				'src_count'           => 2,
				'module_count'        => 1,
				'async_count'         => 1,
				'defer_count'         => 1,
				'inline_script_bytes' => 10,
				'src_value_bytes'     => 17,
			),
			$this->summarize_script_inventory( $processor, false )
		);
	}

	/**
	 * Verifies form inventory summaries match the per-tag workflow.
	 *
	 * @dataProvider data_tag_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_tag_processor_summarizes_form_inventory( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Tag_Processor' );

		$html = '<form><input name="q"><input name="page"><input><button name="go"></button></form>';

		$processor = $this->create_tag_processor( $implementation, $html );
		$this->assertSame(
			array(
				'tag_count'                 => 7,
				'form_count'                => 1,
				'control_count'             => 4,
				'named_control_count'       => 3,
				'unique_control_name_count' => 3,
				'control_name_value_bytes'  => 7,
			),
			$this->summarize_form_inventory( $processor, true )
		);

		$processor = $this->create_tag_processor( $implementation, $html );
		$this->assertSame(
			array(
				'tag_count'                 => 5,
				'form_count'                => 1,
				'control_count'             => 4,
				'named_control_count'       => 3,
				'unique_control_name_count' => 3,
				'control_name_value_bytes'  => 7,
			),
			$this->summarize_form_inventory( $processor, false )
		);
	}

	/**
	 * Verifies chunked prefix summaries match the per-tag workflow.
	 *
	 * @dataProvider data_tag_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_tag_processor_reads_prefix_summary_batches( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Tag_Processor' );

		$processor = $this->create_tag_processor(
			$implementation,
			'<main data-id="7"><a data-kind="nav" href="/x">Link</a><img data-src="/x"></main>'
		);
		$rows      = array();

		do {
			$batch = $this->next_tag_prefix_summary_batch( $processor, $implementation, 'data-', 2, true );
			$rows  = array_merge( $rows, $batch );
		} while ( ! empty( $batch ) );

		$this->assertSame(
			array( 'MAIN', 'A', 'A', 'IMG', 'MAIN' ),
			array_column( $rows, 'tag_name' )
		);
		$this->assertSame(
			array( false, false, true, false, true ),
			array_column( $rows, 'is_tag_closer' )
		);
		$this->assertSame(
			array( 1, 1, 0, 1, 0 ),
			array_column( $rows, 'attribute_count' )
		);
		$this->assertSame( 5, count( $rows ) );

		if ( 'tag-processor' === $implementation ) {
			$processor = $this->create_tag_processor(
				$implementation,
				'<main data-id="7"><a data-kind="nav" href="/x">Link</a><img data-src="/x"></main>'
			);
			$this->assertSame(
				"MAIN\x1f0\x1f1\x1eA\x1f0\x1f1",
				$processor->next_tag_prefix_compact_summary_batch( 'data-', 2, true )
			);
		}
	}

	/**
	 * Verifies chunked prefix count summaries match the per-tag workflow.
	 *
	 * @dataProvider data_tag_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_tag_processor_reads_prefix_count_batches( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Tag_Processor' );

		$processor = $this->create_tag_processor(
			$implementation,
			'<main data-id="7"><a data-kind="nav" href="/x">Link</a><img data-src="/x"></main>'
		);
		$summary   = array(
			'tag_count'       => 0,
			'attribute_count' => 0,
		);

		do {
			$batch = $this->next_tag_prefix_count_batch( $processor, 'data-', 2, true );
			if ( ! empty( $batch ) ) {
				$summary['tag_count']       += $batch['tag_count'];
				$summary['attribute_count'] += $batch['attribute_count'];
			}
		} while ( ! empty( $batch ) );

		$this->assertSame(
			array(
				'tag_count'       => 5,
				'attribute_count' => 3,
			),
			$summary
		);
	}

	/**
	 * Verifies chunked tag summaries match the per-tag workflow.
	 *
	 * @dataProvider data_tag_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_tag_processor_reads_tag_summary_batches( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Tag_Processor' );

		$processor = $this->create_tag_processor(
			$implementation,
			'<main data-id="7"><a href="/x">Link</a><img src="/x"></main>'
		);
		$rows      = array();

		do {
			$batch = $this->next_tag_summary_batch( $processor, $implementation, 2, true );
			$rows  = array_merge( $rows, $batch );
		} while ( ! empty( $batch ) );

		$this->assertSame(
			array( 'MAIN', 'A', 'A', 'IMG', 'MAIN' ),
			array_column( $rows, 'tag_name' )
		);
		$this->assertSame(
			array( false, false, true, false, true ),
			array_column( $rows, 'is_tag_closer' )
		);

		$processor = $this->create_tag_processor(
			$implementation,
			'<main><a>Link</a></main>'
		);

		$this->assertSame(
			"MAIN\x1f0\x1eA\x1f0",
			$processor->next_tag_compact_summary_batch( 2, true )
		);
	}

	/**
	 * Verifies chunked tag-name summaries match the per-tag workflow.
	 *
	 * @dataProvider data_tag_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_tag_processor_reads_matching_tag_summary_batches( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Tag_Processor' );

		$processor = $this->create_tag_processor(
			$implementation,
			'<main><a href="/one">One</a><span><a href="/two">Two</a></span><img src="/x"></main>'
		);
		$rows      = array();

		do {
			$batch = $this->next_matching_tag_summary_batch( $processor, $implementation, 'a', 2, true );
			$rows  = array_merge( $rows, $batch );
		} while ( ! empty( $batch ) );

		$this->assertSame(
			array( 'A', 'A', 'A', 'A' ),
			array_column( $rows, 'tag_name' )
		);
		$this->assertSame(
			array( false, true, false, true ),
			array_column( $rows, 'is_tag_closer' )
		);

		$processor = $this->create_tag_processor(
			$implementation,
			'<main><a>One</a><a>Two</a></main>'
		);

		$this->assertSame(
			"A\x1f0\x1eA\x1f1",
			$processor->next_matching_tag_compact_summary_batch( 'A', 2, true )
		);
	}

	/**
	 * Verifies chunked tag-name and attribute summaries match the per-tag workflow.
	 *
	 * @dataProvider data_tag_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_tag_processor_reads_matching_tag_attribute_summary_batches( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Tag_Processor' );

		$processor = $this->create_tag_processor(
			$implementation,
			'<main><a href="/one">One</a><span><a href="/two?x=1&amp;y=2">Two</a></span><a>No href</a></main>'
		);
		$rows      = array();

		do {
			$batch = $this->next_matching_tag_attribute_summary_batch( $processor, $implementation, 'a', 'href', 2, true );
			$rows  = array_merge( $rows, $batch );
		} while ( ! empty( $batch ) );

		$this->assertSame(
			array( 'A', 'A', 'A', 'A', 'A', 'A' ),
			array_column( $rows, 'tag_name' )
		);
		$this->assertSame(
			array( false, true, false, true, false, true ),
			array_column( $rows, 'is_tag_closer' )
		);
		$this->assertSame(
			array( '/one', null, '/two?x=1&y=2', null, null, null ),
			array_column( $rows, 'attribute_value' )
		);

		$processor = $this->create_tag_processor(
			$implementation,
			'<main><a href="/one">One</a><a>Two</a></main>'
		);

		$this->assertSame(
			"A\x1f0\x1f1/one\x1eA\x1f1\x1f0",
			$processor->next_matching_tag_attribute_compact_summary_batch( 'A', 'href', 2, true )
		);
	}

	/**
	 * Verifies chunked tag-name and multi-attribute summaries match the per-tag workflow.
	 *
	 * @dataProvider data_tag_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_tag_processor_reads_matching_tag_attributes_summary_batches( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Tag_Processor' );

		$processor = $this->create_tag_processor(
			$implementation,
			'<main><a href="/one" title="One &amp; two">One</a><span><a href="/two" rel>Two</a></span><a title="Three">Three</a></main>'
		);
		$rows      = array();

		do {
			$batch = $this->next_matching_tag_attributes_summary_batch( $processor, $implementation, 'a', array( 'href', 'title', 'rel' ), 2, true );
			$rows  = array_merge( $rows, $batch );
		} while ( ! empty( $batch ) );

		$this->assertSame(
			array( 'A', 'A', 'A', 'A', 'A', 'A' ),
			array_column( $rows, 'tag_name' )
		);
		$this->assertSame(
			array( false, true, false, true, false, true ),
			array_column( $rows, 'is_tag_closer' )
		);
		$this->assertSame(
			array(
				array(
					'href'  => '/one',
					'title' => 'One & two',
					'rel'   => null,
				),
				array(
					'href'  => null,
					'title' => null,
					'rel'   => null,
				),
				array(
					'href'  => '/two',
					'title' => null,
					'rel'   => '',
				),
				array(
					'href'  => null,
					'title' => null,
					'rel'   => null,
				),
				array(
					'href'  => null,
					'title' => 'Three',
					'rel'   => null,
				),
				array(
					'href'  => null,
					'title' => null,
					'rel'   => null,
				),
			),
			array_column( $rows, 'attribute_values' )
		);

		$processor = $this->create_tag_processor(
			$implementation,
			'<main><a href="/one" title="One">One</a><a rel>Two</a></main>'
		);

		$this->assertSame(
			"A\x1f0\x1f1/one\x1f1One\x1f0\x1eA\x1f1\x1f0\x1f0\x1f0",
			$this->next_matching_tag_attributes_compact_summary_batch( $processor, $implementation, 'A', array( 'href', 'title', 'rel' ), 2, true )
		);
	}

	/**
	 * Verifies document-level tag-name and multi-attribute summaries.
	 *
	 * @dataProvider data_tag_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_tag_processor_summarizes_matching_tag_attributes( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Tag_Processor' );

		$processor = $this->create_tag_processor(
			$implementation,
			'<main><a href="/one" title="One &amp; two">One</a><span><a href="/two" rel>Two</a></span><a title="Three">Three</a></main>'
		);

		$this->assertSame(
			array(
				'tag_count'             => 6,
				'attribute_count'       => 5,
				'attribute_value_bytes' => 22,
			),
			$this->summarize_matching_tag_attributes( $processor, $implementation, 'a', array( 'href', 'title', 'rel' ), true )
		);

		$processor = $this->create_tag_processor(
			$implementation,
			'<main><a href="/one" title="One &amp; two">One</a><span><a href="/two" rel>Two</a></span><a title="Three">Three</a></main>'
		);

		$this->assertSame(
			array(
				'tag_count'             => 3,
				'attribute_count'       => 5,
				'attribute_value_bytes' => 22,
			),
			$this->summarize_matching_tag_attributes( $processor, $implementation, 'A', array( 'href', 'title', 'rel' ), false )
		);
	}

	/**
	 * Verifies numeric and legacy character-reference decoding parity.
	 *
	 * @dataProvider data_tag_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_tag_processor_decodes_numeric_and_legacy_character_references( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Tag_Processor' );

		$processor = $this->create_tag_processor(
			$implementation,
			'<a title="&#0 &#xD800 &#128 &#x85 &#x41 &copy &notin &ampx &notit;"></a><title>&notin &ampx &copyx &#x110000</title>'
		);

		$this->assertTrue( $processor->next_tag(), 'Expected the anchor tag.' );
		$this->assertSame( "\u{fffd} \u{fffd} \u{20ac} \u{2026} A \u{00a9} &notin &ampx &notit;", $processor->get_attribute( 'title' ) );

		$this->assertTrue( $processor->next_tag(), 'Expected the title tag.' );
		$this->assertSame( 'TITLE', $processor->get_token_name() );
		$this->assertSame( "\u{00ac}in &x \u{00a9}x \u{fffd}", $processor->get_modifiable_text() );
	}

	/**
	 * Verifies the native tag processor exposes DOCTYPE tokens like PHP userland.
	 *
	 * @dataProvider data_tag_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_tag_processor_reads_doctype_tokens( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Tag_Processor' );

		foreach (
			array(
				'<!DOCTYPE html>'    => ' html',
				'<!DOCTYPE>'         => '',
				'<!DOCTYPE svg>'     => ' svg',
				'<!DOCTYPE 123>'     => ' 123',
				'<!DOCTYPE html<p>'  => ' html<p',
				'<!DOCTYPE html<!--x-->' => ' html<!--x--',
			) as $doctype_html => $modifiable_text
		) {
			$processor = $this->create_tag_processor(
				$implementation,
				$doctype_html . '<p>Text</p>'
			);

			$this->assertTrue( $processor->next_token(), 'Expected the DOCTYPE token.' );
			$this->assertSame( '#doctype', $processor->get_token_type() );
			$this->assertSame( 'html', $processor->get_token_name() );
			$this->assertSame( $modifiable_text, $processor->get_modifiable_text() );

			$this->assertTrue( $processor->next_tag(), 'Expected next_tag() to skip DOCTYPE and find the paragraph tag.' );
			$this->assertSame( 'P', $processor->get_tag() );
		}
	}

	/**
	 * Verifies complete native tag-processor inputs do not report incomplete-token pauses.
	 *
	 * @dataProvider data_tag_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_tag_processor_reports_complete_input_status( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Tag_Processor' );

		$processor = $this->create_tag_processor(
			$implementation,
			'<main><p>Text</p></main>'
		);

		$this->assertFalse( $processor->paused_at_incomplete_token() );
		$this->assertTrue( $processor->next_tag(), 'Expected the main tag.' );
		$this->assertTrue( $processor->next_tag(), 'Expected the paragraph tag.' );
		$this->assertFalse( $processor->next_tag(), 'Expected complete input to be exhausted.' );
		$this->assertFalse( $processor->paused_at_incomplete_token() );
	}

	/**
	 * Verifies comment metadata is exposed through the tag processor.
	 *
	 * @dataProvider data_tag_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_tag_processor_reads_comment_metadata( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Tag_Processor' );

		$processor = $this->create_tag_processor(
			$implementation,
			'<!--note--><p>Text</p>'
		);

		$this->assertTrue( $processor->next_token(), 'Expected the comment token.' );
		$this->assertSame( '#comment', $processor->get_token_type() );
		$this->assertSame( '#comment', $processor->get_token_name() );
		$this->assertSame( 'note', $processor->get_modifiable_text() );
		$this->assertSame( 'COMMENT_AS_HTML_COMMENT', $processor->get_comment_type() );
		$this->assertSame( 'note', $processor->get_full_comment_text() );

		$this->assertTrue( $processor->next_tag(), 'Expected next_tag() to skip the comment and find the paragraph tag.' );
		$this->assertSame( 'P', $processor->get_tag() );
		$this->assertNull( $processor->get_comment_type() );
		$this->assertNull( $processor->get_full_comment_text() );

		$processor = $this->create_tag_processor(
			$implementation,
			'<!--note--!><p>Text</p>'
		);

		$this->assertTrue( $processor->next_token(), 'Expected the comment token with a --!> close.' );
		$this->assertSame( '#comment', $processor->get_token_type() );
		$this->assertSame( 'note', $processor->get_modifiable_text() );
		$this->assertSame( 'COMMENT_AS_HTML_COMMENT', $processor->get_comment_type() );
		$this->assertSame( 'note', $processor->get_full_comment_text() );
		$this->assertTrue( $processor->next_tag(), 'Expected next_tag() to find the paragraph after the --!> comment.' );
		$this->assertSame( 'P', $processor->get_tag() );

		$processor = $this->create_tag_processor(
			$implementation,
			'<!--note-- <p>Text</p>'
		);

		$this->assertFalse( $processor->next_token(), 'Unclosed comments should not expose a token.' );
	}

	/**
	 * Verifies non-standard comment-like tokens expose PHP userland metadata.
	 *
	 * @dataProvider data_tag_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_tag_processor_reads_funky_comment_metadata( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Tag_Processor' );

		$processor = $this->create_tag_processor(
			$implementation,
			'<?pi.name data?><?1 data?><?pi data><![CDATA[x]]><!notdoctype><p>Text</p>'
		);

		$this->assertTrue( $processor->next_token(), 'Expected the processing-instruction-looking comment.' );
		$this->assertSame( '#comment', $processor->get_token_type() );
		$this->assertSame( '#comment', $processor->get_token_name() );
		$this->assertSame( 'pi.name', $processor->get_tag() );
		$this->assertSame( ' data', $processor->get_modifiable_text() );
		$this->assertSame( 'COMMENT_AS_PI_NODE_LOOKALIKE', $processor->get_comment_type() );
		$this->assertSame( '?pi.name data?', $processor->get_full_comment_text() );

		$this->assertTrue( $processor->next_token(), 'Expected the invalid processing-instruction-looking comment with a numeric target.' );
		$this->assertSame( '#comment', $processor->get_token_type() );
		$this->assertSame( '#comment', $processor->get_token_name() );
		$this->assertNull( $processor->get_tag() );
		$this->assertSame( '1 data?', $processor->get_modifiable_text() );
		$this->assertSame( 'COMMENT_AS_INVALID_HTML', $processor->get_comment_type() );
		$this->assertSame( '?1 data?', $processor->get_full_comment_text() );

		$this->assertTrue( $processor->next_token(), 'Expected the invalid processing-instruction-looking comment without XML-style close.' );
		$this->assertSame( '#comment', $processor->get_token_type() );
		$this->assertSame( '#comment', $processor->get_token_name() );
		$this->assertNull( $processor->get_tag() );
		$this->assertSame( 'pi data', $processor->get_modifiable_text() );
		$this->assertSame( 'COMMENT_AS_INVALID_HTML', $processor->get_comment_type() );
		$this->assertSame( '?pi data', $processor->get_full_comment_text() );

		$this->assertTrue( $processor->next_token(), 'Expected the CDATA-looking comment.' );
		$this->assertSame( '#comment', $processor->get_token_type() );
		$this->assertSame( '#comment', $processor->get_token_name() );
		$this->assertNull( $processor->get_tag() );
		$this->assertSame( 'x', $processor->get_modifiable_text() );
		$this->assertSame( 'COMMENT_AS_CDATA_LOOKALIKE', $processor->get_comment_type() );
		$this->assertSame( '[CDATA[x]]', $processor->get_full_comment_text() );

		$this->assertTrue( $processor->next_token(), 'Expected the invalid-HTML comment.' );
		$this->assertSame( '#comment', $processor->get_token_type() );
		$this->assertSame( '#comment', $processor->get_token_name() );
		$this->assertNull( $processor->get_tag() );
		$this->assertSame( 'notdoctype', $processor->get_modifiable_text() );
		$this->assertSame( 'COMMENT_AS_INVALID_HTML', $processor->get_comment_type() );
		$this->assertSame( 'notdoctype', $processor->get_full_comment_text() );

		$this->assertTrue( $processor->next_tag(), 'Expected next_tag() to skip funky comments and find the paragraph tag.' );
		$this->assertSame( 'P', $processor->get_tag() );
	}

	/**
	 * Verifies presumptuous tags, funky closers, and abruptly closed comments match PHP userland.
	 *
	 * @dataProvider data_tag_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_tag_processor_reads_invalid_closing_and_abrupt_comment_tokens( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Tag_Processor' );

		$processor = $this->create_tag_processor(
			$implementation,
			'</></1></%bad><//></ p></_x></:x><!--><!---><p>Text</p>'
		);

		$this->assertTrue( $processor->next_token(), 'Expected the presumptuous tag token.' );
		$this->assertSame( '#presumptuous-tag', $processor->get_token_type() );
		$this->assertSame( '#presumptuous-tag', $processor->get_token_name() );
		$this->assertSame( '', $processor->get_modifiable_text() );
		$this->assertNull( $processor->get_full_comment_text() );

		foreach ( array( '1', '%bad', '/', ' p', '_x', ':x' ) as $expected_text ) {
			$this->assertTrue( $processor->next_token(), 'Expected a funky closing comment token.' );
			$this->assertSame( '#funky-comment', $processor->get_token_type() );
			$this->assertSame( '#funky-comment', $processor->get_token_name() );
			$this->assertSame( $expected_text, $processor->get_modifiable_text() );
			$this->assertSame( $expected_text, $processor->get_full_comment_text() );
		}

		$this->assertTrue( $processor->next_token(), 'Expected the abruptly closed HTML comment.' );
		$this->assertSame( '#comment', $processor->get_token_type() );
		$this->assertSame( '#comment', $processor->get_token_name() );
		$this->assertSame( '', $processor->get_modifiable_text() );
		$this->assertSame( 'COMMENT_AS_ABRUPTLY_CLOSED_COMMENT', $processor->get_comment_type() );
		$this->assertSame( '', $processor->get_full_comment_text() );

		$this->assertTrue( $processor->next_token(), 'Expected the dash-abruptly closed HTML comment.' );
		$this->assertSame( '#comment', $processor->get_token_type() );
		$this->assertSame( '#comment', $processor->get_token_name() );
		$this->assertSame( '', $processor->get_modifiable_text() );
		$this->assertSame( 'COMMENT_AS_ABRUPTLY_CLOSED_COMMENT', $processor->get_comment_type() );
		$this->assertSame( '', $processor->get_full_comment_text() );

		$this->assertTrue( $processor->next_tag(), 'Expected next_tag() to skip invalid tokens and find the paragraph tag.' );
		$this->assertSame( 'P', $processor->get_tag() );
	}

	/**
	 * Verifies invalid opening tags remain text tokens like PHP userland.
	 *
	 * @dataProvider data_tag_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_tag_processor_reads_invalid_opening_tag_text_tokens( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Tag_Processor' );

		$processor = $this->create_tag_processor(
			$implementation,
			'before &amp; <1><%bad><_x><:x><.x><-x>< p><p>Text</p>'
		);

		$this->assertTrue( $processor->next_token(), 'Expected an invalid opening-tag text token.' );
		$this->assertSame( '#text', $processor->get_token_type() );
		$this->assertSame( '#text', $processor->get_token_name() );
		$this->assertSame( 'before & <1><%bad><_x><:x><.x><-x>< p>', $processor->get_modifiable_text() );
		$this->assertNull( $processor->get_comment_type() );
		$this->assertNull( $processor->get_full_comment_text() );

		$this->assertTrue( $processor->next_tag(), 'Expected next_tag() to skip invalid text and find the paragraph tag.' );
		$this->assertSame( 'P', $processor->get_tag() );

		$processor = $this->create_tag_processor( $implementation, '<<p>Text</p><' );

		$this->assertTrue( $processor->next_token(), 'Expected adjacent invalid opening text.' );
		$this->assertSame( '#text', $processor->get_token_type() );
		$this->assertSame( '<', $processor->get_modifiable_text() );

		$this->assertTrue( $processor->next_token(), 'Expected valid paragraph after adjacent invalid opening text.' );
		$this->assertSame( '#tag', $processor->get_token_type() );
		$this->assertSame( 'P', $processor->get_tag() );

		$this->assertTrue( $processor->next_token(), 'Expected paragraph text.' );
		$this->assertSame( '#text', $processor->get_token_type() );
		$this->assertSame( 'Text', $processor->get_modifiable_text() );

		$this->assertTrue( $processor->next_token(), 'Expected paragraph closer.' );
		$this->assertSame( '#tag', $processor->get_token_type() );
		$this->assertSame( 'P', $processor->get_tag() );
		$this->assertTrue( $processor->is_tag_closer() );

		$this->assertFalse( $processor->next_token(), 'Expected a trailing lone < not to expose a token.' );

		$processor = $this->create_tag_processor( $implementation, 'x<y <' );

		$this->assertTrue( $processor->next_token(), 'Expected text before an incomplete tag-like sequence.' );
		$this->assertSame( '#text', $processor->get_token_type() );
		$this->assertSame( 'x', $processor->get_modifiable_text() );
		$this->assertFalse( $processor->next_token(), 'Expected incomplete tag-like sequences not to expose tokens.' );

		$processor = $this->create_tag_processor( $implementation, '<< <<p>Text</p>' );

		$this->assertTrue( $processor->next_token(), 'Expected consecutive invalid openings to coalesce.' );
		$this->assertSame( '#text', $processor->get_token_type() );
		$this->assertSame( '<< <', $processor->get_modifiable_text() );
		$this->assertTrue( $processor->next_tag(), 'Expected next_tag() to find the valid tag after consecutive invalid openings.' );
		$this->assertSame( 'P', $processor->get_tag() );
	}

	/**
	 * Verifies raw-text contents are attached to the opening tag like PHP userland.
	 *
	 * @dataProvider data_tag_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_tag_processor_reads_raw_text_element_contents( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Tag_Processor' );

		$processor = $this->create_tag_processor(
			$implementation,
			'<script>if (a < b) { c(); }</script><iframe>A&amp;<b>C</b></iframe><noembed>D&amp;<i>E</i></noembed><noframes>F&amp;<u>G</u></noframes><xmp>H&amp;<q>I</q></xmp><title>A&amp;B&nbsp;&copy;</title><textarea>C&lt;D&hellip;</textarea><p>x</p>'
		);

		$this->assertTrue( $processor->next_token(), 'Expected the script token.' );
		$this->assertSame( '#tag', $processor->get_token_type() );
		$this->assertSame( 'SCRIPT', $processor->get_token_name() );
		$this->assertSame( 'if (a < b) { c(); }', $processor->get_modifiable_text() );

		$this->assertTrue( $processor->next_token(), 'Expected the iframe token after the script contents.' );
		$this->assertSame( '#tag', $processor->get_token_type() );
		$this->assertSame( 'IFRAME', $processor->get_token_name() );
		$this->assertSame( 'A&amp;<b>C</b>', $processor->get_modifiable_text() );

		$this->assertTrue( $processor->next_token(), 'Expected the noembed token after the iframe contents.' );
		$this->assertSame( '#tag', $processor->get_token_type() );
		$this->assertSame( 'NOEMBED', $processor->get_token_name() );
		$this->assertSame( 'D&amp;<i>E</i>', $processor->get_modifiable_text() );

		$this->assertTrue( $processor->next_token(), 'Expected the noframes token after the noembed contents.' );
		$this->assertSame( '#tag', $processor->get_token_type() );
		$this->assertSame( 'NOFRAMES', $processor->get_token_name() );
		$this->assertSame( 'F&amp;<u>G</u>', $processor->get_modifiable_text() );

		$this->assertTrue( $processor->next_token(), 'Expected the xmp token after the noframes contents.' );
		$this->assertSame( '#tag', $processor->get_token_type() );
		$this->assertSame( 'XMP', $processor->get_token_name() );
		$this->assertSame( 'H&amp;<q>I</q>', $processor->get_modifiable_text() );

		$this->assertTrue( $processor->next_token(), 'Expected the title token after the script contents.' );
		$this->assertSame( '#tag', $processor->get_token_type() );
		$this->assertSame( 'TITLE', $processor->get_token_name() );
		$this->assertSame( "A&B\u{00a0}\u{00a9}", $processor->get_modifiable_text() );

		$this->assertTrue( $processor->next_token(), 'Expected the textarea token after the title contents.' );
		$this->assertSame( '#tag', $processor->get_token_type() );
		$this->assertSame( 'TEXTAREA', $processor->get_token_name() );
		$this->assertSame( "C<D\u{2026}", $processor->get_modifiable_text() );

		$this->assertTrue( $processor->next_token(), 'Expected the paragraph token after the script contents.' );
		$this->assertSame( '#tag', $processor->get_token_type() );
		$this->assertSame( 'P', $processor->get_token_name() );
		$this->assertFalse( $processor->is_tag_closer(), 'The script closer should not be exposed as the next token.' );
	}

	/**
	 * Verifies the first native slice matches the PHP HTML processor token stream.
	 *
	 * @dataProvider data_html_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_html_processor_reads_tag_tokens( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Processor' );

		$processor = $this->create_html_processor(
			$implementation,
			'<section><p data-id="7">Text</p></section>'
		);

		$this->assertTrue( $processor->next_token(), 'Expected the section token.' );
		$this->assertSame( '#tag', $processor->get_token_type() );
		$this->assertSame( 'SECTION', $processor->get_token_name() );
		$this->assertFalse( $processor->is_tag_closer() );

		$this->assertTrue( $processor->next_token(), 'Expected the paragraph token.' );
		$this->assertSame( '#tag', $processor->get_token_type() );
		$this->assertSame( 'P', $processor->get_token_name() );
		$this->assertFalse( $processor->is_tag_closer() );
		$this->assertSame( '7', $processor->get_attribute( 'data-id' ) );

		while ( $processor->next_token() && ! $processor->is_tag_closer() ) {
			continue;
		}

		$this->assertTrue( $processor->is_tag_closer(), 'next_token() should expose closing tag tokens.' );
		$this->assertSame( 'P', $processor->get_token_name() );
	}

	/**
	 * Verifies the first native slice matches PHP HTML processor ancestry APIs.
	 *
	 * @dataProvider data_html_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_html_processor_reports_breadcrumbs_and_current_depth( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Processor' );

		$processor = $this->create_html_processor(
			$implementation,
			'<section><p><img></p></section>'
		);

		$this->assertSame( 2, $processor->get_current_depth() );
		$this->assertSame( array( 'HTML', 'BODY' ), $processor->get_breadcrumbs() );

		$this->assertTrue( $processor->next_token(), 'Expected the section token.' );
		$this->assertSame( 3, $processor->get_current_depth() );
		$this->assertSame( array( 'HTML', 'BODY', 'SECTION' ), $processor->get_breadcrumbs() );

		$this->assertTrue( $processor->next_token(), 'Expected the paragraph token.' );
		$this->assertSame( 4, $processor->get_current_depth() );
		$this->assertSame( array( 'HTML', 'BODY', 'SECTION', 'P' ), $processor->get_breadcrumbs() );

		$this->assertTrue( $processor->next_token(), 'Expected the image token.' );
		$this->assertSame( 5, $processor->get_current_depth() );
		$this->assertSame( array( 'HTML', 'BODY', 'SECTION', 'P', 'IMG' ), $processor->get_breadcrumbs() );

		$this->assertTrue( $processor->next_token(), 'Expected the paragraph closer.' );
		$this->assertTrue( $processor->is_tag_closer(), 'Expected a closing paragraph token.' );
		$this->assertSame( 3, $processor->get_current_depth() );
		$this->assertSame( array( 'HTML', 'BODY', 'SECTION' ), $processor->get_breadcrumbs() );
	}

	/**
	 * Verifies text and comment tokens are visible through next_token().
	 *
	 * @dataProvider data_html_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_html_processor_reads_text_and_comment_tokens( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Processor' );

		$processor = $this->create_html_processor(
			$implementation,
			'<p>Hello<!--note--><em>World</em></p>'
		);

		$this->assertTrue( $processor->next_token(), 'Expected the paragraph token.' );
		$this->assertSame( '#tag', $processor->get_token_type() );
		$this->assertSame( 'P', $processor->get_token_name() );

		$this->assertTrue( $processor->next_token(), 'Expected the text token.' );
		$this->assertSame( '#text', $processor->get_token_type() );
		$this->assertSame( '#text', $processor->get_token_name() );
		$this->assertSame( 'Hello', $processor->get_modifiable_text() );
		$this->assertSame( 4, $processor->get_current_depth() );
		$this->assertSame( array( 'HTML', 'BODY', 'P', '#text' ), $processor->get_breadcrumbs() );

		$this->assertTrue( $processor->next_token(), 'Expected the comment token.' );
		$this->assertSame( '#comment', $processor->get_token_type() );
		$this->assertSame( '#comment', $processor->get_token_name() );
		$this->assertSame( 'note', $processor->get_modifiable_text() );
		$this->assertSame( 'COMMENT_AS_HTML_COMMENT', $processor->get_comment_type() );
		$this->assertSame( 'note', $processor->get_full_comment_text() );
		$this->assertSame( 4, $processor->get_current_depth() );
		$this->assertSame( array( 'HTML', 'BODY', 'P', '#comment' ), $processor->get_breadcrumbs() );

		$this->assertTrue( $processor->next_token(), 'Expected the emphasis token.' );
		$this->assertSame( '#tag', $processor->get_token_type() );
		$this->assertSame( 'EM', $processor->get_token_name() );

		$this->assertTrue( $processor->next_token(), 'Expected the nested text token.' );
		$this->assertSame( '#text', $processor->get_token_type() );
		$this->assertSame( 'World', $processor->get_modifiable_text() );
		$this->assertSame( 5, $processor->get_current_depth() );
		$this->assertSame( array( 'HTML', 'BODY', 'P', 'EM', '#text' ), $processor->get_breadcrumbs() );
	}

	/**
	 * Verifies chunked HTML processor token summaries match next_token() metadata.
	 *
	 * @dataProvider data_html_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_html_processor_reads_token_summary_batches( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Processor' );

		$processor = $this->create_html_processor(
			$implementation,
			'<section><p data-id="7">Text</p><!--note--></section>'
		);
		$rows      = array();

		do {
			$batch = $this->next_html_token_summary_batch( $processor, $implementation, 2 );
			$rows  = array_merge( $rows, $batch );
		} while ( ! empty( $batch ) );

		$this->assertSame(
			array( '#tag', '#tag', '#text', '#tag', '#comment', '#tag' ),
			array_column( $rows, 'token_type' )
		);
		$this->assertSame(
			array( 'SECTION', 'P', '#text', 'P', '#comment', 'SECTION' ),
			array_column( $rows, 'token_name' )
		);
		$this->assertSame(
			array( false, false, false, true, false, true ),
			array_column( $rows, 'is_tag_closer' )
		);
		$this->assertSame(
			array( 3, 4, 5, 3, 4, 2 ),
			array_column( $rows, 'current_depth' )
		);
		$this->assertSame(
			array( 'HTML', 'BODY', 'SECTION', 'P', '#text' ),
			$rows[2]['breadcrumbs']
		);

		$processor = $this->create_html_processor(
			$implementation,
			'<section><p>Text</p></section>'
		);

		$this->assertSame(
			"t\x1fSECTION\x1f0\x1f3\x1fHTML\x1dBODY\x1dSECTION\x1et\x1fP\x1f0\x1f4\x1fHTML\x1dBODY\x1dSECTION\x1dP",
			$processor->next_token_compact_summary_batch( 2 )
		);
	}

	/**
	 * Verifies non-standard comment-like tokens stay visible in fragment parsing.
	 *
	 * @dataProvider data_html_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_html_processor_reads_funky_comment_tokens( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Processor' );

		$processor = $this->create_html_processor(
			$implementation,
			'<?pi.name data?><?1 data?><?pi data><![CDATA[x]]><!notdoctype><p>Text</p>'
		);

		$this->assertTrue( $processor->next_token(), 'Expected the processing-instruction-looking comment.' );
		$this->assertSame( '#comment', $processor->get_token_type() );
		$this->assertSame( '#comment', $processor->get_token_name() );
		$this->assertSame( 'pi.name', $processor->get_tag() );
		$this->assertSame( ' data', $processor->get_modifiable_text() );
		$this->assertSame( 'COMMENT_AS_PI_NODE_LOOKALIKE', $processor->get_comment_type() );
		$this->assertSame( '?pi.name data?', $processor->get_full_comment_text() );
		$this->assertSame( 3, $processor->get_current_depth() );
		$this->assertSame( array( 'HTML', 'BODY', '#comment' ), $processor->get_breadcrumbs() );

		$this->assertTrue( $processor->next_token(), 'Expected the invalid processing-instruction-looking comment with a numeric target.' );
		$this->assertSame( '#comment', $processor->get_token_type() );
		$this->assertSame( '#comment', $processor->get_token_name() );
		$this->assertNull( $processor->get_tag() );
		$this->assertSame( '1 data?', $processor->get_modifiable_text() );
		$this->assertSame( 'COMMENT_AS_INVALID_HTML', $processor->get_comment_type() );
		$this->assertSame( '?1 data?', $processor->get_full_comment_text() );
		$this->assertSame( 3, $processor->get_current_depth() );
		$this->assertSame( array( 'HTML', 'BODY', '#comment' ), $processor->get_breadcrumbs() );

		$this->assertTrue( $processor->next_token(), 'Expected the invalid processing-instruction-looking comment without XML-style close.' );
		$this->assertSame( '#comment', $processor->get_token_type() );
		$this->assertSame( '#comment', $processor->get_token_name() );
		$this->assertNull( $processor->get_tag() );
		$this->assertSame( 'pi data', $processor->get_modifiable_text() );
		$this->assertSame( 'COMMENT_AS_INVALID_HTML', $processor->get_comment_type() );
		$this->assertSame( '?pi data', $processor->get_full_comment_text() );
		$this->assertSame( 3, $processor->get_current_depth() );
		$this->assertSame( array( 'HTML', 'BODY', '#comment' ), $processor->get_breadcrumbs() );

		$this->assertTrue( $processor->next_token(), 'Expected the CDATA-looking comment.' );
		$this->assertSame( '#comment', $processor->get_token_type() );
		$this->assertSame( '#comment', $processor->get_token_name() );
		$this->assertNull( $processor->get_tag() );
		$this->assertSame( 'x', $processor->get_modifiable_text() );
		$this->assertSame( 'COMMENT_AS_CDATA_LOOKALIKE', $processor->get_comment_type() );
		$this->assertSame( '[CDATA[x]]', $processor->get_full_comment_text() );
		$this->assertSame( 3, $processor->get_current_depth() );
		$this->assertSame( array( 'HTML', 'BODY', '#comment' ), $processor->get_breadcrumbs() );

		$this->assertTrue( $processor->next_token(), 'Expected the invalid-HTML comment.' );
		$this->assertSame( '#comment', $processor->get_token_type() );
		$this->assertSame( '#comment', $processor->get_token_name() );
		$this->assertNull( $processor->get_tag() );
		$this->assertSame( 'notdoctype', $processor->get_modifiable_text() );
		$this->assertSame( 'COMMENT_AS_INVALID_HTML', $processor->get_comment_type() );
		$this->assertSame( 'notdoctype', $processor->get_full_comment_text() );
		$this->assertSame( 3, $processor->get_current_depth() );
		$this->assertSame( array( 'HTML', 'BODY', '#comment' ), $processor->get_breadcrumbs() );

		$this->assertTrue( $processor->next_tag(), 'Expected next_tag() to skip funky comments and find the paragraph tag.' );
		$this->assertSame( 'P', $processor->get_token_name() );
	}

	/**
	 * Verifies processor handling of invalid closing and abruptly closed comment tokens.
	 *
	 * @dataProvider data_html_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_html_processor_reads_invalid_closing_and_abrupt_comment_tokens( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Processor' );

		$processor = $this->create_html_processor(
			$implementation,
			'</></1></%bad><//></ p></_x></:x><!--><!---><p>Text</p>'
		);

		$this->assertTrue( $processor->next_token(), 'Expected the presumptuous tag token.' );
		$this->assertSame( '#presumptuous-tag', $processor->get_token_type() );
		$this->assertSame( '#presumptuous-tag', $processor->get_token_name() );
		$this->assertSame( '', $processor->get_modifiable_text() );
		$this->assertNull( $processor->get_full_comment_text() );
		$this->assertSame( 3, $processor->get_current_depth() );
		$this->assertSame( array( 'HTML', 'BODY', '#presumptuous-tag' ), $processor->get_breadcrumbs() );

		foreach ( array( '1', '%bad', '/', ' p', '_x', ':x' ) as $expected_text ) {
			$this->assertTrue( $processor->next_token(), 'Expected a funky closing comment token.' );
			$this->assertSame( '#funky-comment', $processor->get_token_type() );
			$this->assertSame( '#funky-comment', $processor->get_token_name() );
			$this->assertSame( $expected_text, $processor->get_modifiable_text() );
			$this->assertSame( $expected_text, $processor->get_full_comment_text() );
			$this->assertSame( 3, $processor->get_current_depth() );
			$this->assertSame( array( 'HTML', 'BODY', '#funky-comment' ), $processor->get_breadcrumbs() );
		}

		$this->assertTrue( $processor->next_token(), 'Expected the abruptly closed HTML comment.' );
		$this->assertSame( '#comment', $processor->get_token_type() );
		$this->assertSame( '#comment', $processor->get_token_name() );
		$this->assertSame( '', $processor->get_modifiable_text() );
		$this->assertSame( 'COMMENT_AS_ABRUPTLY_CLOSED_COMMENT', $processor->get_comment_type() );
		$this->assertSame( '', $processor->get_full_comment_text() );
		$this->assertSame( 3, $processor->get_current_depth() );
		$this->assertSame( array( 'HTML', 'BODY', '#comment' ), $processor->get_breadcrumbs() );

		$this->assertTrue( $processor->next_token(), 'Expected the dash-abruptly closed HTML comment.' );
		$this->assertSame( '#comment', $processor->get_token_type() );
		$this->assertSame( '#comment', $processor->get_token_name() );
		$this->assertSame( '', $processor->get_modifiable_text() );
		$this->assertSame( 'COMMENT_AS_ABRUPTLY_CLOSED_COMMENT', $processor->get_comment_type() );
		$this->assertSame( '', $processor->get_full_comment_text() );
		$this->assertSame( 3, $processor->get_current_depth() );
		$this->assertSame( array( 'HTML', 'BODY', '#comment' ), $processor->get_breadcrumbs() );

		$this->assertTrue( $processor->next_tag(), 'Expected next_tag() to skip invalid tokens and find the paragraph tag.' );
		$this->assertSame( 'P', $processor->get_token_name() );
	}

	/**
	 * Verifies invalid opening tags stay visible as text in fragment parsing.
	 *
	 * @dataProvider data_html_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_html_processor_reads_invalid_opening_tag_text_tokens( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Processor' );

		$processor = $this->create_html_processor(
			$implementation,
			'before &amp; <1><%bad><_x><:x><.x><-x>< p><p>Text</p>'
		);

		$this->assertTrue( $processor->next_token(), 'Expected an invalid opening-tag text token.' );
		$this->assertSame( '#text', $processor->get_token_type() );
		$this->assertSame( '#text', $processor->get_token_name() );
		$this->assertSame( 'before & <1><%bad><_x><:x><.x><-x>< p>', $processor->get_modifiable_text() );
		$this->assertNull( $processor->get_comment_type() );
		$this->assertNull( $processor->get_full_comment_text() );
		$this->assertSame( 3, $processor->get_current_depth() );
		$this->assertSame( array( 'HTML', 'BODY', '#text' ), $processor->get_breadcrumbs() );

		$this->assertTrue( $processor->next_tag(), 'Expected next_tag() to skip invalid text and find the paragraph tag.' );
		$this->assertSame( 'P', $processor->get_token_name() );

		$processor = $this->create_html_processor( $implementation, '<<p>Text</p><' );

		$this->assertTrue( $processor->next_token(), 'Expected adjacent invalid opening text.' );
		$this->assertSame( '#text', $processor->get_token_type() );
		$this->assertSame( '<', $processor->get_modifiable_text() );

		$this->assertTrue( $processor->next_token(), 'Expected valid paragraph after adjacent invalid opening text.' );
		$this->assertSame( '#tag', $processor->get_token_type() );
		$this->assertSame( 'P', $processor->get_token_name() );

		$this->assertTrue( $processor->next_token(), 'Expected paragraph text.' );
		$this->assertSame( '#text', $processor->get_token_type() );
		$this->assertSame( 'Text', $processor->get_modifiable_text() );

		$this->assertTrue( $processor->next_token(), 'Expected paragraph closer.' );
		$this->assertSame( '#tag', $processor->get_token_type() );
		$this->assertSame( 'P', $processor->get_token_name() );
		$this->assertTrue( $processor->is_tag_closer() );

		$this->assertFalse( $processor->next_token(), 'Expected a trailing lone < not to expose a token.' );

		$processor = $this->create_html_processor( $implementation, 'x<y <' );

		$this->assertTrue( $processor->next_token(), 'Expected text before an incomplete tag-like sequence.' );
		$this->assertSame( '#text', $processor->get_token_type() );
		$this->assertSame( 'x', $processor->get_modifiable_text() );
		$this->assertFalse( $processor->next_token(), 'Expected incomplete tag-like sequences not to expose tokens.' );

		$processor = $this->create_html_processor( $implementation, '<< <<p>Text</p>' );

		$this->assertTrue( $processor->next_token(), 'Expected consecutive invalid openings to coalesce.' );
		$this->assertSame( '#text', $processor->get_token_type() );
		$this->assertSame( '<< <', $processor->get_modifiable_text() );
		$this->assertTrue( $processor->next_tag(), 'Expected next_tag() to find the valid tag after consecutive invalid openings.' );
		$this->assertSame( 'P', $processor->get_token_name() );
	}

	/**
	 * Verifies table, list, option, and paragraph fragments synthesize implied tokens.
	 *
	 * @dataProvider data_html_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_html_processor_synthesizes_selected_implied_closers( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Processor' );

		$cases = array(
			'table-cells' => array(
				'<table><tr><td>A<td>B</tr></table>',
				array(
					array( 'TABLE', false, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'TBODY', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY' ) ),
					array( 'TR', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR' ) ),
					array( 'TD', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD', '#text' ) ),
					array( 'TD', true, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR' ) ),
					array( 'TD', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD', '#text' ) ),
					array( 'TD', true, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR' ) ),
					array( 'TR', true, array( 'HTML', 'BODY', 'TABLE', 'TBODY' ) ),
					array( 'TBODY', true, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'TABLE', true, array( 'HTML', 'BODY' ) ),
				),
			),
			'table-bare-cells' => array(
				'<table><td>A<td>B</table>',
				array(
					array( 'TABLE', false, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'TBODY', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY' ) ),
					array( 'TR', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR' ) ),
					array( 'TD', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD', '#text' ) ),
					array( 'TD', true, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR' ) ),
					array( 'TD', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD', '#text' ) ),
					array( 'TD', true, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR' ) ),
					array( 'TR', true, array( 'HTML', 'BODY', 'TABLE', 'TBODY' ) ),
					array( 'TBODY', true, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'TABLE', true, array( 'HTML', 'BODY' ) ),
				),
			),
			'table-explicit-sections' => array(
				'<table><thead><tr><th>A<th>B</thead><tbody><tr><td>C</tbody></table>',
				array(
					array( 'TABLE', false, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'THEAD', false, array( 'HTML', 'BODY', 'TABLE', 'THEAD' ) ),
					array( 'TR', false, array( 'HTML', 'BODY', 'TABLE', 'THEAD', 'TR' ) ),
					array( 'TH', false, array( 'HTML', 'BODY', 'TABLE', 'THEAD', 'TR', 'TH' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'TABLE', 'THEAD', 'TR', 'TH', '#text' ) ),
					array( 'TH', true, array( 'HTML', 'BODY', 'TABLE', 'THEAD', 'TR' ) ),
					array( 'TH', false, array( 'HTML', 'BODY', 'TABLE', 'THEAD', 'TR', 'TH' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'TABLE', 'THEAD', 'TR', 'TH', '#text' ) ),
					array( 'TH', true, array( 'HTML', 'BODY', 'TABLE', 'THEAD', 'TR' ) ),
					array( 'TR', true, array( 'HTML', 'BODY', 'TABLE', 'THEAD' ) ),
					array( 'THEAD', true, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'TBODY', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY' ) ),
					array( 'TR', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR' ) ),
					array( 'TD', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD', '#text' ) ),
					array( 'TD', true, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR' ) ),
					array( 'TR', true, array( 'HTML', 'BODY', 'TABLE', 'TBODY' ) ),
					array( 'TBODY', true, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'TABLE', true, array( 'HTML', 'BODY' ) ),
				),
			),
			'table-repeated-body-sections' => array(
				'<table><tbody><tr><td>a<tbody><tr><td>b</table>',
				array(
					array( 'TABLE', false, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'TBODY', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY' ) ),
					array( 'TR', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR' ) ),
					array( 'TD', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD', '#text' ) ),
					array( 'TD', true, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR' ) ),
					array( 'TR', true, array( 'HTML', 'BODY', 'TABLE', 'TBODY' ) ),
					array( 'TBODY', true, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'TBODY', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY' ) ),
					array( 'TR', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR' ) ),
					array( 'TD', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD', '#text' ) ),
					array( 'TD', true, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR' ) ),
					array( 'TR', true, array( 'HTML', 'BODY', 'TABLE', 'TBODY' ) ),
					array( 'TBODY', true, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'TABLE', true, array( 'HTML', 'BODY' ) ),
				),
			),
			'table-colgroup' => array(
				'<table><col><tr><td>A</table>',
				array(
					array( 'TABLE', false, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'COLGROUP', false, array( 'HTML', 'BODY', 'TABLE', 'COLGROUP' ) ),
					array( 'COL', false, array( 'HTML', 'BODY', 'TABLE', 'COLGROUP', 'COL' ) ),
					array( 'COLGROUP', true, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'TBODY', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY' ) ),
					array( 'TR', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR' ) ),
					array( 'TD', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD', '#text' ) ),
					array( 'TD', true, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR' ) ),
					array( 'TR', true, array( 'HTML', 'BODY', 'TABLE', 'TBODY' ) ),
					array( 'TBODY', true, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'TABLE', true, array( 'HTML', 'BODY' ) ),
				),
			),
			'table-caption' => array(
				'<table><caption>A<tr><td>B</table>',
				array(
					array( 'TABLE', false, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'CAPTION', false, array( 'HTML', 'BODY', 'TABLE', 'CAPTION' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'TABLE', 'CAPTION', '#text' ) ),
					array( 'CAPTION', true, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'TBODY', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY' ) ),
					array( 'TR', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR' ) ),
					array( 'TD', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD', '#text' ) ),
					array( 'TD', true, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR' ) ),
					array( 'TR', true, array( 'HTML', 'BODY', 'TABLE', 'TBODY' ) ),
					array( 'TBODY', true, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'TABLE', true, array( 'HTML', 'BODY' ) ),
				),
			),
			'table-caption-after-row' => array(
				'<table><tr><td>x<caption>c</caption></table>',
				array(
					array( 'TABLE', false, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'TBODY', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY' ) ),
					array( 'TR', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR' ) ),
					array( 'TD', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD', '#text' ) ),
					array( 'TD', true, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR' ) ),
					array( 'TR', true, array( 'HTML', 'BODY', 'TABLE', 'TBODY' ) ),
					array( 'TBODY', true, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'CAPTION', false, array( 'HTML', 'BODY', 'TABLE', 'CAPTION' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'TABLE', 'CAPTION', '#text' ) ),
					array( 'CAPTION', true, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'TABLE', true, array( 'HTML', 'BODY' ) ),
				),
			),
			'table-caption-after-colgroup' => array(
				'<table><colgroup><col><caption>c</caption></table>',
				array(
					array( 'TABLE', false, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'COLGROUP', false, array( 'HTML', 'BODY', 'TABLE', 'COLGROUP' ) ),
					array( 'COL', false, array( 'HTML', 'BODY', 'TABLE', 'COLGROUP', 'COL' ) ),
					array( 'COLGROUP', true, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'CAPTION', false, array( 'HTML', 'BODY', 'TABLE', 'CAPTION' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'TABLE', 'CAPTION', '#text' ) ),
					array( 'CAPTION', true, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'TABLE', true, array( 'HTML', 'BODY' ) ),
				),
			),
			'table-stray-row-closer-ignored' => array(
				'<table></tr><tr><td>x</table>',
				array(
					array( 'TABLE', false, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'TBODY', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY' ) ),
					array( 'TR', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR' ) ),
					array( 'TD', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD', '#text' ) ),
					array( 'TD', true, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR' ) ),
					array( 'TR', true, array( 'HTML', 'BODY', 'TABLE', 'TBODY' ) ),
					array( 'TBODY', true, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'TABLE', true, array( 'HTML', 'BODY' ) ),
				),
			),
			'table-stray-caption-closer-ignored' => array(
				'<table><tr><td>x</caption><tr><td>y</table>',
				array(
					array( 'TABLE', false, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'TBODY', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY' ) ),
					array( 'TR', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR' ) ),
					array( 'TD', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD', '#text' ) ),
					array( 'TD', true, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR' ) ),
					array( 'TR', true, array( 'HTML', 'BODY', 'TABLE', 'TBODY' ) ),
					array( 'TR', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR' ) ),
					array( 'TD', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD', '#text' ) ),
					array( 'TD', true, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR' ) ),
					array( 'TR', true, array( 'HTML', 'BODY', 'TABLE', 'TBODY' ) ),
					array( 'TBODY', true, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'TABLE', true, array( 'HTML', 'BODY' ) ),
				),
			),
			'table-unsupported-stray-closer-aborts-table' => array(
				'<table></p><tr><td>x</table>',
				array(
					array( 'TABLE', false, array( 'HTML', 'BODY', 'TABLE' ) ),
				),
			),
			'table-template-stray-closer-ignored' => array(
				'<table></template><tr><td>x</table>',
				array(
					array( 'TABLE', false, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'TBODY', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY' ) ),
					array( 'TR', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR' ) ),
					array( 'TD', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD', '#text' ) ),
					array( 'TD', true, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR' ) ),
					array( 'TR', true, array( 'HTML', 'BODY', 'TABLE', 'TBODY' ) ),
					array( 'TBODY', true, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'TABLE', true, array( 'HTML', 'BODY' ) ),
				),
			),
			'table-form-before-row' => array(
				'<table><form><tr><td>x</td></tr></table>',
				array(
					array( 'TABLE', false, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'FORM', false, array( 'HTML', 'BODY', 'TABLE', 'FORM' ) ),
					array( 'FORM', true, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'TBODY', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY' ) ),
					array( 'TR', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR' ) ),
					array( 'TD', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD', '#text' ) ),
					array( 'TD', true, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR' ) ),
					array( 'TR', true, array( 'HTML', 'BODY', 'TABLE', 'TBODY' ) ),
					array( 'TBODY', true, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'TABLE', true, array( 'HTML', 'BODY' ) ),
				),
			),
			'table-explicit-form-before-row' => array(
				'<table><form></form><tr><td>x</td></tr></table>',
				array(
					array( 'TABLE', false, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'FORM', false, array( 'HTML', 'BODY', 'TABLE', 'FORM' ) ),
					array( 'FORM', true, array( 'HTML', 'BODY', 'TABLE' ) ),
				),
			),
			'table-form-whitespace-before-row' => array(
				'<table><form> <tr><td>x</table>',
				array(
					array( 'TABLE', false, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'FORM', false, array( 'HTML', 'BODY', 'TABLE', 'FORM' ) ),
					array( 'FORM', true, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'TABLE', '#text' ) ),
					array( 'TBODY', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY' ) ),
					array( 'TR', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR' ) ),
					array( 'TD', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD', '#text' ) ),
					array( 'TD', true, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR' ) ),
					array( 'TR', true, array( 'HTML', 'BODY', 'TABLE', 'TBODY' ) ),
					array( 'TBODY', true, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'TABLE', true, array( 'HTML', 'BODY' ) ),
				),
			),
			'table-form-text-aborts-table' => array(
				'<table><form>x<tr><td>y</table>',
				array(
					array( 'TABLE', false, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'FORM', false, array( 'HTML', 'BODY', 'TABLE', 'FORM' ) ),
					array( 'FORM', true, array( 'HTML', 'BODY', 'TABLE' ) ),
				),
			),
			'table-form-comment-before-row' => array(
				'<table><form><!--x--><tr><td>y</table>',
				array(
					array( 'TABLE', false, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'FORM', false, array( 'HTML', 'BODY', 'TABLE', 'FORM' ) ),
					array( 'FORM', true, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( '#comment', false, array( 'HTML', 'BODY', 'TABLE', '#comment' ) ),
					array( 'TBODY', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY' ) ),
					array( 'TR', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR' ) ),
					array( 'TD', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD', '#text' ) ),
					array( 'TD', true, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR' ) ),
					array( 'TR', true, array( 'HTML', 'BODY', 'TABLE', 'TBODY' ) ),
					array( 'TBODY', true, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'TABLE', true, array( 'HTML', 'BODY' ) ),
				),
			),
			'table-form-script-before-row' => array(
				'<table><form><script>x</script><tr><td>y</table>',
				array(
					array( 'TABLE', false, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'FORM', false, array( 'HTML', 'BODY', 'TABLE', 'FORM' ) ),
					array( 'FORM', true, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'SCRIPT', false, array( 'HTML', 'BODY', 'TABLE', 'SCRIPT' ) ),
					array( 'TBODY', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY' ) ),
					array( 'TR', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR' ) ),
					array( 'TD', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR', 'TD', '#text' ) ),
					array( 'TD', true, array( 'HTML', 'BODY', 'TABLE', 'TBODY', 'TR' ) ),
					array( 'TR', true, array( 'HTML', 'BODY', 'TABLE', 'TBODY' ) ),
					array( 'TBODY', true, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'TABLE', true, array( 'HTML', 'BODY' ) ),
				),
			),
			'table-form-flow-start-aborts-table' => array(
				'<table><form><div>x</div><tr><td>y</table>',
				array(
					array( 'TABLE', false, array( 'HTML', 'BODY', 'TABLE' ) ),
					array( 'FORM', false, array( 'HTML', 'BODY', 'TABLE', 'FORM' ) ),
					array( 'FORM', true, array( 'HTML', 'BODY', 'TABLE' ) ),
				),
			),
			'table-flow-start-aborts-table' => array(
				'<table><select>x</select><tr><td>y</table>',
				array(
					array( 'TABLE', false, array( 'HTML', 'BODY', 'TABLE' ) ),
				),
			),
			'table-inline-start-aborts-table' => array(
				'<table><span>x</span><tr><td>y</table>',
				array(
					array( 'TABLE', false, array( 'HTML', 'BODY', 'TABLE' ) ),
				),
			),
			'list-items' => array(
				'<ul><li>One<li>Two</ul>',
				array(
					array( 'UL', false, array( 'HTML', 'BODY', 'UL' ) ),
					array( 'LI', false, array( 'HTML', 'BODY', 'UL', 'LI' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'UL', 'LI', '#text' ) ),
					array( 'LI', true, array( 'HTML', 'BODY', 'UL' ) ),
					array( 'LI', false, array( 'HTML', 'BODY', 'UL', 'LI' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'UL', 'LI', '#text' ) ),
					array( 'LI', true, array( 'HTML', 'BODY', 'UL' ) ),
					array( 'UL', true, array( 'HTML', 'BODY' ) ),
				),
			),
			'ordinary-eof-closers' => array(
				'<div><span>Text',
				array(
					array( 'DIV', false, array( 'HTML', 'BODY', 'DIV' ) ),
					array( 'SPAN', false, array( 'HTML', 'BODY', 'DIV', 'SPAN' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'DIV', 'SPAN', '#text' ) ),
					array( 'SPAN', true, array( 'HTML', 'BODY', 'DIV' ) ),
					array( 'DIV', true, array( 'HTML', 'BODY' ) ),
				),
			),
			'void-child-eof-closer' => array(
				'<a><img>',
				array(
					array( 'A', false, array( 'HTML', 'BODY', 'A' ) ),
					array( 'IMG', false, array( 'HTML', 'BODY', 'A', 'IMG' ) ),
					array( 'A', true, array( 'HTML', 'BODY' ) ),
				),
			),
			'options'    => array(
				'<select><option>A<option>B</select>',
				array(
					array( 'SELECT', false, array( 'HTML', 'BODY', 'SELECT' ) ),
					array( 'OPTION', false, array( 'HTML', 'BODY', 'SELECT', 'OPTION' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'SELECT', 'OPTION', '#text' ) ),
					array( 'OPTION', true, array( 'HTML', 'BODY', 'SELECT' ) ),
					array( 'OPTION', false, array( 'HTML', 'BODY', 'SELECT', 'OPTION' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'SELECT', 'OPTION', '#text' ) ),
					array( 'OPTION', true, array( 'HTML', 'BODY', 'SELECT' ) ),
					array( 'SELECT', true, array( 'HTML', 'BODY' ) ),
				),
			),
			'paragraphs' => array(
				'<p>One<p>Two',
				array(
					array( 'P', false, array( 'HTML', 'BODY', 'P' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'P', '#text' ) ),
					array( 'P', true, array( 'HTML', 'BODY' ) ),
					array( 'P', false, array( 'HTML', 'BODY', 'P' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'P', '#text' ) ),
					array( 'P', true, array( 'HTML', 'BODY' ) ),
				),
			),
			'paragraph-before-block' => array(
				'<p>Text<div>Block</div>',
				array(
					array( 'P', false, array( 'HTML', 'BODY', 'P' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'P', '#text' ) ),
					array( 'P', true, array( 'HTML', 'BODY' ) ),
					array( 'DIV', false, array( 'HTML', 'BODY', 'DIV' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'DIV', '#text' ) ),
					array( 'DIV', true, array( 'HTML', 'BODY' ) ),
				),
			),
			'paragraph-before-heading' => array(
				'<p>Text<h1>Heading</h1>',
				array(
					array( 'P', false, array( 'HTML', 'BODY', 'P' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'P', '#text' ) ),
					array( 'P', true, array( 'HTML', 'BODY' ) ),
					array( 'H1', false, array( 'HTML', 'BODY', 'H1' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'H1', '#text' ) ),
					array( 'H1', true, array( 'HTML', 'BODY' ) ),
				),
			),
			'paragraph-before-inline' => array(
				'<p>Text<span>Inline</span>',
				array(
					array( 'P', false, array( 'HTML', 'BODY', 'P' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'P', '#text' ) ),
					array( 'SPAN', false, array( 'HTML', 'BODY', 'P', 'SPAN' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'P', 'SPAN', '#text' ) ),
					array( 'SPAN', true, array( 'HTML', 'BODY', 'P' ) ),
					array( 'P', true, array( 'HTML', 'BODY' ) ),
				),
			),
			'heading-before-heading' => array(
				'<h1>One<h2>Two</h2>',
				array(
					array( 'H1', false, array( 'HTML', 'BODY', 'H1' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'H1', '#text' ) ),
					array( 'H1', true, array( 'HTML', 'BODY' ) ),
					array( 'H2', false, array( 'HTML', 'BODY', 'H2' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'H2', '#text' ) ),
					array( 'H2', true, array( 'HTML', 'BODY' ) ),
				),
			),
			'heading-before-inline' => array(
				'<h1>One<span>Span</span>',
				array(
					array( 'H1', false, array( 'HTML', 'BODY', 'H1' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'H1', '#text' ) ),
					array( 'SPAN', false, array( 'HTML', 'BODY', 'H1', 'SPAN' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'H1', 'SPAN', '#text' ) ),
					array( 'SPAN', true, array( 'HTML', 'BODY', 'H1' ) ),
					array( 'H1', true, array( 'HTML', 'BODY' ) ),
				),
			),
			'button-before-button' => array(
				'<button><span>One<button>Two</button>',
				array(
					array( 'BUTTON', false, array( 'HTML', 'BODY', 'BUTTON' ) ),
					array( 'SPAN', false, array( 'HTML', 'BODY', 'BUTTON', 'SPAN' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'BUTTON', 'SPAN', '#text' ) ),
					array( 'SPAN', true, array( 'HTML', 'BODY', 'BUTTON' ) ),
					array( 'BUTTON', true, array( 'HTML', 'BODY' ) ),
					array( 'BUTTON', false, array( 'HTML', 'BODY', 'BUTTON' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'BUTTON', '#text' ) ),
					array( 'BUTTON', true, array( 'HTML', 'BODY' ) ),
				),
			),
			'button-explicit-closer' => array(
				'<button><span>One</button>',
				array(
					array( 'BUTTON', false, array( 'HTML', 'BODY', 'BUTTON' ) ),
					array( 'SPAN', false, array( 'HTML', 'BODY', 'BUTTON', 'SPAN' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'BUTTON', 'SPAN', '#text' ) ),
					array( 'SPAN', true, array( 'HTML', 'BODY', 'BUTTON' ) ),
					array( 'BUTTON', true, array( 'HTML', 'BODY' ) ),
				),
			),
			'summary-before-details-closer' => array(
				'<details><summary>One<summary>Two</summary></details>',
				array(
					array( 'DETAILS', false, array( 'HTML', 'BODY', 'DETAILS' ) ),
					array( 'SUMMARY', false, array( 'HTML', 'BODY', 'DETAILS', 'SUMMARY' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'DETAILS', 'SUMMARY', '#text' ) ),
					array( 'SUMMARY', false, array( 'HTML', 'BODY', 'DETAILS', 'SUMMARY', 'SUMMARY' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'DETAILS', 'SUMMARY', 'SUMMARY', '#text' ) ),
					array( 'SUMMARY', true, array( 'HTML', 'BODY', 'DETAILS', 'SUMMARY' ) ),
					array( 'SUMMARY', true, array( 'HTML', 'BODY', 'DETAILS' ) ),
					array( 'DETAILS', true, array( 'HTML', 'BODY' ) ),
				),
			),
			'nested-form-start-ignored' => array(
				'<form>One<form>Two</form>',
				array(
					array( 'FORM', false, array( 'HTML', 'BODY', 'FORM' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'FORM', '#text' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'FORM', '#text' ) ),
					array( 'FORM', true, array( 'HTML', 'BODY' ) ),
				),
			),
			'nobr-before-nobr' => array(
				'<nobr>One<nobr>Two</nobr>',
				array(
					array( 'NOBR', false, array( 'HTML', 'BODY', 'NOBR' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'NOBR', '#text' ) ),
					array( 'NOBR', true, array( 'HTML', 'BODY' ) ),
					array( 'NOBR', false, array( 'HTML', 'BODY', 'NOBR' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'NOBR', '#text' ) ),
					array( 'NOBR', true, array( 'HTML', 'BODY' ) ),
				),
			),
			'nobr-explicit-closer' => array(
				'<nobr><span>One</nobr>',
				array(
					array( 'NOBR', false, array( 'HTML', 'BODY', 'NOBR' ) ),
					array( 'SPAN', false, array( 'HTML', 'BODY', 'NOBR', 'SPAN' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'NOBR', 'SPAN', '#text' ) ),
					array( 'SPAN', true, array( 'HTML', 'BODY', 'NOBR' ) ),
					array( 'NOBR', true, array( 'HTML', 'BODY' ) ),
				),
			),
			'select-before-input' => array(
				'<select><option>A<input>B</select>',
				array(
					array( 'SELECT', false, array( 'HTML', 'BODY', 'SELECT' ) ),
					array( 'OPTION', false, array( 'HTML', 'BODY', 'SELECT', 'OPTION' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'SELECT', 'OPTION', '#text' ) ),
					array( 'OPTION', true, array( 'HTML', 'BODY', 'SELECT' ) ),
					array( 'SELECT', true, array( 'HTML', 'BODY' ) ),
					array( 'INPUT', false, array( 'HTML', 'BODY', 'INPUT' ) ),
					array( '#text', false, array( 'HTML', 'BODY', '#text' ) ),
				),
			),
			'select-option-before-hr' => array(
				'<select><option>A<hr>B</select>',
				array(
					array( 'SELECT', false, array( 'HTML', 'BODY', 'SELECT' ) ),
					array( 'OPTION', false, array( 'HTML', 'BODY', 'SELECT', 'OPTION' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'SELECT', 'OPTION', '#text' ) ),
					array( 'OPTION', true, array( 'HTML', 'BODY', 'SELECT' ) ),
					array( 'HR', false, array( 'HTML', 'BODY', 'SELECT', 'HR' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'SELECT', '#text' ) ),
					array( 'SELECT', true, array( 'HTML', 'BODY' ) ),
				),
			),
			'select-before-textarea' => array(
				'<select><option>A<textarea>B</textarea></select>',
				array(
					array( 'SELECT', false, array( 'HTML', 'BODY', 'SELECT' ) ),
					array( 'OPTION', false, array( 'HTML', 'BODY', 'SELECT', 'OPTION' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'SELECT', 'OPTION', '#text' ) ),
					array( 'OPTION', true, array( 'HTML', 'BODY', 'SELECT' ) ),
					array( 'SELECT', true, array( 'HTML', 'BODY' ) ),
					array( 'TEXTAREA', false, array( 'HTML', 'BODY', 'TEXTAREA' ) ),
				),
			),
			'html-and-body-starts-ignored' => array(
				'<html><body><body><p>x',
				array(
					array( 'P', false, array( 'HTML', 'BODY', 'P' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'P', '#text' ) ),
					array( 'P', true, array( 'HTML', 'BODY' ) ),
				),
			),
			'description-list' => array(
				'<dl><dt>A<dd>B<dt>C</dl>',
				array(
					array( 'DL', false, array( 'HTML', 'BODY', 'DL' ) ),
					array( 'DT', false, array( 'HTML', 'BODY', 'DL', 'DT' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'DL', 'DT', '#text' ) ),
					array( 'DT', true, array( 'HTML', 'BODY', 'DL' ) ),
					array( 'DD', false, array( 'HTML', 'BODY', 'DL', 'DD' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'DL', 'DD', '#text' ) ),
					array( 'DD', true, array( 'HTML', 'BODY', 'DL' ) ),
					array( 'DT', false, array( 'HTML', 'BODY', 'DL', 'DT' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'DL', 'DT', '#text' ) ),
					array( 'DT', true, array( 'HTML', 'BODY', 'DL' ) ),
					array( 'DL', true, array( 'HTML', 'BODY' ) ),
				),
			),
			'ruby-text' => array(
				'<ruby>a<rt>b<rp>(<rt>c</ruby>',
				array(
					array( 'RUBY', false, array( 'HTML', 'BODY', 'RUBY' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'RUBY', '#text' ) ),
					array( 'RT', false, array( 'HTML', 'BODY', 'RUBY', 'RT' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'RUBY', 'RT', '#text' ) ),
					array( 'RT', true, array( 'HTML', 'BODY', 'RUBY' ) ),
					array( 'RP', false, array( 'HTML', 'BODY', 'RUBY', 'RP' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'RUBY', 'RP', '#text' ) ),
					array( 'RP', true, array( 'HTML', 'BODY', 'RUBY' ) ),
					array( 'RT', false, array( 'HTML', 'BODY', 'RUBY', 'RT' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'RUBY', 'RT', '#text' ) ),
					array( 'RT', true, array( 'HTML', 'BODY', 'RUBY' ) ),
					array( 'RUBY', true, array( 'HTML', 'BODY' ) ),
				),
			),
			'optgroups' => array(
				'<select><option>A<optgroup label=b><option>B<optgroup label=c><option>C</select>',
				array(
					array( 'SELECT', false, array( 'HTML', 'BODY', 'SELECT' ) ),
					array( 'OPTION', false, array( 'HTML', 'BODY', 'SELECT', 'OPTION' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'SELECT', 'OPTION', '#text' ) ),
					array( 'OPTION', true, array( 'HTML', 'BODY', 'SELECT' ) ),
					array( 'OPTGROUP', false, array( 'HTML', 'BODY', 'SELECT', 'OPTGROUP' ) ),
					array( 'OPTION', false, array( 'HTML', 'BODY', 'SELECT', 'OPTGROUP', 'OPTION' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'SELECT', 'OPTGROUP', 'OPTION', '#text' ) ),
					array( 'OPTION', true, array( 'HTML', 'BODY', 'SELECT', 'OPTGROUP' ) ),
					array( 'OPTGROUP', true, array( 'HTML', 'BODY', 'SELECT' ) ),
					array( 'OPTGROUP', false, array( 'HTML', 'BODY', 'SELECT', 'OPTGROUP' ) ),
					array( 'OPTION', false, array( 'HTML', 'BODY', 'SELECT', 'OPTGROUP', 'OPTION' ) ),
					array( '#text', false, array( 'HTML', 'BODY', 'SELECT', 'OPTGROUP', 'OPTION', '#text' ) ),
					array( 'OPTION', true, array( 'HTML', 'BODY', 'SELECT', 'OPTGROUP' ) ),
					array( 'OPTGROUP', true, array( 'HTML', 'BODY', 'SELECT' ) ),
					array( 'SELECT', true, array( 'HTML', 'BODY' ) ),
				),
			),
		);

		foreach ( $cases as $label => $case ) {
			$processor = $this->create_html_processor( $implementation, $case[0] );

			foreach ( $case[1] as $expected ) {
				$this->assertTrue( $processor->next_token(), "Expected {$label} token." );
				$this->assertSame( $expected[0], $processor->get_token_name(), "Unexpected {$label} token name." );
				$this->assertSame( $expected[1], $processor->is_tag_closer(), "Unexpected {$label} closer flag." );
				$this->assertSame( $expected[2], $processor->get_breadcrumbs(), "Unexpected {$label} breadcrumbs." );
			}

			$this->assertFalse( $processor->next_token(), "Expected {$label} to be exhausted." );
		}
	}

	/**
	 * Verifies HTML processor text-token subdivision and table-text boundaries.
	 *
	 * @dataProvider data_html_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_html_processor_subdivides_selected_text_tokens( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation, 'WP_HTML_Native_Processor' );

		$cases = array(
			'body-text-leading-whitespace' => array(
				'<p> A <b>B</b></p>',
				array(
					array( 'P', '', array( 'HTML', 'BODY', 'P' ) ),
					array( '#text', ' ', array( 'HTML', 'BODY', 'P', '#text' ) ),
					array( '#text', 'A ', array( 'HTML', 'BODY', 'P', '#text' ) ),
					array( 'B', '', array( 'HTML', 'BODY', 'P', 'B' ) ),
					array( '#text', 'B', array( 'HTML', 'BODY', 'P', 'B', '#text' ) ),
					array( 'B', '', array( 'HTML', 'BODY', 'P' ) ),
					array( 'P', '', array( 'HTML', 'BODY' ) ),
				),
			),
			'body-text-reference-leading-whitespace' => array(
				'<p> &#10;&#x20;A</p>',
				array(
					array( 'P', '', array( 'HTML', 'BODY', 'P' ) ),
					array( '#text', " \n ", array( 'HTML', 'BODY', 'P', '#text' ) ),
					array( '#text', 'A', array( 'HTML', 'BODY', 'P', '#text' ) ),
					array( 'P', '', array( 'HTML', 'BODY' ) ),
				),
			),
			'body-text-raw-null' => array(
				"<p>\0 A\0B</p>",
				array(
					array( 'P', '', array( 'HTML', 'BODY', 'P' ) ),
					array( '#text', ' ', array( 'HTML', 'BODY', 'P', '#text' ) ),
					array( '#text', 'AB', array( 'HTML', 'BODY', 'P', '#text' ) ),
					array( 'P', '', array( 'HTML', 'BODY' ) ),
				),
			),
			'body-text-newlines' => array(
				"<p>\r\n\tA\rB</p>",
				array(
					array( 'P', '', array( 'HTML', 'BODY', 'P' ) ),
					array( '#text', "\n\t", array( 'HTML', 'BODY', 'P', '#text' ) ),
					array( '#text', "A\nB", array( 'HTML', 'BODY', 'P', '#text' ) ),
					array( 'P', '', array( 'HTML', 'BODY' ) ),
				),
			),
			'table-text-leading-whitespace' => array(
				'<table> A <tr><td>B</table>',
				array(
					array( 'TABLE', '', array( 'HTML', 'BODY', 'TABLE' ) ),
					array( '#text', ' ', array( 'HTML', 'BODY', 'TABLE', '#text' ) ),
				),
			),
			'table-text-reference-leading-whitespace' => array(
				'<table>&#x20;A<tr><td>B</table>',
				array(
					array( 'TABLE', '', array( 'HTML', 'BODY', 'TABLE' ) ),
					array( '#text', ' ', array( 'HTML', 'BODY', 'TABLE', '#text' ) ),
				),
			),
			'table-text-no-leading-whitespace' => array(
				'<table>A<tr><td>B</table>',
				array(
					array( 'TABLE', '', array( 'HTML', 'BODY', 'TABLE' ) ),
				),
			),
		);

		foreach ( $cases as $label => $case ) {
			$processor = $this->create_html_processor( $implementation, $case[0] );

			foreach ( $case[1] as $expected ) {
				$this->assertTrue( $processor->next_token(), "Expected {$label} token." );
				$this->assertSame( $expected[0], $processor->get_token_name(), "Unexpected {$label} token name." );
				$this->assertSame( $expected[1], $processor->get_modifiable_text(), "Unexpected {$label} text." );
				$this->assertSame( $expected[2], $processor->get_breadcrumbs(), "Unexpected {$label} breadcrumbs." );
			}

			$this->assertFalse( $processor->next_token(), "Expected {$label} to be exhausted." );
		}
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_tag_processor_implementations() {
		return array(
			'php-tag-processor'    => array( 'php-tag-processor' ),
			'native-tag-processor' => array( 'native-tag-processor' ),
		);
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_html_processor_implementations() {
		return array(
			'php-html-processor'    => array( 'php-html-processor' ),
			'native-html-processor' => array( 'native-html-processor' ),
		);
	}

	/**
	 * Creates a tag processor for a specific implementation.
	 *
	 * @param string $implementation Implementation identifier.
	 * @param string $html           HTML input.
	 * @return object Processor instance.
	 */
	private function create_tag_processor( $implementation, $html ) {
		if ( 'native-tag-processor' === $implementation ) {
			return new WP_HTML_Native_Tag_Processor( $html );
		}

		$processor = new WP_HTML_Tag_Processor( $html );
		$this->disable_native_delegate( $processor );

		return $processor;
	}

	/**
	 * Creates an HTML processor for a specific implementation.
	 *
	 * @param string $implementation Implementation identifier.
	 * @param string $html           HTML input.
	 * @return object Processor instance.
	 */
	private function create_html_processor( $implementation, $html ) {
		if ( 'native-html-processor' === $implementation ) {
			return WP_HTML_Native_Processor::create_fragment( $html );
		}

		$processor = WP_HTML_Processor::create_fragment( $html );
		$this->disable_native_delegate( $processor );

		return $processor;
	}

	/**
	 * Disables the native delegate so PHP implementation rows still cover PHP.
	 *
	 * @param object $processor Processor instance.
	 */
	private function disable_native_delegate( $processor ) {
		if ( ! property_exists( 'WP_HTML_Tag_Processor', 'native_processor' ) ) {
			return;
		}

		$property = new ReflectionProperty( 'WP_HTML_Tag_Processor', 'native_processor' );
		$property->setAccessible( true );
		$property->setValue( $processor, null );
	}

	/**
	 * Returns the native delegate for public-class default checks.
	 *
	 * @param object $processor Processor instance.
	 * @return object|null Native delegate.
	 */
	private function get_native_delegate( $processor ) {
		if ( ! property_exists( 'WP_HTML_Tag_Processor', 'native_processor' ) ) {
			return null;
		}

		$property = new ReflectionProperty( 'WP_HTML_Tag_Processor', 'native_processor' );
		$property->setAccessible( true );

		return $property->getValue( $processor );
	}

	/**
	 * Runs a fresh PHP process that defines native-default constants before bootstrap.
	 *
	 * @param string $constant_definitions PHP source defining constants.
	 * @return string[] Probe output lines.
	 */
	private function run_html_native_defaults_constant_probe( $constant_definitions ) {
		$root      = dirname( __DIR__, 3 );
		$extension = $root . '/extensions/native-apis/target/release/libwp_native_apis.so';

		if ( ! file_exists( $extension ) ) {
			$this->markTestSkipped( 'Native API extension binary is not built.' );
		}

		$code = $constant_definitions . "\n" .
			'require ' . var_export( $root . '/bootstrap.php', true ) . ";\n" .
			'$property = new ReflectionProperty( \'WP_HTML_Tag_Processor\', \'native_processor\' );' . "\n" .
			'$property->setAccessible( true );' . "\n" .
			'$tag_processor = new WP_HTML_Tag_Processor( \'<p data-id="1">Text</p>\' );' . "\n" .
			'$tag_delegate = $property->getValue( $tag_processor );' . "\n" .
			'echo \'tag:\' . ( null === $tag_delegate ? \'php\' : get_class( $tag_delegate ) ) . "\n";' . "\n" .
			'$tag_processor->next_tag( \'p\' );' . "\n" .
			'echo \'tag-id:\' . $tag_processor->get_attribute( \'data-id\' ) . "\n";' . "\n" .
			'$html_processor = WP_HTML_Processor::create_fragment( \'<p>Text</p>\' );' . "\n" .
			'$tree_delegate = $property->getValue( $html_processor );' . "\n" .
			'echo \'tree:\' . ( null === $tree_delegate ? \'php\' : get_class( $tree_delegate ) ) . "\n";' . "\n" .
			'$html_processor->next_token();' . "\n" .
			'echo \'tree-token:\' . $html_processor->get_token_name() . "\n";' . "\n" .
			'$full_processor = WP_HTML_Processor::create_full_parser( \'<html><body><main>Text</main></body></html>\' );' . "\n" .
			'$full_delegate = $property->getValue( $full_processor );' . "\n" .
			'echo \'full:\' . ( null === $full_delegate ? \'php\' : get_class( $full_delegate ) ) . "\n";' . "\n" .
			'$full_processor->next_token();' . "\n" .
			'echo \'full-token:\' . $full_processor->get_token_name() . "\n";';

		$command = escapeshellarg( PHP_BINARY ) . ' -d extension=' . escapeshellarg( $extension ) . ' -r ' . escapeshellarg( $code );
		$output  = array();
		$status  = 0;
		exec( $command . ' 2>&1', $output, $status );

		$this->assertSame( 0, $status, implode( "\n", $output ) );

		return $output;
	}

	/**
	 * Returns the public parser state for lifecycle conformance checks.
	 *
	 * @param object $processor Processor instance.
	 * @return string Parser state.
	 */
	private function get_parser_state( $processor ) {
		$property = new ReflectionProperty( 'WP_HTML_Tag_Processor', 'parser_state' );
		$property->setAccessible( true );

		return $property->getValue( $processor );
	}

	/**
	 * Advances either tag-processor implementation while visiting closers.
	 *
	 * @param object $processor Processor instance.
	 * @return bool Whether a tag was matched.
	 */
	private function next_tag_visiting_closers( $processor ) {
		if ( method_exists( $processor, 'next_tag_any' ) ) {
			return $processor->next_tag_any( true, 1 );
		}

		return $processor->next_tag( array( 'tag_closers' => 'visit' ) );
	}

	/**
	 * Skips implementation rows when an API is provided only by the native class.
	 *
	 * @param object $processor Processor instance.
	 * @param string $method    Method name.
	 */
	private function skip_if_html_method_is_unavailable( $processor, $method ) {
		if ( ! method_exists( $processor, $method ) ) {
			$this->markTestSkipped( "{$method} is not exposed by this HTML processor implementation." );
		}
	}

	/**
	 * Summarizes prefixed attributes for either public or native processors.
	 *
	 * @param object $processor Processor instance.
	 * @param string $prefix    Attribute-name prefix.
	 * @param bool   $closers   Whether to include closing tags in the tag count.
	 * @return array Summary with `tag_count` and `attribute_count`.
	 */
	private function summarize_attribute_names_with_prefix( $processor, $prefix, $closers ) {
		$this->skip_if_html_method_is_unavailable( $processor, 'summarize_attribute_names_with_prefix' );

		$summary = $processor->summarize_attribute_names_with_prefix( $prefix, $closers );
		if ( is_array( $summary ) ) {
			return $summary;
		}

		$parts = explode( "\x1f", $summary, 2 );

		return array(
			'tag_count'       => (int) $parts[0],
			'attribute_count' => (int) $parts[1],
		);
	}

	/**
	 * Summarizes tag inventory for either public or native processors.
	 *
	 * @param object $processor Processor instance.
	 * @param bool   $closers   Whether to include closing tags.
	 * @return array Summary with tag inventory counts.
	 */
	private function summarize_tag_inventory( $processor, $closers ) {
		$this->skip_if_html_method_is_unavailable( $processor, 'summarize_tag_inventory' );

		$summary = $processor->summarize_tag_inventory( $closers );
		if ( is_array( $summary ) ) {
			return $summary;
		}

		$parts = explode( "\x1f", $summary, 5 );

		return array(
			'tag_count'             => (int) $parts[0],
			'open_tag_count'        => (int) $parts[1],
			'closing_tag_count'     => (int) $parts[2],
			'attribute_count'       => (int) $parts[3],
			'unique_tag_name_count' => (int) $parts[4],
		);
	}

	/**
	 * Summarizes heading inventory for either public or native processors.
	 *
	 * @param object $processor Processor instance.
	 * @param bool   $closers   Whether to include closing tags.
	 * @return array Summary with heading inventory counts.
	 */
	private function summarize_heading_inventory( $processor, $closers ) {
		$this->skip_if_html_method_is_unavailable( $processor, 'summarize_heading_inventory' );

		$summary = $processor->summarize_heading_inventory( $closers );
		if ( is_array( $summary ) ) {
			return $summary;
		}

		$parts = explode( "\x1f", $summary, 8 );

		return array(
			'tag_count'     => (int) $parts[0],
			'heading_count' => (int) $parts[1],
			'h1_count'      => (int) $parts[2],
			'h2_count'      => (int) $parts[3],
			'h3_count'      => (int) $parts[4],
			'h4_count'      => (int) $parts[5],
			'h5_count'      => (int) $parts[6],
			'h6_count'      => (int) $parts[7],
		);
	}

	/**
	 * Summarizes ID inventory for either public or native processors.
	 *
	 * @param object $processor Processor instance.
	 * @param bool   $closers   Whether to include closing tags.
	 * @return array Summary with ID inventory counts.
	 */
	private function summarize_id_inventory( $processor, $closers ) {
		$this->skip_if_html_method_is_unavailable( $processor, 'summarize_id_inventory' );

		$summary = $processor->summarize_id_inventory( $closers );
		if ( is_array( $summary ) ) {
			return $summary;
		}

		$parts = explode( "\x1f", $summary, 5 );

		return array(
			'tag_count'          => (int) $parts[0],
			'id_tag_count'       => (int) $parts[1],
			'unique_id_count'    => (int) $parts[2],
			'duplicate_id_count' => (int) $parts[3],
			'id_value_bytes'     => (int) $parts[4],
		);
	}

	/**
	 * Summarizes attribute inventory for either public or native processors.
	 *
	 * @param object $processor Processor instance.
	 * @param bool   $closers   Whether to include closing tags.
	 * @return array Summary with attribute inventory counts.
	 */
	private function summarize_attribute_inventory( $processor, $closers ) {
		$this->skip_if_html_method_is_unavailable( $processor, 'summarize_attribute_inventory' );

		$summary = $processor->summarize_attribute_inventory( $closers );
		if ( is_array( $summary ) ) {
			return $summary;
		}

		$parts = explode( "\x1f", $summary, 4 );

		return array(
			'tag_count'                   => (int) $parts[0],
			'attribute_count'             => (int) $parts[1],
			'unique_attribute_name_count' => (int) $parts[2],
			'attribute_value_bytes'       => (int) $parts[3],
		);
	}

	/**
	 * Summarizes data-attribute inventory for either public or native processors.
	 *
	 * @param object $processor Processor instance.
	 * @param bool   $closers   Whether to include closing tags.
	 * @return array Summary with data-attribute inventory counts.
	 */
	private function summarize_data_attribute_inventory( $processor, $closers ) {
		$this->skip_if_html_method_is_unavailable( $processor, 'summarize_data_attribute_inventory' );

		$summary = $processor->summarize_data_attribute_inventory( $closers );
		if ( is_array( $summary ) ) {
			return $summary;
		}

		$parts = explode( "\x1f", $summary, 5 );

		return array(
			'tag_count'                        => (int) $parts[0],
			'data_attribute_tag_count'         => (int) $parts[1],
			'data_attribute_count'             => (int) $parts[2],
			'unique_data_attribute_name_count' => (int) $parts[3],
			'data_attribute_value_bytes'       => (int) $parts[4],
		);
	}

	/**
	 * Summarizes ARIA attribute inventory for either public or native processors.
	 *
	 * @param object $processor Processor instance.
	 * @param bool   $closers   Whether to include closing tags.
	 * @return array Summary with ARIA attribute inventory counts.
	 */
	private function summarize_aria_attribute_inventory( $processor, $closers ) {
		$this->skip_if_html_method_is_unavailable( $processor, 'summarize_aria_attribute_inventory' );

		$summary = $processor->summarize_aria_attribute_inventory( $closers );
		if ( is_array( $summary ) ) {
			return $summary;
		}

		$parts = explode( "\x1f", $summary, 5 );

		return array(
			'tag_count'                        => (int) $parts[0],
			'aria_attribute_tag_count'         => (int) $parts[1],
			'aria_attribute_count'             => (int) $parts[2],
			'unique_aria_attribute_name_count' => (int) $parts[3],
			'aria_attribute_value_bytes'       => (int) $parts[4],
		);
	}

	/**
	 * Summarizes class inventory for either public or native processors.
	 *
	 * @param object $processor Processor instance.
	 * @param bool   $closers   Whether to include closing tags.
	 * @return array Summary with class inventory counts.
	 */
	private function summarize_class_inventory( $processor, $closers ) {
		$this->skip_if_html_method_is_unavailable( $processor, 'summarize_class_inventory' );

		$summary = $processor->summarize_class_inventory( $closers );
		if ( is_array( $summary ) ) {
			return $summary;
		}

		$parts = explode( "\x1f", $summary, 5 );

		return array(
			'tag_count'               => (int) $parts[0],
			'class_attribute_count'   => (int) $parts[1],
			'class_name_count'        => (int) $parts[2],
			'unique_class_name_count' => (int) $parts[3],
			'class_value_bytes'       => (int) $parts[4],
		);
	}

	/**
	 * Summarizes resource inventory for either public or native processors.
	 *
	 * @param object $processor Processor instance.
	 * @param bool   $closers   Whether to include closing tags.
	 * @return array Summary with resource inventory counts.
	 */
	private function summarize_resource_inventory( $processor, $closers ) {
		$this->skip_if_html_method_is_unavailable( $processor, 'summarize_resource_inventory' );

		$summary = $processor->summarize_resource_inventory( $closers );
		if ( is_array( $summary ) ) {
			return $summary;
		}

		$parts = explode( "\x1f", $summary, 5 );

		return array(
			'tag_count'                      => (int) $parts[0],
			'resource_tag_count'             => (int) $parts[1],
			'resource_attribute_count'       => (int) $parts[2],
			'unique_resource_tag_name_count' => (int) $parts[3],
			'resource_value_bytes'           => (int) $parts[4],
		);
	}

	/**
	 * Summarizes image inventory for either public or native processors.
	 *
	 * @param object $processor Processor instance.
	 * @param bool   $closers   Whether to include closing tags.
	 * @return array Summary with image inventory counts.
	 */
	private function summarize_image_inventory( $processor, $closers ) {
		$this->skip_if_html_method_is_unavailable( $processor, 'summarize_image_inventory' );

		$summary = $processor->summarize_image_inventory( $closers );
		if ( is_array( $summary ) ) {
			return $summary;
		}

		$parts = explode( "\x1f", $summary, 8 );

		return array(
			'tag_count'       => (int) $parts[0],
			'image_count'     => (int) $parts[1],
			'src_count'       => (int) $parts[2],
			'alt_count'       => (int) $parts[3],
			'empty_alt_count' => (int) $parts[4],
			'dimension_count' => (int) $parts[5],
			'src_value_bytes' => (int) $parts[6],
			'alt_value_bytes' => (int) $parts[7],
		);
	}

	/**
	 * Summarizes script inventory for either public or native processors.
	 *
	 * @param object $processor Processor instance.
	 * @param bool   $closers   Whether to include closing tags.
	 * @return array Summary with script inventory counts.
	 */
	private function summarize_script_inventory( $processor, $closers ) {
		$this->skip_if_html_method_is_unavailable( $processor, 'summarize_script_inventory' );

		$summary = $processor->summarize_script_inventory( $closers );
		if ( is_array( $summary ) ) {
			return $summary;
		}

		$parts = explode( "\x1f", $summary, 8 );

		return array(
			'tag_count'           => (int) $parts[0],
			'script_count'        => (int) $parts[1],
			'src_count'           => (int) $parts[2],
			'module_count'        => (int) $parts[3],
			'async_count'         => (int) $parts[4],
			'defer_count'         => (int) $parts[5],
			'inline_script_bytes' => (int) $parts[6],
			'src_value_bytes'     => (int) $parts[7],
		);
	}

	/**
	 * Summarizes form inventory for either public or native processors.
	 *
	 * @param object $processor Processor instance.
	 * @param bool   $closers   Whether to include closing tags.
	 * @return array Summary with form inventory counts.
	 */
	private function summarize_form_inventory( $processor, $closers ) {
		$this->skip_if_html_method_is_unavailable( $processor, 'summarize_form_inventory' );

		$summary = $processor->summarize_form_inventory( $closers );
		if ( is_array( $summary ) ) {
			return $summary;
		}

		$parts = explode( "\x1f", $summary, 6 );

		return array(
			'tag_count'                 => (int) $parts[0],
			'form_count'                => (int) $parts[1],
			'control_count'             => (int) $parts[2],
			'named_control_count'       => (int) $parts[3],
			'unique_control_name_count' => (int) $parts[4],
			'control_name_value_bytes'  => (int) $parts[5],
		);
	}

	/**
	 * Summarizes matching tag attributes for either public or native processors.
	 *
	 * @param object $processor       Processor instance.
	 * @param string $implementation  Implementation identifier.
	 * @param string $tag_name        Tag name to match.
	 * @param array  $attribute_names Attribute names to read.
	 * @param bool   $closers         Whether to include closing tags in the tag count.
	 * @return array Summary with `tag_count`, `attribute_count`, and `attribute_value_bytes`.
	 */
	private function summarize_matching_tag_attributes( $processor, $implementation, $tag_name, $attribute_names, $closers ) {
		$this->skip_if_html_method_is_unavailable( $processor, 'summarize_matching_tag_attributes' );

		if ( 'native-tag-processor' === $implementation ) {
			$summary = $processor->summarize_matching_tag_attributes( $tag_name, implode( "\x1f", $attribute_names ), $closers );
		} else {
			$summary = $processor->summarize_matching_tag_attributes( $tag_name, $attribute_names, $closers );
		}

		if ( is_array( $summary ) ) {
			return $summary;
		}

		$parts = explode( "\x1f", $summary, 3 );

		return array(
			'tag_count'             => (int) $parts[0],
			'attribute_count'       => (int) $parts[1],
			'attribute_value_bytes' => (int) $parts[2],
		);
	}

	/**
	 * Removes prefixed attributes for either public or native processors.
	 *
	 * @param object $processor Processor instance.
	 * @param string $prefix    Attribute-name prefix.
	 * @param bool   $closers   Whether to include closing tags in the tag count.
	 * @return array Summary with `tag_count`, `removed_count`, and `html`.
	 */
	private function remove_attributes_with_prefix_from_document( $processor, $prefix, $closers ) {
		$this->skip_if_html_method_is_unavailable( $processor, 'remove_attributes_with_prefix_from_document' );

		$summary = $processor->remove_attributes_with_prefix_from_document( $prefix, $closers );
		if ( is_array( $summary ) ) {
			return $summary;
		}

		$parts = explode( "\x1f", $summary, 3 );

		return array(
			'tag_count'     => (int) $parts[0],
			'removed_count' => (int) $parts[1],
			'html'          => $parts[2],
		);
	}

	/**
	 * Reads a chunked tag-prefix summary for either public or native processors.
	 *
	 * @param object $processor      Processor instance.
	 * @param string $implementation Implementation identifier.
	 * @param string $prefix         Attribute-name prefix.
	 * @param int    $max_tags       Maximum number of tag rows.
	 * @param bool   $closers        Whether to include closing tags.
	 * @return array[] Tag summary rows.
	 */
	private function next_tag_prefix_summary_batch( $processor, $implementation, $prefix, $max_tags, $closers ) {
		$this->skip_if_html_method_is_unavailable( $processor, 'next_tag_prefix_summary_batch' );

		$batch = $processor->next_tag_prefix_summary_batch( $prefix, $max_tags, $closers );
		if ( 'native-tag-processor' !== $implementation ) {
			return $batch;
		}

		if ( ! is_string( $batch ) || '' === $batch ) {
			return array();
		}

		$rows = array();
		foreach ( explode( "\x1e", $batch ) as $metadata ) {
			$parts = explode( "\x1f", $metadata, 3 );
			$this->assertCount( 3, $parts, 'Expected compact HTML tag summary row.' );

			$rows[] = array(
				'tag_name'        => $parts[0],
				'is_tag_closer'   => '1' === $parts[1],
				'attribute_count' => (int) $parts[2],
			);
		}

		return $rows;
	}

	/**
	 * Reads a chunked tag-prefix count summary for either public or native processors.
	 *
	 * @param object $processor Processor instance.
	 * @param string $prefix    Attribute-name prefix.
	 * @param int    $max_tags  Maximum number of tags to consume.
	 * @param bool   $closers   Whether to include closing tags.
	 * @return array|null Summary with `tag_count` and `attribute_count`, or null when exhausted.
	 */
	private function next_tag_prefix_count_batch( $processor, $prefix, $max_tags, $closers ) {
		$this->skip_if_html_method_is_unavailable( $processor, 'next_tag_prefix_count_compact_batch' );

		$summary = $processor->next_tag_prefix_count_compact_batch( $prefix, $max_tags, $closers );
		if ( ! is_string( $summary ) || '' === $summary ) {
			return null;
		}

		$parts = explode( "\x1f", $summary, 2 );
		$this->assertCount( 2, $parts, 'Expected compact HTML tag prefix count batch.' );

		return array(
			'tag_count'       => (int) $parts[0],
			'attribute_count' => (int) $parts[1],
		);
	}

	/**
	 * Reads a chunked tag summary for either public or native processors.
	 *
	 * @param object $processor      Processor instance.
	 * @param string $implementation Implementation identifier.
	 * @param int    $max_tags       Maximum number of tag rows.
	 * @param bool   $closers        Whether to include closing tags.
	 * @return array[] Tag summary rows.
	 */
	private function next_tag_summary_batch( $processor, $implementation, $max_tags, $closers ) {
		$this->skip_if_html_method_is_unavailable( $processor, 'next_tag_summary_batch' );

		return $processor->next_tag_summary_batch( $max_tags, $closers );
	}

	/**
	 * Reads a chunked matching-tag summary for either public or native processors.
	 *
	 * @param object $processor      Processor instance.
	 * @param string $implementation Implementation identifier.
	 * @param string $tag_name       Tag name to match.
	 * @param int    $max_tags       Maximum number of tag rows.
	 * @param bool   $closers        Whether to include closing tags.
	 * @return array[] Tag summary rows.
	 */
	private function next_matching_tag_summary_batch( $processor, $implementation, $tag_name, $max_tags, $closers ) {
		$this->skip_if_html_method_is_unavailable( $processor, 'next_matching_tag_summary_batch' );

		return $processor->next_matching_tag_summary_batch( $tag_name, $max_tags, $closers );
	}

	/**
	 * Reads a chunked matching-tag attribute summary for either public or native processors.
	 *
	 * @param object $processor      Processor instance.
	 * @param string $implementation Implementation identifier.
	 * @param string $tag_name       Tag name to match.
	 * @param string $attribute_name Attribute name to read.
	 * @param int    $max_tags       Maximum number of tag rows.
	 * @param bool   $closers        Whether to include closing tags.
	 * @return array[] Tag summary rows.
	 */
	private function next_matching_tag_attribute_summary_batch( $processor, $implementation, $tag_name, $attribute_name, $max_tags, $closers ) {
		$this->skip_if_html_method_is_unavailable( $processor, 'next_matching_tag_attribute_summary_batch' );

		return $processor->next_matching_tag_attribute_summary_batch( $tag_name, $attribute_name, $max_tags, $closers );
	}

	/**
	 * Reads a chunked matching-tag multi-attribute summary for either public or native processors.
	 *
	 * @param object $processor       Processor instance.
	 * @param string $implementation  Implementation identifier.
	 * @param string $tag_name        Tag name to match.
	 * @param array  $attribute_names Attribute names to read.
	 * @param int    $max_tags        Maximum number of tag rows.
	 * @param bool   $closers         Whether to include closing tags.
	 * @return array[] Tag summary rows.
	 */
	private function next_matching_tag_attributes_summary_batch( $processor, $implementation, $tag_name, $attribute_names, $max_tags, $closers ) {
		$this->skip_if_html_method_is_unavailable( $processor, 'next_matching_tag_attributes_summary_batch' );

		return $processor->next_matching_tag_attributes_summary_batch( $tag_name, $attribute_names, $max_tags, $closers );
	}

	/**
	 * Reads a compact chunked matching-tag multi-attribute summary.
	 *
	 * @param object $processor       Processor instance.
	 * @param string $implementation  Implementation identifier.
	 * @param string $tag_name        Tag name to match.
	 * @param array  $attribute_names Attribute names to read.
	 * @param int    $max_tags        Maximum number of tag rows.
	 * @param bool   $closers         Whether to include closing tags.
	 * @return string|null Compact tag summary batch, or null when exhausted.
	 */
	private function next_matching_tag_attributes_compact_summary_batch( $processor, $implementation, $tag_name, $attribute_names, $max_tags, $closers ) {
		$this->skip_if_html_method_is_unavailable( $processor, 'next_matching_tag_attributes_compact_summary_batch' );

		if ( 'native-tag-processor' === $implementation ) {
			return $processor->next_matching_tag_attributes_compact_summary_batch( $tag_name, implode( "\x1f", $attribute_names ), $max_tags, $closers );
		}

		return $processor->next_matching_tag_attributes_compact_summary_batch( $tag_name, $attribute_names, $max_tags, $closers );
	}

	/**
	 * Reads a chunked HTML token summary for either public or native processors.
	 *
	 * @param object $processor      Processor instance.
	 * @param string $implementation Implementation identifier.
	 * @param int    $max_tokens     Maximum number of token rows.
	 * @return array[] Token summary rows.
	 */
	private function next_html_token_summary_batch( $processor, $implementation, $max_tokens ) {
		$this->skip_if_html_method_is_unavailable( $processor, 'next_token_summary_batch' );

		return $processor->next_token_summary_batch( $max_tokens );
	}

	/**
	 * Skips native implementation cases when the extension is unavailable.
	 *
	 * @param string $implementation Implementation identifier.
	 * @param string $class_name     Native class name.
	 */
	private function skip_if_native_is_unavailable( $implementation, $class_name ) {
		if ( 0 === strpos( $implementation, 'native-' ) && ! class_exists( $class_name, false ) ) {
			$this->markTestSkipped( $class_name . ' is not registered; load the native API extension to run this case.' );
		}
	}
}
