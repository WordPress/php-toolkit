<?php

namespace WordPress\DataLiberation\URL;

use function WordPress\Encoding\utf8_codepoint_at;

/**
 * Converts a Unicode codepoint to its UTF-8 byte sequence.
 *
 * @param int $codepoint Unicode codepoint value.
 * @return string UTF-8 encoded byte sequence.
 */
function codepoint_to_utf8( int $codepoint ): string {
	// Invalid codepoints: zero, surrogates, or beyond Unicode range
	if ( 0 === $codepoint || $codepoint > 0x10FFFF || ( $codepoint >= 0xD800 && $codepoint <= 0xDFFF ) ) {
		return "\xEF\xBF\xBD"; // U+FFFD REPLACEMENT CHARACTER
	}

	// 1-byte sequence (ASCII): 0x00-0x7F
	if ( $codepoint < 0x80 ) {
		return chr( $codepoint );
	}

	// 2-byte sequence: 0x80-0x7FF
	if ( $codepoint < 0x800 ) {
		return chr( 0xC0 | ( $codepoint >> 6 ) ) .
		       chr( 0x80 | ( $codepoint & 0x3F ) );
	}

	// 3-byte sequence: 0x800-0xFFFF
	if ( $codepoint < 0x10000 ) {
		return chr( 0xE0 | ( $codepoint >> 12 ) ) .
		       chr( 0x80 | ( ( $codepoint >> 6 ) & 0x3F ) ) .
		       chr( 0x80 | ( $codepoint & 0x3F ) );
	}

	// 4-byte sequence: 0x10000-0x10FFFF
	return chr( 0xF0 | ( $codepoint >> 18 ) ) .
	       chr( 0x80 | ( ( $codepoint >> 12 ) & 0x3F ) ) .
	       chr( 0x80 | ( ( $codepoint >> 6 ) & 0x3F ) ) .
	       chr( 0x80 | ( $codepoint & 0x3F ) );
}

/**
 * Tokenizes CSS according to the CSS Syntax Level 3 specification.
 *
 * This class implements the CSS tokenization algorithm as defined in:
 * https://www.w3.org/TR/css-syntax-3/
 *
 * @see https://www.w3.org/TR/css-syntax-3/#tokenization
 */
class CSSProcessor {
	/**
	 * Token type constants matching the CSS Syntax Level 3 specification.
	 * @see https://www.w3.org/TR/css-syntax-3/#tokenization
	 */
	public const TOKEN_WHITESPACE      = 'whitespace-token';
	public const TOKEN_COMMENT         = 'comment';
	public const TOKEN_STRING          = 'string-token';
	/**
	 * BAD-STRING tokens occur when a string contains an unescaped newline.
	 *
	 * Valid strings: "hello", 'world', "line1\Aline2" (escaped newline)
	 * Invalid (produces bad-string): "hello
	 *                                 world"  (literal newline breaks the string)
	 *
	 * The tokenizer stops at the newline and produces a bad-string token for error recovery.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#typedef-bad-string-token
	 */
	public const TOKEN_BAD_STRING      = 'bad-string-token';
	public const TOKEN_HASH            = 'hash-token';
	public const TOKEN_DELIM           = 'delim-token';
	public const TOKEN_NUMBER          = 'number-token';
	public const TOKEN_PERCENTAGE      = 'percentage-token';
	public const TOKEN_DIMENSION       = 'dimension-token';
	public const TOKEN_AT_KEYWORD      = 'at-keyword-token';
	public const TOKEN_COLON           = 'colon-token';
	public const TOKEN_SEMICOLON       = 'semicolon-token';
	public const TOKEN_COMMA           = 'comma-token';
	public const TOKEN_LEFT_PAREN      = '(-token';
	public const TOKEN_RIGHT_PAREN     = ')-token';
	public const TOKEN_LEFT_BRACKET    = '[-token';
	public const TOKEN_RIGHT_BRACKET   = ']-token';
	public const TOKEN_LEFT_BRACE      = '{-token';
	public const TOKEN_RIGHT_BRACE     = '}-token';
	public const TOKEN_FUNCTION        = 'function-token';
	/**
	 * URL tokens represent unquoted URLs in url() notation.
	 *
	 * Valid: url(image.jpg), url(https://example.com)
	 * Quoted URLs are parsed as url( + string-token + ), not url-token.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#typedef-url-token
	 */
	public const TOKEN_URL             = 'url-token';
	/**
	 * BAD-URL tokens occur when a URL contains invalid characters.
	 *
	 * Invalid characters: quotes ("), apostrophes ('), parentheses (()
	 * Example invalid: url(image(.jpg) or url(image".jpg)
	 *
	 * When detected, the tokenizer consumes everything up to ) or EOF.
	 * This prevents the bad URL from breaking subsequent tokens.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#typedef-bad-url-token
	 */
	public const TOKEN_BAD_URL         = 'bad-url-token';

	/**
	 * Identifier tokens, such as `color`, `margin-top`, `red`,
	 * `inherit`, `--my-var`, `\escaped`, `über` (Unicode), etc.
	 * 
	 * They can contain: letters, digits, hyphens, underscores, non-ASCII, escapes
	 * and cannot start with a digit (unless preceded by a hyphen).
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#typedef-ident-token
	 */
	public const TOKEN_IDENT           = 'ident-token';

	/**
	 * CDC (Comment Delimiter Close) token: -->
	 *
	 * Legacy token from when CSS was embedded in HTML <style> tags
	 * and needed to be hidden from old browsers using HTML comments:
	 *
	 *   <style>
	 *   <!--
	 *   body { color: red; }
	 *   -->
	 *   </style>
	 *
	 * Modern CSS no longer needs these, but they're preserved for compatibility.
	 * In stylesheets, they're typically treated like whitespace.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#typedef-CDC-token
	 */
	public const TOKEN_CDC             = 'CDC-token';

	/**
	 * CDO (Comment Delimiter Open) token: <!--
	 *
	 * Legacy token from when CSS was embedded in HTML <style> tags.
	 * See TOKEN_CDC for full explanation of HTML comment compatibility.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#typedef-CDO-token
	 */
	public const TOKEN_CDO             = 'CDO-token';
	/**
	 * EOF (End Of File) token marks the end of the input stream.
	 *
	 * This implementation returns false from next_token() instead of producing
	 * an explicit EOF token, but the concept is the same.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#typedef-eof-token
	 */
	public const TOKEN_EOF             = 'EOF-token';

