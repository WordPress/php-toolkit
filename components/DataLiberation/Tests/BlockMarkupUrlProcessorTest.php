<?php

use PHPUnit\Framework\TestCase;
use WordPress\DataLiberation\BlockMarkup\BlockMarkupUrlProcessor;
use WordPress\DataLiberation\URL\WPURL;

class BlockMarkupUrlProcessorTest extends TestCase {


	public function test_next_url_in_current_token_returns_false_when_no_url_is_found() {
		$p = new BlockMarkupUrlProcessor( 'Text without URLs' );
		$this->assertFalse( $p->next_url_in_current_token() );
	}

	/**
	 *
	 * @dataProvider provider_test_finds_next_url
	 */
	public function test_next_url_finds_the_url( $expected_raw_url, $expected_absolute_url, $markup, $base_url = 'https://wordpress.org' ) {
		$p = new BlockMarkupUrlProcessor( $markup, $base_url );
		$this->assertTrue( $p->next_url(), 'Failed to find the URL in the markup.' );
		$this->assertEquals( $expected_raw_url, $p->get_raw_url(), 'Found a URL in the markup, but it wasn\'t the expected one.' );
		$this->assertEquals( $expected_absolute_url, $p->get_parsed_url()->toString(), 'Found a URL in the markup, but it wasn\'t the expected one.' );
	}

	public static function provider_test_finds_next_url() {
		return array(
			'In the <a> tag'                                                                       => array(
				'https://wordpress.org',
				'https://wordpress.org/',
				'<a href="https://wordpress.org">',
			),
			'In the wp:image url attribute when it is the first block attribute and contains a relative URL'                          => array(
				'/wp-content/image.png',
				'https://wordpress.org/wp-content/image.png',
				'<!-- wp:image {"url": "/wp-content/image.png"} -->',
			),
			'In the wp:image url attribute when it is the second block attribute and contains just the URL'                         => array(
				'https://mysite.com/wp-content/image.png',
				'https://mysite.com/wp-content/image.png',
				'<!-- wp:image {"class": "wp-bold", "url": "https://mysite.com/wp-content/image.png"} -->',
			),
			'In a text node, when it contains a well-formed absolute URL'                          => array(
				'https://wordpress.org',
				'https://wordpress.org/',
				'Have you seen https://wordpress.org? ',
			),
			'In a text node after a tag'                                                           => array(
				'wordpress.org',
				'https://wordpress.org/',
				'<p>Have you seen wordpress.org',
			),
			'In a text node, when it contains a protocol-relative absolute URL'                    => array(
				'//wordpress.org',
				'https://wordpress.org/',
				'Have you seen //wordpress.org? ',
			),
			'In a text node, when it contains a domain-only absolute URL'                          => array(
				'wordpress.org',
				'https://wordpress.org/',
				'Have you seen wordpress.org? ',
			),
			'In a text node, when it contains a domain-only absolute URL with path'                => array(
				'wordpress.org/plugins',
				'https://wordpress.org/plugins',
				'Have you seen wordpress.org/plugins? ',
			),
			'Matches an empty string in <a href=""> as a valid relative URL when given a base URL' => array(
				'',
				'https://wordpress.org/',
				'<a href=""></a>',
				'https://wordpress.org/',
			),
			'Skips over an empty string in <a href=""> when not given a base URL'                  => array(
				'https://developer.w.org',
				'https://developer.w.org/',
				'<a href=""></a><a href="https://developer.w.org"></a>',
				null,
			),
			'Skips over a class name in the <a> tag' => array(
				'https://developer.w.org',
				'https://developer.w.org/',
				'<a class="http://example.com" href="https://developer.w.org"></a>',
				null,
			),
		);
	}

	/**
	 *
	 * @dataProvider provider_test_finds_next_negative_url
	 */
	public function test_next_url_finds_the_negative_url( $markup, $base_url = 'https://wordpress.org' ) {
		$p = new BlockMarkupUrlProcessor( $markup, $base_url );
		$this->assertFalse( $p->next_url(), 'Found a URL in the markup, but it wasn\'t the expected one.' );
	}

	public static function provider_test_finds_next_negative_url() {
		return array(
			'In a block attribute, in a nested object, when it contains just the URL'              => array(
				'<!-- wp:image {"class": "wp-bold", "meta": { "src": "https://mysite.com/wp-content/image.png" } } -->',
			),
			'In a block attribute, in an array, when it contains just the URL'                     => array(
				'<!-- wp:image {"class": "wp-bold", "srcs": [ "https://mysite.com/wp-content/image.png" ] } -->',
			),
		);
	}

