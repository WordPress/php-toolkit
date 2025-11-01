<?php

use PHPUnit\Framework\TestCase;
use WordPress\DataLiberation\URL\CSSURLProcessor;

class CSSURLProcessorTest extends TestCase {

	/**
	 * @dataProvider provider_test_css_escape_decoding
	 */
	public function test_css_escape_decoding( $css_value, $expected_url ) {
		$processor = new CSSURLProcessor( $css_value );

		$this->assertTrue( $processor->next_url(), 'Failed to find URL in CSS' );
		$this->assertEquals( $expected_url, $processor->get_raw_url(), 'Decoded URL does not match expected value' );
	}

	public static function provider_test_css_escape_decoding() {
		// U+005C is REVERSE SOLIDUS (\)
		// These tests all represent a backslash \ as a \u{5c} escape sequence
		// to avoid confusing the reader with sequences such as \\\" where it's
		// unclear which escapes belong to the PHP string, which to the CSS string,
		// and what is the final string value.
		return array(
			// Basic hex escapes
			"Space as `\u{5c}20`"                           => array(
				"background: url(https://example.com/hello\u{5c}20world.png)",
				'https://example.com/hello world.png',
			),
			"Space as `\u{5c}000020` (6 digits)"            => array(
				"background: url(https://example.com/hello\u{5c}000020world.png)",
				'https://example.com/hello world.png',
			),
			"Space as `\u{5c}000020 ` (6 digits + space)"            => array(
				"background: url(https://example.com/hello\u{5c}000020 world.png)",
				'https://example.com/hello world.png',
			),
			"8-digit space is treated as a replacement character followed by a string `\u{5c}20`: `\u{5c}00000020`"            => array(
				"background: url(https://example.com/hello\u{5c}00000020world.png)",
				"https://example.com/hello\u{FFFD}20world.png",
			),

			// Single character escapes in unquoted URLs
			"Escaped parenthesis `\u{5c}(`"                 => array(
				"background: url(https://example.com/file\u{5c}(1\u{5c}).png)",
				'https://example.com/file(1).png',
			),
			"Escaped quote `\u{5c}\u{0022}`"                       => array(
				"background: url(https://example.com/file\u{5c}\u{0022}name.png)",
				'https://example.com/file"name.png',
			),
			"Escaped single quote `\u{5c}'`"               => array(
				"background: url(https://example.com/file\u{5c}\u{0027}name.png)",
				"https://example.com/file'name.png",
			),
			"Escaped backslash `\u{5c}\u{5c}`"                  => array(
				"background: url(https://example.com/path\u{5c}\u{5c}file.png)",
				"https://example.com/path\u{5c}file.png",
			),

			// Hex escapes with trailing whitespace
			// Note: A single whitespace character immediately after a hex escape is consumed
			// as the escape sequence terminator and is not included in the decoded output.
			// The decoded result can contain actual whitespace characters (from the escape itself).
			'Hex escape followed by more hex'         => array(
				"background: url(https://example.com/\u{5c}20test.png)",
				'https://example.com/ test.png',  // \20 decodes to a space character
			),
			'Hex escape at end with space after'      => array(
				"background: url(\u{22}https://example.com/test\u{5c}20 more.png\u{22})",
				'https://example.com/test more.png',  // \20 decodes to space; the space after \20 is consumed as terminator
			),

			// Edge cases with hex digits
			'1-digit hex escape'                      => array(
				"background: url(https://example.com/\u{5c}9.png)",
				"https://example.com/\u{09}.png",
			),
			'2-digit hex escape'                      => array(
				"background: url(https://example.com/\u{5c}41.png)",
				'https://example.com/A.png',
			),
			'3-digit hex escape'                      => array(
				"background: url(https://example.com/\u{5c}263A.png)",
				'https://example.com/☺.png',
			),
			'4-digit hex escape'                      => array(
				"background: url(https://example.com/\u{5c}1F600.png)",
				'https://example.com/😀.png',
			),
			'5-digit hex escape'                      => array(
				"background: url(https://example.com/\u{5c}0263A.png)",
				'https://example.com/☺.png',
			),
			'6-digit hex escape (max length)'         => array(
				"background: url(https://example.com/\u{5c}01F600.png)",
				'https://example.com/😀.png',
			),

			// Hex escapes followed by hex-like characters
			'Hex escape followed by non-hex letter'   => array(
				"background: url(https://example.com/\u{5c}41G.png)",
				'https://example.com/AG.png',
			),
			'Hex escape at end of value'              => array(
				"background: url(https://example.com/test\u{5c}41)",
				'https://example.com/testA',
			),

			// Line breaks in escapes
			// Note: Hex escapes can encode line break characters (U+000A newline, U+000D carriage return).
			// The decoded result contains actual line break characters.
			'Newline as hex `\u{5c}00000A`'                      => array(
				"background: url(\u{22}https://example.com/test\u{5c}00000Amore.png\u{22})",
				"https://example.com/test\u{0A}more.png",  // \00000A decodes to newline character
			),
			'Carriage return as hex `\u{5c}00000D`'              => array(
				"background: url(\u{22}https://example.com/test\u{5c}00000Dmore.png\u{22})",
				"https://example.com/test\u{0D}more.png",  // \00000D decodes to carriage return character
			),

			// Multiple escapes
			'Multiple hex escapes'                    => array(
				"background: url(https://example.com/\u{5c}41\u{5c}42\u{5c}43.png)",
				'https://example.com/ABC.png',
			),
			'Mixed escape types'                      => array(
				"background: url(https://example.com/\u{5c}41\u{5c}(test\u{5c}).png)",
				'https://example.com/A(test).png',
			),

			// Backslash at end of string (edge case)
			// Note: \\ at end escapes the backslash itself
			'Trailing escaped backslash'              => array(
				"background: url(\u{22}https://example.com/test\u{5c}\u{5c}\u{22})",
				"https://example.com/test\u{5c}",
			),

			// Unicode characters
			'Unicode emoji via hex escape'            => array(
				"background: url(https://example.com/\u{5c}1F44D.png)",
				'https://example.com/👍.png',
			),
			'Chinese character via hex escape'        => array(
				"background: url(https://example.com/\u{5c}4E2D\u{5c}6587.png)",
				'https://example.com/中文.png',
			),
			// One space after hex escape is consumed as terminator; additional spaces are preserved
			'Multiple trailing whitespaces after the hex escape are preserved' => array(
				"background: url(\u{22}https://example.com/test\u{5c}26   more.png\u{22})",  // \26 = &, followed by 3 spaces
				'https://example.com/test&  more.png',  // Result has & followed by 2 spaces (1st space consumed as terminator)
			),

			// Case insensitivity of hex digits
			'Lowercase hex digits'                    => array(
				"background: url(https://example.com/\u{5c}00002f\u{5c}000061.png)",
				'https://example.com//a.png',
			),
			'Uppercase hex digits'                    => array(
				"background: url(https://example.com/\u{5c}00002F\u{5c}000041.png)",
				'https://example.com//A.png',
			),
			'Mixed case hex digits (2f 2F) with trailing whitespace'   => array(
				// Note: The whitespace after hex escapes is consumed as part of the escape sequence
				"background: url(\u{22}https://example.com\u{5c}2F \u{5c}2f file.png\u{22})",
				'https://example.com//file.png',
			),

			// Very low codepoint
			'Control character `\u{5c}1` (SOH)'             => array(
				                    // https://example.com/test\1 .png
				"background: url(\u{22}https://example.com/test\u{5c}1 .png\u{22})",
				"https://example.com/test\u{01}.png",
			),

			// Special URL characters escaped
			'Escaped forward slash'                   => array(
				              // https://example.com/path\/to\/file.png
				"background: url(https://example.com/path\u{5c}\u{2f}to\u{5c}\u{2f}file.png)",
				'https://example.com/path/to/file.png',
			),
			'Escaped question mark'                   => array(
				"background: url(https://example.com/file.png\u{5c}\u{003f}query)",
				'https://example.com/file.png?query',
			),
			'Escaped hash'                            => array(
				"background: url(https://example.com/file.png\u{5c}\u{0023}anchor)",
				'https://example.com/file.png#anchor',
			),

			// Consecutive backslashes
			'Two backslashes'                         => array(
				"background: url(https://example.com/test\u{5c}\u{5c}.png)",
				"https://example.com/test\u{5c}.png",
			),
			'Three backslashes'                       => array(
				"background: url(https://example.com/test\u{5c}\u{5c}\u{5c}.png)",
				"https://example.com/test\u{5c}.png",
			),
			'Four backslashes'                        => array(
				"background: url(https://example.com/test\u{5c}\u{5c}\u{5c}\u{5c}.png)",
				"https://example.com/test\u{5c}\u{5c}.png",
			),
		);
	}

