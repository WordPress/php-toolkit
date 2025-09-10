<?php

use PHPUnit\Framework\TestCase;
use WordPress\DataLiberation\URL\URLInTextProcessor;

class URLInTextProcessorTest extends TestCase {

	/**
	 * @dataProvider provider_test_finds_next_url_when_base_url_is_used
	 */
	public function test_finds_next_url_when_base_url_is_used( $url, $parsed_href, $text, $which_url = 1 ) {
		$p = new URLInTextProcessor( $text, 'https://w.org' );
		for ( $i = 0; $i < $which_url; $i ++ ) {
			$this->assertTrue( $p->next_url(), 'Failed to find the URL in the text.' );
		}
		$this->assertEquals( $url, $p->get_raw_url(), 'Found a URL in the text, but it wasn\'t the expected one.' );
		$this->assertEquals( $parsed_href, $p->get_parsed_url()->href, 'Found a URL in the text, but it wasn\'t the expected one.' );
	}

	public static function provider_test_finds_next_url_when_base_url_is_used() {
		return array(
			// Standard URLs
			'HTTP protocol'                               => array(
				'http://example.com',
				'http://example.com/',
				'Visit http://example.com',
			),
			'HTTPS protocol'                              => array(
				'https://example.com',
				'https://example.com/',
				'Visit https://example.com',
			),
			'HTTP without www'                            => array(
				'http://example.com',
				'http://example.com/',
				'This link: http://example.com',
			),
			'HTTPS without www'                           => array(
				'https://example.com',
				'https://example.com/',
				'Here is a URL: https://example.com',
			),
			'URL with www only'                           => array(
				'http://www.example.com',
				'http://www.example.com/',
				'Check this link: http://www.example.com',
			),
			'HTTPS with www'                              => array(
				'https://www.example.com',
				'https://www.example.com/',
				'Secure link: https://www.example.com',
			),

			// Special hostnames
			'Localhost'                                   => array( 'http://localhost', 'http://localhost/', 'Visit http://localhost' ),
			'127.0.0.1'                                   => array( 'http://127.0.0.1', 'http://127.0.0.1/', 'Visit http://127.0.0.1' ),
			'Reserved TLD – example.internal'             => array(
				'http://example.internal',
				'http://example.internal/',
				'Visit http://example.internal',
			),
			'Reserved TLD – example.test'                 => array(
				'http://example.test',
				'http://example.test/',
				'Visit http://example.test',
			),

			// Subdomains
			'Single Subdomain'                            => array(
				'http://blog.example.com',
				'http://blog.example.com/',
				'Visit http://blog.example.com',
			),
			'Multiple Subdomains'                         => array(
				'http://a.b.c.d.example.com',
				'http://a.b.c.d.example.com/',
				'Visit http://a.b.c.d.example.com',
			),
			'Subdomain with Port'                         => array(
				'http://blog.example.com:8080',
				'http://blog.example.com:8080/',
				'Check out http://blog.example.com:8080',
			),
			'Multiple Subdomains with Port'               => array(
				'http://sub1.sub2.example.com:3000',
				'http://sub1.sub2.example.com:3000/',
				'Try this: http://sub1.sub2.example.com:3000',
			),
			'Subdomain with Query'                        => array(
				'http://blog.example.com/?id=1',
				'http://blog.example.com/?id=1',
				'Visit http://blog.example.com/?id=1',
			),
			'Subdomain with Path and Query'               => array(
				'http://api.blog.example.com/v1/posts?sort=asc',
				'http://api.blog.example.com/v1/posts?sort=asc',
				'API link: http://api.blog.example.com/v1/posts?sort=asc',
			),

			// Ports
			'With Port Number'                            => array(
				'http://example.com:8080',
				'http://example.com:8080/',
				'Visit http://example.com:8080',
			),
			'HTTPS with Port'                             => array(
				'https://example.com:443',
				'https://example.com/',
				'Secure link: https://example.com:443',
			),
			'Non-Standard Port'                           => array(
				'https://example.com:12345',
				'https://example.com:12345/',
				'Check out https://example.com:12345',
			),

			// Paths
			'Simple Path'                                 => array(
				'http://example.com/path',
				'http://example.com/path',
				'Visit http://example.com/path',
			),
			'Nested Paths'                                => array(
				'http://example.com/path/to/resource',
				'http://example.com/path/to/resource',
				'Visit http://example.com/path/to/resource',
			),
			'Path with Special Characters'                => array(
				'http://example.com/path%20with%20spaces',
				'http://example.com/path%20with%20spaces',
				'Link: http://example.com/path%20with%20spaces',
			),
			'Path with unencoded special characters'      => array(
				'http://example.com/!$&\'()*+,;=',
				'http://example.com/!$&\'()*+,;=',
				'Link: http://example.com/!$&\'()*+,;=',
			),
			'Path with Trailing Slash'                    => array(
				'http://example.com/path/',
				'http://example.com/path/',
				'Navigate to http://example.com/path/',
			),
			'Path with Mixed Case'                        => array(
				'http://example.com/Path/To/Resource',
				'http://example.com/Path/To/Resource',
				'Link: http://example.com/Path/To/Resource',
			),
			'Path with Dot Segments'                      => array(
				'http://example.com/./path/../to/resource',
				'http://example.com/to/resource',
				'Check http://example.com/./path/../to/resource',
			),
			'Empty Path'                                  => array(
				'http://example.com/',
				'http://example.com/',
				'Link to root: http://example.com/',
			),
			'Double Slash'                                => array(
				'http://example.com//path//to/resource',
				'http://example.com//path//to/resource',
				'Visit http://example.com//path//to/resource',
			),
			'Dot segments'                                => array(
				'http://example.com/./path/../to/resource',
				'http://example.com/to/resource',
				'Check http://example.com/./path/../to/resource',
			),

			// Query Parameters
			'Single Parameter'                            => array(
				'http://example.com/?id=123',
				'http://example.com/?id=123',
				'Visit http://example.com/?id=123',
			),
			'Multiple Parameters'                         => array(
				'http://example.com/?id=123&name=abc',
				'http://example.com/?id=123&name=abc',
				'Visit http://example.com/?id=123&name=abc',
			),
			'Encoded Parameters'                          => array(
				'http://example.com/?q=hello%20world',
				'http://example.com/?q=hello%20world',
				'Search at http://example.com/?q=hello%20world',
			),
			'Empty Parameter'                             => array(
				'http://example.com/?q=',
				'http://example.com/?q=',
				'Check http://example.com/?q=',
			),
			'Semicolon in Query'                          => array(
				'http://example.com/?q=hello;world',
				'http://example.com/?q=hello;world',
				'Search at http://example.com/?q=hello;world',
			),
			'Unicode in Query'                            => array(
				'http://example.com/?q=prüfung',
				'http://example.com/?q=pr%C3%BCfung',
				'Search at http://example.com/?q=prüfung',
			),
			'Plus in Query'                               => array(
				'http://example.com/?q=hello+world',
				'http://example.com/?q=hello+world',
				'Search at http://example.com/?q=hello+world',
			),
			'Nested URL in Query'                         => array(
				'http://example.com/?q=http://example.com&test=123',
				'http://example.com/?q=http://example.com&test=123',
				'Search at http://example.com/?q=http://example.com&test=123',
			),
			'Does not ingest a trailing exclamation mark' => array(
				'http://example.com/?param1=val1&param2=val@2',
				'http://example.com/?param1=val1&param2=val@2',
				'Params: http://example.com/?param1=val1&param2=val@2!',
			),
			'Does not ingest a trailing quote'            => array(
				'http://example.com/?param1=val1&param2=val@2',
				'http://example.com/?param1=val1&param2=val@2',
				'Params: "http://example.com/?param1=val1&param2=val@2"',
			),
			'Does not ingest a trailing parenthesis'      => array(
				'http://example.com/?param1=val1&param2=val@2',
				'http://example.com/?param1=val1&param2=val@2',
				'Params: (http://example.com/?param1=val1&param2=val@2)',
			),
			'Does not ingest a trailing dot'              => array(
				'http://example.com/?param1=val1&param2=val@2',
				'http://example.com/?param1=val1&param2=val@2',
				'Params: http://example.com/?param1=val1&param2=val@2.',
			),

			// Fragments
			'Simple Fragment'                             => array(
				'http://example.com/#section1',
				'http://example.com/#section1',
				'Visit http://example.com/#section1',
			),
			'Fragment with Special Characters'            => array(
				'http://example.com/#/path/to/section',
				'http://example.com/#/path/to/section',
				'Section link: http://example.com/#/path/to/section',
			),
			'Fragment with Numbers'                       => array(
				'http://example.com/#123',
				'http://example.com/#123',
				'Navigate to http://example.com/#123',
			),
			'Fragment Only'                               => array(
				'http://example.com#fragment',
				'http://example.com/#fragment',
				'Check http://example.com#fragment',
			),
			'Fragment with Query'                         => array(
				'http://example.com/?id=1#section',
				'http://example.com/?id=1#section',
				'Link with fragment: http://example.com/?id=1#section',
			),

			// Internationalized Domain Names (IDNs)
			'Unicode Domains'                             => array(
				'http://例子.测试',
				'http://xn--fsqu00a.xn--0zwm56d/',
				'Visit http://例子.测试',
			),
			'Punycode Representation'                     => array(
				'http://xn--fsqu00a.xn--0zwm56d',
				'http://xn--fsqu00a.xn--0zwm56d/',
				'Visit http://xn--fsqu00a.xn--0zwm56d',
			),
			'Unicode in Path'                             => array(
				'http://example.com/über',
				'http://example.com/%C3%BCber',
				'Visit http://example.com/über',
			),
			'Unicode in Query'                            => array(
				'http://example.com/?q=prüfung',
				'http://example.com/?q=pr%C3%BCfung',
				'Search at http://example.com/?q=prüfung',
			),

			// IPv4 and IPv6
			'IPv4 Address'                                => array(
				'http://192.168.0.1',
				'http://192.168.0.1/',
				'Visit http://192.168.0.1',
			),
			'IPv4 with Port'                              => array(
				'http://192.168.0.1:8080',
				'http://192.168.0.1:8080/',
				'Access http://192.168.0.1:8080',
			),
			// 'IPv6 Address' => ['http://[2001:db8::1]', 'http://[2001:db8::1]/', 'Visit http://[2001:db8::1]'],
			// 'IPv6 with Port' => ['http://[2001:db8::1]:8080', 'http://[2001:db8::1]:8080/', 'Visit http://[2001:db8::1]:8080'],

			// Protocols
			'Protocol-Relative URL'                       => array(
				'//example.com/path',
				'https://example.com/path',
				'Visit protocol-relative URL: //example.com/path',
			),

			// Domain only
			'Domain only'                                 => array( 'example.com', 'https://example.com/', 'Visit example.com' ),
			'Domain only – Unicode domain'                => array( '例子.com', 'https://xn--fsqu00a.com/', 'Visit 例子.com' ),
			'Domain only – Punycode domain'               => array(
				'xn--fsqu00a.com',
				'https://xn--fsqu00a.com/',
				'Visit xn--fsqu00a.com',
			),
			'Domain only – Unicode in Path'               => array(
				'example.com/über',
				'https://example.com/%C3%BCber',
				'Visit example.com/über',
			),
			'Domain only – long TLD'                      => array(
				'example.technology',
				'https://example.technology/',
				'Visit example.technology',
			),
			'Domain only – double TLD'                    => array( 'example.co.uk', 'https://example.co.uk/', 'Visit example.co.uk' ),
			// @TODO
			// 'Domain only – Punycode TLD' => ['xn--fsqu00a.xn--0zwm56d', 'https://xn--fsqu00a.xn--0zwm56d/', 'Visit xn--fsqu00a.xn--0zwm56d'],

			// Other
			'Uppercase protocol'                          => array(
				'HTTP://example.com',
				'http://example.com/',
				'Visit HTTP://example.com',
			),
			'Uppercase hostname and TLD'                  => array(
				'http://EXAMPLE.COM',
				'http://example.com/',
				'Visit http://EXAMPLE.COM',
			),
			'Uppercase URL'                               => array(
				'HTTP://EXAMPLE.COM',
				'http://example.com/',
				'Visit HTTP://EXAMPLE.COM',
			),
			'Trailing slash'                              => array(
				'http://example.com/',
				'http://example.com/',
				'Visit http://example.com/',
			),
			'No trailing slash'                           => array(
				'http://example.com',
				'http://example.com/',
				'Visit http://example.com',
			),
		);
	}