	/**
	 * @var string
	 */
	private $css;

	/**
	 * @var int
	 */
	private $length = 0;

	/**
	 * @var int
	 */
	private $at = 0;

	/**
	 * Cached codepoint at the current position ($this->at).
	 * Used to avoid repeatedly decoding the same UTF-8 sequence.
	 *
	 * @var int|null
	 */
	private $current_codepoint = null;

	/**
	 * Cached byte length of the current codepoint.
	 *
	 * @var int
	 */
	private $current_codepoint_bytes = 0;

	/**
	 * The byte offset for which the codepoint is cached.
	 *
	 * @var int
	 */
	private $current_codepoint_offset = -1;

	// Current token properties
	/**
	 * The type of the current token. One of the self::TOKEN_* constants.
	 *
	 * @var string|null
	 */
	private $token_type              = null;
	/**
	 * The byte offset at which the current token starts.
	 * 
	 * Example:
	 * 
	 * background-image: url(https://example.com/image.jpg);
	 *                   ^ token_starts_at
	 *
	 * @var int|null
	 */
	private $token_starts_at         = null;
	/**
	 * The byte length of the current token.
	 *
	 * Example:
	 * 
	 * background-image: url(https://example.com/image.jpg);
	 *                   ^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^
	 *                             token_length
	 *
	 * @var int|null
	 */
	private $token_length            = null;
	/**
	 * The string value of the current token.
	 *
	 * @var string|null
	 */
	private $token_value             = null;
	private $token_name              = null;
	private $token_unit              = null;
	/**
	 * The byte offset at which the value of the current token starts.
	 * It's used for STRING and URL tokens. For example:
	 * 
	 * background-image: url(https://example.com/image.jpg);
	 *                       ^ token_value_starts_at
	 *
	 * @var int|null
	 */
	private $token_value_starts_at   = null;
	/**
	 * The byte offset at which the value of the current token starts.
	 * It's relevant for STRING and URL tokens. For example:
	 * 
	 * background-image: url(https://example.com/image.jpg);
	 *                       ^^^^^^^^^^^^^^^^^^^^^^^^^^^^^
	 *                             token_value_length
	 *
	 * @var int|null
	 */
	private $token_value_length      = null;

	/**
	 * @param string $css CSS source to tokenize.
	 */
	public function __construct( string $css ) {
		$this->css    = $css;
		$this->length = strlen( $css );
	}

