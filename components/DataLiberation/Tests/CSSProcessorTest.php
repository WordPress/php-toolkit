<?php

use PHPUnit\Framework\TestCase;
use WordPress\DataLiberation\CSS\CSSProcessor;

/**
 * Comprehensive CSS processor tests based on the CSS Syntax Level 3 specification.
 */
class CSSProcessorTest extends TestCase {

	/**
	 * Tests that the processor produces the expected tokens for all test cases.
	 *
	 * @dataProvider corpus_provider
	 */
	public function test_processor_matches_spec( string $css, array $expected_tokens ): void {
		$processor = CSSProcessor::create( $css );
		$actual_tokens = $this->collect_tokens( $processor );
		$this->assertSame( $expected_tokens, $actual_tokens );
	}

	/**
	 * Provides the test cases from the @rmenke/css-processor-test test corpus.
	 *
	 * @see https://github.com/romainmenke/css-processor-tests/
	 * @return array
	 */
	static public function corpus_provider(): array {
		return json_decode(file_get_contents(__DIR__ . '/css-test-cases.json'), true);
	}

	/**
	 * Collects all tokens from a CSS processor into an array.
	 *
	 * @param CSSProcessor $processor The CSS processor.
	 * @return array Array of tokens with type, raw, startIndex, endIndex, structured.
	 */
	static public function collect_tokens( CSSProcessor $processor, $keys = null ): array {
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
				'normalized' => $processor->get_normalized_token(),
				'value'      => $processor->get_token_value(),
			);
			if ( null !== $processor->get_token_unit() ) {
				$token['unit'] = $processor->get_token_unit();
			}

			if ( null !== $keys ) {
				$token = array_intersect_key( $token, array_flip( $keys ) );
			}