	/**
	 * @dataProvider provider_test_no_url_should_be_found
	 */
	public function test_no_url_should_be_found( $text ) {
		$p = new URLInTextProcessor( $text, 'https://w.org' );
		$this->assertFalse( $p->next_url(), 'next_url() returned true when no URL was expected in the text.' );
	}

	public static function provider_test_no_url_should_be_found() {
		return array(
			'No URL Present'               => array( 'This text has no URL.' ),
			'Malformed URL without Scheme' => array( 'Invalid link: example..com' ),
			'Not a URL - Math Expression'  => array( 'Calculate x/y where y ≠ 0' ),
			'Text with Version Number'     => array( 'Version 1.2.3 is released' ),
			'Text with a filename'         => array( 'Edit the plugins.php file' ),
			'Random Text with Symbols'     => array( 'Check this out: !@#$%^&*()' ),
			'Magnet URI'                   => array( 'magnet:?xt=urn:btih:123456789abcdef' ),
			'Single Word'                  => array( 'example' ),
			'Random Special Characters'    => array( '{}[]|\;:"<>,./?' ),
			'Plain Text with Colon'        => array( 'This is not a URL: it is just text.' ),
			'Numeric Only Text'            => array( '1234567890' ),
			'Only TLD'                     => array( 'http://.org' ),
			'Missing protocol'             => array( '://.org' ),
			'Incomplete IPv6'              => array( 'http://[2001:db8::' ),
			'IPv6 with Zone Index'         => array( 'http://[fe80::1%25eth0]' ),
			'Protocol without Domain'      => array( 'http://' ),

			// Only HTTP and HTTPS URLs are supported
			'Tel Protocol'                 => array( 'tel:+123456789' ),
			'Data URL'                     => array( 'data:text/plain;base64,SGVsbG8sIFdvcmxkIQ==' ),
			// @TODO fix failures
			// 'Custom protocol' => ['myapp://open?param=value'],
			// 'File Protocol' => ['file:///C:/path/to/file'],
			// 'FTP Protocol' => ['ftp://example.com/resource'],
			// 'Mailto Protocol' => ['mailto:user@example.com'],
		);
	}