	/**
	 * @dataProvider provider_test_basic_css_url_detection
	 */
	public function test_basic_css_url_detection( $css_value, $expected_url ) {
		$processor = new CSSURLProcessor( $css_value );

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
		$processor = new CSSURLProcessor( $css );

		$this->assertTrue( $processor->next_url() );
		$this->assertEquals( 'https://real.com/image.png', $processor->get_raw_url() );
		$this->assertFalse( $processor->next_url(), 'Should not find commented URL' );
	}

	public function test_skips_urls_in_strings() {
		$css       = 'content: "Visit url(https://example.com)"; background: url("https://real.com/image.png")';
		$processor = new CSSURLProcessor( $css );

		$this->assertTrue( $processor->next_url() );
		$this->assertEquals( 'https://real.com/image.png', $processor->get_raw_url() );
		$this->assertFalse( $processor->next_url(), 'Should not find URL in content string' );
	}

	public function test_handles_multiple_urls() {
		$css       = 'background: url("https://example.com/bg1.png"), url("https://example.com/bg2.png")';
		$processor = new CSSURLProcessor( $css );

		$this->assertTrue( $processor->next_url() );
		$this->assertEquals( 'https://example.com/bg1.png', $processor->get_raw_url() );

		$this->assertTrue( $processor->next_url() );
		$this->assertEquals( 'https://example.com/bg2.png', $processor->get_raw_url() );

		$this->assertFalse( $processor->next_url() );
	}

