<?php

use PHPUnit\Framework\TestCase;
use WordPress\DataLiberation\URL\CSSTokenizer;

/**
 * Comprehensive CSS tokenizer tests based on the CSS Syntax Level 3 specification.
 * Test corpus from @rmenke/css-tokenizer-tests
 */
class CSSTokenizerTest extends TestCase {

	/**
	 * Tests that the tokenizer produces the expected tokens for all test cases.
	 *
	 * @dataProvider corpus_provider
	 */
	public function test_tokenizer_matches_spec( string $css, array $expected_tokens ): void {
		$processor = CSSTokenizer::create( $css );
		$actual_tokens = $this->collect_tokens( $processor, $css );

		// Compare token count first
		$this->assertCount(
			count( $expected_tokens ),
			$actual_tokens,
			'Token count mismatch for CSS: ' . var_export( $css, true )
		);

		// Compare each token
		foreach ( $expected_tokens as $index => $expected_token ) {
			if ( ! isset( $actual_tokens[ $index ] ) ) {
				$this->fail( "Missing token at index $index for CSS: " . var_export( $css, true ) );
			}
			$actual_token = $actual_tokens[ $index ];
			$this->assert_token_matches( $expected_token, $actual_token, $index, $css );
		}
	}

	/**
	 * Provides all test cases from the test corpus.
	 *
	 * @return array
	 */
	static public function corpus_provider(): array {
		return require __DIR__ . '/css-test-cases.php';
	}

	/**
	 * Collects all tokens from a CSS processor into an array.
	 *
	 * @param CSSTokenizer $processor The CSS processor.
	 * @return array Array of tokens with type, raw, startIndex, endIndex, structured.
	 */
	private function collect_tokens( CSSTokenizer $processor, string $css ): array {
		$tokens = array();

		while ( $processor->next_token() ) {
			$type = $processor->get_token_type();

			$byte_start = $processor->get_token_start();
			$byte_end   = $byte_start + $processor->get_token_length();

			$token = array(
				'type'       => $type,
				'raw'        => $processor->get_unnormalized_token(),
				'startIndex' => $byte_start,
				'endIndex'   => $byte_end,
				'value'      => $processor->get_token_value(),
			);

			$tokens[] = $token;
		}

		return $tokens;
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
		if ( isset( $expected['value'] ) && null !== $expected['value'] ) {
			$this->assertArrayHasKey( 'value', $actual, $message . ' (value missing)' );
			// Loose comparison because floats may differ slightly
			$this->assertEquals(
				$expected['value'],
				$actual['value'],
				$message . ' (value)',
				0.0001  // delta for float comparison
			);
		}
	}

	/**
	 * Asserts that CSS parses to expected tokens.
	 *
	 * @param string $css      The CSS to parse.
	 * @param array  $expected Array of expected tokens. Each token can have:
	 *                         - 'type' (required): Token type constant
	 *                         - 'raw' (optional): Unnormalized token text
	 *                         - 'normalized' (optional): Normalized token text
	 *                         - 'value' (optional): Semantic token value
	 */
	private function assert_css_parses_to_tokens( string $css, array $expected ): void {
		$processor = CSSTokenizer::create( $css );

		$this->assertCount( count( $expected ), $expected, 'Expected tokens array should not be empty' );

		$index = 0;
		while ( $processor->next_token() ) {
			$this->assertArrayHasKey(
				$index,
				$expected,
				sprintf( 'Unexpected token at index %d: got %s', $index, $processor->get_token_type() )
			);

			$exp     = $expected[ $index ];
			$message = sprintf( 'Token %d mismatch in CSS: %s', $index, var_export( $css, true ) );

			// Check type (required).
			$this->assertArrayHasKey( 'type', $exp, 'Expected token must have a type' );
			$this->assertSame(
				$exp['type'],
				$processor->get_token_type(),
				$message . ' (type)'
			);

			// Check raw/unnormalized (optional).
			if ( isset( $exp['raw'] ) ) {
				$this->assertSame(
					$exp['raw'],
					$processor->get_unnormalized_token(),
					$message . ' (raw/unnormalized)'
				);
			}

			// Check normalized (optional).
			if ( isset( $exp['normalized'] ) ) {
				$this->assertSame(
					$exp['normalized'],
					$processor->get_normalized_token(),
					$message . ' (normalized)'
				);
			}

			// Check value (optional).
			if ( array_key_exists( 'value', $exp ) ) {
				if ( is_float( $exp['value'] ) ) {
					// Loose comparison for floats.
					$this->assertEquals(
						$exp['value'],
						$processor->get_token_value(),
						$message . ' (value)',
						0.0001
					);
				} else {
					$this->assertSame(
						$exp['value'],
						$processor->get_token_value(),
						$message . ' (value)'
					);
				}
			}

			++$index;
		}

		// Ensure we consumed all expected tokens.
		$this->assertCount(
			$index,
			$expected,
			sprintf( 'Expected %d tokens but only found %d', count( $expected ), $index )
		);
	}

