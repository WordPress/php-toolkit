<?php

use PHPUnit\Framework\TestCase;
use WordPress\DataLiberation\URL\CSSUrlProcessor;

class CSSUrlProcessorTest extends TestCase {

	/**
	 * @dataProvider provider_test_css_escape_decoding
	 */
	public function test_css_escape_decoding( $css_value, $expected_url ) {
		$processor = new CSSUrlProcessor( $css_value );

		$this->assertTrue( $processor->next_url(), 'Failed to find URL in CSS' );
		$this->assertEquals( $expected_url, $processor->get_raw_url(), 'Decoded URL does not match expected value' );
	}

	public static function provider_test_css_escape_decoding() {
		return array(
			// Basic hex escapes
			'Space as \\20'                           => array(
				'background: url(https://example.com/hello\\20world.png)',
				'https://example.com/hello world.png',
			),
			'Space as \\000020 (6 digits)'            => array(
				'background: url(https://example.com/hello\\000020world.png)',
				'https://example.com/hello world.png',
			),
			'Non-breaking space \\A0'                 => array(
				'background: url("https://example.com/test\\A0 file.png")',
				'https://example.com/test' . "\xC2\xA0" . 'file.png',
			),
			'Tab character \\9'                       => array(
				'background: url(https://example.com/file\\9name.png)',
				"https://example.com/file\tname.png",
			),
			'Newline \\A'                             => array(
				'background: url(https://example.com/file\\Aname.png)',
				"https://example.com/file\nname.png",
			),

			// Single character escapes
			'Escaped parenthesis \\('                 => array(
				'background: url(https://example.com/file\\(1\\).png)',
				'https://example.com/file(1).png',
			),
			'Escaped quote \\"'                       => array(
				'background: url(https://example.com/file\\"name.png)',
				'https://example.com/file"name.png',
			),
			'Escaped single quote \\\''               => array(
				'background: url(https://example.com/file\\\'name.png)',
				"https://example.com/file'name.png",
			),
			'Escaped backslash \\\\'                  => array(
				'background: url(https://example.com/path\\\\file.png)',
				'https://example.com/path\\file.png',
			),

			// Hex escapes with trailing whitespace
			// Note: Trailing whitespace after hex escapes is consumed by the decoder
			// but the URL must still be valid according to the regex (no actual whitespace in unquoted URLs)
			'Hex escape followed by more hex'         => array(
				'background: url(https://example.com/\\20test.png)',
				'https://example.com/ test.png',
			),
			'Hex escape at end with space after'      => array(
				'background: url("https://example.com/test\\20 more.png")',
				'https://example.com/test more.png',
			),

			// Edge cases with hex digits
			'1-digit hex escape'                      => array(
				'background: url(https://example.com/\\9.png)',
				"https://example.com/\t.png",
			),
			'2-digit hex escape'                      => array(
				'background: url(https://example.com/\\41.png)',
				'https://example.com/A.png',
			),
			'3-digit hex escape'                      => array(
				'background: url(https://example.com/\\263A.png)',
				'https://example.com/☺.png',
			),
			'4-digit hex escape'                      => array(
				'background: url(https://example.com/\\1F600.png)',
				'https://example.com/😀.png',
			),
			'5-digit hex escape'                      => array(
				'background: url(https://example.com/\\0263A.png)',
				'https://example.com/☺.png',
			),
			'6-digit hex escape (max length)'         => array(
				'background: url(https://example.com/\\01F600.png)',
				'https://example.com/😀.png',
			),

			// Hex escapes followed by hex-like characters
			'Hex escape followed by non-hex letter'   => array(
				'background: url(https://example.com/\\41G.png)',
				'https://example.com/AG.png',
			),
			'Hex escape at end of value'              => array(
				'background: url(https://example.com/test\\41)',
				'https://example.com/testA',
			),

			// Line breaks in escapes
			// Note: Escaped line breaks consume the line break character
			// but actual line breaks in quoted strings need special regex handling
			'Newline as hex \\A'                      => array(
				'background: url("https://example.com/test\\00000Amore.png")',
				"https://example.com/test\nmore.png",
			),
			'Carriage return as hex \\D'              => array(
				'background: url("https://example.com/test\\00000Dmore.png")',
				"https://example.com/test\rmore.png",
			),

			// Multiple escapes
			'Multiple hex escapes'                    => array(
				'background: url(https://example.com/\\41\\42\\43.png)',
				'https://example.com/ABC.png',
			),
			'Mixed escape types'                      => array(
				'background: url(https://example.com/\\41\\(test\\).png)',
				'https://example.com/A(test).png',
			),

			// Backslash at end of string (edge case)
			// Note: \\ at end escapes the backslash itself
			'Trailing escaped backslash'              => array(
				'background: url("https://example.com/test\\\\")',
				'https://example.com/test\\',
			),

			// Unicode characters
			'Unicode emoji via hex escape'            => array(
				'background: url(https://example.com/\\1F44D.png)',
				'https://example.com/👍.png',
			),
			'Chinese character via hex escape'        => array(
				'background: url(https://example.com/\\4E2D\\6587.png)',
				'https://example.com/中文.png',
			),
			'Multiple trailing whitespaces after the hex escape are preserved' => array(
				'background: url("https://example.com/test\\26   more.png")',
				'https://example.com/test&  more.png',
			),

			// Case insensitivity of hex digits
			'Lowercase hex digits'                    => array(
				'background: url(https://example.com/\\00002f\\000061.png)',
				'https://example.com//a.png',
			),
			'Uppercase hex digits'                    => array(
				'background: url(https://example.com/\\00002F\\000041.png)',
				'https://example.com//A.png',
			),
			'Mixed case hex digits with whitespace'   => array(
				// Note: The whitespace after hex escapes is consumed as part of the escape sequence
				'background: url("https://example.com/\\2F \\61 \\41 \\42 .png")',
				'https://example.com//aAB.png',
			),

			// Very low codepoint
			'Control character \\1 (SOH)'             => array(
				'background: url("https://example.com/test\\1 .png")',
				"https://example.com/test\x01.png",
			),

			// Special URL characters escaped
			'Escaped forward slash'                   => array(
				'background: url(https://example.com/path\\/to\\/file.png)',
				'https://example.com/path/to/file.png',
			),
			'Escaped question mark'                   => array(
				'background: url(https://example.com/file.png\\?query)',
				'https://example.com/file.png?query',
			),
			'Escaped hash'                            => array(
				'background: url(https://example.com/file.png\\#anchor)',
				'https://example.com/file.png#anchor',
			),

			// Consecutive backslashes
			'Two backslashes'                         => array(
				'background: url(https://example.com/test\\\\.png)',
				'https://example.com/test\\.png',
			),
			'Three backslashes'                       => array(
				'background: url(https://example.com/test\\\\\\.png)',
				'https://example.com/test\\.png',
			),
			'Four backslashes'                        => array(
				'background: url(https://example.com/test\\\\\\\\.png)',
				'https://example.com/test\\\\.png',
			),
		);
	}