			$tokens[] = $token;
		}

		return $tokens;
	}

	/**
	 * Tests handling of non-UTF-8 byte sequences in identifiers.
	 *
	 * Invalid UTF-8 sequences should be replaced with U+FFFD replacement characters
	 * during tokenization, allowing the CSS to continue processing.
	 */
	public function test_non_utf8_sequences_in_identifiers(): void {
		// Invalid UTF-8 sequence 0xC0 0x80 (overlong encoding).
		$css = ".class\xF1name";

		$expected = array(
			// .class�name (0xF1 replaced with U+FFFD).
			array(
				'type' => CSSProcessor::TOKEN_DELIM,
				'raw'  => '.',
				'normalized' => '.',
				'value' => '.',
			),
			array(
				'type' => CSSProcessor::TOKEN_IDENT,
				'raw'  => "class\xF1name",
				'normalized' => 'class�name',
				'value' => 'class�name',
			),
		);

		$processor = CSSProcessor::create( $css );
		$actual_tokens = $this->collect_tokens( $processor, ['type', 'raw', 'normalized', 'value', 'unit'] );
		$this->assertSame( $expected, $actual_tokens );
	}

	public function test_invalid_utf8_with_valid_prefix_in_identifiers(): void {
		// Invalid 2-byte prefix is replaced with a single U+FFFD.
		$css = ".test\xE2\x80name";

		$expected = array(
			array(
				'type' => CSSProcessor::TOKEN_DELIM,
				'raw'  => '.',
				'normalized' => '.',
				'value' => '.',
			),
			array(
				'type' => CSSProcessor::TOKEN_IDENT,
				'raw'  => "test\xE2\x80name",
				'normalized' => 'test�name',
				'value' => 'test�name',
			),
		);

		$processor = CSSProcessor::create( $css );
		$actual_tokens = $this->collect_tokens( $processor, ['type', 'raw', 'normalized', 'value'] );
		$this->assertSame( $expected, $actual_tokens );
	}

	public function test_invalid_utf8_with_two_single_byte_invalid_sequences(): void {
		// Two distinct single byte invalid sequences are replaced with
		// two separate U+FFFD replacement characters.
		$css = ".test\xE2\xE2name";

		$expected = array(
			array(
				'type' => CSSProcessor::TOKEN_DELIM,
				'raw'  => '.',
				'normalized' => '.',
				'value' => '.',
			),
			array(
				'type' => CSSProcessor::TOKEN_IDENT,
				'raw'  => "test\xE2\xE2name",
				'normalized' => 'test��name',
				'value' => 'test��name',
			),
		);

		$processor = CSSProcessor::create( $css );
		$actual_tokens = $this->collect_tokens( $processor, ['type', 'raw', 'normalized', 'value'] );
		$this->assertSame( $expected, $actual_tokens );
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
			array( 'type' => CSSProcessor::TOKEN_AT_KEYWORD, 'raw' => '@media' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE, 'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT, 'raw' => 'screen' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE, 'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT, 'raw' => 'and' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE, 'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_LEFT_PAREN, 'raw' => '(' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT, 'raw' => 'min-width' ),
			array( 'type' => CSSProcessor::TOKEN_COLON, 'raw' => ':' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE, 'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_DIMENSION, 'raw' => '10px' ),
			array( 'type' => CSSProcessor::TOKEN_RIGHT_PAREN, 'raw' => ')' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE, 'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_LEFT_BRACE, 'raw' => '{' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE, 'raw' => "\n\t" ),
			array( 'type' => CSSProcessor::TOKEN_IDENT, 'raw' => 'background' ),
			array( 'type' => CSSProcessor::TOKEN_COLON, 'raw' => ':' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE, 'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_FUNCTION, 'raw' => 'url(' ),
			array( 'type' => CSSProcessor::TOKEN_STRING, 'raw' => '"/images/a.png"' ),
			array( 'type' => CSSProcessor::TOKEN_RIGHT_PAREN, 'raw' => ')' ),
			array( 'type' => CSSProcessor::TOKEN_SEMICOLON, 'raw' => ';' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE, 'raw' => "\n" ),
			array( 'type' => CSSProcessor::TOKEN_RIGHT_BRACE, 'raw' => '}' ),
		);

		$processor = CSSProcessor::create( $css );
		$actual_tokens = $this->collect_tokens( $processor, ['type', 'raw'] );
		$this->assertSame( $actual_tokens, $expected );
	}

	/**
	 * Tests tokenization of complex selectors with pseudo-classes.
	 */
	public function test_complex_selector_with_pseudo_classes(): void {
		$css = 'a:hover::before, div.class#id:not(.disabled)';

		$expected = array(
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'a' ),
			array( 'type' => CSSProcessor::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'hover' ),
			array( 'type' => CSSProcessor::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSProcessor::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'before' ),
			array( 'type' => CSSProcessor::TOKEN_COMMA,        'raw' => ',' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'div' ),
			array( 'type' => CSSProcessor::TOKEN_DELIM,        'raw' => '.' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'class' ),
			array( 'type' => CSSProcessor::TOKEN_HASH,         'raw' => '#id' ),
			array( 'type' => CSSProcessor::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSProcessor::TOKEN_FUNCTION,     'raw' => 'not(' ),
			array( 'type' => CSSProcessor::TOKEN_DELIM,        'raw' => '.' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'disabled' ),
			array( 'type' => CSSProcessor::TOKEN_RIGHT_PAREN,  'raw' => ')' ),
		);

		$processor = CSSProcessor::create( $css );
		$actual_tokens = $this->collect_tokens( $processor, ['type', 'raw'] );
		$this->assertSame( $actual_tokens, $expected );
	}

	/**
	 * Tests tokenization of CSS comments.
	 */
	public function test_css_comments(): void {
		$css = '/* This is a comment */ .class { color: red; /* Another comment */ }';

		$expected = array(
			array( 'type' => CSSProcessor::TOKEN_COMMENT,      'raw' => '/* This is a comment */' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_DELIM,        'raw' => '.' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'class' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_LEFT_BRACE,   'raw' => '{' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'color' ),
			array( 'type' => CSSProcessor::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'red' ),
			array( 'type' => CSSProcessor::TOKEN_SEMICOLON,    'raw' => ';' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_COMMENT,      'raw' => '/* Another comment */' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_RIGHT_BRACE,  'raw' => '}' ),
		);

		$processor = CSSProcessor::create( $css );
		$actual_tokens = $this->collect_tokens( $processor, ['type', 'raw'] );
		$this->assertSame( $actual_tokens, $expected );
	}

	/**
	 * Tests tokenization of media queries.
	 */
	public function test_media_query(): void {
		$css = '@media screen and (min-width: 768px) and (max-width: 1024px)';

		$expected = array(
			array( 'type' => CSSProcessor::TOKEN_AT_KEYWORD,   'raw' => '@media' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'screen' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'and' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_LEFT_PAREN,   'raw' => '(' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'min-width' ),
			array( 'type' => CSSProcessor::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_DIMENSION,    'raw' => '768px' ),
			array( 'type' => CSSProcessor::TOKEN_RIGHT_PAREN,  'raw' => ')' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'and' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_LEFT_PAREN,   'raw' => '(' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'max-width' ),
			array( 'type' => CSSProcessor::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_DIMENSION,    'raw' => '1024px' ),
			array( 'type' => CSSProcessor::TOKEN_RIGHT_PAREN,  'raw' => ')' ),
		);

		$processor = CSSProcessor::create( $css );
		$actual_tokens = $this->collect_tokens( $processor, ['type', 'raw'] );
		$this->assertSame( $actual_tokens, $expected );
	}

	/**
	 * Tests tokenization of keyframes animation.
	 */
	public function test_keyframes_animation(): void {
		$css = '@keyframes slide-in { 0% { opacity: 0; } 100% { opacity: 1; } }';

		$expected = array(
			array( 'type' => CSSProcessor::TOKEN_AT_KEYWORD,   'raw' => '@keyframes' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'slide-in' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_LEFT_BRACE,   'raw' => '{' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_PERCENTAGE,   'raw' => '0%' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_LEFT_BRACE,   'raw' => '{' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'opacity' ),
			array( 'type' => CSSProcessor::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_NUMBER,       'raw' => '0' ),
			array( 'type' => CSSProcessor::TOKEN_SEMICOLON,    'raw' => ';' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_RIGHT_BRACE,  'raw' => '}' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_PERCENTAGE,   'raw' => '100%' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_LEFT_BRACE,   'raw' => '{' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'opacity' ),
			array( 'type' => CSSProcessor::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_NUMBER,       'raw' => '1' ),
			array( 'type' => CSSProcessor::TOKEN_SEMICOLON,    'raw' => ';' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_RIGHT_BRACE,  'raw' => '}' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_RIGHT_BRACE,  'raw' => '}' ),
		);

		$processor = CSSProcessor::create( $css );
		$actual_tokens = $this->collect_tokens( $processor, ['type', 'raw'] );
		$this->assertSame( $actual_tokens, $expected );
	}

	/**
	 * Tests tokenization of vendor-prefixed properties.
	 */
	public function test_vendor_prefixed_properties(): void {
		$css = '-webkit-transform: rotate(45deg); -moz-border-radius: 5px;';

		$expected = array(
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => '-webkit-transform' ),
			array( 'type' => CSSProcessor::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_FUNCTION,     'raw' => 'rotate(' ),
			array( 'type' => CSSProcessor::TOKEN_DIMENSION,    'raw' => '45deg' ),
			array( 'type' => CSSProcessor::TOKEN_RIGHT_PAREN,  'raw' => ')' ),
			array( 'type' => CSSProcessor::TOKEN_SEMICOLON,    'raw' => ';' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => '-moz-border-radius' ),
			array( 'type' => CSSProcessor::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_DIMENSION,    'raw' => '5px' ),
			array( 'type' => CSSProcessor::TOKEN_SEMICOLON,    'raw' => ';' ),
		);

		$processor = CSSProcessor::create( $css );
		$actual_tokens = $this->collect_tokens( $processor, ['type', 'raw'] );
		$this->assertSame( $actual_tokens, $expected );
	}

	/**
	 * Tests tokenization of attribute selectors.
	 */
	public function test_attribute_selectors(): void {
		$css = 'input[type="text"][required], a[href^="https://"]';

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

		$processor = CSSProcessor::create( $css );
		$actual_tokens = $this->collect_tokens( $processor, ['type', 'raw'] );
		$this->assertSame( $actual_tokens, $expected );
	}

	/**
	 * Tests tokenization of calc() function with complex expressions.
	 */
	public function test_calc_function(): void {
		$css = 'width: calc(100% - 20px * 2 + 5em);';

		$expected = array(
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'width' ),
			array( 'type' => CSSProcessor::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_FUNCTION,     'raw' => 'calc(' ),
			array( 'type' => CSSProcessor::TOKEN_PERCENTAGE,   'raw' => '100%' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_DELIM,        'raw' => '-' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_DIMENSION,    'raw' => '20px' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_DELIM,        'raw' => '*' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_NUMBER,       'raw' => '2' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_DELIM,        'raw' => '+' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_DIMENSION,    'raw' => '5em' ),
			array( 'type' => CSSProcessor::TOKEN_RIGHT_PAREN,  'raw' => ')' ),
			array( 'type' => CSSProcessor::TOKEN_SEMICOLON,    'raw' => ';' ),
		);

		$processor = CSSProcessor::create( $css );
		$actual_tokens = $this->collect_tokens( $processor, ['type', 'raw'] );
		$this->assertSame( $actual_tokens, $expected );
	}

	/**
	 * Tests tokenization of RGB/RGBA color functions.
	 */
	public function test_color_functions(): void {
		$css = 'color: rgb(255, 128, 0); background: rgba(0, 0, 0, 0.5);';

		$expected = array(
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'color' ),
			array( 'type' => CSSProcessor::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_FUNCTION,     'raw' => 'rgb(' ),
			array( 'type' => CSSProcessor::TOKEN_NUMBER,       'raw' => '255' ),
			array( 'type' => CSSProcessor::TOKEN_COMMA,        'raw' => ',' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_NUMBER,       'raw' => '128' ),
			array( 'type' => CSSProcessor::TOKEN_COMMA,        'raw' => ',' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_NUMBER,       'raw' => '0' ),
			array( 'type' => CSSProcessor::TOKEN_RIGHT_PAREN,  'raw' => ')' ),
			array( 'type' => CSSProcessor::TOKEN_SEMICOLON,    'raw' => ';' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'background' ),
			array( 'type' => CSSProcessor::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_FUNCTION,     'raw' => 'rgba(' ),
			array( 'type' => CSSProcessor::TOKEN_NUMBER,       'raw' => '0' ),
			array( 'type' => CSSProcessor::TOKEN_COMMA,        'raw' => ',' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_NUMBER,       'raw' => '0' ),
			array( 'type' => CSSProcessor::TOKEN_COMMA,        'raw' => ',' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_NUMBER,       'raw' => '0' ),
			array( 'type' => CSSProcessor::TOKEN_COMMA,        'raw' => ',' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_NUMBER,       'raw' => '0.5' ),
			array( 'type' => CSSProcessor::TOKEN_RIGHT_PAREN,  'raw' => ')' ),
			array( 'type' => CSSProcessor::TOKEN_SEMICOLON,    'raw' => ';' ),
		);

		$processor = CSSProcessor::create( $css );
		$actual_tokens = $this->collect_tokens( $processor, ['type', 'raw'] );
		$this->assertSame( $actual_tokens, $expected );
	}

	/**
	 * Tests tokenization of CSS custom properties (variables).
	 */
	public function test_css_variables(): void {
		$css = '--main-color: #ff0000; color: var(--main-color);';

		$expected = array(
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => '--main-color' ),
			array( 'type' => CSSProcessor::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_HASH,         'raw' => '#ff0000' ),
			array( 'type' => CSSProcessor::TOKEN_SEMICOLON,    'raw' => ';' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'color' ),
			array( 'type' => CSSProcessor::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_FUNCTION,     'raw' => 'var(' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => '--main-color' ),
			array( 'type' => CSSProcessor::TOKEN_RIGHT_PAREN,  'raw' => ')' ),
			array( 'type' => CSSProcessor::TOKEN_SEMICOLON,    'raw' => ';' ),
		);

		$processor = CSSProcessor::create( $css );
		$actual_tokens = $this->collect_tokens( $processor, ['type', 'raw'] );
		$this->assertSame( $actual_tokens, $expected );
	}

	/**
	 * Tests tokenization of gradient functions.
	 */
	public function test_gradient_functions(): void {
		$css = 'background: linear-gradient(to right, red 0%, blue 100%);';

		$expected = array(
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'background' ),
			array( 'type' => CSSProcessor::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_FUNCTION,     'raw' => 'linear-gradient(' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'to' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'right' ),
			array( 'type' => CSSProcessor::TOKEN_COMMA,        'raw' => ',' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'red' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_PERCENTAGE,   'raw' => '0%' ),
			array( 'type' => CSSProcessor::TOKEN_COMMA,        'raw' => ',' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'blue' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_PERCENTAGE,   'raw' => '100%' ),
			array( 'type' => CSSProcessor::TOKEN_RIGHT_PAREN,  'raw' => ')' ),
			array( 'type' => CSSProcessor::TOKEN_SEMICOLON,    'raw' => ';' ),
		);

		$processor = CSSProcessor::create( $css );
		$actual_tokens = $this->collect_tokens( $processor, ['type', 'raw'] );
		$this->assertSame( $actual_tokens, $expected );
	}

	/**
	 * Tests tokenization of grid layout properties.
	 */
	public function test_grid_layout(): void {
		$css = 'grid-template-columns: repeat(3, 1fr); gap: 10px 20px;';

		$expected = array(
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'grid-template-columns' ),
			array( 'type' => CSSProcessor::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_FUNCTION,     'raw' => 'repeat(' ),
			array( 'type' => CSSProcessor::TOKEN_NUMBER,       'raw' => '3' ),
			array( 'type' => CSSProcessor::TOKEN_COMMA,        'raw' => ',' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_DIMENSION,    'raw' => '1fr' ),
			array( 'type' => CSSProcessor::TOKEN_RIGHT_PAREN,  'raw' => ')' ),
			array( 'type' => CSSProcessor::TOKEN_SEMICOLON,    'raw' => ';' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'gap' ),
			array( 'type' => CSSProcessor::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_DIMENSION,    'raw' => '10px' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_DIMENSION,    'raw' => '20px' ),
			array( 'type' => CSSProcessor::TOKEN_SEMICOLON,    'raw' => ';' ),
		);

		$processor = CSSProcessor::create( $css );
		$actual_tokens = $this->collect_tokens( $processor, ['type', 'raw'] );
		$this->assertSame( $actual_tokens, $expected );
	}

	/**
	 * Tests tokenization of URL functions with various formats.
	 */
	public function test_url_formats(): void {
		$css = 'background: url("image.png"), url(\'font.woff\'), url(https://example.com/bg.jpg);';

		$expected = array(
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'background' ),
			array( 'type' => CSSProcessor::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_FUNCTION,     'raw' => 'url(' ),
			array( 'type' => CSSProcessor::TOKEN_STRING,       'raw' => '"image.png"' ),
			array( 'type' => CSSProcessor::TOKEN_RIGHT_PAREN,  'raw' => ')' ),
			array( 'type' => CSSProcessor::TOKEN_COMMA,        'raw' => ',' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_FUNCTION,     'raw' => 'url(' ),
			array( 'type' => CSSProcessor::TOKEN_STRING,       'raw' => "'font.woff'" ),
			array( 'type' => CSSProcessor::TOKEN_RIGHT_PAREN,  'raw' => ')' ),
			array( 'type' => CSSProcessor::TOKEN_COMMA,        'raw' => ',' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_URL,          'raw' => 'url(https://example.com/bg.jpg)' ),
			array( 'type' => CSSProcessor::TOKEN_SEMICOLON,    'raw' => ';' ),
		);

		$processor = CSSProcessor::create( $css );
		$actual_tokens = $this->collect_tokens( $processor, ['type', 'raw'] );
		$this->assertSame( $actual_tokens, $expected );
	}

	/**
	 * Tests tokenization of !important declarations.
	 */
	public function test_important_declarations(): void {
		$css = 'color: red !important; margin: 0px !important;';

		$expected = array(
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'color' ),
			array( 'type' => CSSProcessor::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'red' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_DELIM,        'raw' => '!' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'important' ),
			array( 'type' => CSSProcessor::TOKEN_SEMICOLON,    'raw' => ';' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'margin' ),
			array( 'type' => CSSProcessor::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_DIMENSION,    'raw' => '0px' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_DELIM,        'raw' => '!' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'important' ),
			array( 'type' => CSSProcessor::TOKEN_SEMICOLON,    'raw' => ';' ),
		);

		$processor = CSSProcessor::create( $css );
		$actual_tokens = $this->collect_tokens( $processor, ['type', 'raw'] );
		$this->assertSame( $actual_tokens, $expected );
	}

	/**
	 * Tests tokenization of multiple selectors with combinators.
	 */
	public function test_complex_combinators(): void {
		$css = 'div > p + span ~ a.link';

		$expected = array(
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'div' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_DELIM,        'raw' => '>' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'p' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_DELIM,        'raw' => '+' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'span' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_DELIM,        'raw' => '~' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'a' ),
			array( 'type' => CSSProcessor::TOKEN_DELIM,        'raw' => '.' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'link' ),
		);

		$processor = CSSProcessor::create( $css );
		$actual_tokens = $this->collect_tokens( $processor, ['type', 'raw'] );
		$this->assertSame( $actual_tokens, $expected );
	}

	/**
	 * Tests tokenization of escaped characters in identifiers.
	 */
	public function test_escaped_identifiers(): void {
		$css = '.class\\:name, #id\\@special { color: blue; }';

		$expected = array(
			array( 'type' => CSSProcessor::TOKEN_DELIM,        'raw' => '.' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'class\\:name' ),
			array( 'type' => CSSProcessor::TOKEN_COMMA,        'raw' => ',' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_HASH,         'raw' => '#id\\@special' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_LEFT_BRACE,   'raw' => '{' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'color' ),
			array( 'type' => CSSProcessor::TOKEN_COLON,        'raw' => ':' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_IDENT,        'raw' => 'blue' ),
			array( 'type' => CSSProcessor::TOKEN_SEMICOLON,    'raw' => ';' ),
			array( 'type' => CSSProcessor::TOKEN_WHITESPACE,   'raw' => ' ' ),
			array( 'type' => CSSProcessor::TOKEN_RIGHT_BRACE,  'raw' => '}' ),
		);

		$processor = CSSProcessor::create( $css );
		$actual_tokens = $this->collect_tokens( $processor, ['type', 'raw'] );
		$this->assertSame( $actual_tokens, $expected );
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
				'type'       => CSSProcessor::TOKEN_COMMENT,
				'raw'        => "/* Comment\r\nwith\flines */",
				'normalized' => "/* Comment\nwith\nlines */",
				'value'      => null,
			),
			// Whitespace with \r\n.
			array(
				'type'       => CSSProcessor::TOKEN_WHITESPACE,
				'raw'        => "\r\n",
				'normalized' => "\n",
				'value'      => null,
			),
			// Class selector delimiter.
			array(
				'type'       => CSSProcessor::TOKEN_DELIM,
				'raw'        => '.',
				'normalized' => '.',
				'value'      => '.',
			),
			// Class name with escape (\6c = 'l'), space gets consumed by escape.
			array(
				'type'       => CSSProcessor::TOKEN_IDENT,
				'raw'        => 'c\\6c ass',
				'normalized' => 'class', // Escapes decoded.
				'value'      => 'class', // Decoded: \6c → l, space consumed.
			),
			// Delimiter.
			array(
				'type'       => CSSProcessor::TOKEN_DELIM,
				'raw'        => '.',
				'normalized' => '.',
				'value'      => '.',
			),
			// Identifier with escape (\61 = 'a'), space gets consumed.
			array(
				'type'       => CSSProcessor::TOKEN_IDENT,
				'raw'        => 'n\\61 me',
				'normalized' => 'name', // Escapes decoded.
				'value'      => 'name', // Decoded: \61 → a, space consumed.
			),
			// Whitespace with \r.
			array(
				'type'       => CSSProcessor::TOKEN_WHITESPACE,
				'raw'        => "\r",
				'normalized' => "\n",
				'value'      => null,
			),
			// ID selector with escape.
			array(
				'type'       => CSSProcessor::TOKEN_HASH,
				'raw'        => '#id\\@value',
				'normalized' => '#id@value', // Escapes decoded.
				'value'      => 'id@value', // Decoded value.
			),
			// Whitespace with \r\n.
			array(
				'type'       => CSSProcessor::TOKEN_WHITESPACE,
				'raw'        => "\r\n",
				'normalized' => "\n",
				'value'      => null,
			),
			// Opening brace.
			array(
				'type'       => CSSProcessor::TOKEN_LEFT_BRACE,
				'raw'        => '{',
				'normalized' => '{',
				'value'      => null,
			),
			// Whitespace with \r\n and tab (consumed together).
			array(
				'type'       => CSSProcessor::TOKEN_WHITESPACE,
				'raw'        => "\r\n\t",
				'normalized' => "\n\t",
				'value'      => null,
			),
			array(
				'type'       => CSSProcessor::TOKEN_IDENT,
				'raw'        => 'background',
				'normalized' => 'background',
				'value'      => 'background',
			),
			// Colon.
			array(
				'type'       => CSSProcessor::TOKEN_COLON,
				'raw'        => ':',
				'normalized' => ':',
				'value'      => null,
			),
			// Whitespace with \f.
			array(
				'type'       => CSSProcessor::TOKEN_WHITESPACE,
				'raw'        => "\f",
				'normalized' => "\n",
				'value'      => null,
			),
			// URL token with escapes (entire url(...) is one token).
			array(
				'type'       => CSSProcessor::TOKEN_URL,
				'raw'        => 'url(path\\2f to\\2f image.png)',
				'normalized' => 'url(path/to/image.png)', // Escapes decoded.
				'value'      => 'path/to/image.png', // Decoded: \2f → /, spaces consumed.
			),
			// Semicolon.
			array(
				'type'       => CSSProcessor::TOKEN_SEMICOLON,
				'raw'        => ';',
				'normalized' => ';',
				'value'      => null,
			),
			// Whitespace with \r\n and tab (consumed together).
			array(
				'type'       => CSSProcessor::TOKEN_WHITESPACE,
				'raw'        => "\r\n\t",
				'normalized' => "\n\t",
				'value'      => null,
			),
			array(
				'type'       => CSSProcessor::TOKEN_IDENT,
				'raw'        => 'content',
				'normalized' => 'content',
				'value'      => 'content',
			),
			// Colon.
			array(
				'type'       => CSSProcessor::TOKEN_COLON,
				'raw'        => ':',
				'normalized' => ':',
				'value'      => null,
			),
			// Whitespace with \r.
			array(
				'type'       => CSSProcessor::TOKEN_WHITESPACE,
				'raw'        => "\r",
				'normalized' => "\n",
				'value'      => null,
			),
			// String with escape (\A = newline, space consumed).
			array(
				'type'       => CSSProcessor::TOKEN_STRING,
				'raw'        => '"text\\A string"',
				'normalized' => "\"text\nstring\"", // Escapes decoded, quotes preserved.
				'value'      => "text\nstring", // \A → \n, space consumed.
			),
			// Semicolon.
			array(
				'type'       => CSSProcessor::TOKEN_SEMICOLON,
				'raw'        => ';',
				'normalized' => ';',
				'value'      => null,
			),
			// Whitespace with \r\n.
			array(
				'type'       => CSSProcessor::TOKEN_WHITESPACE,
				'raw'        => "\r\n",
				'normalized' => "\n",
				'value'      => null,
			),
			// Closing brace.
			array(
				'type'       => CSSProcessor::TOKEN_RIGHT_BRACE,
				'raw'        => '}',
				'normalized' => '}',
				'value'      => null,
			),
		);

		$processor = CSSProcessor::create( $css );
		$actual_tokens = $this->collect_tokens( $processor, ['type', 'raw', 'normalized', 'value'] );
		$this->assertSame( $expected, $actual_tokens );
	}

	public function test_dimension_token_value(): void {
		$css = '10px;15em;20%;30pt;40pc;50vw;';
		$expected = array(
			array(
				'type' => CSSProcessor::TOKEN_DIMENSION,
				'raw' => '10px',
				'normalized' => '10px',
				'value' => '10',
				'unit' => 'px',
			),
			array(
				'type' => CSSProcessor::TOKEN_SEMICOLON,
				'raw' => ';',
				'normalized' => ';',
				'value' => null,
			),
			array(
				'type' => CSSProcessor::TOKEN_DIMENSION,
				'raw' => '15em',
				'normalized' => '15em',
				'value' => '15',
				'unit' => 'em',
			),
			array(
				'type' => CSSProcessor::TOKEN_SEMICOLON,
				'raw' => ';',
				'normalized' => ';',
				'value' => null,
			),
			array(
				'type' => CSSProcessor::TOKEN_PERCENTAGE,
				'raw' => '20%',
				'normalized' => '20%',
				'value' => '20',
			),
			array(
				'type' => CSSProcessor::TOKEN_SEMICOLON,
				'raw' => ';',
				'normalized' => ';',
				'value' => null,
			),
			array(
				'type' => CSSProcessor::TOKEN_DIMENSION,
				'raw' => '30pt',
				'normalized' => '30pt',
				'value' => '30',
				'unit' => 'pt',
			),
			array(
				'type' => CSSProcessor::TOKEN_SEMICOLON,
				'raw' => ';',
				'normalized' => ';',
				'value' => null,
			),
			array(
				'type' => CSSProcessor::TOKEN_DIMENSION,
				'raw' => '40pc',
				'normalized' => '40pc',
				'value' => '40',
				'unit' => 'pc',
			),
			array(
				'type' => CSSProcessor::TOKEN_SEMICOLON,
				'raw' => ';',
				'normalized' => ';',
				'value' => null,
			),
			array(
				'type' => CSSProcessor::TOKEN_DIMENSION,
				'raw' => '50vw',
				'normalized' => '50vw',
				'value' => '50',
				'unit' => 'vw',
			),
			array(
				'type' => CSSProcessor::TOKEN_SEMICOLON,
				'raw' => ';',
				'normalized' => ';',
				'value' => null,
			),
		);
		$processor = CSSProcessor::create( $css );
		$actual_tokens = $this->collect_tokens( $processor, ['type', 'raw', 'normalized', 'value', 'unit'] );
		$this->assertSame( $expected, $actual_tokens );
	}

	/**
	 * Tests that create() validates encoding and only accepts UTF-8.
	 */
	public function test_create_validates_encoding(): void {
		// UTF-8 encoding should work (default).
		$processor = CSSProcessor::create( '.class { color: red; }' );
		$this->assertInstanceOf( CSSProcessor::class, $processor );

		// UTF-8 encoding should work (explicit).
		$processor = CSSProcessor::create( '.class { color: red; }', 'UTF-8' );
		$this->assertInstanceOf( CSSProcessor::class, $processor );

		// Other encodings should return null.
		$processor = CSSProcessor::create( '.class { color: red; }', 'ISO-8859-1' );
		$this->assertNull( $processor );

		$processor = CSSProcessor::create( '.class { color: red; }', 'Windows-1252' );
		$this->assertNull( $processor );
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
				'type' => CSSProcessor::TOKEN_AT_KEYWORD,
				'raw'  => '@\\6D edia',
				'normalized' => '@media',
				'value' => 'media',
			),
			array(
				'type' => CSSProcessor::TOKEN_WHITESPACE,
				'raw'  => ' ',
				'normalized' => ' ',
				'value' => null,
			),
			// \73 creen -> screen
			array(
				'type' => CSSProcessor::TOKEN_IDENT,
				'raw'  => '\\73 creen',
				'normalized' => 'screen',
				'value' => 'screen',
			),
			array(
				'type' => CSSProcessor::TOKEN_WHITESPACE,
				'raw'  => ' ',
				'normalized' => ' ',
				'value' => null,
			),
			array(
				'type' => CSSProcessor::TOKEN_LEFT_BRACE,
				'raw'  => '{',
				'normalized' => '{',
				'value' => null,
			),
			array(
				'type' => CSSProcessor::TOKEN_WHITESPACE,
				'raw'  => ' ',
				'normalized' => ' ',
				'value' => null,
			),
			// Delimiter .
			array(
				'type' => CSSProcessor::TOKEN_DELIM,
				'raw'  => '.',
				'normalized' => '.',
				'value' => '.',
			),
			// \63 l\61 ss\5F name -> class_name
			array(
				'type' => CSSProcessor::TOKEN_IDENT,
				'raw'  => '\\63 l\\61 ss\\5F name',
				'normalized' => 'class_name',
				'value' => 'class_name',
			),
			array(
				'type' => CSSProcessor::TOKEN_WHITESPACE,
				'raw'  => ' ',
				'normalized' => ' ',
				'value' => null,
			),
			// #\69 d\5C 0test -> #id\0test (with encoded null byte)
			array(
				'type' => CSSProcessor::TOKEN_HASH,
				'raw'  => "#\\69 d\\5C 0test\x00",
				'normalized' => "#id\\0test�",
				// Ensure the value is normalized.
				'value' => "id\\0test�",
			),
			array(
				'type' => CSSProcessor::TOKEN_WHITESPACE,
				'raw'  => ' ',
				'normalized' => ' ',
				'value' => null,
			),
			array(
				'type' => CSSProcessor::TOKEN_LEFT_BRACE,
				'raw'  => '{',
				'normalized' => '{',
				'value' => null,
			),
			array(
				'type' => CSSProcessor::TOKEN_WHITESPACE,
				'raw'  => ' ',
				'normalized' => ' ',
				'value' => null,
			),
			// c\6F lor -> color
			array(
				'type' => CSSProcessor::TOKEN_IDENT,
				'raw'  => 'c\\6F lor',
				'normalized' => 'color',
				'value' => 'color',
			),
			array(
				'type' => CSSProcessor::TOKEN_COLON,
				'raw'  => ':',
				'normalized' => ':',
				'value' => null,
			),
			array(
				'type' => CSSProcessor::TOKEN_WHITESPACE,
				'raw'  => ' ',
				'normalized' => ' ',
				'value' => null,
			),
			// r\65 d -> red
			array(
				'type' => CSSProcessor::TOKEN_IDENT,
				'raw'  => 'r\\65 d',
				'normalized' => 'red',
				'value' => 'red',
			),
			array(
				'type' => CSSProcessor::TOKEN_WHITESPACE,
				'raw'  => ' ',
				'normalized' => ' ',
				'value' => null,
			),
			// \21 important -> !important (single identifier with escaped !)
			array(
				'type' => CSSProcessor::TOKEN_IDENT,
				'raw'  => '\\21 important',
				'normalized' => '!important',
				'value' => '!important',
			),
			array(
				'type' => CSSProcessor::TOKEN_SEMICOLON,
				'raw'  => ';',
				'normalized' => ';',
				'value' => null,
			),
			array(
				'type' => CSSProcessor::TOKEN_WHITESPACE,
				'raw'  => ' ',
				'normalized' => ' ',
				'value' => null,
			),
			// w\69 dth -> width
			array(
				'type' => CSSProcessor::TOKEN_IDENT,
				'raw'  => 'w\\69 dth',
				'normalized' => 'width',
				'value' => 'width',
			),
			array(
				'type' => CSSProcessor::TOKEN_COLON,
				'raw'  => ':',
				'normalized' => ':',
				'value' => null,
			),
			array(
				'type' => CSSProcessor::TOKEN_WHITESPACE,
				'raw'  => ' ',
				'normalized' => ' ',
				'value' => null,
			),
			// 10\70 x -> 10px (dimension with escaped unit)
			array(
				'type' => CSSProcessor::TOKEN_DIMENSION,
				'raw'  => '10\\70 x',
				'normalized' => '10px',
				'value' => '10',
			),
			array(
				'type' => CSSProcessor::TOKEN_SEMICOLON,
				'raw'  => ';',
				'normalized' => ';',
				'value' => null,
			),
			array(
				'type' => CSSProcessor::TOKEN_WHITESPACE,
				'raw'  => ' ',
				'normalized' => ' ',
				'value' => null,
			),
			array(
				'type' => CSSProcessor::TOKEN_IDENT,
				'raw'  => 'background',
				'normalized' => 'background',
				'value' => 'background',
			),
			array(
				'type' => CSSProcessor::TOKEN_COLON,
				'raw'  => ':',
				'normalized' => ':',
				'value' => null,
			),
			array(
				'type' => CSSProcessor::TOKEN_WHITESPACE,
				'raw'  => ' ',
				'normalized' => ' ',
				'value' => null,
			),
			// \75 rl( -> url( (escaped function name)
			array(
				'type' => CSSProcessor::TOKEN_FUNCTION,
				'raw'  => '\\75 rl(',
				'normalized' => 'url(',
				'value' => 'url',
			),
			// String with escapes: "p\61 th\2F img\2E png"
			array(
				'type' => CSSProcessor::TOKEN_STRING,
				'raw'  => '"p\\61 th\\2F img\\2E png"',
				'normalized' => '"path/img.png"',
				'value' => 'path/img.png',
			),
			array(
				'type' => CSSProcessor::TOKEN_RIGHT_PAREN,
				'raw'  => ')',
				'normalized' => ')',
				'value' => null,
			),
			array(
				'type' => CSSProcessor::TOKEN_SEMICOLON,
				'raw'  => ';',
				'normalized' => ';',
				'value' => null,
			),
			array(
				'type' => CSSProcessor::TOKEN_WHITESPACE,
				'raw'  => ' ',
				'normalized' => ' ',
				'value' => null,
			),
			array(
				'type' => CSSProcessor::TOKEN_IDENT,
				'raw'  => 'content',
				'normalized' => 'content',
				'value' => 'content',
			),
			array(
				'type' => CSSProcessor::TOKEN_COLON,
				'raw'  => ':',
				'normalized' => ':',
				'value' => null,
			),
			array(
				'type' => CSSProcessor::TOKEN_WHITESPACE,
				'raw'  => ' ',
				'normalized' => ' ',
				'value' => null,
			),
			// String with escaped backslashes: "\5C \5C " -> "\\"
			// Each \5C sequence (with trailing space consumed) becomes one backslash
			array(
				'type' => CSSProcessor::TOKEN_STRING,
				'raw'  => '"\\5C \\5C "',
				'normalized' => '"\\\\"',
				'value' => '\\\\',  // Two backslashes total
			),
			array(
				'type' => CSSProcessor::TOKEN_SEMICOLON,
				'raw'  => ';',
				'normalized' => ';',
				'value' => null,
			),
			array(
				'type' => CSSProcessor::TOKEN_WHITESPACE,
				'raw'  => ' ',
				'normalized' => ' ',
				'value' => null,
			),
			array(
				'type' => CSSProcessor::TOKEN_IDENT,
				'raw'  => 'font-family',
				'normalized' => 'font-family',
				'value' => 'font-family',
			),
			array(
				'type' => CSSProcessor::TOKEN_COLON,
				'raw'  => ':',
				'normalized' => ':',
				'value' => null,
			),
			array(
				'type' => CSSProcessor::TOKEN_WHITESPACE,
				'raw'  => ' ',
				'normalized' => ' ',
				'value' => null,
			),
			// \22 Arial\22 -> "Arial" (escaped quotes make it an ident)
			array(
				'type' => CSSProcessor::TOKEN_IDENT,
				'raw'  => '\\22 Arial\\22 ',
				'normalized' => '"Arial"',
				'value' => '"Arial"',
			),
			array(
				'type' => CSSProcessor::TOKEN_SEMICOLON,
				'raw'  => ';',
				'normalized' => ';',
				'value' => null,
			),
			array(
				'type' => CSSProcessor::TOKEN_WHITESPACE,
				'raw'  => ' ',
				'normalized' => ' ',
				'value' => null,
			),
			array(
				'type' => CSSProcessor::TOKEN_RIGHT_BRACE,
				'raw'  => '}',
				'normalized' => '}',
				'value' => null,
			),
			array(
				'type' => CSSProcessor::TOKEN_RIGHT_BRACE,
				'raw'  => '}',
				'normalized' => '}',
				'value' => null,
			),
		);

		$processor = CSSProcessor::create( $css );
		$actual_tokens = $this->collect_tokens( $processor, ['type', 'raw', 'normalized', 'value'] );
		$this->assertSame( $expected, $actual_tokens );
	}

	/**
	 * Tests that set_token_value() only works on URL tokens.
	 */
	public function test_set_token_value_only_works_on_url_tokens(): void {
		$css = 'color: red; background: url(old.jpg);';
		$processor = CSSProcessor::create( $css );

		while ( $processor->next_token() ) {
			$token_type = $processor->get_token_type();

			if ( CSSProcessor::TOKEN_URL === $token_type ) {
				// Should succeed on URL tokens.
				$this->assertTrue( $processor->set_token_value( 'new.jpg' ) );
			} else {
				// Should fail on non-URL tokens.
				$this->assertFalse( $processor->set_token_value( 'test' ) );
			}
		}

		// Verify the update was applied.
		$updated = $processor->get_updated_css();
		$this->assertSame( 'color: red; background: url("new.jpg");', $updated );
	}

	/**
	 * Tests that set_token_value() properly escapes special characters in quoted URLs.
	 */
	public function test_set_token_value_escapes_special_characters(): void {
		$css = 'background: url(old.jpg);';
		$processor = CSSProcessor::create( $css );

		while ( $processor->next_token() ) {
			if ( CSSProcessor::TOKEN_URL === $processor->get_token_type() ) {
				// URL with spaces, quotes, and parentheses.
				$processor->set_token_value( 'path with spaces("special").jpg' );
			}
		}

		$updated = $processor->get_updated_css();

		$this->assertSame( 'background: url("path with spaces(\\22 special\\22 ).jpg");', $updated );
	}

	/**
	 * Tests that set_token_value() preserves Unicode characters in quoted URLs.
	 */
	public function test_set_token_value_encodes_unicode(): void {
		$css = 'background: url(old.jpg);';
		$processor = CSSProcessor::create( $css );

		while ( $processor->next_token() ) {
			if ( CSSProcessor::TOKEN_URL === $processor->get_token_type() ) {
				// URL with Unicode characters: "测试.jpg" (Chinese characters).
				$processor->set_token_value( '测试.jpg' );
			}
		}

		$updated = $processor->get_updated_css();

		$this->assertSame( 'background: url("测试.jpg");', $updated );
	}

	/**
	 * Tests that set_token_value() preserves emoji in quoted URLs.
	 */
	public function test_set_token_value_handles_emoji(): void {
		$css = 'background: url(old.jpg);';
		$processor = CSSProcessor::create( $css );

		while ( $processor->next_token() ) {
			if ( CSSProcessor::TOKEN_URL === $processor->get_token_type() ) {
				// URL with emoji: "image😀.jpg".
				$processor->set_token_value( 'image😀.jpg' );
			}
		}

		$updated = $processor->get_updated_css();

		$this->assertSame( 'background: url("image😀.jpg");', $updated );
	}

	/**
	 * Tests that multiple URL values can be updated in the same CSS.
	 */
	public function test_set_token_value_multiple_urls(): void {
		$css = 'background: url(old1.jpg); border-image: url(old2.png);';
		$processor = CSSProcessor::create( $css );

		$url_count = 0;
		while ( $processor->next_token() ) {
			if ( CSSProcessor::TOKEN_URL === $processor->get_token_type() ) {
				$url_count++;
				if ( 1 === $url_count ) {
					$processor->set_token_value( 'new1.jpg' );
				} elseif ( 2 === $url_count ) {
					$processor->set_token_value( 'new2.png' );
				}
			}
		}

		$updated = $processor->get_updated_css();

		// Verify both URLs were updated.
		$this->assertSame( 'background: url("new1.jpg"); border-image: url("new2.png");', $updated );
	}

	/**
	 * Tests that newlines are properly escaped in quoted URLs.
	 */
	public function test_set_token_value_escapes_control_characters(): void {
		$css = 'background: url(old.jpg);';
		$processor = CSSProcessor::create( $css );

		while ( $processor->next_token() ) {
			if ( CSSProcessor::TOKEN_URL === $processor->get_token_type() ) {
				// URL with newlines and carriage returns.
				$processor->set_token_value( "path\nwith\rnewlines\ftest.jpg" );
			}
		}

		$updated = $processor->get_updated_css();

		$this->assertSame( 'background: url("path\\a with\\a newlines\\a test.jpg");', $updated );
	}

	/**
	 * Tests that backslashes are properly escaped in quoted URLs.
	 */
	public function test_set_token_value_escapes_backslashes(): void {
		$css = 'background: url(old.jpg);';
		$processor = CSSProcessor::create( $css );

		while ( $processor->next_token() ) {
			if ( CSSProcessor::TOKEN_URL === $processor->get_token_type() ) {
				$processor->set_token_value( 'path\\with\\backslashes.jpg' );
			}
		}

		$updated = $processor->get_updated_css();

		// Verify backslashes are escaped as \\.
		$this->assertSame( 'background: url("path\\5C with\\5C backslashes.jpg");', $updated );
	}

	/**
	 * Tests that get_updated_css() returns original CSS when no changes are made.
	 */
	public function test_get_updated_css_returns_original_when_unchanged(): void {
		$css = 'background: url(image.jpg); color: red;';
		$processor = CSSProcessor::create( $css );

		// Iterate through tokens without making changes.
		while ( $processor->next_token() ) {
			// Do nothing.
		}

		$updated = $processor->get_updated_css();

		// Should return original CSS.
		$this->assertSame( $css, $updated );
	}

	/**
	 * Tests that safe ASCII characters are preserved in quoted URLs.
	 */
	public function test_set_token_value_preserves_safe_characters(): void {
		$css = 'background: url(old.jpg);';
		$processor = CSSProcessor::create( $css );

		while ( $processor->next_token() ) {
			if ( CSSProcessor::TOKEN_URL === $processor->get_token_type() ) {
				// URL with safe characters: letters, digits, hyphens, underscores, dots, slashes.
				$processor->set_token_value( 'path/to/my-image_2024.jpg' );
			}
		}

		$updated = $processor->get_updated_css();

		// Verify safe characters are preserved as-is in quoted URL.
		$this->assertSame( 'background: url("path/to/my-image_2024.jpg");', $updated );
	}

	/**
	 * Tests that safe ASCII characters are preserved in quoted URLs.
	 */
	public function test_set_token_with_invalid_utf8_sequence(): void {
		$css = 'background: url(old.jpg);';
		$processor = CSSProcessor::create( $css );

		while ( $processor->next_token() ) {
			if ( CSSProcessor::TOKEN_URL === $processor->get_token_type() ) {
				// URL with safe characters: letters, digits, hyphens, underscores, dots, slashes.
				$processor->set_token_value( "\xC0.jpg" );
			}
		}

		$updated = $processor->get_updated_css();

		// Invalid UTF-8 sequence is preserved as-is – garbage in, garbage out.
		$this->assertSame( "background: url(\"\xC0.jpg\");", $updated );
	}

	/**
	 * Test bounds check when consuming and ident start token.
	 */
	public function test_ident_start_codepoint_bounds_check(): void {
		$processor     = CSSProcessor::create( '-' );
		$actual_tokens = $this->collect_tokens( $processor, array( 'type', 'raw' ) );
		$expected_tokens = array(
			array( 'type' => CSSProcessor::TOKEN_DELIM, 'raw' => '-' ),
		);
		$this->assertSame( $expected_tokens, $actual_tokens );
	}

	/**
	 * Tests that bad-string-token returns null for get_token_value().
	 *
	 * Per the CSS spec, bad-string-token has no associated data.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#tokenization
	 */
	public function test_bad_string_token_value_is_null(): void {
		// A bad-string-token is produced when a string is broken by a newline.
		$processor = CSSProcessor::create( "'str\ning'" );
		$processor->next_token();
		$this->assertSame( CSSProcessor::TOKEN_BAD_STRING, $processor->get_token_type() );
		$this->assertNull( $processor->get_token_value() );
	}

	/**
	 * Tests that decode_string_or_url() respects the token's length boundary
	 * and does not include content from beyond the token end.
	 *
	 * The escape sequence \41 (= 'A') triggers the slow path in
	 * decode_string_or_url(). The CSS string continues with "; color: red;"
	 * after the closing quote, which must not appear in the token value.
	 */
	public function test_decode_string_or_url_respects_length_boundary(): void {
		// \41 = 'A' — triggers the slow path; "; color: red;" follows the token.
		$css = '"hello\\41 world"; color: red;';

		$processor = CSSProcessor::create( $css );
		$processor->next_token();

		$this->assertSame( CSSProcessor::TOKEN_STRING, $processor->get_token_type() );
		$this->assertSame( 'helloAworld', $processor->get_token_value() );
		$this->assertSame( '"helloAworld"', $processor->get_normalized_token() );
	}

	/**
	 * Tests that decode_escape_at() consumes at most 6 hex digits, as required
	 * by the CSS Syntax Level 3 specification.
	 *
	 * A hex escape with 7 consecutive hex digits must only consume the first 6,
	 * leaving the 7th as a literal character in the string value.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#consume-escaped-code-point
	 */
	public function test_decode_escape_at_hex_limit_is_six_digits(): void {
		// \000041 is 6 hex digits → U+0041 = 'A'; the trailing '1' is literal.
		// Without the length limit, strspn() would scan 7 hex digits (0000411),
		// giving U+0411 = 'Б' (Cyrillic), which is incorrect.
		$css = '"\\0000411rest"';

		$processor = CSSProcessor::create( $css );
		$processor->next_token();

		$this->assertSame( CSSProcessor::TOKEN_STRING, $processor->get_token_type() );
		$this->assertSame( 'A1rest', $processor->get_token_value() );
	}
}