	public function test_url_replacement() {
		$css       = 'background: url("https://old.com/image.png")';
		$processor = new CSSURLProcessor( $css );

		$this->assertTrue( $processor->next_url() );
		$this->assertTrue( $processor->set_raw_url( 'https://new.com/image.png' ) );

		$expected = 'background: url("https://new.com/image.png")';
		$this->assertEquals( $expected, $processor->get_updated_css() );
	}

	public function test_replaces_multiple_urls() {
		$css       = 'background: url("https://example.com/bg1.png"), url("https://example.com/bg2.png")';
		$processor = new CSSURLProcessor( $css );

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
		$processor = new CSSURLProcessor( $css );

		$this->assertTrue( $processor->next_url() );
		$this->assertEquals( 'https://example.com/image.png', $processor->get_raw_url() );
	}

	public function test_returns_false_when_no_urls() {
		$css       = 'background: #fff; color: red;';
		$processor = new CSSURLProcessor( $css );

		$this->assertFalse( $processor->next_url() );
	}

	public function test_handles_data_uris() {
		$css       = 'background: url("data:image/png;base64,iVBORw0KGgo=")';
		$processor = new CSSURLProcessor( $css );

		$this->assertTrue( $processor->next_url() );
		$this->assertEquals( 'data:image/png;base64,iVBORw0KGgo=', $processor->get_raw_url() );
	}