	/**
	 * @dataProvider provider_test_basic_css_url_detection
	 */
	public function test_basic_css_url_detection( $css_value, $expected_url ) {
		$processor = new CSSUrlProcessor( $css_value );

		$this->assertTrue( $processor->next_url(), 'Failed to find URL in CSS' );
		$this->assertEquals( $expected_url, $processor->get_raw_url() );
	}

	public static function provider_test_basic_css_url_detection() {
		return array(
			'Quoted URL'                              => array(
				'background: url("https://example.com/image.png")',
				'https://example.com/image.png',
			),
			'Single-quoted URL'                       => array(
				"background: url('https://example.com/image.png')",
				'https://example.com/image.png',
			),
			'Unquoted URL'                            => array(
				'background: url(https://example.com/image.png)',
				'https://example.com/image.png',
			),
			'URL with whitespace before'              => array(
				'background: url(  "https://example.com/image.png")',
				'https://example.com/image.png',
			),
			'URL with whitespace after'               => array(
				'background: url("https://example.com/image.png"  )',
				'https://example.com/image.png',
			),
			'Case-insensitive URL function'           => array(
				'background: URL("https://example.com/image.png")',
				'https://example.com/image.png',
			),
		);
	}

	public function test_skips_urls_in_comments() {
		$css       = '/* background: url("https://commented.com/image.png"); */ background: url("https://real.com/image.png")';
		$processor = new CSSUrlProcessor( $css );

		$this->assertTrue( $processor->next_url() );
		$this->assertEquals( 'https://real.com/image.png', $processor->get_raw_url() );
		$this->assertFalse( $processor->next_url(), 'Should not find commented URL' );
	}