	/**
	 * Moves to the next token in the CSS stream.
	 *
	 * Implements the main tokenization loop, consuming the next token from the input stream.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#consume-token
	 *
	 * @return bool Whether a token was found.
	 */
	public function next_token(): bool {
		$this->after_token();

		// Bale out once we reach the end.
		if ( $this->at >= $this->length ) {
			return false;
		}

		/*
		 * CSS comments. They are not preserved as tokens in the specification, but we
		 * still track them.
		 *
		 * @see https://www.w3.org/TR/css-syntax-3/#consume-comment
		 */
		if (
			$this->at + 1 < $this->length &&
			'/' === $this->css[ $this->at ] &&
			'*' === $this->css[ $this->at + 1 ]
		) {
			$this->token_type   = self::TOKEN_COMMENT;
			$this->token_starts_at = $this->at;
			$this->token_value_starts_at = $this->at;

			$end = strpos( $this->css, '*/', $this->at + 2 );
			$this->at = false !== $end ? $end + 2 : $this->length;
			$this->token_length = $this->at - $this->token_starts_at;
			$this->token_value_length = $this->token_length - 4;
			return true;
		}

		/*
		 * Whitespace tokens.
		 * 
		 * We consider U+000A LINE FEED, U+0009 CHARACTER TABULATION, and U+0020 SPACE bytes covered by the spec.
		 * In addition, we also capture U+000D CARRIAGE RETURN and U+000C FORM FEED that are normally converted to
		 * U+000A LINE FEED during the preprocessing phase.
		 *
		 * @see https://www.w3.org/TR/css-syntax-3/#newline
		 * @see https://www.w3.org/TR/css-syntax-3/#whitespace
		 */
		$whitespace_length = strspn( $this->css, "\t\n\f\r ", $this->at );
		if ( $whitespace_length > 0 ) {
			$this->token_type   = self::TOKEN_WHITESPACE;
			$this->token_length = $whitespace_length;
			$this->token_starts_at = $this->at;
			$this->at    += $whitespace_length;
			return true;
		}

		/*
		 * String tokens with either " or ' as delimiters.
		 *
		 * @see https://www.w3.org/TR/css-syntax-3/#consume-string-token
		 */
		if ( '"' === $this->css[ $this->at ] || "'" === $this->css[ $this->at ] ) {
			return $this->consume_string();
		}

		$char = $this->css[ $this->at ];
		$this->token_starts_at = $this->at;

		/*
		 * U+0023 NUMBER SIGN (#)
		 *
		 * A hash token is created when # is followed by an ident code point or valid escape.
		 * This is commonly used for hex colors (#fff) or ID selectors (#header).
		 *
		 * @see https://www.w3.org/TR/css-syntax-3/#consume-token
		 */
		if ( '#' === $char ) {
			if ( 
				$this->at + 1 < $this->length &&
				(
					$this->is_ident_code_point_at( $this->at + 1 ) ||
					// the next two input code points are a valid escape:
					$this->is_valid_escape( $this->at + 1 )
				)
			) {
				// Create a <hash-token>.
				$this->at++;

				// We skip this check as we don't track the type flag:
				// > If the next 3 input code points would start an ident sequence,
				// > set the <hash-token>'s type flag to "id".

				// Consume an ident sequence, and set the <hash-token>'s value to the returned string.
				$this->token_name   = $this->consume_ident_sequence();
				$this->token_type   = self::TOKEN_HASH;
				$this->token_length = $this->at - $this->token_starts_at;
				return true;
			}
			// Otherwise, return a <delim-token> with its value set to the current input code point.
			$this->at++;
			$this->token_type   = self::TOKEN_DELIM;
			$this->token_length = 1;
			return true;
		}

		/*
		 * Simple single-byte tokens
		 *
		 * These characters form their own tokens when encountered.
		 * Note: ( tokens here are not function tokens - those are handled
		 * in consume_ident_like() when ( follows an identifier.
		 *
		 * @see https://www.w3.org/TR/css-syntax-3/#tokenization
		 */
		$simple = array(
			'(' => self::TOKEN_LEFT_PAREN,
			')' => self::TOKEN_RIGHT_PAREN,
			',' => self::TOKEN_COMMA,
			':' => self::TOKEN_COLON,
			';' => self::TOKEN_SEMICOLON,
			'[' => self::TOKEN_LEFT_BRACKET,
			']' => self::TOKEN_RIGHT_BRACKET,
			'{' => self::TOKEN_LEFT_BRACE,
			'}' => self::TOKEN_RIGHT_BRACE,
		);
		if ( isset( $simple[ $char ] ) ) {
			$this->at++;
			$this->token_type   = $simple[ $char ];
			$this->token_length = 1;
			return true;
		}

		/*
		 * U+0040 COMMERCIAL AT (@)
		 *
		 * An at-keyword is @ followed by an identifier, used for at-rules like
		 * @media, @import, @keyframes, etc.
		 *
		 * @see https://www.w3.org/TR/css-syntax-3/#consume-token
		 */
		if ( '@' === $char ) {
			++$this->at;
			// If the next 3 input code points after the @ would start an ident sequence,
			// consume an ident sequence, create an <at-keyword-token> with its value set to the returned value,
			// and return it
			if ( $this->would_next_3_code_points_start_an_ident( $this->at ) ) {
				$this->token_name   = $this->consume_ident_sequence();
				$this->token_type   = self::TOKEN_AT_KEYWORD;
				$this->token_length = $this->at - $this->token_starts_at;
				return true;
			} else {
				// Otherwise, return a <delim-token> with its value set to the current input code point.
				$this->token_type   = self::TOKEN_DELIM;
				$this->token_length = 1;
				return true;
			}
		}

		/*
		 * Numbers start with digits, the plus sign, minus sign, and decimal point.
		 *
		 * @see https://www.w3.org/TR/css-syntax-3/#starts-with-a-number
		 */
		if ( strspn( $char, '+-.0123456789' ) > 0 && $this->would_start_number() ) {
			return $this->consume_numeric();
		}

		/*
		 * U+002D HYPHEN-MINUS (-)
		 * If followed by another hyphen and >, this is a CDC token (-->)
		 *
		 * Comment Delimiter Close - legacy HTML comment syntax in CSS.
		 *
		 * @see https://www.w3.org/TR/css-syntax-3/#CDC-token-diagram
		 */
		if (
			'-' === $char && $this->at + 2 < $this->length &&
			'-' === $this->css[ $this->at + 1 ] &&
			'>' === $this->css[ $this->at + 2 ]
		) {
			// Consume them and return a <CDC-token>.
			$this->at    += 3;
			$this->token_type   = self::TOKEN_CDC;
			$this->token_length = 3;
			return true;
		}

		/*
		 * U+003C LESS-THAN SIGN (<)
		 * If followed by !--, this is a CDO token (<!--)
		 *
		 * Comment Delimiter Open - legacy HTML comment syntax in CSS.
		 *
		 * @see https://www.w3.org/TR/css-syntax-3/#CDO-token-diagram
		 */
		if ( '<' === $char && $this->at + 3 < $this->length &&
		     '!' === $this->css[ $this->at + 1 ] &&
		     '-' === $this->css[ $this->at + 2 ] &&
		     '-' === $this->css[ $this->at + 3 ] ) {
			// Consume them and return a <CDO-token>.
			$this->at    += 4;
			$this->token_type   = self::TOKEN_CDO;
			$this->token_length = 4;
			return true;
		}

		// @ADAM reviewed up to here

		/*
		 * Ident-start code point
		 *
		 * If the input stream starts with an ident sequence, reconsume the current
		 * input code point, consume an ident-like token, and return it.
		 *
		 * Could be an identifier, function, or url() token.
		 *
		 * @see https://www.w3.org/TR/css-syntax-3/#consume-ident-like-token
		 */
		if ( $this->would_next_3_code_points_start_an_ident( $this->at ) ) {
			return $this->consume_ident_like();
		}

		/*
		 * Delim token (delimiter)
		 *
		 * Any code point that doesn't match above rules becomes a delim token.
		 * Handle multi-byte UTF-8 characters properly.
		 *
		 * @see https://www.w3.org/TR/css-syntax-3/#delim-token-diagram
		 */
		if ( ord( $char ) >= 0x80 ) {
			$matched_bytes     = 0;
			$this->get_codepoint_at( $this->at, $matched_bytes );

			// Safeguard: if get_codepoint_at fails to advance, skip 1 byte to prevent infinite loop
			if ( 0 === $matched_bytes ) {
				$matched_bytes = 1;
			}

			$this->at    += $matched_bytes;
			$this->token_type   = self::TOKEN_DELIM;
			$this->token_length = $matched_bytes;
			return true;
		}

		// Single ASCII delim
		$this->at++;
		$this->token_type   = self::TOKEN_DELIM;
		$this->token_length = 1;
		return true;
	}

	/**
	 * Gets the current token type.
	 *
	 * @return string|null
	 */
	public function get_token_type(): ?string {
		return $this->token_type;
	}

	/**
	 * Gets the current token value (for numbers).
	 *
	 * @return float|null
	 */
	public function get_token_value() {
		return $this->token_value;
	}

	/**
	 * Gets the current token name (for functions).
	 *
	 * @return string|null
	 */
	public function get_token_name(): ?string {
		return $this->token_name;
	}

	/**
	 * Gets the raw token text from the CSS source.
	 *
	 * @return string|null
	 */
	public function get_token_raw(): ?string {
		if ( null === $this->token_starts_at || null === $this->token_length ) {
			return null;
		}
		return substr( $this->css, $this->token_starts_at, $this->token_length );
	}

	/**
	 * Gets the token start at.
	 *
	 * @return int|null
	 */
	public function get_token_start(): ?int {
		return $this->token_starts_at;
	}

	/**
	 * Gets the token length.
	 *
	 * @return int|null
	 */
	public function get_token_length(): ?int {
		return $this->token_length;
	}