	public function test_handles_1mb_data_uri() {
		// Test with 1MB data URI using state machine parser
		// The parser can handle arbitrarily large URLs without PCRE limits
		$data_uri  = 'data:image/png;base64,' . str_repeat( 'A', 2 * 1024 * 1024 );
		$css_value = 'background: url("' . $data_uri . '")';
		$processor = new CSSURLProcessor( $css_value );

		$this->assertTrue( $processor->next_url(), 'Failed to find URL in CSS' );
		$this->assertEquals( $data_uri, $processor->get_raw_url() );
	}

	/**
	 * @dataProvider provider_test_is_data_uri
	 */
	public function test_is_data_uri( $css_value, $expected ) {
		$processor = new CSSURLProcessor( $css_value );

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
		$processor = new CSSURLProcessor( 'background: #fff;' );

		$this->assertFalse( $processor->is_data_uri(), 'is_data_uri() should return false when no URL is matched' );
	}

	public function test_is_data_uri_optimized_no_extraction() {
		// Test that is_data_uri() works correctly for data URIs.
		// Note: The optimization (checking first 5 bytes without extraction)
		// is now internal to CSSProcessor.
		$css       = 'background: url("data:image/png;base64,iVBORw0KGgo=")';
		$processor = new CSSURLProcessor( $css );

		$this->assertTrue( $processor->next_url() );
		$this->assertTrue( $processor->is_data_uri(), 'is_data_uri() should return true for data URIs' );
	}

	public function test_large_data_uri_does_not_allocate_additional_memory() {
		// Save original memory limit
		$original_limit = ini_get( 'memory_limit' );

		// Set memory limit to 1GB for this test
		ini_set( 'memory_limit', '1G' );

		// Generate a 200MB data URI to test memory efficiency
		$size_mb      = 200;
		$size_bytes   = $size_mb * 1024 * 1024;
		$data_payload = str_repeat( 'A', $size_bytes );
		$data_uri     = 'data:image/png;base64,' . $data_payload;
		$css_value    = 'background: url("' . $data_uri . '")';

		// Get memory before parsing
		$memory_before = memory_get_usage( true );

		// Parse the CSS
		$processor = new CSSURLProcessor( $css_value );
		$this->assertTrue( $processor->next_url(), 'Failed to find URL in CSS' );

		// Get memory after parsing
		$memory_after = memory_get_usage( true );

		// Calculate memory increase
		$memory_increase = $memory_after - $memory_before;

		// The parser should not duplicate the 200MB data. We measure memory_get_usage(true)
		// which tracks actual allocated memory from the OS. Some overhead is expected due to
		// internal data structures, but it should be much less than duplicating the full data.
		// Allow up to 10MB overhead for parser state and temporary allocations.
		$max_allowed_increase = 10 * 1024 * 1024;  // 10MB overhead

		$this->assertLessThan(
			$max_allowed_increase,
			$memory_increase,
			sprintf(
				'Memory increased by %.2f MB during parsing. This suggests the data may be duplicated. Expected less than %.2f MB increase.',
				$memory_increase / 1024 / 1024,
				$max_allowed_increase / 1024 / 1024
			)
		);

		// Also verify that is_data_uri() works correctly
		$this->assertTrue( $processor->is_data_uri(), 'is_data_uri() should return true for large data URI' );

		// Verify we can get the raw URL (even though it's large)
		$retrieved_url = $processor->get_raw_url();
		$this->assertEquals( $data_uri, $retrieved_url, 'Retrieved data URI does not match original' );

		// Clean up large variables to free memory
		unset( $data_payload, $data_uri, $css_value, $processor, $retrieved_url );
		gc_collect_cycles();

		// Restore original memory limit (if possible)
		// Note: We can't restore if current usage exceeds the original limit
		@ini_set( 'memory_limit', $original_limit );
	}

}