	/**
	 * @dataProvider provider_test_parse_url_with_base_url
	 */
	public function test_parse_url_with_base_url( $expected_result, $markup, $base_url ) {
		$p = new BlockMarkupUrlProcessor( $markup, $base_url );
		$this->assertTrue( $p->next_url(), 'Failed to find the URL in the markup.' );
		$parsed_url = $p->get_parsed_url();
		$this->assertNotFalse( $parsed_url, 'URL parsing failed' );
		$this->assertEquals( $expected_result, $parsed_url->toString(), 'Parsed URL does not match the expected URL.' );
	}

	public static function provider_test_parse_url_with_base_url() {
		return array(
			'Static file URL in the <a> tag' => array(
				'https://wordpress.org/nodejs-development-environment.md',
				'<a href="nodejs-development-environment.md">',
				'https://wordpress.org',
			),
			'Relative URL in the <a> tag'    => array(
				'https://wordpress.org/docs/page.html',
				'<a href="docs/page.html">',
				'https://wordpress.org',
			),
			'Absolute URL with base URL'     => array(
				'https://example.com/page.html',
				'<a href="https://example.com/page.html">',
				'https://wordpress.org',
			),
		);
	}

	public function test_next_url_returns_false_once_theres_no_more_urls() {
		$markup = '<img longdesc="https://first-url.org" src="https://mysite.com/wp-content/image.png">';
		$p      = new BlockMarkupUrlProcessor( $markup );
		$this->assertTrue( $p->next_url(), 'Failed to find the URL in the markup.' );
		$this->assertTrue( $p->next_url(), 'Failed to find the URL in the markup.' );
		$this->assertFalse( $p->next_url(), 'Found more URLs than expected.' );
	}

	public function test_next_url_finds_urls_in_multiple_attributes() {
		$markup = '<img longdesc="https://first-url.org" src="https://mysite.com/wp-content/image.png">';
		$p      = new BlockMarkupUrlProcessor( $markup );
		$this->assertTrue( $p->next_url(), 'Failed to find the URL in the markup.' );
		$this->assertEquals( 'https://mysite.com/wp-content/image.png', $p->get_raw_url(), 'Found a URL in the markup, but it wasn\'t the expected one.' );

		$this->assertTrue( $p->next_url(), 'Failed to find the URL in the markup.' );
		$this->assertEquals( 'https://first-url.org', $p->get_raw_url(),
			'Found a URL in the markup, but it wasn\'t the expected one.' );
	}

	public function test_next_url_finds_urls_in_multiple_tags() {
		$markup = '<img longdesc="https://first-url.org" src="https://mysite.com/wp-content/image.png"><a href="https://third-url.org">';
		$p      = new BlockMarkupUrlProcessor( $markup );
		$this->assertTrue( $p->next_url(), 'Failed to find the URL in the markup.' );
		$this->assertEquals( 'https://mysite.com/wp-content/image.png', $p->get_raw_url(), 'Found a URL in the markup, but it wasn\'t the expected one.' );

		$this->assertTrue( $p->next_url(), 'Failed to find the URL in the markup.' );
		$this->assertEquals( 'https://first-url.org', $p->get_raw_url(),
			'Found a URL in the markup, but it wasn\'t the expected one.' );

		$this->assertTrue( $p->next_url(), 'Failed to find the URL in the markup.' );
		$this->assertEquals( 'https://third-url.org', $p->get_raw_url(), 'Found a URL in the markup, but it wasn\'t the expected one.' );
	}

	/**
	 *
	 * @dataProvider provider_test_set_url_examples
	 */
	public function test_set_url( $markup, $new_url, $new_markup ) {
		$p = new BlockMarkupUrlProcessor( $markup );
		$this->assertTrue( $p->next_url(), 'Failed to find the URL in the markup.' );
		$this->assertTrue( $p->set_url( $new_url, WPURL::parse( $new_url ) ), 'Failed to set the URL in the markup.' );
		$this->assertEquals( $new_markup, $p->get_updated_html(), 'Failed to set the URL in the markup.' );
	}

