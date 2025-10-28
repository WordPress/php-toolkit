<?php

namespace WordPress\DataLiberation\URL;

use function WordPress\Encoding\utf8_codepoint_at;

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
		 * A hash token is created when # is followed by an identifier or hex digit.
		 * This is commonly used for hex colors (#fff) or ID selectors (#header).
		 *
		 * @see https://www.w3.org/TR/css-syntax-3/#hash-token-diagram
		 */
		if ( '#' === $char ) {
			if ( $this->at + 1 < $this->length ) {
				$next      = $this->css[ $this->at + 1 ];
				$next_byte = ord( $next );
				// If the next input code point is an ident code point or the next two
				// input code points are a valid escape, then:
				$is_ident  = $this->is_ident_start( $next_byte ) ||
				             ( $next >= '0' && $next <= '9' ) ||
				             '-' === $next ||
				             $this->is_unicode_letter_at( $this->at + 1 ) ||
				             $this->is_valid_escape( $this->at + 1 );
				if ( $is_ident ) {
					// Create a <hash-token>. Consume an ident sequence.
					$this->at++;
					$this->token_name   = $this->consume_ident();
					$this->token_type   = self::TOKEN_HASH;
					$this->token_length = $this->at - $this->token_starts_at;
					return true;
				}
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
		 * @see https://www.w3.org/TR/css-syntax-3/#at-keyword-token-diagram
		 */
		if ( '@' === $char ) {
			++$this->at;
			// If the next 3 input code points would start an ident sequence, consume an ident
			// sequence, create an <at-keyword-token> with its value set to the returned value,
			// and return it.
			if ( $this->would_start_ident( $this->at ) ) {
				$this->token_name   = $this->consume_ident();
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
		 * U+002B PLUS SIGN (+)
		 * U+002D HYPHEN-MINUS (-)
		 * U+002E FULL STOP (.)
		 *
		 * These can start numbers (e.g., +1.5, -10, .5).
		 *
		 * @see https://www.w3.org/TR/css-syntax-3/#starts-with-a-number
		 */
		if ( '+' === $char || '-' === $char || '.' === $char ) {
			// If the input stream starts with a number, reconsume the current input code point,
			// consume a numeric token, and return it.
			if ( $this->would_start_number() ) {
				return $this->consume_numeric();
			}
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
		if ( $this->would_start_ident( $this->at ) ) {
			return $this->consume_ident_like();
		}

		/*
		 * Digit
		 *
		 * If the input stream starts with a number, reconsume the current input
		 * code point, consume a numeric token, and return it.
		 *
		 * @see https://www.w3.org/TR/css-syntax-3/#consume-numeric-token
		 */
		if ( $this->would_start_number() ) {
			return $this->consume_numeric();
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
			utf8_codepoint_at( $this->css, $this->at, $matched_bytes );

			// Safeguard: if utf8_codepoint_at fails to advance, skip 1 byte to prevent infinite loop
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
	 * @see https://www.w3.org/TR/css-syntax-3/#consume-numeric-token
	 * @see https://www.w3.org/TR/css-syntax-3/#consume-number
	 *
	 * @return bool
	 */
	private function consume_numeric(): bool {
		// Consume a number and let number be the result.
		$repr = '';
		$char = $this->css[ $this->at ];

		// If the next input code point is U+002B PLUS SIGN (+) or U+002D HYPHEN-MINUS (-),
		// consume it and append it to repr.
		if ( '+' === $char || '-' === $char ) {
			$repr .= $char;
			$this->at++;
		}

		// While the next input code point is a digit, consume it and append it to repr.
		$digits = strspn( $this->css, '0123456789', $this->at );
		if ( $digits > 0 ) {
			$repr          .= substr( $this->css, $this->at, $digits );
			$this->at += $digits;
		}

		// If the next 2 input code points are U+002E FULL STOP (.) followed by a digit, then:
		if ( $this->at + 1 < $this->length &&
		     '.' === $this->css[ $this->at ] &&
		     $this->css[ $this->at + 1 ] >= '0' &&
		     $this->css[ $this->at + 1 ] <= '9' ) {
			// Consume them and append them to repr.
			$repr .= '.';
			$this->at++;
			// While the next input code point is a digit, consume it and append it to repr.
			$digits = strspn( $this->css, '0123456789', $this->at );
			if ( $digits > 0 ) {
				$repr          .= substr( $this->css, $this->at, $digits );
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
						// Consume them and append them to repr.
						$repr .= $e . $next;
						$this->at++;
						$has_exp = true;
					} elseif ( $next >= '0' && $next <= '9' ) {
						$repr .= $e;
						$has_exp = true;
					}
				}

				if ( $has_exp ) {
					// While the next input code point is a digit, consume it and append it to repr.
					$digits = strspn( $this->css, '0123456789', $this->at );
					if ( $digits > 0 ) {
						$repr          .= substr( $this->css, $this->at, $digits );
						$this->at += $digits;
					}
				} else {
					$this->at = $save_pos;
				}
			}
		}

		// Convert string repr to a number, and set the value to the returned value.
		$this->token_value = (float) $repr;

		// If the next 3 input code points would start an ident sequence, then:
		if ( $this->would_start_ident( $this->at ) ) {
			// Create a <dimension-token> with the same value and type flag as number,
			// and a unit set initially to the empty string.
			// Consume an ident sequence. Set the <dimension-token>'s unit to the returned value.
			$this->token_unit   = $this->consume_ident();
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
		$string = $this->consume_ident();

		/*
		 * If string's value is an ASCII case-insensitive match for "url",
		 * and the next input code point is U+0028 LEFT PARENTHESIS ((), then:
		 *
		 * url() is special - it can take either a string or an unquoted URL.
		 * If followed by a quote, treat it as a function token.
		 *
		 * @see https://www.w3.org/TR/css-syntax-3/#url-token-diagram
		 */
		if ( 0 === strcasecmp( $string, 'url' ) && $this->at < $this->length && '(' === $this->css[ $this->at ] ) {
			// Consume the next input code point.
			$this->at++;

			// While the next two input code points are whitespace, consume the next input code point.
			$ws_len = strspn( $this->css, "\t\n\f\r ", $this->at );

			if ( $this->at + $ws_len < $this->length ) {
				$next = $this->css[ $this->at + $ws_len ];
				// If the next one or two input code points are U+0022 QUOTATION MARK ("),
				// U+0027 APOSTROPHE ('), or whitespace followed by U+0022 QUOTATION MARK (")
				// or U+0027 APOSTROPHE ('), then create a <function-token> with its value
				// set to string and return it.
				if ( '"' === $next || "'" === $next ) {
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

		// Otherwise, if the next input code point is U+0028 LEFT PARENTHESIS ((), consume it.
		// Create a <function-token> with its value set to string and return it.
		if ( $this->at < $this->length && '(' === $this->css[ $this->at ] ) {
			$this->at++;
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
			$char = $this->css[ $this->at ];

			// U+0029 RIGHT PARENTHESIS ())
			// Return the <url-token>.
			if ( ')' === $char ) {
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
			if ( "\t" === $char || "\n" === $char || "\f" === $char || "\r" === $char || ' ' === $char ) {
				$value_ends_at = $this->at;
				$ws_len = strspn( $this->css, "\t\n\f\r ", $this->at );
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
				return $this->finish_bad_url();
			}

			$byte = ord( $char );

			// U+0022 QUOTATION MARK (")
			// U+0027 APOSTROPHE (')
			// U+0028 LEFT PARENTHESIS (()
			// non-printable code point
			// This is a parse error. Consume the remnants of a bad url,
			// create a <bad-url-token>, and return it.
			if ( '"' === $char || "'" === $char || '(' === $char ||
			     ( $byte <= 0x0008 ) || "\v" === $char ||
			     ( $byte >= 0x000E && $byte <= 0x001F ) || "\x7F" === $char ) {
				return $this->finish_bad_url();
			}

			// U+005C REVERSE SOLIDUS (\)
			// If the stream starts with a valid escape, consume an escaped code point and
			// append the returned code point to the <url-token>'s value.
			if ( '\\' === $char ) {
				if ( $this->is_valid_escape( $this->at ) ) {
					$this->at++;
					$value .= $this->consume_escape();
					continue;
				}
				// Otherwise, this is a parse error. Consume the remnants of a bad url,
				// create a <bad-url-token>, and return it.
				return $this->finish_bad_url();
			}

			// anything else
			// Append the current input code point to the <url-token>'s value.
			// Fast path for ASCII
			if ( $byte < 0x80 ) {
				$value .= $char;
				$this->at++;
			} else {
				// Multi-byte UTF-8
				$matched_bytes = 0;
				utf8_codepoint_at( $this->css, $this->at, $matched_bytes );

				// Safeguard: if utf8_codepoint_at fails to advance, skip 1 byte to prevent infinite loop
				if ( 0 === $matched_bytes ) {
					$matched_bytes = 1;
				}

				$value         .= substr( $this->css, $this->at, $matched_bytes );
				$this->at += $matched_bytes;
			}
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
	private function finish_bad_url(): bool {
		while ( $this->at < $this->length ) {
			$char = $this->css[ $this->at ];

			if ( ')' === $char ) {
				$this->at++;
				break;
			}

			if ( '\\' === $char && $this->is_valid_escape( $this->at ) ) {
				$this->at++;
				$this->consume_escape();
				continue;
			}

			$this->at++;
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
	private function consume_ident(): string {
		$result = '';

		while ( $this->at < $this->length ) {
			$char = $this->css[ $this->at ];
			$byte = ord( $char );

			// Fast path for common ASCII ident chars
			if ( ( $char >= 'A' && $char <= 'Z' ) ||
			     ( $char >= 'a' && $char <= 'z' ) ||
			     ( $char >= '0' && $char <= '9' ) ||
			     '_' === $char || '-' === $char ) {
				// Use strspn to consume multiple chars at once
				$len = strspn( $this->css, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-', $this->at );
				$result        .= substr( $this->css, $this->at, $len );
				$this->at += $len;
				continue;
			}

			// Escape
			if ( '\\' === $char ) {
				if ( $this->is_valid_escape( $this->at ) ) {
					$this->at++;
					$result .= $this->consume_escape();
					continue;
				} else {
					// Invalid escape (EOF or newline) - produce replacement character
					$this->at++;
					$result .= "\xEF\xBF\xBD"; // U+FFFD in UTF-8
					continue;
				}
			}

			// Non-ASCII (>= 0x80)
			// - For identifiers starting with --, any >= 0x80 is valid (CSS custom properties)
			// - For other identifiers, only Unicode letters >= 0x80 are valid
			if ( $byte >= 0x80 ) {
				$starts_with_double_hyphen = ( strlen( $result ) >= 2 && substr( $result, 0, 2 ) === '--' );

				// Check if it's a Unicode letter (only needed for non-custom-property identifiers)
				if ( ! $starts_with_double_hyphen && ! $this->is_unicode_letter_at( $this->at ) ) {
					// Non-letter >= 0x80 in a regular identifier stops the identifier
					break;
				}

				// Determine byte length of this UTF-8 character
				if ( $byte < 0xC0 ) {
					// Invalid start byte - consume 1 byte
					$matched_bytes = 1;
				} elseif ( $byte < 0xE0 ) {
					$matched_bytes = 2;
				} elseif ( $byte < 0xF0 ) {
					$matched_bytes = 3;
				} else {
					$matched_bytes = 4;
				}

				// Make sure we don't read past end of string
				if ( $this->at + $matched_bytes > $this->length ) {
					$matched_bytes = $this->length - $this->at;
				}

				$result        .= substr( $this->css, $this->at, $matched_bytes );
				$this->at += $matched_bytes;
				continue;
			}

			// Null byte (0x00) is consumed but replaced with U+FFFD per CSS spec
			// Other control characters stop identifier consumption
			if ( $byte === 0x00 ) {
				$this->at++;
				$result .= "\xEF\xBF\xBD"; // U+FFFD
				continue;
			}

			break;
		}

		return $result;
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

		// Consume the next input code point.
		if ( $this->at >= $this->length ) {
			// This is a parse error. Return U+FFFD REPLACEMENT CHARACTER (�).
			return "\xEF\xBF\xBD"; // U+FFFD
		}

		$char = $this->css[ $this->at ];

		// hex digit
		if ( ( $char >= '0' && $char <= '9' ) ||
		     ( $char >= 'A' && $char <= 'F' ) ||
		     ( $char >= 'a' && $char <= 'f' ) ) {
			// Consume as many hex digits as possible, but no more than 5 more
			// (for a total of 6).
			$hex_len = strspn( $this->css, '0123456789ABCDEFabcdef', $this->at );
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
					// Handle \r\n as a single whitespace
					if ( $this->at < $this->length && "\n" === $this->css[ $this->at ] ) {
						$this->at++;
					}
				}
			}

			// Interpret the hex digits as a hexadecimal number.
			$codepoint = hexdec( $hex );

			// If this number is zero, or is for a surrogate, or is greater than
			// the maximum allowed code point, return U+FFFD REPLACEMENT CHARACTER (�).
			if ( 0 === $codepoint || $codepoint > 0x10FFFF || ( $codepoint >= 0xD800 && $codepoint <= 0xDFFF ) ) {
				return "\xEF\xBF\xBD"; // U+FFFD
			}

			// Otherwise, return the code point with that value.
			// Convert codepoint to UTF-8
			if ( $codepoint < 0x80 ) {
				return chr( $codepoint );
			} elseif ( $codepoint < 0x800 ) {
				return chr( 0xC0 | ( $codepoint >> 6 ) ) . chr( 0x80 | ( $codepoint & 0x3F ) );
			} elseif ( $codepoint < 0x10000 ) {
				return chr( 0xE0 | ( $codepoint >> 12 ) ) . chr( 0x80 | ( ( $codepoint >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $codepoint & 0x3F ) );
			} else {
				return chr( 0xF0 | ( $codepoint >> 18 ) ) . chr( 0x80 | ( ( $codepoint >> 12 ) & 0x3F ) ) . chr( 0x80 | ( ( $codepoint >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $codepoint & 0x3F ) );
			}
		}

		// U+0000 NULL
		// This is a parse error. Return U+FFFD REPLACEMENT CHARACTER (�).
		if ( "\x00" === $char ) {
			$this->at++;
			return "\xEF\xBF\xBD"; // U+FFFD
		}

		$byte = ord( $char );

		// anything else
		// Return the current input code point.
		// Single character escape - use UTF-8 decoder for multi-byte
		if ( $byte >= 0x80 ) {
			$matched_bytes = 0;
			utf8_codepoint_at( $this->css, $this->at, $matched_bytes );

			// Safeguard: if utf8_codepoint_at fails to advance, skip 1 byte to prevent infinite loop
			if ( 0 === $matched_bytes ) {
				$matched_bytes = 1;
			}

			$result         = substr( $this->css, $this->at, $matched_bytes );
			$this->at += $matched_bytes;
			return $result;
		}

		$this->at++;
		return $char;
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
		if ( $offset + 1 >= $this->length ) {
			return false;
		}
		$next = $this->css[ $offset + 1 ];
		return "\n" !== $next && "\f" !== $next && "\r" !== $next;
	}

	/**
	 * Checks if the character at the given offset is a Unicode letter (category L*).
	 * Only characters >= U+0080 that are Unicode letters are valid in CSS identifiers.
	 *
	 * @param int $offset Byte offset.
	 * @return bool True if the character is a Unicode letter, false otherwise.
	 */
	private function is_unicode_letter_at( int $offset ): bool {
		if ( $offset >= $this->length ) {
			return false;
		}

		$byte = ord( $this->css[ $offset ] );

		// ASCII characters are not Unicode letters (they're checked separately)
		if ( $byte < 0x80 ) {
			return false;
		}

		// Extract the UTF-8 character sequence
		$matched_bytes = 0;

		// Determine how many bytes this UTF-8 character should have
		if ( $byte < 0xC0 ) {
			// Invalid start byte or continuation byte
			return false;
		} elseif ( $byte < 0xE0 ) {
			$matched_bytes = 2;
		} elseif ( $byte < 0xF0 ) {
			$matched_bytes = 3;
		} else {
			$matched_bytes = 4;
		}

		// Make sure we have enough bytes
		if ( $offset + $matched_bytes > $this->length ) {
			return false;
		}

		// Extract the character bytes
		$char = substr( $this->css, $offset, $matched_bytes );

		// Check if it's a valid Unicode letter using PHP's character class
		return preg_match( '/\p{L}/u', $char ) === 1;
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
	 * Checks if position would start an identifier sequence.
	 *
	 * An identifier can start with a letter, underscore, hyphen (if followed by
	 * letter/hyphen/-/escape), non-ASCII character, escape sequence, or null byte.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#would-start-an-identifier
	 *
	 * @param int $offset Byte offset.
	 * @return bool
	 */
	private function would_start_ident( int $offset ): bool {
		if ( $offset >= $this->length ) {
			return false;
		}

		$char1 = $this->css[ $offset ];
		$byte1 = ord( $char1 );

		// Look at the first code point:
		// U+002D HYPHEN-MINUS
		if ( '-' === $char1 ) {
			// If the second code point is an ident-start code point or a U+002D HYPHEN-MINUS,
			// or the second and third code points are a valid escape, return true.
			if ( $offset + 1 >= $this->length ) {
				return false;
			}
			$char2 = $this->css[ $offset + 1 ];
			$byte2 = ord( $char2 );

			// After single hyphen, we need:
			// - ASCII letter/underscore (ident-start code point)
			// - Another hyphen (for -- custom properties)
			// - Unicode letter (category L*)
			// - Valid escape sequence
			if ( $this->is_ident_start( $byte2 ) || $this->is_valid_escape( $offset + 1 ) ) {
				return true;
			}

			// Double hyphen -- always starts an identifier
			// (CSS custom properties like --primary-color or just --)
			if ( '-' === $char2 ) {
				return true;
			}

			// Single hyphen followed by non-ASCII: only allow Unicode letters
			if ( $byte2 >= 0x80 ) {
				return $this->is_unicode_letter_at( $offset + 1 );
			}

			// Otherwise, return false.
			return false;
		}

		// ident-start code point
		// Return true.
		if ( $this->is_ident_start( $byte1 ) || $this->is_unicode_letter_at( $offset ) ) {
			return true;
		}

		// U+005C REVERSE SOLIDUS (\)
		// If the first and second code points are a valid escape, return true.
		if ( '\\' === $char1 ) {
			// Check if it's a valid escape OR backslash at EOF
			if ( $this->is_valid_escape( $offset ) ) {
				return true;
			}
			// Backslash at EOF starts an ident (produces U+FFFD)
			if ( $offset + 1 >= $this->length ) {
				return true;
			}
			// Otherwise, return false.
			return false;
		}

		// Null byte starts an ident (will be replaced with U+FFFD)
		if ( 0x00 === $byte1 ) {
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
}
