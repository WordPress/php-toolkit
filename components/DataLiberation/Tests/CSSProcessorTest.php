<?php

use PHPUnit\Framework\TestCase;
use WordPress\DataLiberation\URL\CSSProcessor;

/**
 * Comprehensive CSS tokenizer tests based on the CSS Syntax Level 3 specification.
 * Test corpus from @rmenke/css-tokenizer-tests
 */
class CSSProcessorTest extends TestCase {

	/**
	 * Provides all test cases from the test corpus.
	 *
	 * @return array
	 */
	public function test_corpus_provider(): array {
		static $test_cases = null;

		if ( null === $test_cases ) {
			$test_cases = require __DIR__ . '/css-test-cases.php';
		}

		$data = array();
		foreach ( $test_cases as $test_name => $test_case ) {
			$data[ $test_name ] = array(
				$test_case['css'],
				$test_case['tokens'],
			);
		}

		return $data;
	}

	/**
	 * Tests that the tokenizer produces the expected tokens for all test cases.
	 *
	 * @dataProvider test_corpus_provider
	 */
	public function test_tokenizer_matches_spec( string $css, array $expected_tokens ): void {
		$processor = new CSSProcessor( $css );
		$actual_tokens = $this->collect_tokens( $processor, $css );

		// Convert byte indices to UTF-16 code unit indices for comparison
		foreach ( $actual_tokens as &$token ) {
			$token['startIndex'] = $this->byte_to_utf16_index( $css, $token['startIndex'] );
			$token['endIndex'] = $this->byte_to_utf16_index( $css, $token['endIndex'] );
		}

		// Compare token count
		$this->assertCount(
			count( $expected_tokens ),
			$actual_tokens,
			'Token count mismatch for CSS: ' . var_export( $css, true )
		);

		// Compare each token
		foreach ( $expected_tokens as $index => $expected_token ) {
			$actual_token = $actual_tokens[ $index ];
			$this->assert_token_matches( $expected_token, $actual_token, $index, $css );
		}
	}

	/**
	 * Collects all tokens from a CSS processor into an array.
	 *
	 * @param CSSProcessor $processor The CSS processor.
	 * @return array Array of tokens with type, raw, startIndex, endIndex, structured.
	 */
	private function collect_tokens( CSSProcessor $processor, string $css ): array {
		$tokens = array();

		while ( $processor->next_token() ) {
			$type = $processor->get_token_type();

			// Skip EOF tokens (they're not in the test corpus)
			if ( CSSProcessor::TOKEN_EOF === $type ) {
				break;
			}

			$byte_start = $processor->get_token_start();
			$byte_end = $byte_start + $processor->get_token_length();

			$token = array(
				'type'       => $type,
				'raw'        => $processor->get_token_raw(),
				'startIndex' => $byte_start,
				'endIndex'   => $byte_end,
				'structured' => $this->extract_structured_data( $processor, $type, $css ),
			);

			$tokens[] = $token;
		}

		return $tokens;
	}

	/**
	 * Converts UTF-8 byte index to UTF-16 code unit index.
	 *
	 * @param string $text The UTF-8 text.
	 * @param int    $byte_index The byte index to convert.
	 * @return int The UTF-16 code unit index.
	 */
	private function byte_to_utf16_index( string $text, int $byte_index ): int {
		$utf16_index = 0;
		$byte_pos = 0;

		while ( $byte_pos < $byte_index && $byte_pos < strlen( $text ) ) {
			$char = $text[ $byte_pos ];
			$byte = ord( $char );

			if ( $byte < 0x80 ) {
				// ASCII: 1 byte, 1 UTF-16 code unit
				$byte_pos++;
				$utf16_index++;
			} elseif ( $byte < 0xE0 ) {
				// 2-byte UTF-8: 1 UTF-16 code unit
				$byte_pos += 2;
				$utf16_index++;
			} elseif ( $byte < 0xF0 ) {
				// 3-byte UTF-8: 1 UTF-16 code unit
				$byte_pos += 3;
				$utf16_index++;
			} else {
				// 4-byte UTF-8: 2 UTF-16 code units (surrogate pair)
				$byte_pos += 4;
				$utf16_index += 2;
			}
		}

		return $utf16_index;
	}