	public function test_skips_urls_in_strings() {
		$css       = 'content: "Visit url(https://example.com)"; background: url("https://real.com/image.png")';
		$processor = new CSSUrlProcessor( $css );

		$this->assertTrue( $processor->next_url() );
		$this->assertEquals( 'https://real.com/image.png', $processor->get_raw_url() );
		$this->assertFalse( $processor->next_url(), 'Should not find URL in content string' );
	}

	public function test_handles_multiple_urls() {
		$css       = 'background: url("https://example.com/bg1.png"), url("https://example.com/bg2.png")';
		$processor = new CSSUrlProcessor( $css );

		$this->assertTrue( $processor->next_url() );
		$this->assertEquals( 'https://example.com/bg1.png', $processor->get_raw_url() );

		$this->assertTrue( $processor->next_url() );
		$this->assertEquals( 'https://example.com/bg2.png', $processor->get_raw_url() );

		$this->assertFalse( $processor->next_url() );
	}

	public function test_url_replacement() {
		$css       = 'background: url("https://old.com/image.png")';
		$processor = new CSSUrlProcessor( $css );

		$this->assertTrue( $processor->next_url() );
		$this->assertTrue( $processor->set_raw_url( 'https://new.com/image.png' ) );

		$expected = 'background: url("https://new.com/image.png")';
		$this->assertEquals( $expected, $processor->get_updated_css() );
	}

	public function test_replaces_multiple_urls() {
		$css       = 'background: url("https://example.com/bg1.png"), url("https://example.com/bg2.png")';
		$processor = new CSSUrlProcessor( $css );

		$processor->next_url();
		$processor->set_raw_url( 'https://new.com/bg1.png' );

		$processor->next_url();
		$processor->set_raw_url( 'https://new.com/bg2.png' );

		$expected = 'background: url("https://new.com/bg1.png"), url("https://new.com/bg2.png")';
		$this->assertEquals( $expected, $processor->get_updated_css() );
	}

	public function test_handles_whitespace_inside_url() {
		// CSS spec allows whitespace but not comments inside url()
		$css       = 'background: url(  "https://example.com/image.png"  )';
		$processor = new CSSUrlProcessor( $css );

		$this->assertTrue( $processor->next_url() );
		$this->assertEquals( 'https://example.com/image.png', $processor->get_raw_url() );
	}

	public function test_returns_false_when_no_urls() {
		$css       = 'background: #fff; color: red;';
		$processor = new CSSUrlProcessor( $css );

		$this->assertFalse( $processor->next_url() );
	}

	public function test_handles_data_uris() {
		$css       = 'background: url("data:image/png;base64,iVBORw0KGgo=")';
		$processor = new CSSUrlProcessor( $css );

		$this->assertTrue( $processor->next_url() );
		$this->assertEquals( 'data:image/png;base64,iVBORw0KGgo=', $processor->get_raw_url() );
	}