	/**
	 * Gets the unit for dimension tokens.
	 *
	 * @return string|null
	 */
	public function get_token_unit(): ?string {
		return $this->token_unit;
	}

	/**
	 * Gets the byte at where the token value starts (for STRING and URL tokens).
	 *
	 * @return int|null
	 */
	public function get_token_value_start(): ?int {
		return $this->token_value_starts_at;
	}

	/**
	 * Gets the byte length of the token value (for STRING and URL tokens).
	 *
	 * @return int|null
	 */
	public function get_token_value_length(): ?int {
		return $this->token_value_length;
	}

	/**
	 * Clears token state between tokens.
	 */
	private function after_token(): void {
		$this->token_type              = null;
		$this->token_starts_at         = null;
		$this->token_length            = null;
		$this->token_value             = null;
		$this->token_name              = null;
		$this->token_unit              = null;
		$this->token_value_starts_at   = null;
		$this->token_value_length      = null;
	}

	/**
	 * Gets the Unicode codepoint at the given byte offset, with caching.
	 *
	 * This method caches the result to avoid repeatedly decoding the same UTF-8
	 * sequence when multiple helpers need to check the same position.
	 *
	 * @param int $offset Byte offset in the CSS string.
	 * @param int &$matched_bytes Output parameter: number of bytes consumed.
	 * @return int|null The Unicode codepoint value, or null if invalid UTF-8.
	 */
	private function get_codepoint_at( int $offset, &$matched_bytes ): ?int {
		// Check if we have a cached value for this offset
		if ( $offset === $this->current_codepoint_offset ) {
			$matched_bytes = $this->current_codepoint_bytes;
			return $this->current_codepoint;
		}

		// Decode the UTF-8 sequence
		$codepoint = utf8_codepoint_at( $this->css, $offset, $matched_bytes );

		// Cache the result
		$this->current_codepoint = $codepoint;
		$this->current_codepoint_bytes = $matched_bytes;
		$this->current_codepoint_offset = $offset;

		return $codepoint;
	}

	/**
	 * Consumes a string token.
	 *
	 * Strings are quoted with either " or ' and can contain escape sequences.
	 * Newlines inside strings (without escaping) make the string invalid.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#consume-string-token
	 *
	 * @param int $ending_code_point Ending delimiter code point.
	 * @return bool
	 */
	private function consume_string(): bool {
		// Initially create a <string-token> with its value set to the empty string.
		$this->token_starts_at = $this->at;
		$ending_char = $this->css[ $this->at ];

		// Skip past the opening quote.
		$this->at++;
		$value_starts_at = $this->at;

		// Characters that need special handling: the ending quote, newlines, backslashes.
		$special_chars = $ending_char === "'" ? "'\n\f\r\\" : "\"\n\f\r\\";

		while ( $this->at < $this->length ) {
			// Consume normal characters until we hit a special character.
			$normal_len = strcspn( $this->css, $special_chars, $this->at );
			$this->at += $normal_len;

			if ( $this->at >= $this->length ) {
				break; // EOF
			}

			$char = $this->css[ $this->at ];
			switch ( $char ) {
				case $ending_char:
					// Ending quote.
					// Return the <string-token>.
					$this->at++;
					$this->token_type              = self::TOKEN_STRING;
					$this->token_length            = $this->at - $this->token_starts_at;
					$this->token_value_starts_at   = $value_starts_at;
					$this->token_value_length      = $this->at - $value_starts_at - 1;
					return true;

				case "\n":
				case "\f":
				case "\r":
					/*
					 * Newline.
					 *
					 * This is a parse error. Reconsume the current input code point,
					 * create a <bad-string-token>, and return it.
					 *
					 * Unescaped newlines are not allowed in strings. To include a newline,
					 * it must be escaped as \A or the string must end and a new one begin.
					 *
					 * @see https://www.w3.org/TR/css-syntax-3/#consume-string-token
					 */
					$this->token_type              = self::TOKEN_BAD_STRING;
					$this->token_length            = $this->at - $this->token_starts_at;
					$this->token_value_starts_at   = $value_starts_at;
					$this->token_value_length      = $this->at - $value_starts_at;
					return true;

				case '\\':
					// U+005C REVERSE SOLIDUS (\)
					// If the next input code point is EOF, do nothing.
					// Otherwise, if the next input code point is a newline, consume it.
					// Otherwise, (the stream starts with a valid escape) consume an escaped
					// code point and append the returned code point to the <string-token>'s value.
					if ( $this->is_valid_escape( $this->at ) ) {
						$this->at++;
						$this->consume_escape();
						continue 2;
					}
					// Handle escaped newline (not counted as valid escape by is_valid_escape)
					$this->at++;
					if ( $this->at < $this->length ) {
						$next = $this->css[ $this->at ];
						if ( "\n" === $next || "\f" === $next ) {
							$this->at++;
						} elseif ( "\r" === $next ) {
							$this->at++;
							// Handle \r\n as a single newline.
							// In a fully spec-compliant parser, \r\n pairs would be replaced with a single
							// newline, but we're not doing input pre-processing here to save memory.
							// https://www.w3.org/TR/css-syntax-3/#input-preprocessing
							if ( $this->at < $this->length && "\n" === $this->css[ $this->at ] ) {
								$this->at++;
							}
						}
					}
					continue 2;

				default:
					_doing_it_wrong( __METHOD__, 'Unexpected character in string: ' . $char, '1.0.0' );
					break;
			}
		}

		// EOF
		// This is a parse error. Return the <string-token>.
		$this->token_type              = self::TOKEN_STRING;
		$this->token_length            = $this->at - $this->token_starts_at;
		$this->token_value_starts_at   = $value_starts_at;
		$this->token_value_length      = $this->at - $value_starts_at;
		return true;
	}