	public static function provider_test_set_url_examples() {
		return array(
			'In the href attribute of an <a> tag' => array(
				'<a href="https://wordpress.org">',
				'https://w.org',
				'<a href="https://w.org">',
			),
			'In the "src" block attribute'        => array(
				'<!-- wp:image {"src": "https://mysite.com/wp-content/image.png"} -->',
				'https://w.org',
				'<!-- wp:image {"src":"https:\/\/w.org"} -->',
			),
			'In the "url" block attribute of a navigation-link block' => array(
				'<!-- wp:navigation-link {"url": "https://w.org"} /-->',
				'https://w.org',
				'<!-- wp:navigation-link {"url":"https:\/\/w.org"} /-->',
			),
			'In a text node'                      => array(
				'Have you seen https://wordpress.org yet?',
				'https://w.org',
				'Have you seen https://w.org yet?',
			),
		);
	}

	public function test_set_url_complex_test_case() {
		$p = new BlockMarkupUrlProcessor(
			<<<HTML
<!-- wp:image {"url": "https://mysite.com/wp-content/image.png", "meta": {"src": "https://mysite.com/wp-content/image.png"}} -->
	<img src="https://mysite.com/wp-content/image.png">
<!-- /wp:image -->

<!-- wp:paragraph -->
<p>During the <a href="writeofpassage.school">Write of Passage</a>, I stubbornly tried to beat my writer’s block by writing until 3am multiple times. The burnout returned. I dropped everything and went to Greece for a week.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>
Have you seen my blog, adamadam.blog? I told a story there of how I got my Bachelor&apos;s degree,
check it out: https://adamadam.blog/2021/09/16/how-i-got-bachelors-in-six-months/
</p>
<!-- /wp:paragraph -->
HTML
			,
			'https://adamadam.blog'
		);

		// Replace every url with 'https://site-export.internal'
		while ( $p->next_url() ) {
			$p->set_url( 'https://site-export.internal', WPURL::parse( 'https://site-export.internal' ) );
		}

		// meta.src is a nested property and not supported yet
		$this->assertEquals(
			<<<HTML
<!-- wp:image {"url":"https:\/\/site-export.internal","meta":{"src":"https:\/\/mysite.com\/wp-content\/image.png"}} -->
	<img src="https://site-export.internal">
<!-- /wp:image -->

<!-- wp:paragraph -->
<p>During the <a href="https://site-export.internal">Write of Passage</a>, I stubbornly tried to beat my writer’s block by writing until 3am multiple times. The burnout returned. I dropped everything and went to Greece for a week.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>
Have you seen my blog, site-export.internal? I told a story there of how I got my Bachelor&apos;s degree,
check it out: https://site-export.internal
</p>
<!-- /wp:paragraph -->
HTML
			,
			$p->get_updated_html(),
			'Failed to update all the URLs in the markup.'
		);
	}

	/**
	 * @dataProvider provider_test_next_url_replace_base_url
	 */
	public function test_next_url_replace_base_url( $input_url, $base_url, $target_base_url, $expected ) {
		$p = new BlockMarkupUrlProcessor( $input_url, $base_url );

		while ( $p->next_url() ) {
			$p->replace_base_url( WPURL::parse( $target_base_url ) );
		}

		$this->assertEquals( $expected, $p->get_updated_html() );
	}

	public static function provider_test_next_url_replace_base_url() {
		return array(
			'simple url with query params'           => array(
				'input_url'       => 'https://example.com/test/?page_id=1',
				'base_url'        => 'https://example.com/',
				'target_base_url' => 'https://example.org:8888/',
				'expected'        => 'https://example.org:8888/test/?page_id=1',
			),
			'complex path with many segments'        => array(
				'input_url'       => 'https://example.com/a/b/c/d/e/f/g/h/i/j/page/',
				'base_url'        => 'https://example.com/a/b/c/d/e/f/',
				'target_base_url' => 'https://example.org/docs/',
				'expected'        => 'https://example.org/docs/g/h/i/j/page/',
			),
			'Actual developer.wordpress.org example' => array(
				'input_url'       => 'https://developer.wordpress.org/block-editor/getting-started/devenv/get-started-with-wp-env/',
				'base_url'        => 'https://developer.wordpress.org/block-editor/getting-started/devenv/',
				'target_base_url' => 'http://127.0.0.1:9400/imported_content/',
				'expected'        => 'http://127.0.0.1:9400/imported_content/get-started-with-wp-env/',
			),
			'path with query and hash'               => array(
				'input_url'       => 'https://example.com/path/to/page/?id=123#section',
				'base_url'        => 'https://example.com/path/',
				'target_base_url' => 'https://example.org/new/',
				'expected'        => 'https://example.org/new/to/page/?id=123#section',
			),
			'deep nested paths'                      => array(
				'input_url'       => 'https://example.com/one/two/three/four/five/six/seven/eight/nine/ten/file.html',
				'base_url'        => 'https://example.com/one/two/three/four/five/six/',
				'target_base_url' => 'https://example.org/root/',
				'expected'        => 'https://example.org/root/seven/eight/nine/ten/file.html',
			),
		);
	}