	public function test_handles_1mb_data_uri() {
		// Test with 1MB data URI using state machine parser
		// The parser can handle arbitrarily large URLs without PCRE limits
		$data_uri  = 'data:image/png;base64,' . str_repeat( 'A', 2 * 1024 * 1024 );
		$css_value = 'background: url("' . $data_uri . '")';
		$processor = new CSSUrlProcessor( $css_value );

		$this->assertTrue( $processor->next_url(), 'Failed to find URL in CSS' );
		$this->assertEquals( $data_uri, $processor->get_raw_url() );
	}

	/**
	 * @dataProvider provider_test_is_data_uri
	 */
	public function test_is_data_uri( $css_value, $expected ) {
		$processor = new CSSUrlProcessor( $css_value );

		$this->assertTrue( $processor->next_url(), 'Failed to find URL in CSS' );
		$this->assertEquals( $expected, $processor->is_data_uri(), 'is_data_uri() returned unexpected value' );
	}

	public static function provider_test_is_data_uri() {
		return array(
			// Data URIs - quoted
			'Quoted data URI'                  => array(
				'background: url("data:image/png;base64,iVBORw0KGgo=")',
				true,
			),
			'Single-quoted data URI'           => array(
				"background: url('data:image/png;base64,iVBORw0KGgo=')",
				true,
			),
			'Quoted data URI uppercase'        => array(
				'background: url("DATA:image/png;base64,iVBORw0KGgo=")',
				true,
			),
			'Quoted data URI mixed case'       => array(
				'background: url("DaTa:image/png;base64,iVBORw0KGgo=")',
				true,
			),

			// Data URIs - unquoted
			'Unquoted data URI'                => array(
				'background: url(data:image/png;base64,iVBORw0KGgo=)',
				true,
			),
			'Unquoted data URI uppercase'      => array(
				'background: url(DATA:image/png;base64,iVBORw0KGgo=)',
				true,
			),
			'Unquoted data URI mixed case'     => array(
				'background: url(DaTa:image/png;base64,iVBORw0KGgo=)',
				true,
			),

			// Large data URIs
			'Large quoted data URI'            => array(
				'background: url("data:image/png;base64,' . str_repeat( 'A', 10000 ) . '")',
				true,
			),
			'Large unquoted data URI'          => array(
				'background: url(data:image/png;base64,' . str_repeat( 'A', 10000 ) . ')',
				true,
			),

			// Non-data URIs - quoted
			'Quoted HTTP URL'                  => array(
				'background: url("https://example.com/image.png")',
				false,
			),
			'Quoted relative URL'              => array(
				'background: url("/images/bg.png")',
				false,
			),
			'Quoted file URL'                  => array(
				'background: url("file:///path/to/image.png")',
				false,
			),

			// Non-data URIs - unquoted
			'Unquoted HTTP URL'                => array(
				'background: url(https://example.com/image.png)',
				false,
			),
			'Unquoted relative URL'            => array(
				'background: url(/images/bg.png)',
				false,
			),

			// Edge cases
			'URL containing "data:" substring' => array(
				'background: url("https://example.com/data:test.png")',
				false,
			),
			'Short URL starting with "dat"'    => array(
				'background: url(data)',
				false,
			),
		);
	}

	public function test_is_data_uri_without_url_match() {
		$processor = new CSSUrlProcessor( 'background: #fff;' );

		$this->assertFalse( $processor->is_data_uri(), 'is_data_uri() should return false when no URL is matched' );
	}

	public function test_is_data_uri_optimized_no_extraction() {
		// Test that is_data_uri() doesn't trigger URL extraction
		$css       = 'background: url("data:image/png;base64,iVBORw0KGgo=")';
		$processor = new CSSUrlProcessor( $css );

		$this->assertTrue( $processor->next_url() );

		// Use reflection to verify matched_url is still null
		$reflection       = new ReflectionClass( $processor );
		$matched_url_prop = $reflection->getProperty( 'matched_url' );
		$matched_url_prop->setAccessible( true );

		$this->assertTrue( $processor->is_data_uri(), 'is_data_uri() should return true' );
		$this->assertNull( $matched_url_prop->getValue( $processor ), 'is_data_uri() should not extract the URL' );
	}

}