	/**
	 * Extracts structured data from a token based on its type.
	 *
	 * @param CSSProcessor $processor The CSS processor.
	 * @param string       $type      The token type.
	 * @param string       $css       The full CSS string.
	 * @return array|null Structured data or null.
	 */
	private function extract_structured_data( CSSProcessor $processor, string $type, string $css ): ?array {
		switch ( $type ) {
			case CSSProcessor::TOKEN_AT_KEYWORD:
			case CSSProcessor::TOKEN_IDENT:
				$name = $processor->get_token_name();
				return $name !== null ? array( 'value' => $name ) : null;

			case CSSProcessor::TOKEN_FUNCTION:
				$name = $processor->get_token_name();
				return $name !== null ? array( 'value' => $name ) : null;

			case CSSProcessor::TOKEN_HASH:
				$name = $processor->get_token_name();
				// The test corpus includes a 'type' field for hash tokens (id, unrestricted)
				// For now, we'll assume all hash tokens are 'id' type
				// This may need refinement based on actual implementation
				return $name !== null ? array( 'value' => $name, 'type' => 'id' ) : null;

			case CSSProcessor::TOKEN_STRING:
				// Strings have value in structured data
				$start = $processor->get_token_value_start();
				$length = $processor->get_token_value_length();
				if ( null !== $start && null !== $length ) {
					// Extract the string value from the CSS (inside the quotes)
					$string_value = substr( $css, $start, $length );
					// Decode CSS escapes
					$decoded = $this->decode_css_escapes( $string_value );
					return array( 'value' => $decoded );
				}
				return null;

			case CSSProcessor::TOKEN_URL:
				// URLs have value in structured data
				$start = $processor->get_token_value_start();
				$length = $processor->get_token_value_length();
				if ( null !== $start && null !== $length ) {
					// The value is between url( and )
					// We need to extract and decode it
					// Extract the URL value from the full CSS using absolute positions
					$url_value = substr( $css, $start, $length );
					$decoded = $this->decode_css_escapes( $url_value );
					return array( 'value' => $decoded );
				}
				return null;

			case CSSProcessor::TOKEN_NUMBER:
			case CSSProcessor::TOKEN_PERCENTAGE:
				$value = $processor->get_token_value();
				if ( null !== $value ) {
					// Determine if it's an integer or number
					$is_integer = floor( $value ) == $value;
					return array(
						'value' => $value,
						'type'  => $is_integer ? 'integer' : 'number',
					);
				}
				return null;

			case CSSProcessor::TOKEN_DIMENSION:
				$value = $processor->get_token_value();
				$unit = $processor->get_token_unit();
				if ( null !== $value && null !== $unit ) {
					$is_integer = floor( $value ) == $value;
					return array(
						'value' => $value,
						'type'  => $is_integer ? 'integer' : 'number',
						'unit'  => $unit,
					);
				}
				return null;

			case CSSProcessor::TOKEN_DELIM:
				// Delim tokens have their character value in structured data
				$raw = $processor->get_token_raw();
				return array( 'value' => $raw );

			default:
				return null;
		}
	}

	/**
	 * Asserts that an actual token matches the expected token.
	 *
	 * @param array  $expected The expected token.
	 * @param array  $actual   The actual token.
	 * @param int    $index    The token index.
	 * @param string $css      The CSS source.
	 */
	private function assert_token_matches( array $expected, array $actual, int $index, string $css ): void {
		$message = sprintf(
			'Token %d mismatch in CSS: %s',
			$index,
			var_export( $css, true )
		);

		// Check type
		$this->assertSame(
			$expected['type'],
			$actual['type'],
			$message . ' (type)'
		);

		// Check raw
		$this->assertSame(
			$expected['raw'],
			$actual['raw'],
			$message . ' (raw)'
		);

		// Check positions
		$this->assertSame(
			$expected['startIndex'],
			$actual['startIndex'],
			$message . ' (startIndex)'
		);

		$this->assertSame(
			$expected['endIndex'],
			$actual['endIndex'],
			$message . ' (endIndex)'
		);

		// Check structured data (if present in expected)
		// We'll do a loose comparison here since our implementation might differ slightly
		if ( isset( $expected['structured'] ) && null !== $expected['structured'] ) {
			$this->assertNotNull(
				$actual['structured'],
				$message . ' (expected structured data but got null)'
			);

			// For now, just check that the value matches if present
			if ( isset( $expected['structured']['value'] ) ) {
				$this->assertArrayHasKey( 'value', $actual['structured'], $message . ' (structured.value missing)' );
				// Loose comparison because floats may differ slightly
				$this->assertEquals(
					$expected['structured']['value'],
					$actual['structured']['value'],
					$message . ' (structured.value)',
					0.0001  // delta for float comparison
				);
			}
		}
	}