	/**
	 * Consumes a numeric token (number, percentage, dimension).
	 *
	 * Numbers can be integers or decimals, with optional sign and exponent.
	 * They can be followed by % (percentage) or an identifier (dimension).
	 *
	 * @TODO: Keep track of the "type" flag ("integer" or "number").
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#consume-numeric-token
	 * @see https://www.w3.org/TR/css-syntax-3/#consume-number
	 *
	 * @return bool
	 */
	private function consume_numeric(): bool {
		// Consume a number and let number be the result.
		$start = $this->at;

		// If the next input code point is U+002B PLUS SIGN (+) or U+002D HYPHEN-MINUS (-),
		// consume it and append it to repr.
		if ( '+' === $this->css[ $this->at ] || '-' === $this->css[ $this->at ] ) {
			$this->at++;
		}

		// While the next input code point is a digit, consume it and append it to repr.
		$digits = strspn( $this->css, '0123456789', $this->at );
		if ( $digits > 0 ) {
			$this->at += $digits;
		}

		// If the next 2 input code points are U+002E FULL STOP (.) followed by a digit, then:
		if ( $this->at + 1 < $this->length &&
		     '.' === $this->css[ $this->at ] &&
		     $this->css[ $this->at + 1 ] >= '0' &&
		     $this->css[ $this->at + 1 ] <= '9'
		) {
			// Consume them.
			$this->at++;
			// While the next input code point is a digit, consume it and append it to repr.
			$digits = strspn( $this->css, '0123456789', $this->at );
			if ( $digits > 0 ) {
				$this->at += $digits;
			}
		}

		// If the next 2 or 3 input code points are U+0045 LATIN CAPITAL LETTER E (E)
		// or U+0065 LATIN SMALL LETTER E (e), optionally followed by U+002D HYPHEN-MINUS (-)
		// or U+002B PLUS SIGN (+), followed by a digit, then:
		if ( $this->at < $this->length ) {
			$e = $this->css[ $this->at ];
			if ( 'e' === $e || 'E' === $e ) {
				$save_pos = $this->at;
				$this->at++;
				$has_exp = false;

				if ( $this->at < $this->length ) {
					$next = $this->css[ $this->at ];
					if ( ( '+' === $next || '-' === $next ) && $this->at + 1 < $this->length &&
					     $this->css[ $this->at + 1 ] >= '0' && $this->css[ $this->at + 1 ] <= '9' ) {
						// Consume them.
						$this->at++;
						$has_exp = true;
					} elseif ( $next >= '0' && $next <= '9' ) {
						$has_exp = true;
					}
				}

				if ( $has_exp ) {
					// While the next input code point is a digit, consume it and append it to repr.
					$digits = strspn( $this->css, '0123456789', $this->at );
					if ( $digits > 0 ) {
						$this->at += $digits;
					}
				} else {
					$this->at = $save_pos;
				}
			}
		}

		// Convert string to a number, and set the value to the returned value.
		// We use a PHP typecast as it's mostly compatible with the spec's behavior.
		// @TODO: Investigate any differences
		// @see https://www.w3.org/TR/css-syntax-3/#convert-a-string-to-a-number
		$this->token_value = (float) substr( $this->css, $start, $this->at - $start );

		/**
		 * This is the end of spec section 4.3.12. Consume a number.
		 * We still have some work to do as specified in section 4.3.3. Consume a numeric token:
		 * https://www.w3.org/TR/css-syntax-3/#consume-numeric-token
		 */

		// If the next 3 input code points would start an ident sequence, then:
		if ( $this->would_next_3_code_points_start_an_ident( $this->at ) ) {
			// Create a <dimension-token> with the same value and type flag as number,
			// and a unit set initially to the empty string.
			// Consume an ident sequence. Set the <dimension-token>'s unit to the returned value.
			$this->token_unit   = $this->consume_ident_sequence();
			$this->token_type   = self::TOKEN_DIMENSION;
			$this->token_length = $this->at - $this->token_starts_at;
			return true;
		}

		// Otherwise, if the next input code point is U+0025 PERCENTAGE SIGN (%), consume it.
		// Create a <percentage-token> with the same value as number, and return it.
		if ( $this->at < $this->length && '%' === $this->css[ $this->at ] ) {
			$this->at++;
			$this->token_type   = self::TOKEN_PERCENTAGE;
			$this->token_length = $this->at - $this->token_starts_at;
			return true;
		}

		// Otherwise, create a <number-token> with the same value and type flag as number, and return it.
		$this->token_type   = self::TOKEN_NUMBER;
		$this->token_length = $this->at - $this->token_starts_at;
		return true;
	}

	/**
	 * Consumes an ident-like token (function, url, ident).
	 *
	 * After consuming an identifier, checks if it's followed by '(' to determine
	 * if it's a function or url() token, otherwise it's a plain identifier.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#consume-ident-like-token
	 *
	 * @return bool
	 */
	private function consume_ident_like(): bool {
		// Consume an ident sequence, and let string be the result.
		$string = $this->consume_ident_sequence();

		// If string's value is an ASCII case-insensitive match for "url",
		// and the next input code point is U+0028 LEFT PARENTHESIS (()
		if ( 0 === strcasecmp( $string, 'url' ) && $this->at < $this->length && '(' === $this->css[ $this->at ] ) {
			// consume it
			$this->at++;

			// While the next two input code points are whitespace, consume the next input code point.
			$ws_len = strspn( $this->css, "\t\n\f\r ", $this->at );

			// If the next one or two input code points are U+0022 QUOTATION MARK ("),
			// U+0027 APOSTROPHE ('), or whitespace followed by U+0022 QUOTATION MARK (")
			// or U+0027 APOSTROPHE (')
			if ( $this->at + $ws_len < $this->length ) {
				$next = $this->css[ $this->at + $ws_len ];
				if ( '"' === $next || "'" === $next ) {
					// then create a <function-token> with its value set to string and return it.
					$this->token_type   = self::TOKEN_FUNCTION;
					$this->token_name   = $string;
					$this->token_length = $this->at - $this->token_starts_at;
					return true;
				}
			}

			// Otherwise, consume a url token, and return it.
			$this->at += $ws_len;
			return $this->consume_url();
		}

		// Otherwise, if the next input code point is U+0028 LEFT PARENTHESIS (()
		if ( $this->at < $this->length && '(' === $this->css[ $this->at ] ) {
			// consume it
			$this->at++;
			// Create a <function-token> with its value set to string and return it.
			$this->token_type   = self::TOKEN_FUNCTION;
			$this->token_name   = $string;
			$this->token_length = $this->at - $this->token_starts_at;
			return true;
		}

		// Otherwise, create an <ident-token> with its value set to string and return it.
		$this->token_type   = self::TOKEN_IDENT;
		$this->token_name   = $string;
		$this->token_length = $this->at - $this->token_starts_at;
		return true;
	}

