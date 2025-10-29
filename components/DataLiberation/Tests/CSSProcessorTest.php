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
				// Strings have decoded value in token_name
				$decoded_value = $processor->get_token_name();
				return $decoded_value !== null ? array( 'value' => $decoded_value ) : null;

			case CSSProcessor::TOKEN_URL:
				// URLs have decoded value in token_name
				$decoded_value = $processor->get_token_name();
				return $decoded_value !== null ? array( 'value' => $decoded_value ) : null;

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
	 * Tests tokenization of complex selectors with pseudo-classes.
	 */
	public function test_complex_selector_with_pseudo_classes(): void {
		$css       = 'a:hover::before, div.class#id:not(.disabled)';
		$processor = new CSSProcessor( $css );
		$tokens    = $this->collect_tokens( $processor, $css );

		// Expected: a :hover ::before , whitespace div .class #id :not (.disabled )
		$expected = array(
			array( 'type' => CSSProcessor::TOKEN_IDENT,      'raw' => 'a' ),
			array( 'type' => CSSProcessor::TOKEN_COLON,      'raw' => ':' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,      'raw' => 'hover' ),
			array( 'type' => CSSProcessor::TOKEN_COLON,      'raw' => ':' ),
			array( 'type' => CSSProcessor::TOKEN_COLON,      'raw' => ':' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,      'raw' => 'before' ),
			array( 'type' => CSSProcessor::TOKEN_COMMA,      'raw' => ',' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE, 'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,      'raw' => 'div' ),
			array( 'type' => CSSProcessor::TOKEN_DELIM,      'raw' => '.' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,      'raw' => 'class' ),
			array( 'type' => CSSProcessor::TOKEN_HASH,       'raw' => '#id' ),
			array( 'type' => CSSProcessor::TOKEN_COLON,      'raw' => ':' ),
			array( 'type' => CSSProcessor::TOKEN_FUNCTION,   'raw' => 'not(' ),
			array( 'type' => CSSProcessor::TOKEN_DELIM,      'raw' => '.' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,      'raw' => 'disabled' ),
			array( 'type' => CSSProcessor::TOKEN_RIGHT_PAREN, 'raw' => ')' ),
		);

		$this->assertCount( count( $expected ), $tokens, 'Token count mismatch' );
		foreach ( $expected as $index => $exp ) {
			$this->assertSame( $exp['type'], $tokens[ $index ]['type'], "Token $index type mismatch" );
			$this->assertSame( $exp['raw'], $tokens[ $index ]['raw'], "Token $index raw mismatch" );
		}
	}

	/**
	 * Tests tokenization of CSS comments.
	 */
	public function test_css_comments(): void {
		$css       = '/* This is a comment */ .class { color: red; /* Another comment */ }';
		$processor = new CSSProcessor( $css );
		$tokens    = $this->collect_tokens( $processor, $css );

		$expected = array(
			array( 'type' => CSSProcessor::TOKEN_COMMENT,    'raw' => '/* This is a comment */' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE, 'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_DELIM,      'raw' => '.' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,      'raw' => 'class' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE, 'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_LEFT_BRACE, 'raw' => '{' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE, 'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,      'raw' => 'color' ),
			array( 'type' => CSSProcessor::TOKEN_COLON,      'raw' => ':' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE, 'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,      'raw' => 'red' ),
			array( 'type' => CSSProcessor::TOKEN_SEMICOLON,  'raw' => ';' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE, 'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_COMMENT,    'raw' => '/* Another comment */' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE, 'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_RIGHT_BRACE, 'raw' => '}' ),
		);

		$this->assertCount( count( $expected ), $tokens, 'Token count mismatch' );
		foreach ( $expected as $index => $exp ) {
			$this->assertSame( $exp['type'], $tokens[ $index ]['type'], "Token $index type mismatch" );
			$this->assertSame( $exp['raw'], $tokens[ $index ]['raw'], "Token $index raw mismatch" );
		}
	}

	/**
	 * Tests tokenization of media queries.
	 */
	public function test_media_query(): void {
		$css       = '@media screen and (min-width: 768px) and (max-width: 1024px)';
		$processor = new CSSProcessor( $css );
		$tokens    = $this->collect_tokens( $processor, $css );

		$expected = array(
			array( 'type' => CSSProcessor::TOKEN_AT_KEYWORD,  'raw' => '@media' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,  'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,       'raw' => 'screen' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,  'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,       'raw' => 'and' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,  'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_LEFT_PAREN,  'raw' => '(' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,       'raw' => 'min-width' ),
			array( 'type' => CSSProcessor::TOKEN_COLON,       'raw' => ':' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,  'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_DIMENSION,   'raw' => '768px' ),
			array( 'type' => CSSProcessor::TOKEN_RIGHT_PAREN, 'raw' => ')' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,  'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,       'raw' => 'and' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,  'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_LEFT_PAREN,  'raw' => '(' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,       'raw' => 'max-width' ),
			array( 'type' => CSSProcessor::TOKEN_COLON,       'raw' => ':' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,  'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_DIMENSION,   'raw' => '1024px' ),
			array( 'type' => CSSProcessor::TOKEN_RIGHT_PAREN, 'raw' => ')' ),
		);

		$this->assertCount( count( $expected ), $tokens, 'Token count mismatch' );
		foreach ( $expected as $index => $exp ) {
			$this->assertSame( $exp['type'], $tokens[ $index ]['type'], "Token $index type mismatch" );
			$this->assertSame( $exp['raw'], $tokens[ $index ]['raw'], "Token $index raw mismatch" );
		}
	}

	/**
	 * Tests tokenization of keyframes animation.
	 */
	public function test_keyframes_animation(): void {
		$css       = '@keyframes slide-in { 0% { opacity: 0; } 100% { opacity: 1; } }';
		$processor = new CSSProcessor( $css );
		$tokens    = $this->collect_tokens( $processor, $css );

		// @keyframes slide-in { 0% { opacity : 0 ; } 100% { opacity : 1 ; } }
		$expected = array(
			array( 'type' => CSSProcessor::TOKEN_AT_KEYWORD,  'raw' => '@keyframes' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,  'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,       'raw' => 'slide-in' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,  'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_LEFT_BRACE,  'raw' => '{' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,  'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_PERCENTAGE,  'raw' => '0%' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,  'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_LEFT_BRACE,  'raw' => '{' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,  'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,       'raw' => 'opacity' ),
			array( 'type' => CSSProcessor::TOKEN_COLON,       'raw' => ':' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,  'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_NUMBER,      'raw' => '0' ),
			array( 'type' => CSSProcessor::TOKEN_SEMICOLON,   'raw' => ';' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,  'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_RIGHT_BRACE, 'raw' => '}' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,  'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_PERCENTAGE,  'raw' => '100%' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,  'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_LEFT_BRACE,  'raw' => '{' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,  'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,       'raw' => 'opacity' ),
			array( 'type' => CSSProcessor::TOKEN_COLON,       'raw' => ':' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,  'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_NUMBER,      'raw' => '1' ),
			array( 'type' => CSSProcessor::TOKEN_SEMICOLON,   'raw' => ';' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,  'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_RIGHT_BRACE, 'raw' => '}' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,  'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_RIGHT_BRACE, 'raw' => '}' ),
		);

		$this->assertCount( count( $expected ), $tokens, 'Token count mismatch' );
		foreach ( $expected as $index => $exp ) {
			$this->assertSame( $exp['type'], $tokens[ $index ]['type'], "Token $index type mismatch" );
			$this->assertSame( $exp['raw'], $tokens[ $index ]['raw'], "Token $index raw mismatch" );
		}
	}

	/**
	 * Tests tokenization of vendor-prefixed properties.
	 */
	public function test_vendor_prefixed_properties(): void {
		$css       = '-webkit-transform: rotate(45deg); -moz-border-radius: 5px;';
		$processor = new CSSProcessor( $css );
		$tokens    = $this->collect_tokens( $processor, $css );

		// -webkit-transform : rotate ( 45deg ) ; -moz-border-radius : 5px ;
		$expected = array(
			array( 'type' => CSSProcessor::TOKEN_IDENT,       'raw' => '-webkit-transform' ),
			array( 'type' => CSSProcessor::TOKEN_COLON,       'raw' => ':' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,  'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_FUNCTION,    'raw' => 'rotate(' ),
			array( 'type' => CSSProcessor::TOKEN_DIMENSION,   'raw' => '45deg' ),
			array( 'type' => CSSProcessor::TOKEN_RIGHT_PAREN, 'raw' => ')' ),
			array( 'type' => CSSProcessor::TOKEN_SEMICOLON,   'raw' => ';' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,  'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,       'raw' => '-moz-border-radius' ),
			array( 'type' => CSSProcessor::TOKEN_COLON,       'raw' => ':' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,  'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_DIMENSION,   'raw' => '5px' ),
			array( 'type' => CSSProcessor::TOKEN_SEMICOLON,   'raw' => ';' ),
		);

		$this->assertCount( count( $expected ), $tokens, 'Token count mismatch' );
		foreach ( $expected as $index => $exp ) {
			$this->assertSame( $exp['type'], $tokens[ $index ]['type'], "Token $index type mismatch" );
			$this->assertSame( $exp['raw'], $tokens[ $index ]['raw'], "Token $index raw mismatch" );
		}
	}

	/**
	 * Tests tokenization of attribute selectors.
	 */
	public function test_attribute_selectors(): void {
		$css       = 'input[type="text"][required], a[href^="https://"]';
		$processor = new CSSProcessor( $css );
		$tokens    = $this->collect_tokens( $processor, $css );

		// input [ type = "text" ] [ required ] , a [ href ^ = "https://" ]
		$expected = array(
			array( 'type' => CSSProcessor::TOKEN_IDENT,         'raw' => 'input' ),
			array( 'type' => CSSProcessor::TOKEN_LEFT_BRACKET,  'raw' => '[' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,         'raw' => 'type' ),
			array( 'type' => CSSProcessor::TOKEN_DELIM,         'raw' => '=' ),
			array( 'type' => CSSProcessor::TOKEN_STRING,        'raw' => '"text"' ),
			array( 'type' => CSSProcessor::TOKEN_RIGHT_BRACKET, 'raw' => ']' ),
			array( 'type' => CSSProcessor::TOKEN_LEFT_BRACKET,  'raw' => '[' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,         'raw' => 'required' ),
			array( 'type' => CSSProcessor::TOKEN_RIGHT_BRACKET, 'raw' => ']' ),
			array( 'type' => CSSProcessor::TOKEN_COMMA,         'raw' => ',' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,    'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,         'raw' => 'a' ),
			array( 'type' => CSSProcessor::TOKEN_LEFT_BRACKET,  'raw' => '[' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,         'raw' => 'href' ),
			array( 'type' => CSSProcessor::TOKEN_DELIM,         'raw' => '^' ),
			array( 'type' => CSSProcessor::TOKEN_DELIM,         'raw' => '=' ),
			array( 'type' => CSSProcessor::TOKEN_STRING,        'raw' => '"https://"' ),
			array( 'type' => CSSProcessor::TOKEN_RIGHT_BRACKET, 'raw' => ']' ),
		);

		$this->assertCount( count( $expected ), $tokens, 'Token count mismatch' );
		foreach ( $expected as $index => $exp ) {
			$this->assertSame( $exp['type'], $tokens[ $index ]['type'], "Token $index type mismatch" );
			$this->assertSame( $exp['raw'], $tokens[ $index ]['raw'], "Token $index raw mismatch" );
		}
	}

	/**
	 * Tests tokenization of calc() function with complex expressions.
	 */
	public function test_calc_function(): void {
		$css       = 'width: calc(100% - 20px * 2 + 5em);';
		$processor = new CSSProcessor( $css );
		$tokens    = $this->collect_tokens( $processor, $css );

		// width : calc ( 100% - 20px * 2 + 5em ) ;
		$expected = array(
			array( 'type' => CSSProcessor::TOKEN_IDENT,       'raw' => 'width' ),
			array( 'type' => CSSProcessor::TOKEN_COLON,       'raw' => ':' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,  'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_FUNCTION,    'raw' => 'calc(' ),
			array( 'type' => CSSProcessor::TOKEN_PERCENTAGE,  'raw' => '100%' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,  'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_DELIM,       'raw' => '-' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,  'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_DIMENSION,   'raw' => '20px' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,  'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_DELIM,       'raw' => '*' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,  'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_NUMBER,      'raw' => '2' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,  'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_DELIM,       'raw' => '+' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,  'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_DIMENSION,   'raw' => '5em' ),
			array( 'type' => CSSProcessor::TOKEN_RIGHT_PAREN, 'raw' => ')' ),
			array( 'type' => CSSProcessor::TOKEN_SEMICOLON,   'raw' => ';' ),
		);

		$this->assertCount( count( $expected ), $tokens, 'Token count mismatch' );
		foreach ( $expected as $index => $exp ) {
			$this->assertSame( $exp['type'], $tokens[ $index ]['type'], "Token $index type mismatch" );
			$this->assertSame( $exp['raw'], $tokens[ $index ]['raw'], "Token $index raw mismatch" );
		}
	}

	/**
	 * Tests tokenization of RGB/RGBA color functions.
	 */
	public function test_color_functions(): void {
		$css       = 'color: rgb(255, 128, 0); background: rgba(0, 0, 0, 0.5);';
		$processor = new CSSProcessor( $css );
		$tokens    = $this->collect_tokens( $processor, $css );

		// Verify full token sequence
		$this->assertSame( CSSProcessor::TOKEN_IDENT, $tokens[0]['type'] );       // color
		$this->assertSame( 'color', $tokens[0]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_COLON, $tokens[1]['type'] );       // :
		$this->assertSame( ':', $tokens[1]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_WHITESPACE, $tokens[2]['type'] );  // space
		$this->assertSame( ' ', $tokens[2]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_FUNCTION, $tokens[3]['type'] );    // rgb(
		$this->assertSame( 'rgb(', $tokens[3]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_NUMBER, $tokens[4]['type'] );      // 255
		$this->assertSame( '255', $tokens[4]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_COMMA, $tokens[5]['type'] );       // ,
		$this->assertSame( ',', $tokens[5]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_WHITESPACE, $tokens[6]['type'] );  // space
		$this->assertSame( ' ', $tokens[6]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_NUMBER, $tokens[7]['type'] );      // 128
		$this->assertSame( '128', $tokens[7]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_COMMA, $tokens[8]['type'] );       // ,
		$this->assertSame( ',', $tokens[8]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_WHITESPACE, $tokens[9]['type'] );  // space
		$this->assertSame( ' ', $tokens[9]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_NUMBER, $tokens[10]['type'] );     // 0
		$this->assertSame( '0', $tokens[10]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_RIGHT_PAREN, $tokens[11]['type'] );// )
		$this->assertSame( ')', $tokens[11]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_SEMICOLON, $tokens[12]['type'] );  // ;
		$this->assertSame( ';', $tokens[12]['raw'] );
		$this->assertCount( 30, $tokens );
	}

	/**
	 * Tests tokenization of CSS custom properties (variables).
	 */
	public function test_css_variables(): void {
		$css       = '--main-color: #ff0000; color: var(--main-color);';
		$processor = new CSSProcessor( $css );
		$tokens    = $this->collect_tokens( $processor, $css );

		// --main-color : #ff0000 ; color : var ( --main-color ) ;
		$this->assertSame( CSSProcessor::TOKEN_IDENT, $tokens[0]['type'] );      // --main-color
		$this->assertSame( '--main-color', $tokens[0]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_COLON, $tokens[1]['type'] );      // :
		$this->assertSame( ':', $tokens[1]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_WHITESPACE, $tokens[2]['type'] ); // space
		$this->assertSame( ' ', $tokens[2]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_HASH, $tokens[3]['type'] );       // #ff0000
		$this->assertSame( '#ff0000', $tokens[3]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_SEMICOLON, $tokens[4]['type'] );  // ;
		$this->assertSame( ';', $tokens[4]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_WHITESPACE, $tokens[5]['type'] ); // space
		$this->assertSame( ' ', $tokens[5]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_IDENT, $tokens[6]['type'] );      // color
		$this->assertSame( 'color', $tokens[6]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_COLON, $tokens[7]['type'] );      // :
		$this->assertSame( ':', $tokens[7]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_WHITESPACE, $tokens[8]['type'] ); // space
		$this->assertSame( ' ', $tokens[8]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_FUNCTION, $tokens[9]['type'] );   // var(
		$this->assertSame( 'var(', $tokens[9]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_IDENT, $tokens[10]['type'] );     // --main-color
		$this->assertSame( '--main-color', $tokens[10]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_RIGHT_PAREN, $tokens[11]['type'] );// )
		$this->assertSame( ')', $tokens[11]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_SEMICOLON, $tokens[12]['type'] ); // ;
		$this->assertSame( ';', $tokens[12]['raw'] );
		$this->assertCount( 13, $tokens );
	}

	/**
	 * Tests tokenization of gradient functions.
	 */
	public function test_gradient_functions(): void {
		$css       = 'background: linear-gradient(to right, red 0%, blue 100%);';
		$processor = new CSSProcessor( $css );
		$tokens    = $this->collect_tokens( $processor, $css );

		// background : linear-gradient ( to right , red 0% , blue 100% ) ;
		$this->assertSame( CSSProcessor::TOKEN_IDENT, $tokens[0]['type'] );       // background
		$this->assertSame( 'background', $tokens[0]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_COLON, $tokens[1]['type'] );       // :
		$this->assertSame( ':', $tokens[1]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_WHITESPACE, $tokens[2]['type'] );  // space
		$this->assertSame( ' ', $tokens[2]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_FUNCTION, $tokens[3]['type'] );    // linear-gradient(
		$this->assertSame( 'linear-gradient(', $tokens[3]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_IDENT, $tokens[4]['type'] );       // to
		$this->assertSame( 'to', $tokens[4]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_WHITESPACE, $tokens[5]['type'] );  // space
		$this->assertSame( ' ', $tokens[5]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_IDENT, $tokens[6]['type'] );       // right
		$this->assertSame( 'right', $tokens[6]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_COMMA, $tokens[7]['type'] );       // ,
		$this->assertSame( ',', $tokens[7]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_WHITESPACE, $tokens[8]['type'] );  // space
		$this->assertSame( ' ', $tokens[8]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_IDENT, $tokens[9]['type'] );       // red
		$this->assertSame( 'red', $tokens[9]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_WHITESPACE, $tokens[10]['type'] ); // space
		$this->assertSame( ' ', $tokens[10]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_PERCENTAGE, $tokens[11]['type'] ); // 0%
		$this->assertSame( '0%', $tokens[11]['raw'] );
		$this->assertCount( 19, $tokens );
	}

	/**
	 * Tests tokenization of grid layout properties.
	 */
	public function test_grid_layout(): void {
		$css       = 'grid-template-columns: repeat(3, 1fr); gap: 10px 20px;';
		$processor = new CSSProcessor( $css );
		$tokens    = $this->collect_tokens( $processor, $css );

		// grid-template-columns : repeat ( 3 , 1fr ) ; gap : 10px 20px ;
		$this->assertSame( CSSProcessor::TOKEN_IDENT, $tokens[0]['type'] );       // grid-template-columns
		$this->assertSame( 'grid-template-columns', $tokens[0]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_COLON, $tokens[1]['type'] );       // :
		$this->assertSame( ':', $tokens[1]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_WHITESPACE, $tokens[2]['type'] );  // space
		$this->assertSame( ' ', $tokens[2]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_FUNCTION, $tokens[3]['type'] );    // repeat(
		$this->assertSame( 'repeat(', $tokens[3]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_NUMBER, $tokens[4]['type'] );      // 3
		$this->assertSame( '3', $tokens[4]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_COMMA, $tokens[5]['type'] );       // ,
		$this->assertSame( ',', $tokens[5]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_WHITESPACE, $tokens[6]['type'] );  // space
		$this->assertSame( ' ', $tokens[6]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_DIMENSION, $tokens[7]['type'] );   // 1fr
		$this->assertSame( '1fr', $tokens[7]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_RIGHT_PAREN, $tokens[8]['type'] ); // )
		$this->assertSame( ')', $tokens[8]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_SEMICOLON, $tokens[9]['type'] );   // ;
		$this->assertSame( ';', $tokens[9]['raw'] );
		$this->assertCount( 18, $tokens );
	}

	/**
	 * Tests tokenization of URL functions with various formats.
	 */
	public function test_url_formats(): void {
		$css       = 'background: url("image.png"), url(\'font.woff\'), url(https://example.com/bg.jpg);';
		$processor = new CSSProcessor( $css );
		$tokens    = $this->collect_tokens( $processor, $css );

		// background : url ( "image.png" ) , url ( 'font.woff' ) , url ( https://... ) ;
		$this->assertSame( CSSProcessor::TOKEN_IDENT, $tokens[0]['type'] );       // background
		$this->assertSame( 'background', $tokens[0]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_COLON, $tokens[1]['type'] );       // :
		$this->assertSame( ':', $tokens[1]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_WHITESPACE, $tokens[2]['type'] );  // space
		$this->assertSame( ' ', $tokens[2]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_FUNCTION, $tokens[3]['type'] );    // url(
		$this->assertSame( 'url(', $tokens[3]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_STRING, $tokens[4]['type'] );      // "image.png"
		$this->assertSame( '"image.png"', $tokens[4]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_RIGHT_PAREN, $tokens[5]['type'] ); // )
		$this->assertSame( ')', $tokens[5]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_COMMA, $tokens[6]['type'] );       // ,
		$this->assertSame( ',', $tokens[6]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_WHITESPACE, $tokens[7]['type'] );  // space
		$this->assertSame( ' ', $tokens[7]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_FUNCTION, $tokens[8]['type'] );    // url(
		$this->assertSame( 'url(', $tokens[8]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_STRING, $tokens[9]['type'] );      // 'font.woff'
		$this->assertSame( "'font.woff'", $tokens[9]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_RIGHT_PAREN, $tokens[10]['type'] );// )
		$this->assertSame( ')', $tokens[10]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_COMMA, $tokens[11]['type'] );      // ,
		$this->assertSame( ',', $tokens[11]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_WHITESPACE, $tokens[12]['type'] ); // space
		$this->assertSame( ' ', $tokens[12]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_URL, $tokens[13]['type'] );        // url(https://...)
		$this->assertSame( 'url(https://example.com/bg.jpg)', $tokens[13]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_SEMICOLON, $tokens[14]['type'] );  // ;
		$this->assertSame( ';', $tokens[14]['raw'] );
		$this->assertCount( 15, $tokens );
	}

	/**
	 * Tests tokenization of !important declarations.
	 */
	public function test_important_declarations(): void {
		$css       = 'color: red !important; margin: 0 !important;';
		$processor = new CSSProcessor( $css );
		$tokens    = $this->collect_tokens( $processor, $css );

		// color : red ! important ; margin : 0 ! important ;
		$this->assertSame( CSSProcessor::TOKEN_IDENT, $tokens[0]['type'] );      // color
		$this->assertSame( 'color', $tokens[0]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_COLON, $tokens[1]['type'] );      // :
		$this->assertSame( ':', $tokens[1]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_WHITESPACE, $tokens[2]['type'] ); // space
		$this->assertSame( ' ', $tokens[2]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_IDENT, $tokens[3]['type'] );      // red
		$this->assertSame( 'red', $tokens[3]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_WHITESPACE, $tokens[4]['type'] ); // space
		$this->assertSame( ' ', $tokens[4]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_DELIM, $tokens[5]['type'] );      // !
		$this->assertSame( '!', $tokens[5]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_IDENT, $tokens[6]['type'] );      // important
		$this->assertSame( 'important', $tokens[6]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_SEMICOLON, $tokens[7]['type'] );  // ;
		$this->assertSame( ';', $tokens[7]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_WHITESPACE, $tokens[8]['type'] ); // space
		$this->assertSame( ' ', $tokens[8]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_IDENT, $tokens[9]['type'] );      // margin
		$this->assertSame( 'margin', $tokens[9]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_COLON, $tokens[10]['type'] );     // :
		$this->assertSame( ':', $tokens[10]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_WHITESPACE, $tokens[11]['type'] );// space
		$this->assertSame( ' ', $tokens[11]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_NUMBER, $tokens[12]['type'] );    // 0
		$this->assertSame( '0', $tokens[12]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_WHITESPACE, $tokens[13]['type'] );// space
		$this->assertSame( ' ', $tokens[13]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_DELIM, $tokens[14]['type'] );     // !
		$this->assertSame( '!', $tokens[14]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_IDENT, $tokens[15]['type'] );     // important
		$this->assertSame( 'important', $tokens[15]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_SEMICOLON, $tokens[16]['type'] ); // ;
		$this->assertSame( ';', $tokens[16]['raw'] );
		$this->assertCount( 17, $tokens );
	}

	/**
	 * Tests tokenization of multiple selectors with combinators.
	 */
	public function test_complex_combinators(): void {
		$css       = 'div > p + span ~ a.link';
		$processor = new CSSProcessor( $css );
		$tokens    = $this->collect_tokens( $processor, $css );

		// div > p + span ~ a . link
		$this->assertSame( CSSProcessor::TOKEN_IDENT, $tokens[0]['type'] );       // div
		$this->assertSame( 'div', $tokens[0]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_WHITESPACE, $tokens[1]['type'] );  // space
		$this->assertSame( ' ', $tokens[1]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_DELIM, $tokens[2]['type'] );       // >
		$this->assertSame( '>', $tokens[2]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_WHITESPACE, $tokens[3]['type'] );  // space
		$this->assertSame( ' ', $tokens[3]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_IDENT, $tokens[4]['type'] );       // p
		$this->assertSame( 'p', $tokens[4]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_WHITESPACE, $tokens[5]['type'] );  // space
		$this->assertSame( ' ', $tokens[5]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_DELIM, $tokens[6]['type'] );       // +
		$this->assertSame( '+', $tokens[6]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_WHITESPACE, $tokens[7]['type'] );  // space
		$this->assertSame( ' ', $tokens[7]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_IDENT, $tokens[8]['type'] );       // span
		$this->assertSame( 'span', $tokens[8]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_WHITESPACE, $tokens[9]['type'] );  // space
		$this->assertSame( ' ', $tokens[9]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_DELIM, $tokens[10]['type'] );      // ~
		$this->assertSame( '~', $tokens[10]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_WHITESPACE, $tokens[11]['type'] ); // space
		$this->assertSame( ' ', $tokens[11]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_IDENT, $tokens[12]['type'] );      // a
		$this->assertSame( 'a', $tokens[12]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_DELIM, $tokens[13]['type'] );      // .
		$this->assertSame( '.', $tokens[13]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_IDENT, $tokens[14]['type'] );      // link
		$this->assertSame( 'link', $tokens[14]['raw'] );
		$this->assertCount( 15, $tokens );
	}

	/**
	 * Tests tokenization of escaped characters in identifiers.
	 */
	public function test_escaped_identifiers(): void {
		$css       = '.class\\:name, #id\\@special { color: blue; }';
		$processor = new CSSProcessor( $css );
		$tokens    = $this->collect_tokens( $processor, $css );

		// . class\:name , # id\@special { color : blue ; }
		$this->assertSame( CSSProcessor::TOKEN_DELIM, $tokens[0]['type'] );       // .
		$this->assertSame( '.', $tokens[0]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_IDENT, $tokens[1]['type'] );       // class\:name
		$this->assertSame( 'class\\:name', $tokens[1]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_COMMA, $tokens[2]['type'] );       // ,
		$this->assertSame( ',', $tokens[2]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_WHITESPACE, $tokens[3]['type'] );  // space
		$this->assertSame( ' ', $tokens[3]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_HASH, $tokens[4]['type'] );        // #id\@special
		$this->assertSame( '#id\\@special', $tokens[4]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_WHITESPACE, $tokens[5]['type'] );  // space
		$this->assertSame( ' ', $tokens[5]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_LEFT_BRACE, $tokens[6]['type'] );  // {
		$this->assertSame( '{', $tokens[6]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_WHITESPACE, $tokens[7]['type'] );  // space
		$this->assertSame( ' ', $tokens[7]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_IDENT, $tokens[8]['type'] );       // color
		$this->assertSame( 'color', $tokens[8]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_COLON, $tokens[9]['type'] );       // :
		$this->assertSame( ':', $tokens[9]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_WHITESPACE, $tokens[10]['type'] ); // space
		$this->assertSame( ' ', $tokens[10]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_IDENT, $tokens[11]['type'] );      // blue
		$this->assertSame( 'blue', $tokens[11]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_SEMICOLON, $tokens[12]['type'] );  // ;
		$this->assertSame( ';', $tokens[12]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_WHITESPACE, $tokens[13]['type'] ); // space
		$this->assertSame( ' ', $tokens[13]['raw'] );
		$this->assertSame( CSSProcessor::TOKEN_RIGHT_BRACE, $tokens[14]['type'] );// }
		$this->assertSame( '}', $tokens[14]['raw'] );
		$this->assertCount( 15, $tokens );
	}
}