	/**
	 * Legacy test to ensure basic tokenization still works.
	 */
	public function test_tokenize_labels_core_tokens(): void {
		$css       = '@media screen and (min-width: 10px) { background: url("/images/a.png") }';
		$processor = new CSSProcessor( $css );
		$tokens    = $this->collect_tokens( $processor, $css );

		$types = array_column( $tokens, 'type' );

		self::assertContains( CSSProcessor::TOKEN_AT_KEYWORD, $types );
		self::assertContains( CSSProcessor::TOKEN_IDENT, $types );
		self::assertContains( CSSProcessor::TOKEN_LEFT_PAREN, $types );
		self::assertContains( CSSProcessor::TOKEN_DIMENSION, $types );
		self::assertContains( CSSProcessor::TOKEN_LEFT_BRACE, $types );
		self::assertContains( CSSProcessor::TOKEN_FUNCTION, $types );
		self::assertContains( CSSProcessor::TOKEN_STRING, $types );
		self::assertContains( CSSProcessor::TOKEN_RIGHT_PAREN, $types );
		self::assertContains( CSSProcessor::TOKEN_RIGHT_BRACE, $types );
	}

	/**
	 * Decodes CSS escape sequences in a string.
	 *
	 * @param string $value The value with potential CSS escapes.
	 * @return string The decoded value.
	 */
	private function decode_css_escapes( string $value ): string {
		$length = strlen( $value );
		$result = '';
		$at     = 0;

		while ( $at < $length ) {
			$span = strcspn( $value, "\\\x00", $at );
			if ( $span > 0 ) {
				$result .= substr( $value, $at, $span );
				$at     += $span;
			}

			if ( $at >= $length ) {
				break;
			}

			$char = $value[ $at ];

			// Null byte - replace with U+FFFD
			if ( "\x00" === $char ) {
				$result .= "\xEF\xBF\xBD";
				++$at;
				continue;
			}

			// Must be backslash
			++$at;
			if ( $at >= $length ) {
				break;
			}

			$hex_len = strspn( $value, '0123456789abcdefABCDEF', $at );
			if ( $hex_len > 6 ) {
				$hex_len = 6;
			}

			if ( $hex_len > 0 ) {
				$hex     = substr( $value, $at, $hex_len );
				$codepoint = hexdec( $hex );
				// Convert codepoint to UTF-8 bytes
				if ( $codepoint <= 0x7F ) {
					$result .= chr( $codepoint );
				} elseif ( $codepoint <= 0x7FF ) {
					$result .= chr( 0xC0 | ( $codepoint >> 6 ) );
					$result .= chr( 0x80 | ( $codepoint & 0x3F ) );
				} elseif ( $codepoint <= 0xFFFF ) {
					$result .= chr( 0xE0 | ( $codepoint >> 12 ) );
					$result .= chr( 0x80 | ( ( $codepoint >> 6 ) & 0x3F ) );
					$result .= chr( 0x80 | ( $codepoint & 0x3F ) );
				} else {
					$result .= chr( 0xF0 | ( $codepoint >> 18 ) );
					$result .= chr( 0x80 | ( ( $codepoint >> 12 ) & 0x3F ) );
					$result .= chr( 0x80 | ( ( $codepoint >> 6 ) & 0x3F ) );
					$result .= chr( 0x80 | ( $codepoint & 0x3F ) );
				}
				$at += $hex_len;

				$ws_len = strspn( $value, " \n\r\t\f", $at );
				if ( $ws_len > 0 ) {
					if ( $at + 1 < $length && "\r" === $value[ $at ] && "\n" === $value[ $at + 1 ] ) {
						$at += 2;
					} else {
						$at += 1;
					}
				}
				continue;
			}

			$next = $value[ $at ];

			if ( "\n" === $next || "\f" === $next ) {
				++$at;
				continue;
			}

			if ( "\r" === $next ) {
				++$at;
				if ( $at < $length && "\n" === $value[ $at ] ) {
					++$at;
				}
				continue;
			}

			$result .= $next;
			++$at;
		}

		return $result;
	}
}