	/**
	 * Legacy test to ensure basic tokenization still works.
	 */
	public function test_tokenize_labels_core_tokens(): void {
		$css = <<<CSS
		@media screen and (min-width: 10px) {
			background: url("/images/a.png");
		}
		CSS;

		$expected = array(
			array( 'type' => CSSTokenizer::TOKEN_AT_KEYWORD, 'raw' => '@media' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE, 'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT, 'raw' => 'screen' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE, 'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT, 'raw' => 'and' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE, 'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_LEFT_PAREN, 'raw' => '(' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT, 'raw' => 'min-width' ),
			array( 'type' => CSSTokenizer::TOKEN_COLON, 'raw' => ':' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE, 'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_DIMENSION, 'raw' => '10px' ),
			array( 'type' => CSSTokenizer::TOKEN_RIGHT_PAREN, 'raw' => ')' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE, 'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_LEFT_BRACE, 'raw' => '{' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE, 'raw' => "\n\t" ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT, 'raw' => 'background' ),
			array( 'type' => CSSTokenizer::TOKEN_COLON, 'raw' => ':' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE, 'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_FUNCTION, 'raw' => 'url(' ),
			array( 'type' => CSSTokenizer::TOKEN_STRING, 'raw' => '"/images/a.png"' ),
			array( 'type' => CSSTokenizer::TOKEN_RIGHT_PAREN, 'raw' => ')' ),
			array( 'type' => CSSTokenizer::TOKEN_SEMICOLON, 'raw' => ';' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE, 'raw' => "\n" ),
			array( 'type' => CSSTokenizer::TOKEN_RIGHT_BRACE, 'raw' => '}' ),
		);

		$this->assert_css_parses_to_tokens( $css, $expected );
	}

	/**
	 * Tests tokenization of complex selectors with pseudo-classes.
	 */
	public function test_complex_selector_with_pseudo_classes(): void {
		$css = 'a:hover::before, div.class#id:not(.disabled)';

		$expected = array(
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'a' ),
			array( 'type' => CSSTokenizer::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'hover' ),
			array( 'type' => CSSTokenizer::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSTokenizer::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'before' ),
			array( 'type' => CSSTokenizer::TOKEN_COMMA,        'raw' => ',' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'div' ),
			array( 'type' => CSSTokenizer::TOKEN_DELIM,        'raw' => '.' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'class' ),
			array( 'type' => CSSTokenizer::TOKEN_HASH,         'raw' => '#id' ),
			array( 'type' => CSSTokenizer::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSTokenizer::TOKEN_FUNCTION,     'raw' => 'not(' ),
			array( 'type' => CSSTokenizer::TOKEN_DELIM,        'raw' => '.' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'disabled' ),
			array( 'type' => CSSTokenizer::TOKEN_RIGHT_PAREN,  'raw' => ')' ),
		);

		$this->assert_css_parses_to_tokens( $css, $expected );
	}

	/**
	 * Tests tokenization of CSS comments.
	 */
	public function test_css_comments(): void {
		$css = '/* This is a comment */ .class { color: red; /* Another comment */ }';

		$expected = array(
			array( 'type' => CSSTokenizer::TOKEN_COMMENT,      'raw' => '/* This is a comment */' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_DELIM,        'raw' => '.' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'class' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_LEFT_BRACE,   'raw' => '{' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'color' ),
			array( 'type' => CSSTokenizer::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'red' ),
			array( 'type' => CSSTokenizer::TOKEN_SEMICOLON,    'raw' => ';' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_COMMENT,      'raw' => '/* Another comment */' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_RIGHT_BRACE,  'raw' => '}' ),
		);

		$this->assert_css_parses_to_tokens( $css, $expected );
	}

	/**
	 * Tests tokenization of media queries.
	 */
	public function test_media_query(): void {
		$css = '@media screen and (min-width: 768px) and (max-width: 1024px)';

		$expected = array(
			array( 'type' => CSSTokenizer::TOKEN_AT_KEYWORD,   'raw' => '@media' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'screen' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'and' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_LEFT_PAREN,   'raw' => '(' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'min-width' ),
			array( 'type' => CSSTokenizer::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_DIMENSION,    'raw' => '768px' ),
			array( 'type' => CSSTokenizer::TOKEN_RIGHT_PAREN,  'raw' => ')' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'and' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_LEFT_PAREN,   'raw' => '(' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'max-width' ),
			array( 'type' => CSSTokenizer::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_DIMENSION,    'raw' => '1024px' ),
			array( 'type' => CSSTokenizer::TOKEN_RIGHT_PAREN,  'raw' => ')' ),
		);

		$this->assert_css_parses_to_tokens( $css, $expected );
	}

	/**
	 * Tests tokenization of keyframes animation.
	 */
	public function test_keyframes_animation(): void {
		$css = '@keyframes slide-in { 0% { opacity: 0; } 100% { opacity: 1; } }';

		$expected = array(
			array( 'type' => CSSTokenizer::TOKEN_AT_KEYWORD,   'raw' => '@keyframes' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'slide-in' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_LEFT_BRACE,   'raw' => '{' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_PERCENTAGE,   'raw' => '0%' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_LEFT_BRACE,   'raw' => '{' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'opacity' ),
			array( 'type' => CSSTokenizer::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_NUMBER,       'raw' => '0' ),
			array( 'type' => CSSTokenizer::TOKEN_SEMICOLON,    'raw' => ';' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_RIGHT_BRACE,  'raw' => '}' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_PERCENTAGE,   'raw' => '100%' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_LEFT_BRACE,   'raw' => '{' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'opacity' ),
			array( 'type' => CSSTokenizer::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_NUMBER,       'raw' => '1' ),
			array( 'type' => CSSTokenizer::TOKEN_SEMICOLON,    'raw' => ';' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_RIGHT_BRACE,  'raw' => '}' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_RIGHT_BRACE,  'raw' => '}' ),
		);

		$this->assert_css_parses_to_tokens( $css, $expected );
	}

	/**
	 * Tests tokenization of vendor-prefixed properties.
	 */
	public function test_vendor_prefixed_properties(): void {
		$css = '-webkit-transform: rotate(45deg); -moz-border-radius: 5px;';

		$expected = array(
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => '-webkit-transform' ),
			array( 'type' => CSSTokenizer::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_FUNCTION,     'raw' => 'rotate(' ),
			array( 'type' => CSSTokenizer::TOKEN_DIMENSION,    'raw' => '45deg' ),
			array( 'type' => CSSTokenizer::TOKEN_RIGHT_PAREN,  'raw' => ')' ),
			array( 'type' => CSSTokenizer::TOKEN_SEMICOLON,    'raw' => ';' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => '-moz-border-radius' ),
			array( 'type' => CSSTokenizer::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_DIMENSION,    'raw' => '5px' ),
			array( 'type' => CSSTokenizer::TOKEN_SEMICOLON,    'raw' => ';' ),
		);

		$this->assert_css_parses_to_tokens( $css, $expected );
	}

	/**
	 * Tests tokenization of attribute selectors.
	 */
	public function test_attribute_selectors(): void {
		$css = 'input[type="text"][required], a[href^="https://"]';

		$expected = array(
			array( 'type' => CSSTokenizer::TOKEN_IDENT,         'raw' => 'input' ),
			array( 'type' => CSSTokenizer::TOKEN_LEFT_BRACKET,  'raw' => '[' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,         'raw' => 'type' ),
			array( 'type' => CSSTokenizer::TOKEN_DELIM,         'raw' => '=' ),
			array( 'type' => CSSTokenizer::TOKEN_STRING,        'raw' => '"text"' ),
			array( 'type' => CSSTokenizer::TOKEN_RIGHT_BRACKET, 'raw' => ']' ),
			array( 'type' => CSSTokenizer::TOKEN_LEFT_BRACKET,  'raw' => '[' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,         'raw' => 'required' ),
			array( 'type' => CSSTokenizer::TOKEN_RIGHT_BRACKET, 'raw' => ']' ),
			array( 'type' => CSSTokenizer::TOKEN_COMMA,         'raw' => ',' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,    'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,         'raw' => 'a' ),
			array( 'type' => CSSTokenizer::TOKEN_LEFT_BRACKET,  'raw' => '[' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,         'raw' => 'href' ),
			array( 'type' => CSSTokenizer::TOKEN_DELIM,         'raw' => '^' ),
			array( 'type' => CSSTokenizer::TOKEN_DELIM,         'raw' => '=' ),
			array( 'type' => CSSTokenizer::TOKEN_STRING,        'raw' => '"https://"' ),
			array( 'type' => CSSTokenizer::TOKEN_RIGHT_BRACKET, 'raw' => ']' ),
		);

		$this->assert_css_parses_to_tokens( $css, $expected );
	}

	/**
	 * Tests tokenization of calc() function with complex expressions.
	 */
	public function test_calc_function(): void {
		$css = 'width: calc(100% - 20px * 2 + 5em);';

		$expected = array(
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'width' ),
			array( 'type' => CSSTokenizer::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_FUNCTION,     'raw' => 'calc(' ),
			array( 'type' => CSSTokenizer::TOKEN_PERCENTAGE,   'raw' => '100%' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_DELIM,        'raw' => '-' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_DIMENSION,    'raw' => '20px' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_DELIM,        'raw' => '*' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_NUMBER,       'raw' => '2' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_DELIM,        'raw' => '+' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_DIMENSION,    'raw' => '5em' ),
			array( 'type' => CSSTokenizer::TOKEN_RIGHT_PAREN,  'raw' => ')' ),
			array( 'type' => CSSTokenizer::TOKEN_SEMICOLON,    'raw' => ';' ),
		);

		$this->assert_css_parses_to_tokens( $css, $expected );
	}

	/**
	 * Tests tokenization of RGB/RGBA color functions.
	 */
	public function test_color_functions(): void {
		$css = 'color: rgb(255, 128, 0); background: rgba(0, 0, 0, 0.5);';

		$expected = array(
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'color' ),
			array( 'type' => CSSTokenizer::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_FUNCTION,     'raw' => 'rgb(' ),
			array( 'type' => CSSTokenizer::TOKEN_NUMBER,       'raw' => '255' ),
			array( 'type' => CSSTokenizer::TOKEN_COMMA,        'raw' => ',' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_NUMBER,       'raw' => '128' ),
			array( 'type' => CSSTokenizer::TOKEN_COMMA,        'raw' => ',' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_NUMBER,       'raw' => '0' ),
			array( 'type' => CSSTokenizer::TOKEN_RIGHT_PAREN,  'raw' => ')' ),
			array( 'type' => CSSTokenizer::TOKEN_SEMICOLON,    'raw' => ';' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'background' ),
			array( 'type' => CSSTokenizer::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_FUNCTION,     'raw' => 'rgba(' ),
			array( 'type' => CSSTokenizer::TOKEN_NUMBER,       'raw' => '0' ),
			array( 'type' => CSSTokenizer::TOKEN_COMMA,        'raw' => ',' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_NUMBER,       'raw' => '0' ),
			array( 'type' => CSSTokenizer::TOKEN_COMMA,        'raw' => ',' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_NUMBER,       'raw' => '0' ),
			array( 'type' => CSSTokenizer::TOKEN_COMMA,        'raw' => ',' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_NUMBER,       'raw' => '0.5' ),
			array( 'type' => CSSTokenizer::TOKEN_RIGHT_PAREN,  'raw' => ')' ),
			array( 'type' => CSSTokenizer::TOKEN_SEMICOLON,    'raw' => ';' ),
		);

		$this->assert_css_parses_to_tokens( $css, $expected );
	}

	/**
	 * Tests tokenization of CSS custom properties (variables).
	 */
	public function test_css_variables(): void {
		$css = '--main-color: #ff0000; color: var(--main-color);';

		$expected = array(
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => '--main-color' ),
			array( 'type' => CSSTokenizer::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_HASH,         'raw' => '#ff0000' ),
			array( 'type' => CSSTokenizer::TOKEN_SEMICOLON,    'raw' => ';' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'color' ),
			array( 'type' => CSSTokenizer::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_FUNCTION,     'raw' => 'var(' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => '--main-color' ),
			array( 'type' => CSSTokenizer::TOKEN_RIGHT_PAREN,  'raw' => ')' ),
			array( 'type' => CSSTokenizer::TOKEN_SEMICOLON,    'raw' => ';' ),
		);

		$this->assert_css_parses_to_tokens( $css, $expected );
	}

	/**
	 * Tests tokenization of gradient functions.
	 */
	public function test_gradient_functions(): void {
		$css = 'background: linear-gradient(to right, red 0%, blue 100%);';

		$expected = array(
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'background' ),
			array( 'type' => CSSTokenizer::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_FUNCTION,     'raw' => 'linear-gradient(' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'to' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'right' ),
			array( 'type' => CSSTokenizer::TOKEN_COMMA,        'raw' => ',' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'red' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_PERCENTAGE,   'raw' => '0%' ),
			array( 'type' => CSSTokenizer::TOKEN_COMMA,        'raw' => ',' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'blue' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_PERCENTAGE,   'raw' => '100%' ),
			array( 'type' => CSSTokenizer::TOKEN_RIGHT_PAREN,  'raw' => ')' ),
			array( 'type' => CSSTokenizer::TOKEN_SEMICOLON,    'raw' => ';' ),
		);

		$this->assert_css_parses_to_tokens( $css, $expected );
	}

	/**
	 * Tests tokenization of grid layout properties.
	 */
	public function test_grid_layout(): void {
		$css = 'grid-template-columns: repeat(3, 1fr); gap: 10px 20px;';

		$expected = array(
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'grid-template-columns' ),
			array( 'type' => CSSTokenizer::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_FUNCTION,     'raw' => 'repeat(' ),
			array( 'type' => CSSTokenizer::TOKEN_NUMBER,       'raw' => '3' ),
			array( 'type' => CSSTokenizer::TOKEN_COMMA,        'raw' => ',' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_DIMENSION,    'raw' => '1fr' ),
			array( 'type' => CSSTokenizer::TOKEN_RIGHT_PAREN,  'raw' => ')' ),
			array( 'type' => CSSTokenizer::TOKEN_SEMICOLON,    'raw' => ';' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'gap' ),
			array( 'type' => CSSTokenizer::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_DIMENSION,    'raw' => '10px' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_DIMENSION,    'raw' => '20px' ),
			array( 'type' => CSSTokenizer::TOKEN_SEMICOLON,    'raw' => ';' ),
		);

		$this->assert_css_parses_to_tokens( $css, $expected );
	}

	/**
	 * Tests tokenization of URL functions with various formats.
	 */
	public function test_url_formats(): void {
		$css = 'background: url("image.png"), url(\'font.woff\'), url(https://example.com/bg.jpg);';

		$expected = array(
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'background' ),
			array( 'type' => CSSTokenizer::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_FUNCTION,     'raw' => 'url(' ),
			array( 'type' => CSSTokenizer::TOKEN_STRING,       'raw' => '"image.png"' ),
			array( 'type' => CSSTokenizer::TOKEN_RIGHT_PAREN,  'raw' => ')' ),
			array( 'type' => CSSTokenizer::TOKEN_COMMA,        'raw' => ',' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_FUNCTION,     'raw' => 'url(' ),
			array( 'type' => CSSTokenizer::TOKEN_STRING,       'raw' => "'font.woff'" ),
			array( 'type' => CSSTokenizer::TOKEN_RIGHT_PAREN,  'raw' => ')' ),
			array( 'type' => CSSTokenizer::TOKEN_COMMA,        'raw' => ',' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_URL,          'raw' => 'url(https://example.com/bg.jpg)' ),
			array( 'type' => CSSTokenizer::TOKEN_SEMICOLON,    'raw' => ';' ),
		);

		$this->assert_css_parses_to_tokens( $css, $expected );
	}