	/**
	 * @dataProvider provider_test_css_url_detection
	 */
	public function test_detects_css_urls_in_style_attribute( $expected_url, $markup, $base_url = 'https://example.com' ) {
		$p = new BlockMarkupUrlProcessor( $markup, $base_url );
		$this->assertTrue( $p->next_url(), 'Failed to find CSS URL in style attribute' );
		$this->assertEquals( $expected_url, $p->get_raw_url(), 'Found CSS URL does not match expected URL' );
	}

	public static function provider_test_css_url_detection() {
		return array(
			'Basic quoted URL in background'                     => array(
				'https://wordpress.org)',
				'<div style="background: url(&quot;https://wordpress.org)&quot;);"></div>',
			),
			'URL in CSS comment (should be skipped)'             => array(
				'https://fallback.com',
				'<div style="/* background: url(&quot;https://wordpress.org)&quot;); */ background: url(&quot;https://fallback.com&quot;);"></div>',
			),
			'URL inside content string (should be skipped)'      => array(
				'https://realurl.com',
				'<div style="content: &quot;Have you ever heard about the url(https://mysite.com) syntax?&quot;; background: url(&quot;https://realurl.com&quot;);"></div>',
			),
			'Unquoted URL with encoded space'                    => array(
				'https://wordpress.org/%20/d',
				'<div style="background: url(https://wordpress.org/%20/d);"></div>',
			),
			'URL with other properties before'                   => array(
				'https://wordpress.org/%20/d',
				'<div style="background: &quot;red&quot; url(https://wordpress.org/%20/d);"></div>',
			),
			'URL with CSS comments around'                       => array(
				'https://wordpress.org/%20/d',
				'<div style="background: /* This is cool */ &quot;red&quot; url(https://wordpress.org/%20/d) /* This is cool */;"></div>',
			),
			'URL with multiple properties'                       => array(
				'https://wordpress.org/%20/d',
				'<div style="background: #fff url(https://wordpress.org/%20/d) dark;"></div>',
			),
			'Single-quoted URL'                                  => array(
				'https://example.com/image.png',
				'<div style="background-image: url(\'https://example.com/image.png\');"></div>',
			),
			'URL with whitespace inside url()'                   => array(
				'https://example.com/image.png',
				'<div style="background: url(  &quot;https://example.com/image.png&quot;  );"></div>',
			),
			'Relative URL'                                       => array(
				'/images/bg.png',
				'<div style="background: url(&quot;/images/bg.png&quot;);"></div>',
			),
			'URL with escaped quotes in quoted form'             => array(
				'https://example.com/path"with"quotes',
				'<div style="background: url(&quot;https://example.com/path\\&quot;with\\&quot;quotes&quot;);"></div>',
			),
			'Multiple URLs in single style attribute'            => array(
				'https://example.com/bg1.png',
				'<div style="background: url(&quot;https://example.com/bg1.png&quot;), url(&quot;https://example.com/bg2.png&quot;);"></div>',
			),
			'URL in different CSS properties'                    => array(
				'https://example.com/cursor.png',
				'<div style="cursor: url(&quot;https://example.com/cursor.png&quot;), auto;"></div>',
			),
			'Case-insensitive url() function'                    => array(
				'https://example.com/image.png',
				'<div style="background: URL(&quot;https://example.com/image.png&quot;);"></div>',
			),
			'Mixed case Url() function'                          => array(
				'https://example.com/image.png',
				'<div style="background: Url(&quot;https://example.com/image.png&quot;);"></div>',
			),
			'Unicode escape in quoted URL'                       => array(
				'https://example.com/image.png',
				'<div style="background: url(&quot;https://example.com/im\\61ge.png&quot;);"></div>',
			),
			'Unicode escape in unquoted URL'                     => array(
				'https://example.com/image.png',
				'<div style="background: url(https://example.com/im\\61ge.png);"></div>',
			),
		);
	}