	/**
	 * Consumes a url token.
	 *
	 * URL tokens can contain unquoted URLs with escape sequences but not quotes,
	 * parentheses, or certain control characters. Invalid characters create a
	 * bad-url token.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#consume-url-token
	 *
	 * @return bool
	 */
	private function consume_url(): bool {
		// Initially create a <url-token> with its value set to the empty string.
		// Consume as much whitespace as possible.
		$ws_len = strspn( $this->css, "\t\n\f\r ", $this->at );
		$this->at += $ws_len;

		$value_starts_at = $this->at;
		$value = '';

		// Repeatedly consume the next input code point from the stream:
		while ( $this->at < $this->length ) {
			// U+0029 RIGHT PARENTHESIS ())
			// Return the <url-token>.
			if ( ')' === $this->css[ $this->at ] ) {
				$this->at++;
				$this->token_type              = self::TOKEN_URL;
				$this->token_length            = $this->at - $this->token_starts_at;
				$this->token_value_starts_at   = $value_starts_at;
				$this->token_value_length      = $this->at - $value_starts_at - 1;
				return true;
			}

			// whitespace
			// Consume as much whitespace as possible. If the next input code point is
			// U+0029 RIGHT PARENTHESIS ()) or EOF, consume it and return the <url-token>
			// (if EOF was encountered, this is a parse error); otherwise, consume the
			// remnants of a bad url, create a <bad-url-token>, and return it.
			$ws_len = strspn( $this->css, "\t\n\f\r ", $this->at );
			if ( $ws_len > 0 ) {
				$value_ends_at = $this->at;
				$this->at += $ws_len;
				// Accept either ) or EOF after whitespace
				if ( $this->at >= $this->length ) {
					// EOF after whitespace - valid URL
					$this->token_type              = self::TOKEN_URL;
					$this->token_length            = $this->at - $this->token_starts_at;
					$this->token_value_starts_at   = $value_starts_at;
					$this->token_value_length      = $value_ends_at - $value_starts_at;
					return true;
				}
				if ( ')' === $this->css[ $this->at ] ) {
					$this->at++;
					$this->token_type              = self::TOKEN_URL;
					$this->token_length            = $this->at - $this->token_starts_at;
					$this->token_value_starts_at   = $value_starts_at;
					$this->token_value_length      = $value_ends_at - $value_starts_at;
					return true;
				}
				return $this->consume_remnants_of_bad_url();
			}

			// These codepoints trigger a parse error:
			$byte = ord( $this->css[ $this->at ] );
			if (
				'"' === $this->css[ $this->at ] ||
				"'" === $this->css[ $this->at ] ||
				'(' === $this->css[ $this->at ] ||
				$byte <= 0x0008 || // non-printable code point
				$byte === 0x0B || // Line Tabulation
			    ( $byte >= 0x000E && $byte <= 0x001F ) ||
				$byte === 0x7F // Delete
			) {
				// Consume the remnants of a bad url,
				// create a <bad-url-token>, and return it.
				return $this->consume_remnants_of_bad_url();
			}

			// U+005C REVERSE SOLIDUS (\)
			// If the stream starts with a valid escape, consume an escaped code point and
			// append the returned code point to the <url-token>'s value.
			if ( '\\' === $this->css[ $this->at ] ) {
				if ( $this->is_valid_escape( $this->at ) ) {
					$this->at++;
					$value .= $this->consume_escape();
					continue;
				}
				// Otherwise, this is a parse error. Consume the remnants of a bad url,
				// create a <bad-url-token>, and return it.
				return $this->consume_remnants_of_bad_url();
			}

			// anything else
			// Append the current input code point to the <url-token>'s value.
			$matched_bytes = 0;
			$this->get_codepoint_at( $this->at, $matched_bytes );

			// Safeguard: if get_codepoint_at fails to advance, skip 1 byte to prevent infinite loop
			if ( 0 === $matched_bytes ) {
				$matched_bytes = 1;
			}

			$value         .= substr( $this->css, $this->at, $matched_bytes );
			$this->at += $matched_bytes;
		}

		// EOF
		// This is a parse error. Return the <url-token>.
		$this->token_type              = self::TOKEN_URL;
		$this->token_length            = $this->at - $this->token_starts_at;
		$this->token_value_starts_at   = $value_starts_at;
		$this->token_value_length      = $this->at - $value_starts_at;
		return true;
	}

	/**
	 * Finishes a bad url token by consuming remnants.
	 *
	 * When an invalid character is encountered in a URL, we must consume
	 * the remainder of the URL up to the closing ) or EOF.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#consume-remnants-of-bad-url
	 *
	 * @return bool
	 */
	private function consume_remnants_of_bad_url(): bool {
		while ( $this->at < $this->length ) {
			$this->at += strcspn( $this->css, ")\\", $this->at );

			if ( '\\' === $this->css[ $this->at ] ) {
				$this->at++;
				if($this->is_valid_escape( $this->at - 1 )) {
					$this->consume_escape();
					continue;
				}
			} else if ( ')' === $this->css[ $this->at ] ) {
				$this->at++;
				break;
			}
		}

		$this->token_type   = self::TOKEN_BAD_URL;
		$this->token_length = $this->at - $this->token_starts_at;
		return true;
	}

	/**
	 * Consumes an identifier sequence.
	 *
	 * Identifiers can contain letters, digits, hyphens, underscores, non-ASCII
	 * characters, and escape sequences. Null bytes are replaced with U+FFFD.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#consume-name
	 *
	 * @return string
	 */
	private function consume_ident_sequence(): string {
		$result = '';

		while ( $this->at < $this->length ) {
			$codepoint_bytes = $this->consume_ident_codepoint( $this->at );
			if ( $codepoint_bytes > 0 ) {
				$result .= substr( $this->css, $this->at, $codepoint_bytes );
				$this->at += $codepoint_bytes;
				continue;
			}

			if ( $this->is_valid_escape( $this->at ) ) {
				$this->at++;
				$result .= $this->consume_escape();
				continue;
			}

			break;
		}

		return $result;
	}