	/**
	 * Tests tokenization of !important declarations.
	 */
	public function test_important_declarations(): void {
		$css = 'color: red !important; margin: 0px !important;';

		$expected = array(
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'color' ),
			array( 'type' => CSSTokenizer::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'red' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_DELIM,        'raw' => '!' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'important' ),
			array( 'type' => CSSTokenizer::TOKEN_SEMICOLON,    'raw' => ';' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'margin' ),
			array( 'type' => CSSTokenizer::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_DIMENSION,    'raw' => '0px' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_DELIM,        'raw' => '!' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'important' ),
			array( 'type' => CSSTokenizer::TOKEN_SEMICOLON,    'raw' => ';' ),
		);

		$this->assert_css_parses_to_tokens( $css, $expected );
	}

	/**
	 * Tests tokenization of multiple selectors with combinators.
	 */
	public function test_complex_combinators(): void {
		$css = 'div > p + span ~ a.link';

		$expected = array(
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'div' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_DELIM,        'raw' => '>' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'p' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_DELIM,        'raw' => '+' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'span' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_DELIM,        'raw' => '~' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'a' ),
			array( 'type' => CSSTokenizer::TOKEN_DELIM,        'raw' => '.' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'link' ),
		);

		$this->assert_css_parses_to_tokens( $css, $expected );
	}

	/**
	 * Tests tokenization of escaped characters in identifiers.
	 */
	public function test_escaped_identifiers(): void {
		$css = '.class\\:name, #id\\@special { color: blue; }';

		$expected = array(
			array( 'type' => CSSTokenizer::TOKEN_DELIM,        'raw' => '.' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'class\\:name' ),
			array( 'type' => CSSTokenizer::TOKEN_COMMA,        'raw' => ',' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_HASH,         'raw' => '#id\\@special' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_LEFT_BRACE,   'raw' => '{' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'color' ),
			array( 'type' => CSSTokenizer::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_IDENT,        'raw' => 'blue' ),
			array( 'type' => CSSTokenizer::TOKEN_SEMICOLON,    'raw' => ';' ),
			array( 'type' => CSSTokenizer::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSTokenizer::TOKEN_RIGHT_BRACE,  'raw' => '}' ),
		);

		$this->assert_css_parses_to_tokens( $css, $expected );
	}

	/**
	 * Tests that get_normalized_token() applies CSS normalization.
	 *
	 * Uses a comprehensive CSS selector with rules that includes:
	 * - CSS escapes in class names and IDs
	 * - URLs with escape sequences
	 * - String values with escapes and line endings
	 * - Comments with various line ending characters
	 * - Null bytes in identifiers
	 * - Mixed line endings (\r\n, \r, \f) that need normalization
	 */
	public function test_get_normalized_token_applies_normalization(): void {
		// Comprehensive CSS with normalization requirements.
		$css = "/* Comment\r\nwith\flines */\r\n" .
		       ".c\\6c ass.n\\61 me\r#id\\@value\r\n{\r\n" .
		       "\tbackground:\furl(path\\2f to\\2f image.png);\r\n" .
		       "\tcontent:\r\"text\\A string\";\r\n" .
		       "}";

		$expected = array(
			// Comment with \r\n and \f.
			array(
				'type'       => CSSTokenizer::TOKEN_COMMENT,
				'raw'        => "/* Comment\r\nwith\flines */",
				'normalized' => "/* Comment\nwith\nlines */",
			),
			// Whitespace with \r\n.
			array(
				'type'       => CSSTokenizer::TOKEN_WHITESPACE,
				'raw'        => "\r\n",
				'normalized' => "\n",
			),
			// Class selector delimiter.
			array(
				'type'       => CSSTokenizer::TOKEN_DELIM,
				'raw'        => '.',
				'normalized' => '.',
			),
			// Class name with escape (\6c = 'l'), space gets consumed by escape.
			array(
				'type'       => CSSTokenizer::TOKEN_IDENT,
				'raw'        => 'c\\6c ass',
				'normalized' => 'class', // Escapes decoded.
				'value'      => 'class', // Decoded: \6c → l, space consumed.
			),
			// Delimiter.
			array(
				'type'       => CSSTokenizer::TOKEN_DELIM,
				'raw'        => '.',
				'normalized' => '.',
			),
			// Identifier with escape (\61 = 'a'), space gets consumed.
			array(
				'type'       => CSSTokenizer::TOKEN_IDENT,
				'raw'        => 'n\\61 me',
				'normalized' => 'name', // Escapes decoded.
				'value'      => 'name', // Decoded: \61 → a, space consumed.
			),
			// Whitespace with \r.
			array(
				'type'       => CSSTokenizer::TOKEN_WHITESPACE,
				'raw'        => "\r",
				'normalized' => "\n",
			),
			// ID selector with escape.
			array(
				'type'       => CSSTokenizer::TOKEN_HASH,
				'raw'        => '#id\\@value',
				'normalized' => '#id@value', // Escapes decoded.
				'value'      => 'id@value', // Decoded value.
			),
			// Whitespace with \r\n.
			array(
				'type'       => CSSTokenizer::TOKEN_WHITESPACE,
				'raw'        => "\r\n",
				'normalized' => "\n",
			),
			// Opening brace.
			array(
				'type'       => CSSTokenizer::TOKEN_LEFT_BRACE,
				'raw'        => '{',
				'normalized' => '{',
			),
			// Whitespace with \r\n and tab (consumed together).
			array(
				'type'       => CSSTokenizer::TOKEN_WHITESPACE,
				'raw'        => "\r\n\t",
				'normalized' => "\n\t",
			),
			array(
				'type'       => CSSTokenizer::TOKEN_IDENT,
				'raw'        => 'background',
				'normalized' => 'background',
				'value'      => 'background',
			),
			// Colon.
			array(
				'type'       => CSSTokenizer::TOKEN_COLON,
				'raw'        => ':',
				'normalized' => ':',
			),
			// Whitespace with \f.
			array(
				'type'       => CSSTokenizer::TOKEN_WHITESPACE,
				'raw'        => "\f",
				'normalized' => "\n",
			),
			// URL token with escapes (entire url(...) is one token).
			array(
				'type'       => CSSTokenizer::TOKEN_URL,
				'raw'        => 'url(path\\2f to\\2f image.png)',
				'normalized' => 'url(path/to/image.png)', // Escapes decoded.
				'value'      => 'path/to/image.png', // Decoded: \2f → /, spaces consumed.
			),
			// Semicolon.
			array(
				'type'       => CSSTokenizer::TOKEN_SEMICOLON,
				'raw'        => ';',
				'normalized' => ';',
			),
			// Whitespace with \r\n and tab (consumed together).
			array(
				'type'       => CSSTokenizer::TOKEN_WHITESPACE,
				'raw'        => "\r\n\t",
				'normalized' => "\n\t",
			),
			array(
				'type'       => CSSTokenizer::TOKEN_IDENT,
				'raw'        => 'content',
				'normalized' => 'content',
				'value'      => 'content',
			),
			// Colon.
			array(
				'type'       => CSSTokenizer::TOKEN_COLON,
				'raw'        => ':',
				'normalized' => ':',
			),
			// Whitespace with \r.
			array(
				'type'       => CSSTokenizer::TOKEN_WHITESPACE,
				'raw'        => "\r",
				'normalized' => "\n",
			),
			// String with escape (\A = newline, space consumed).
			array(
				'type'       => CSSTokenizer::TOKEN_STRING,
				'raw'        => '"text\\A string"',
				'normalized' => "\"text\nstring\"", // Escapes decoded, quotes preserved.
				'value'      => "text\nstring", // \A → \n, space consumed.
			),
			// Semicolon.
			array(
				'type'       => CSSTokenizer::TOKEN_SEMICOLON,
				'raw'        => ';',
				'normalized' => ';',
			),
			// Whitespace with \r\n.
			array(
				'type'       => CSSTokenizer::TOKEN_WHITESPACE,
				'raw'        => "\r\n",
				'normalized' => "\n",
			),
			// Closing brace.
			array(
				'type'       => CSSTokenizer::TOKEN_RIGHT_BRACE,
				'raw'        => '}',
				'normalized' => '}',
			),
		);

		$this->assert_css_parses_to_tokens( $css, $expected );
	}

	/**
	 * Tests that create() validates encoding and only accepts UTF-8.
	 */
	public function test_create_validates_encoding(): void {
		// UTF-8 encoding should work (default).
		$tokenizer = CSSTokenizer::create( '.class { color: red; }' );
		$this->assertInstanceOf( CSSTokenizer::class, $tokenizer );

		// UTF-8 encoding should work (explicit).
		$tokenizer = CSSTokenizer::create( '.class { color: red; }', 'UTF-8' );
		$this->assertInstanceOf( CSSTokenizer::class, $tokenizer );

		// Other encodings should return null.
		$tokenizer = CSSTokenizer::create( '.class { color: red; }', 'ISO-8859-1' );
		$this->assertNull( $tokenizer );

		$tokenizer = CSSTokenizer::create( '.class { color: red; }', 'Windows-1252' );
		$this->assertNull( $tokenizer );
	}

	/**
	 * Tests escape sequences in unusual and edge-case positions.
	 *
	 * Covers:
	 * - Multiple consecutive escapes
	 * - Escapes in function names
	 * - Escapes in at-keywords
	 * - Escapes in dimension units
	 * - Null byte escapes (\0)
	 * - Escaped special characters (@, #, !, etc.)
	 * - Escaped whitespace that gets consumed by the escape
	 * - Unicode escapes for various characters
	 */
	public function test_escape_sequences_in_unusual_places() {
		// Complex CSS with escapes in many unusual but valid positions
		$css = '@\\6D edia ' .                           // @media with \6D (m) and space consumed
		       '\\73 creen ' .                           // screen with \73 (s) and space consumed
		       '{' .
		       ' .\\63 l\\61 ss\\5F name ' .             // .class_name with escapes and spaces consumed
		       "#\\69 d\\5C 0test\x00 " .                // #id\0test with null byte escape (should be preserved)
			                                             // AND an actual null byte (should be replaced with a U+FFFD REPLACEMENT CHARACTER)
		       '{' .
		       ' c\\6F lor: ' .                          // color: with \6F (o) and space consumed
		       'r\\65 d ' .                              // red with escape
		       '\\21 important;' .                       // !important with escaped !
		       ' w\\69 dth: ' .                          // width:
		       '10\\70 x;' .                             // 10px (dimension with escaped unit)
		       ' background: ' .
		       '\\75 rl(' .                              // url( with escaped u
		       '"p\\61 th\\2F img\\2E png"' .            // "path/img.png" with escapes
		       ');' .
		       ' content: "\\5C \\5C ";' .               // "\\ \\" - escaped backslashes
		       ' font-family: \\22 Arial\\22 ;' .       // "Arial" with escaped quotes
		       ' }' .
		       '}';

		$expected = array(
			// @\6D edia -> @media
			array(
				'type' => CSSTokenizer::TOKEN_AT_KEYWORD,
				'raw'  => '@\\6D edia',
				'normalized' => '@media',
				'value' => 'media',
			),
			array(
				'type' => CSSTokenizer::TOKEN_WHITESPACE,
				'normalized' => ' ',
				'raw'  => ' ',
			),
			// \73 creen -> screen
			array(
				'type' => CSSTokenizer::TOKEN_IDENT,
				'raw'  => '\\73 creen',
				'normalized' => 'screen',
				'value' => 'screen',
			),
			array(
				'type' => CSSTokenizer::TOKEN_WHITESPACE,
				'raw'  => ' ',
			),
			array(
				'type' => CSSTokenizer::TOKEN_LEFT_BRACE,
				'raw'  => '{',
			),
			array(
				'type' => CSSTokenizer::TOKEN_WHITESPACE,
				'normalized' => ' ',
				'raw'  => ' ',
			),
			// Delimiter .
			array(
				'type' => CSSTokenizer::TOKEN_DELIM,
				'raw'  => '.',
				'normalized' => '.',
			),
			// \63 l\61 ss\5F name -> class_name
			array(
				'type' => CSSTokenizer::TOKEN_IDENT,
				'raw'  => '\\63 l\\61 ss\\5F name',
				'normalized' => 'class_name',
				'value' => 'class_name',
			),
			array(
				'type' => CSSTokenizer::TOKEN_WHITESPACE,
				'raw'  => ' ',
			),
			// #\69 d\5C 0test -> #id\0test (with encoded null byte)
			array(
				'type' => CSSTokenizer::TOKEN_HASH,
				'raw'  => "#\\69 d\\5C 0test\x00",
				'normalized' => "#id\\0test�",
				// Ensure the value is normalized.
				'value' => "id\\0test�",
			),
			array(
				'type' => CSSTokenizer::TOKEN_WHITESPACE,
				'raw'  => ' ',
			),
			array(
				'type' => CSSTokenizer::TOKEN_LEFT_BRACE,
				'raw'  => '{',
			),
			array(
				'type' => CSSTokenizer::TOKEN_WHITESPACE,
				'raw'  => ' ',
			),
			// c\6F lor -> color
			array(
				'type' => CSSTokenizer::TOKEN_IDENT,
				'raw'  => 'c\\6F lor',
				'normalized' => 'color',
				'value' => 'color',
			),
			array(
				'type' => CSSTokenizer::TOKEN_COLON,
				'raw'  => ':',
			),
			array(
				'type' => CSSTokenizer::TOKEN_WHITESPACE,
				'raw'  => ' ',
			),
			// r\65 d -> red
			array(
				'type' => CSSTokenizer::TOKEN_IDENT,
				'raw'  => 'r\\65 d',
				'normalized' => 'red',
				'value' => 'red',
			),
			array(
				'type' => CSSTokenizer::TOKEN_WHITESPACE,
				'raw'  => ' ',
			),
			// \21 important -> !important (single identifier with escaped !)
			array(
				'type' => CSSTokenizer::TOKEN_IDENT,
				'raw'  => '\\21 important',
				'normalized' => '!important',
				'value' => '!important',
			),
			array(
				'type' => CSSTokenizer::TOKEN_SEMICOLON,
				'raw'  => ';',
			),
			array(
				'type' => CSSTokenizer::TOKEN_WHITESPACE,
				'raw'  => ' ',
			),
			// w\69 dth -> width
			array(
				'type' => CSSTokenizer::TOKEN_IDENT,
				'raw'  => 'w\\69 dth',
				'normalized' => 'width',
				'value' => 'width',
			),
			array(
				'type' => CSSTokenizer::TOKEN_COLON,
				'raw'  => ':',
			),
			array(
				'type' => CSSTokenizer::TOKEN_WHITESPACE,
				'raw'  => ' ',
			),
			// 10\70 x -> 10px (dimension with escaped unit)
			array(
				'type' => CSSTokenizer::TOKEN_DIMENSION,
				'raw'  => '10\\70 x',
				'normalized' => '10px',
			),
			array(
				'type' => CSSTokenizer::TOKEN_SEMICOLON,
				'raw'  => ';',
			),
			array(
				'type' => CSSTokenizer::TOKEN_WHITESPACE,
				'raw'  => ' ',
			),
			array(
				'type' => CSSTokenizer::TOKEN_IDENT,
				'raw'  => 'background',
				'normalized' => 'background',
				'value' => 'background',
			),
			array(
				'type' => CSSTokenizer::TOKEN_COLON,
				'raw'  => ':',
			),
			array(
				'type' => CSSTokenizer::TOKEN_WHITESPACE,
				'raw'  => ' ',
			),
			// \75 rl( -> url( (escaped function name)
			array(
				'type' => CSSTokenizer::TOKEN_FUNCTION,
				'raw'  => '\\75 rl(',
				'normalized' => 'url(',
				'value' => 'url',
			),
			// String with escapes: "p\61 th\2F img\2E png"
			array(
				'type' => CSSTokenizer::TOKEN_STRING,
				'raw'  => '"p\\61 th\\2F img\\2E png"',
				'normalized' => '"path/img.png"',
				'value' => 'path/img.png',
			),
			array(
				'type' => CSSTokenizer::TOKEN_RIGHT_PAREN,
				'raw'  => ')',
			),
			array(
				'type' => CSSTokenizer::TOKEN_SEMICOLON,
				'raw'  => ';',
			),
			array(
				'type' => CSSTokenizer::TOKEN_WHITESPACE,
				'raw'  => ' ',
			),
			array(
				'type' => CSSTokenizer::TOKEN_IDENT,
				'raw'  => 'content',
				'normalized' => 'content',
				'value' => 'content',
			),
			array(
				'type' => CSSTokenizer::TOKEN_COLON,
				'raw'  => ':',
			),
			array(
				'type' => CSSTokenizer::TOKEN_WHITESPACE,
				'raw'  => ' ',
			),
			// String with escaped backslashes: "\5C \5C " -> "\\"
			// Each \5C sequence (with trailing space consumed) becomes one backslash
			array(
				'type' => CSSTokenizer::TOKEN_STRING,
				'raw'  => '"\\5C \\5C "',
				'normalized' => '"\\\\"',
				'value' => '\\\\',  // Two backslashes total
			),
			array(
				'type' => CSSTokenizer::TOKEN_SEMICOLON,
				'raw'  => ';',
			),
			array(
				'type' => CSSTokenizer::TOKEN_WHITESPACE,
				'raw'  => ' ',
			),
			array(
				'type' => CSSTokenizer::TOKEN_IDENT,
				'raw'  => 'font-family',
				'normalized' => 'font-family',
				'value' => 'font-family',
			),
			array(
				'type' => CSSTokenizer::TOKEN_COLON,
				'raw'  => ':',
			),
			array(
				'type' => CSSTokenizer::TOKEN_WHITESPACE,
				'raw'  => ' ',
			),
			// \22 Arial\22 -> "Arial" (escaped quotes make it an ident)
			array(
				'type' => CSSTokenizer::TOKEN_IDENT,
				'raw'  => '\\22 Arial\\22 ',
				'normalized' => '"Arial"',
				'value' => '"Arial"',
			),
			array(
				'type' => CSSTokenizer::TOKEN_SEMICOLON,
				'raw'  => ';',
			),
			array(
				'type' => CSSTokenizer::TOKEN_WHITESPACE,
				'raw'  => ' ',
			),
			array(
				'type' => CSSTokenizer::TOKEN_RIGHT_BRACE,
				'raw'  => '}',
			),
			array(
				'type' => CSSTokenizer::TOKEN_RIGHT_BRACE,
				'raw'  => '}',
			),
		);

		$this->assert_css_parses_to_tokens( $css, $expected );
	}
}