	/**
	 * @dataProvider provider_test_css_url_replacement
	 */
	public function test_replaces_css_urls_in_style_attribute( $markup, $new_url, $expected_output, $base_url = null ) {
		$p = new BlockMarkupUrlProcessor( $markup, $base_url );
		$this->assertTrue( $p->next_url(), 'Failed to find CSS URL' );
		$this->assertTrue( $p->set_url( $new_url, WPURL::parse( $new_url, $base_url ) ), 'Failed to set CSS URL' );
		$this->assertEquals( $expected_output, $p->get_updated_html(), 'CSS URL replacement produced incorrect output' );
	}

	public static function provider_test_css_url_replacement() {
		return array(
			'Replace quoted URL'                 => array(
				'<div style="background: url(&quot;https://old.com/image.png&quot;);"></div>',
				'https://new.com/image.png',
				'<div style="background: url(&quot;https://new.com/image.png&quot;);"></div>',
			),
			'Replace unquoted URL'               => array(
				'<div style="background: url(https://old.com/image.png);"></div>',
				'https://new.com/image.png',
				// CSSProcessor always quotes the new URL:
				'<div style="background: url(&quot;https://new.com/image.png&quot;);"></div>',
			),
			'Replace single-quoted URL'          => array(
				'<div style="background: url(\'https://old.com/image.png\');"></div>',
				'https://new.com/image.png',
				'<div style="background: url(&quot;https://new.com/image.png&quot;);"></div>',
			),
			'Replace relative URL'               => array(
				'<div style="background: url(&quot;/old/path.png&quot;);"></div>',
				'/new/path.png',
				'<div style="background: url(&quot;/new/path.png&quot;);"></div>',
				'https://example.com', // base URL needed to parse relative URLs
			),
			'Replace Unicode escaped URL'        => array(
				'<div style="background: url(&quot;https://example.com/im\\61ge.png&quot;);"></div>',
				'https://new.com/image.png',
				'<div style="background: url(&quot;https://new.com/image.png&quot;);"></div>',
			),
		);
	}

	public function test_replaces_multiple_css_urls_in_style_attribute() {
		$markup = '<div style="background: url(&quot;https://example.com/bg1.png&quot;), url(&quot;https://example.com/bg2.png&quot;);"></div>';
		$p      = new BlockMarkupUrlProcessor( $markup );

		// First URL
		$this->assertTrue( $p->next_url(), 'Failed to find first CSS URL' );
		$this->assertEquals( 'https://example.com/bg1.png', $p->get_raw_url() );
		$p->set_url( 'https://new.com/bg1.png', WPURL::parse( 'https://new.com/bg1.png' ) );

		// Second URL
		$this->assertTrue( $p->next_url(), 'Failed to find second CSS URL' );
		$this->assertEquals( 'https://example.com/bg2.png', $p->get_raw_url() );
		$p->set_url( 'https://new.com/bg2.png', WPURL::parse( 'https://new.com/bg2.png' ) );

		// No more URLs
		$this->assertFalse( $p->next_url(), 'Found more URLs than expected' );

		$expected = '<div style="background: url(&quot;https://new.com/bg1.png&quot;), url(&quot;https://new.com/bg2.png&quot;);"></div>';
		$this->assertEquals( $expected, $p->get_updated_html() );
	}

	public function test_css_urls_with_regular_attributes() {
		$markup = '<img src="https://example.com/image.png" style="border-image: url(&quot;https://example.com/border.png&quot;);">';
		$p      = new BlockMarkupUrlProcessor( $markup );

		$found_urls = array();
		while ( $p->next_url() ) {
			$found_urls[] = $p->get_raw_url();
			$p->set_url( 'https://new.com/replaced.png', WPURL::parse( 'https://new.com/replaced.png' ) );
		}

		$this->assertCount( 2, $found_urls, 'Should find both src attribute and CSS URL' );
		$this->assertContains( 'https://example.com/image.png', $found_urls );
		$this->assertContains( 'https://example.com/border.png', $found_urls );

		$expected = '<img src="https://new.com/replaced.png" style="border-image: url(&quot;https://new.com/replaced.png&quot;);">';
		$this->assertEquals( $expected, $p->get_updated_html() );
	}
}