	/**
	 * ident-start code point
	 *     A letter, a non-ASCII code point, or U+005F LOW LINE (_).
	 *
	 * ident code point
	 *     An ident-start code point, a digit, or U+002D HYPHEN-MINUS (-).
	 * 
	 * @see https://www.w3.org/TR/css-syntax-3/#ident-start-code-point
	 * @return int The number of bytes consumed.
	 */
	private function consume_ident_codepoint($at): int {
		// Supported ASCII codepoints
		$ascii_len = strspn( $this->css, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-', $this->at, 1 );
		if ($ascii_len > 0) {
			return 1;
		}

		// Special case for null bytes – they are replaced with U+FFFD during preprocessing.
		if ( ord($this->css[$at]) === 0x00 ) {
			return 1;
		}

		// Non-ASCII codepoints (>= 0x80)
		if ( ord($this->css[$at]) >= 0x80 ) {
			$matched_bytes = 0;
			$codepoint = $this->get_codepoint_at( $this->at, $matched_bytes );

			// If get_codepoint_at fails to advance, we're dealing with a non-UTF-8 sequence.
			// We're in trouble!
			// @TODO: Decide what's the most useful strategy for handling this. Some form of emitting
			// an error would be useful for sure.
			// For now, we'll just skip 1 byte to prevent infinite loop and keep processing.
			if ( 0 === $matched_bytes ) {
				$matched_bytes = 1;
			}

			// Check if the codepoint is actually >= 0x80 (non-ASCII)
			if ( null !== $codepoint && $codepoint >= 0x80 ) {
				return $matched_bytes;
			}
		}

		return 0;
	}

	/**
	 * Consumes an escaped code point.
	 *
	 * Escape sequences are backslash followed by 1-6 hex digits (with optional
	 * trailing whitespace) or any other character. Invalid code points are
	 * replaced with U+FFFD.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#consume-escaped-code-point
	 *
	 * @return string
	 */
	private function consume_escape(): string {
		// It assumes that the U+005C REVERSE SOLIDUS (\) has already been consumed
		// and that the next input code point has already been verified to be part
		// of a valid escape.

		// The first step is to consume the next input code point. This method handles
		// that part implicitly in every code branch below.

		// EOF
		if ( $this->at >= $this->length ) {
			// This is a parse error. Return U+FFFD REPLACEMENT CHARACTER (�).
			return "\xEF\xBF\xBD"; // U+FFFD
		}

		// Hex digits
		$hex_len = strspn( $this->css, '0123456789ABCDEFabcdef', $this->at );
		if ( $hex_len > 0 ) {
			// Consume up to 6 hex digits.
			$hex_len = min( $hex_len, 6 ); // Max 6 hex digits
			$hex     = substr( $this->css, $this->at, $hex_len );
			$this->at += $hex_len;

			// If the next input code point is whitespace, consume it as well.
			if ( $this->at < $this->length ) {
				$next = $this->css[ $this->at ];
				if ( "\t" === $next || "\n" === $next || "\f" === $next || ' ' === $next ) {
					$this->at++;
				} elseif ( "\r" === $next ) {
					$this->at++;
					// Handle \r\n as a single whitespace – the preprocessing phase would replace \r\n with \n.
					if ( $this->at < $this->length && "\n" === $this->css[ $this->at ] ) {
						$this->at++;
					}
				}
			}

			// Convert the hex digits to a UTF-8 string
			return codepoint_to_utf8( hexdec( $hex ) );
		}

		// anything else
		// Return the current input code point.
		// Null bytes are replaced with U+FFFD during preprocessing.
		if ( "\x00" === $this->css[ $this->at ] ) {
			$this->at++;
			return "\xEF\xBF\xBD"; // U+FFFD
		}
		
		$matched_bytes = 0;
		$this->get_codepoint_at( $this->at, $matched_bytes );

		// If get_codepoint_at fails to advance, we're dealing with a non-UTF-8 sequence.
		// We're in trouble!
		// @TODO: Decide what's the most useful strategy for handling this. Some form of emitting
		// an error would be useful for sure.
		// For now, we'll just skip 1 byte to prevent infinite loop and keep processing.
		if ( 0 === $matched_bytes ) {
			$matched_bytes = 1;
		}

		$result         = substr( $this->css, $this->at, $matched_bytes );
		$this->at += $matched_bytes;
		return $result;
	}

	/**
	 * Checks if current position starts a valid escape sequence.
	 *
	 * A valid escape is a backslash not followed by a newline or EOF.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#starts-with-a-valid-escape
	 *
	 * @param int $offset Byte offset.
	 * @return bool
	 */
	private function is_valid_escape( int $offset ): bool {
		if ( $offset >= $this->length || '\\' !== $this->css[ $offset ] ) {
			return false;
		}
		// Per CSS spec: if the second code point is a newline, return false.
		// Otherwise (including EOF), return true.
		if ( $offset + 1 >= $this->length ) {
			// Second code point is EOF - this is a valid escape per spec
			return true;
		}
		$next = $this->css[ $offset + 1 ];
		return "\n" !== $next && "\f" !== $next && "\r" !== $next;
	}

	/**
	 * Checks if current position would start a number.
	 *
	 * A number can start with +, -, or a digit, or with . followed by a digit.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#starts-with-a-number
	 *
	 * @return bool
	 */
	private function would_start_number(): bool {
		if ( $this->at >= $this->length ) {
			return false;
		}

		$char1 = $this->css[ $this->at ];

		if ( '+' === $char1 || '-' === $char1 ) {
			if ( $this->at + 1 >= $this->length ) {
				return false;
			}
			$char2 = $this->css[ $this->at + 1 ];
			if ( $char2 >= '0' && $char2 <= '9' ) {
				return true;
			}
			if ( '.' === $char2 && $this->at + 2 < $this->length ) {
				$char3 = $this->css[ $this->at + 2 ];
				return $char3 >= '0' && $char3 <= '9';
			}
			return false;
		}

		if ( '.' === $char1 ) {
			if ( $this->at + 1 >= $this->length ) {
				return false;
			}
			$char2 = $this->css[ $this->at + 1 ];
			return $char2 >= '0' && $char2 <= '9';
		}

		return $char1 >= '0' && $char1 <= '9';
	}

	/**
	 * Checks if three code points would start an identifier sequence.
	 *
	 * This implements the CSS spec's "Check if three code points would start an ident sequence"
	 * algorithm, which checks the code point at $offset and the following two code points.
	 *
	 * NOTE: "Three code points" means three Unicode code points, not three bytes.
	 * Multi-byte UTF-8 sequences count as single code points.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#would-start-an-identifier
	 *
	 * @param int $offset Byte offset of the first code point to check.
	 * @return bool
	 */
	private function would_next_3_code_points_start_an_ident( int $offset ): bool {
		if ( $offset >= $this->length ) {
			return false;
		}

		// Get the first code point and its byte length
		$matched_bytes1 = 0;
		$codepoint1 = $this->get_codepoint_at( $offset, $matched_bytes1 );

		// If we couldn't decode a valid codepoint, fail
		if ( null === $codepoint1 || 0 === $matched_bytes1 ) {
			return false;
		}

		// Look at the first code point:
		// U+002D HYPHEN-MINUS (-)
		if ( 0x002D === $codepoint1 ) {
			// If the second code point is an ident-start code point or a U+002D HYPHEN-MINUS,
			// or the second and third code points are a valid escape, return true.

			// Calculate offset of second code point (after the first code point's bytes)
			$offset2 = $offset + $matched_bytes1;
			if ( $offset2 >= $this->length ) {
				return false;
			}

			// Get the second code point
			$matched_bytes2 = 0;
			$codepoint2 = $this->get_codepoint_at( $offset2, $matched_bytes2 );

			if ( null === $codepoint2 ) {
				return false;
			}

			// After single hyphen, we need:
			// - ident-start code point: letter (A-Z, a-z), underscore (_), or non-ASCII (>= U+0080)
			// - Another hyphen (for -- custom properties)
			// - Valid escape sequence (\)

			// Check for ASCII letter (A-Z, a-z)
			if ( ( $codepoint2 >= 0x41 && $codepoint2 <= 0x5A ) ||  // A-Z
			     ( $codepoint2 >= 0x61 && $codepoint2 <= 0x7A ) ) { // a-z
				return true;
			}

			// Check for underscore (_)
			if ( 0x5F === $codepoint2 ) {
				return true;
			}

			// Check for another hyphen (--custom-property)
			if ( 0x002D === $codepoint2 ) {
				return true;
			}

			// Check for non-ASCII code point (>= U+0080)
			if ( $codepoint2 >= 0x80 ) {
				return true;
			}

			// Check for valid escape sequence
			if ( $this->is_valid_escape( $offset2 ) ) {
				return true;
			}

			// Otherwise, return false.
			return false;
		}

		// ident-start code point: letter (A-Z, a-z), underscore (_), or non-ASCII (>= U+0080)

		// Check for ASCII letter (A-Z, a-z)
		if ( ( $codepoint1 >= 0x41 && $codepoint1 <= 0x5A ) ||  // A-Z
		     ( $codepoint1 >= 0x61 && $codepoint1 <= 0x7A ) ) { // a-z
			return true;
		}

		// Check for underscore (_)
		if ( 0x5F === $codepoint1 ) {
			return true;
		}

		// Check for non-ASCII code point (>= U+0080)
		if ( $codepoint1 >= 0x80 ) {
			return true;
		}

		// U+005C REVERSE SOLIDUS (\)
		// If the first and second code points are a valid escape, return true.
		if ( 0x005C === $codepoint1 ) {
			// Check if it's a valid escape
			return $this->is_valid_escape( $offset );
		}

		// U+0000 NULL starts an ident (will be replaced with U+FFFD)
		if ( 0x00 === $codepoint1 ) {
			return true;
		}

		// anything else
		// Return false.
		return false;
	}

	/**
	 * Checks if byte can start an identifier (ASCII only).
	 *
	 * Only checks ASCII identifier start characters: A-Z, a-z, and underscore.
	 * Non-ASCII and escape sequences are checked separately.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#ident-start-code-point
	 *
	 * @param int $byte Byte value.
	 * @return bool
	 */
	private function is_ident_start( int $byte ): bool {
		return ( $byte >= 0x41 && $byte <= 0x5A ) || // A-Z
		       ( $byte >= 0x61 && $byte <= 0x7A ) || // a-z
		       0x5F === $byte;                       // _
	}

	/**
	 * Checks if the code point at the given offset is an ident code point.
	 *
	 * An ident code point is: an ident-start code point, a digit, or U+002D HYPHEN-MINUS (-).
	 * This includes: A-Z, a-z, 0-9, _, -, and any non-ASCII code point (>= U+0080).
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#ident-code-point
	 *
	 * @param int $offset Byte offset.
	 * @return bool
	 */
	private function is_ident_code_point_at( int $offset ): bool {
		if ( $offset >= $this->length ) {
			return false;
		}

		// Get the code point at this offset
		$matched_bytes = 0;
		$codepoint = $this->get_codepoint_at( $offset, $matched_bytes );

		if ( null === $codepoint ) {
			return false;
		}

		// Check for ident-start code point: A-Z, a-z, _, or non-ASCII (>= U+0080)
		if ( ( $codepoint >= 0x41 && $codepoint <= 0x5A ) ||  // A-Z
		     ( $codepoint >= 0x61 && $codepoint <= 0x7A ) ||  // a-z
		     0x5F === $codepoint ||                           // _
		     $codepoint >= 0x80 ) {                           // non-ASCII
			return true;
		}

		// Check for digit: 0-9
		if ( $codepoint >= 0x30 && $codepoint <= 0x39 ) {
			return true;
		}

		// Check for hyphen: -
		if ( 0x002D === $codepoint ) {
			return true;
		}

		return false;
	}

	/**
	 * Checks if the character at the given offset is a non-ASCII code point (>= U+0080).
	 * According to CSS spec, any non-ASCII code point is valid in identifiers.
	 *
	 * @param int $offset Byte offset.
	 * @return bool True if the character is a non-ASCII code point, false otherwise.
	 */
	private function is_non_ascii_at( int $offset ): bool {
		if ( $offset >= $this->length ) {
			return false;
		}

		$byte = ord( $this->css[ $offset ] );

		// ASCII characters (< 0x80) are not non-ASCII
		if ( $byte < 0x80 ) {
			return false;
		}

		// Use get_codepoint_at to get the actual codepoint value
		$matched_bytes = 0;
		$codepoint = $this->get_codepoint_at( $offset, $matched_bytes );

		// Check if we got a valid non-ASCII codepoint
		return null !== $codepoint && $codepoint >= 0x80;
	}

}
