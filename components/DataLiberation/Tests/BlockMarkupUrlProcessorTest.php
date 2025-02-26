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
	public function test_next_url_finds_the_url( $url, $markup, $base_url = 'https://wordpress.org' ) {
		$p = new BlockMarkupUrlProcessor( $markup, $base_url );
		$this->assertTrue( $p->next_url(), 'Failed to find the URL in the markup.' );
		$this->assertEquals( $url, $p->get_raw_url(), 'Found a URL in the markup, but it wasn\'t the expected one.' );
	}

	public static function provider_test_finds_next_url() {
		return array(
			'In the <a> tag' => array( 'https://wordpress.org', '<a href="https://wordpress.org">' ),
			'In the second block attribute, when it contains just the URL' => array(
				'https://mysite.com/wp-content/image.png',
				'<!-- wp:image {"class": "wp-bold", "src": "https://mysite.com/wp-content/image.png"} -->',
			),
			'In the first block attribute, when it contains just the URL' => array(
				'https://mysite.com/wp-content/image.png',
				'<!-- wp:image {"src": "https://mysite.com/wp-content/image.png"} -->',
			),
			'In a block attribute, in a nested object, when it contains just the URL' => array(
				'https://mysite.com/wp-content/image.png',
				'<!-- wp:image {"class": "wp-bold", "meta": { "src": "https://mysite.com/wp-content/image.png" } } -->',
			),
			'In a block attribute, in an array, when it contains just the URL' => array(
				'https://mysite.com/wp-content/image.png',
				'<!-- wp:image {"class": "wp-bold", "srcs": [ "https://mysite.com/wp-content/image.png" ] } -->',
			),
			'In a text node, when it contains a well-formed absolute URL' => array(
				'https://wordpress.org',
				'Have you seen https://wordpress.org? ',
			),
			'In a text node after a tag' => array(
				'wordpress.org',
				'<p>Have you seen wordpress.org',
			),
			'In a text node, when it contains a protocol-relative absolute URL' => array(
				'//wordpress.org',
				'Have you seen //wordpress.org? ',
			),
			'In a text node, when it contains a domain-only absolute URL' => array(
				'wordpress.org',
				'Have you seen wordpress.org? ',
			),
			'In a text node, when it contains a domain-only absolute URL with path' => array(
				'wordpress.org/plugins',
				'Have you seen wordpress.org/plugins? ',
			),
			'Matches an empty string in <a href=""> as a valid relative URL when given a base URL' => array(
				'',
				'<a href=""></a>',
				'https://wordpress.org',
			),
			'Skips over an empty string in <a href=""> when not given a base URL' => array(
				'https://developer.w.org',
				'<a href=""></a><a href="https://developer.w.org"></a>',
				null,
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
		$this->assertEquals( 'https://first-url.org', $p->get_raw_url(), 'Found a URL in the markup, but it wasn\'t the expected one.' );

		$this->assertTrue( $p->next_url(), 'Failed to find the URL in the markup.' );
		$this->assertEquals( 'https://mysite.com/wp-content/image.png', $p->get_raw_url(), 'Found a URL in the markup, but it wasn\'t the expected one.' );
	}

	public function test_next_url_finds_urls_in_multiple_tags() {
		$markup = '<img longdesc="https://first-url.org" src="https://mysite.com/wp-content/image.png"><a href="https://third-url.org">';
		$p      = new BlockMarkupUrlProcessor( $markup );
		$this->assertTrue( $p->next_url(), 'Failed to find the URL in the markup.' );
		$this->assertEquals( 'https://first-url.org', $p->get_raw_url(), 'Found a URL in the markup, but it wasn\'t the expected one.' );

		$this->assertTrue( $p->next_url(), 'Failed to find the URL in the markup.' );
		$this->assertEquals( 'https://mysite.com/wp-content/image.png', $p->get_raw_url(), 'Found a URL in the markup, but it wasn\'t the expected one.' );

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
		$this->assertTrue( $p->set_raw_url( $new_url ), 'Failed to set the URL in the markup.' );
		$this->assertEquals( $new_markup, $p->get_updated_html(), 'Failed to set the URL in the markup.' );
	}

	public static function provider_test_set_url_examples() {
		return array(
			'In the href attribute of an <a> tag' => array(
				'<a href="https://wordpress.org">',
				'https://w.org',
				'<a href="https://w.org">',
			),
			'In the "src" block attribute' => array(
				'<!-- wp:image {"src": "https://mysite.com/wp-content/image.png"} -->',
				'https://w.org',
				'<!-- wp:image {"src":"https:\/\/w.org"} -->',
			),
			'In a text node' => array(
				'Have you seen https://wordpress.org yet?',
				'https://w.org',
				'Have you seen https://w.org yet?',
			),
		);
	}

	public function test_set_url_complex_test_case() {
		$p = new BlockMarkupUrlProcessor(
			<<<HTML
<!-- wp:image {"src": "https://mysite.com/wp-content/image.png", "meta": {"src": "https://mysite.com/wp-content/image.png"}} -->
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
			$p->set_raw_url( 'https://site-export.internal' );
		}

		$this->assertEquals(
			<<<HTML
<!-- wp:image {"src":"https:\/\/site-export.internal","meta":{"src":"https:\/\/site-export.internal"}} -->
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

	public function test_next_url_replace_the_url_for_simple_text() {
		$p = new BlockMarkupUrlProcessor(
			'https://example.com/test/?page_id=1',
			'https://example.com/'
		);

		while ( $p->next_url() ) {
			$p->replace_base_url( WPURL::parse( 'https://example.org:8888/' ) );
		}

		$this->assertEquals( 'https://example.org:8888/test/?page_id=1', $p->get_updated_html() );
	}
}