	public function test_set_url_returns_true_on_success() {
		$p = new URLInTextProcessor( 'Have you seen https://wordpress.org?' );
		$p->next_url();
		$this->assertTrue( $p->set_raw_url( 'https://w.org' ), 'Failed to set the URL in the text.' );
	}

	public function test_set_url_returns_false_on_failure() {
		$p = new URLInTextProcessor( 'Have you seen WordPress?' );
		$p->next_url();
		$this->assertFalse( $p->set_raw_url( 'https://w.org' ), 'set_url returned true when no URL was matched.' );
	}

	/**
	 * @dataProvider provider_test_set_url_data
	 */
	public function test_set_url_replaces_the_url( $text, $new_url, $expected_text ) {
		$p = new URLInTextProcessor( $text );
		$p->next_url();
		$p->set_raw_url( $new_url );
		$this->assertEquals(
			$new_url,
			$p->get_raw_url(),
			'Failed to set the URL in the text.'
		);
		$this->assertEquals(
			$expected_text,
			$p->get_updated_text(),
			'Failed to set the URL in the text.'
		);
	}

	public static function provider_test_set_url_data() {
		return array(
			'Replace with HTTPS URL'               => array(
				'Have you seen https://wordpress.org (or wp.org)?',
				'https://wikipedia.org',
				'Have you seen https://wikipedia.org (or wp.org)?',
			),
			'Replace with a protocol-relative URL' => array(
				'Have you seen https://wordpress.org (or wp.org)?',
				'//wikipedia.org',
				'Have you seen //wikipedia.org (or wp.org)?',
			),
			'Replace with a schema-less URL'       => array(
				'Have you seen https://wordpress.org (or wp.org)?',
				'wikipedia.org',
				'Have you seen wikipedia.org (or wp.org)?',
			),
		);
	}

	public function test_set_url_can_be_called_twice() {
		$p = new URLInTextProcessor( 'Have you seen https://wordpress.org (or w.org)?' );
		$p->next_url();
		$p->set_raw_url( 'https://developer.wordpress.org' );
		$p->get_updated_text();
		$p->set_raw_url( 'https://wikipedia.org' );
		$this->assertEquals(
			'https://wikipedia.org',
			$p->get_raw_url(),
			'Failed to set the URL in the text.'
		);
		$this->assertEquals(
			'Have you seen https://wikipedia.org (or w.org)?',
			$p->get_updated_text(),
			'Failed to set the URL in the text.'
		);
	}

	public function test_set_url_can_be_called_twice_before_moving_on() {
		$p = new URLInTextProcessor( 'Have you seen https://wordpress.org (or w.org)?', 'https://w.org' );
		$p->next_url();
		$p->set_raw_url( 'https://wikipedia.org' );
		$p->get_updated_text();
		$p->set_raw_url( 'https://developer.wordpress.org' );
		$p->next_url();
		$p->set_raw_url( 'https://meetups.wordpress.org' );
		$this->assertEquals(
			'Have you seen https://developer.wordpress.org (or meetups.wordpress.org)?',
			$p->get_updated_text(),
			'Failed to set the URL in the text.'
		);
	}
}
