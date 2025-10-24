<?php

namespace WordPress\DataLiberation\URL;

use function WordPress\Encoding\utf8_codepoint_at;

/**
 * Tokenizes CSS according to the CSS Syntax Level 3 specification.
 */
class CSSProcessor {
	public const TOKEN_WHITESPACE      = 'whitespace-token';
	public const TOKEN_COMMENT         = 'comment-token';
	public const TOKEN_STRING          = 'string-token';
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
	public const TOKEN_URL             = 'url-token';
	public const TOKEN_BAD_URL         = 'bad-url-token';
	public const TOKEN_IDENT           = 'ident-token';
	public const TOKEN_CDC             = 'CDC-token';
	public const TOKEN_CDO             = 'CDO-token';
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
	private $token_type              = null;
	private $token_starts_at         = null;
	private $token_length            = null;
	private $token_value             = null;
	private $token_name              = null;
	private $token_unit              = null;
	private $token_value_starts_at   = null;
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
	 * @return bool Whether a token was found.
	 */
	public function next_token(): bool {
		$this->after_token();

		// If we're already at or past the end, don't process further
		if ( $this->at >= $this->length ) {
			return false;
		}

		// Comments
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

		// Whitespace
		$whitespace_length = strspn( $this->css, "\t\n\f\r ", $this->at );
		if ( $whitespace_length > 0 ) {
			$this->token_type   = self::TOKEN_WHITESPACE;
			$this->token_length = $whitespace_length;
			$this->token_starts_at = $this->at;
			$this->at    += $whitespace_length;
			return true;
		}

		$this->token_starts_at = $this->at;
		$char = $this->css[ $this->at ];

		// String
		if ( '"' === $this->css[ $this->at ] || "'" === $this->css[ $this->at ] ) {
			return $this->consume_string();
		}

		// Hash
		if ( '#' === $char ) {
			if ( $this->at + 1 < $this->length ) {
				$next      = $this->css[ $this->at + 1 ];
				$next_byte = ord( $next );
				$is_ident  = $this->is_ident_start( $next_byte ) ||
				             ( $next >= '0' && $next <= '9' ) ||
				             '-' === $next ||
				             $next_byte >= 0x80 ||
				             $this->is_valid_escape( $this->at + 1 );
				if ( $is_ident ) {
					$this->at++;
					$this->token_name   = $this->consume_ident();
					$this->token_type   = self::TOKEN_HASH;
					$this->token_length = $this->at - $this->token_starts_at;
					return true;
				}
			}
			$this->at++;
			$this->token_type   = self::TOKEN_DELIM;
			$this->token_length = 1;
			return true;
		}

		// Simple single-byte tokens
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

		// At-keyword
		if ( '@' === $char ) {
			if ( $this->would_start_ident( $this->at + 1 ) ) {
				$this->at++;
				$this->token_name   = $this->consume_ident();
				$this->token_type   = self::TOKEN_AT_KEYWORD;
				$this->token_length = $this->at - $this->token_starts_at;
				return true;
			}
			$this->at++;
			$this->token_type   = self::TOKEN_DELIM;
			$this->token_length = 1;
			return true;
		}

		// Number-like tokens
		if ( '+' === $char || '-' === $char || '.' === $char ) {
			if ( $this->would_start_number() ) {
				return $this->consume_numeric();
			}
		}

		// CDC (-->)
		if ( '-' === $char && $this->at + 2 < $this->length &&
		     '-' === $this->css[ $this->at + 1 ] && '>' === $this->css[ $this->at + 2 ] ) {
			$this->at    += 3;
			$this->token_type   = self::TOKEN_CDC;
			$this->token_length = 3;
			return true;
		}

		// CDO (<!--)
		if ( '<' === $char && $this->at + 3 < $this->length &&
		     '!' === $this->css[ $this->at + 1 ] &&
		     '-' === $this->css[ $this->at + 2 ] &&
		     '-' === $this->css[ $this->at + 3 ] ) {
			$this->at    += 4;
			$this->token_type   = self::TOKEN_CDO;
			$this->token_length = 4;
			return true;
		}

		// Ident-like (function, url, ident)
		if ( $this->would_start_ident( $this->at ) ) {
			return $this->consume_ident_like();
		}

		// Number
		if ( $this->would_start_number() ) {
			return $this->consume_numeric();
		}

		// Delim - handle multi-byte UTF-8
		if ( ord( $char ) >= 0x80 ) {
			$matched_bytes     = 0;
			utf8_codepoint_at( $this->css, $this->at, $matched_bytes );
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
	 * @param int $ending_code_point Ending delimiter code point.
	 * @return bool
	 */
	private function consume_string(): bool {
		$ending_char = $this->css[ $this->at ];
		
		$this->at++;
		$value_starts_at = $this->at;

		// Characters that need special handling: backslash, newlines, and the ending quote
		$special_chars = "\\\n\r\f" . $ending_char;

		while ( $this->at < $this->length ) {
			// Skip normal characters all at once
			$normal_len = strcspn( $this->css, $special_chars, $this->at );
			$this->at += $normal_len;

			if ( $this->at >= $this->length ) {
				break; // EOF
			}

			$char = $this->css[ $this->at ];

			// End of string
			if ( $char === $ending_char ) {
				$this->at++;
				$this->token_type              = self::TOKEN_STRING;
				$this->token_length            = $this->at - $this->token_starts_at;
				$this->token_value_starts_at   = $value_starts_at;
				$this->token_value_length      = $this->at - $value_starts_at - 1;
				return true;
			}

			// Newline in string
			// TODO: Double check this
			if ( "\n" === $char || "\f" === $char || "\r" === $char ) {
				$this->token_type              = self::TOKEN_BAD_STRING;
				$this->token_length            = $this->at - $this->token_starts_at;
				$this->token_value_starts_at   = $value_starts_at;
				$this->token_value_length      = $this->at - $value_starts_at;
				return true;
			}

			// Must be a backslash (escape sequence)
			if ( '\\' === $char ) {
				if ( $this->is_valid_escape( $this->at ) ) {
					$this->at++;
					$this->consume_escape();
					continue;
				}
				$this->at++;
				if ( $this->at < $this->length ) {
					$next = $this->css[ $this->at ];
					if ( "\n" === $next || "\f" === $next || "\r" === $next ) {
						$this->at++;
					}
				}
				continue;
			}
		}

		// EOF in string
		$this->token_type              = self::TOKEN_STRING;
		$this->token_length            = $this->at - $this->token_starts_at;
		$this->token_value_starts_at   = $value_starts_at;
		$this->token_value_length      = $this->at - $value_starts_at;
		return true;
	}

	/**
	 * Consumes a numeric token (number, percentage, dimension).
	 *
	 * @return bool
	 */
	private function consume_numeric(): bool {
		$repr = '';
		$char = $this->css[ $this->at ];

		// Sign
		if ( '+' === $char || '-' === $char ) {
			$repr .= $char;
			$this->at++;
		}

		// Integer part - use strspn
		$digits = strspn( $this->css, '0123456789', $this->at );
		if ( $digits > 0 ) {
			$repr          .= substr( $this->css, $this->at, $digits );
			$this->at += $digits;
		}

		// Decimal part
		if ( $this->at + 1 < $this->length &&
		     '.' === $this->css[ $this->at ] &&
		     $this->css[ $this->at + 1 ] >= '0' &&
		     $this->css[ $this->at + 1 ] <= '9' ) {
			$repr .= '.';
			$this->at++;
			$digits = strspn( $this->css, '0123456789', $this->at );
			if ( $digits > 0 ) {
				$repr          .= substr( $this->css, $this->at, $digits );
				$this->at += $digits;
			}
		}

		// Exponent
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
						$repr .= $e . $next;
						$this->at++;
						$has_exp = true;
					} elseif ( $next >= '0' && $next <= '9' ) {
						$repr .= $e;
						$has_exp = true;
					}
				}

				if ( $has_exp ) {
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

		$this->token_value = (float) $repr;

		// Dimension
		if ( $this->would_start_ident( $this->at ) ) {
			$this->token_unit   = $this->consume_ident();
			$this->token_type   = self::TOKEN_DIMENSION;
			$this->token_length = $this->at - $this->token_starts_at;
			return true;
		}

		// Percentage
		if ( $this->at < $this->length && '%' === $this->css[ $this->at ] ) {
			$this->at++;
			$this->token_type   = self::TOKEN_PERCENTAGE;
			$this->token_length = $this->at - $this->token_starts_at;
			return true;
		}

		// Number
		$this->token_type   = self::TOKEN_NUMBER;
		$this->token_length = $this->at - $this->token_starts_at;
		return true;
	}

	/**
	 * Consumes an ident-like token (function, url, ident).
	 *
	 * @return bool
	 */
	private function consume_ident_like(): bool {
		$string = $this->consume_ident();

		// Check for url()
		if ( 0 === strcasecmp( $string, 'url' ) && $this->at < $this->length && '(' === $this->css[ $this->at ] ) {
			$this->at++;

			// Skip whitespace
			$ws_len = strspn( $this->css, "\t\n\f\r ", $this->at );
			$this->at += $ws_len;

			if ( $this->at < $this->length ) {
				$next = $this->css[ $this->at ];
				// url() with string argument - treat as function
				if ( '"' === $next || "'" === $next ) {
					$this->token_type   = self::TOKEN_FUNCTION;
					$this->token_name   = $string;
					$this->token_length = $this->at - $this->token_starts_at;
					return true;
				}
			}

			return $this->consume_url();
		}

		// Function
		if ( $this->at < $this->length && '(' === $this->css[ $this->at ] ) {
			$this->at++;
			$this->token_type   = self::TOKEN_FUNCTION;
			$this->token_name   = $string;
			$this->token_length = $this->at - $this->token_starts_at;
			return true;
		}

		// Ident
		$this->token_type   = self::TOKEN_IDENT;
		$this->token_length = $this->at - $this->token_starts_at;
		return true;
	}

	/**
	 * Consumes a url token.
	 *
	 * @return bool
	 */
	private function consume_url(): bool {
		// Skip whitespace
		$ws_len = strspn( $this->css, "\t\n\f\r ", $this->at );
		$this->at += $ws_len;

		$value_starts_at = $this->at;
		$value = '';

		while ( $this->at < $this->length ) {
			$char = $this->css[ $this->at ];

			// End of URL
			if ( ')' === $char ) {
				$this->at++;
				$this->token_type              = self::TOKEN_URL;
				$this->token_length            = $this->at - $this->token_starts_at;
				$this->token_value_starts_at   = $value_starts_at;
				$this->token_value_length      = $this->at - $value_starts_at - 1;
				return true;
			}

			// Whitespace before )
			if ( "\t" === $char || "\n" === $char || "\f" === $char || "\r" === $char || ' ' === $char ) {
				$value_ends_at = $this->at;
				$ws_len = strspn( $this->css, "\t\n\f\r ", $this->at );
				$this->at += $ws_len;
				if ( $this->at < $this->length && ')' === $this->css[ $this->at ] ) {
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

			// Invalid characters
			if ( '"' === $char || "'" === $char || '(' === $char ||
			     ( $byte <= 0x0008 ) || "\v" === $char ||
			     ( $byte >= 0x000E && $byte <= 0x001F ) || "\x7F" === $char ) {
				return $this->finish_bad_url();
			}

			// Escape
			if ( '\\' === $char ) {
				if ( $this->is_valid_escape( $this->at ) ) {
					$this->at++;
					$value .= $this->consume_escape();
					continue;
				}
				return $this->finish_bad_url();
			}

			// Fast path for ASCII
			if ( $byte < 0x80 ) {
				$value .= $char;
				$this->at++;
			} else {
				// Multi-byte UTF-8
				$matched_bytes = 0;
				utf8_codepoint_at( $this->css, $this->at, $matched_bytes );
				$value         .= substr( $this->css, $this->at, $matched_bytes );
				$this->at += $matched_bytes;
			}
		}

		// EOF in URL
		$this->token_type   = self::TOKEN_BAD_URL;
		$this->token_length = $this->at - $this->token_starts_at;
		return true;
	}

	/**
	 * Finishes a bad url token by consuming remnants.
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
			if ( '\\' === $char && $this->is_valid_escape( $this->at ) ) {
				$this->at++;
				$result .= $this->consume_escape();
				continue;
			}

			// Non-ASCII (>= 0x80)
			if ( $byte >= 0x80 ) {
				$matched_bytes = 0;
				utf8_codepoint_at( $this->css, $this->at, $matched_bytes );
				$result        .= substr( $this->css, $this->at, $matched_bytes );
				$this->at += $matched_bytes;
				continue;
			}

			break;
		}

		return $result;
	}

	/**
	 * Consumes an escaped code point.
	 *
	 * @return string
	 */
	private function consume_escape(): string {
		if ( $this->at >= $this->length ) {
			return "\xEF\xBF\xBD"; // U+FFFD
		}

		$char = $this->css[ $this->at ];

		// Hex escape - use strspn
		if ( ( $char >= '0' && $char <= '9' ) ||
		     ( $char >= 'A' && $char <= 'F' ) ||
		     ( $char >= 'a' && $char <= 'f' ) ) {
			$hex_len = strspn( $this->css, '0123456789ABCDEFabcdef', $this->at );
			$hex_len = min( $hex_len, 6 ); // Max 6 hex digits
			$hex     = substr( $this->css, $this->at, $hex_len );
			$this->at += $hex_len;

			// Skip whitespace after hex escape
			if ( $this->at < $this->length ) {
				$next = $this->css[ $this->at ];
				if ( "\t" === $next || "\n" === $next || "\f" === $next || "\r" === $next || ' ' === $next ) {
					$this->at++;
				}
			}

			$codepoint = hexdec( $hex );
			if ( 0 === $codepoint || $codepoint > 0x10FFFF || ( $codepoint >= 0xD800 && $codepoint <= 0xDFFF ) ) {
				return "\xEF\xBF\xBD"; // U+FFFD
			}

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

		if ( "\x00" === $char ) {
			$this->at++;
			return "\xEF\xBF\xBD"; // U+FFFD
		}

		$byte = ord( $char );

		// Single character escape - use UTF-8 decoder for multi-byte
		if ( $byte >= 0x80 ) {
			$matched_bytes = 0;
			utf8_codepoint_at( $this->css, $this->at, $matched_bytes );
			$result         = substr( $this->css, $this->at, $matched_bytes );
			$this->at += $matched_bytes;
			return $result;
		}

		$this->at++;
		return $char;
	}

	/**
	 * Checks if current at starts a valid escape.
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
	 * Checks if current at would start a number.
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
	 * Checks if at would start an ident sequence.
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

		if ( '-' === $char1 ) {
			if ( $offset + 1 >= $this->length ) {
				return false;
			}
			$char2 = $this->css[ $offset + 1 ];
			$byte2 = ord( $char2 );
			return $this->is_ident_start( $byte2 ) || '-' === $char2 || $byte2 >= 0x80 || $this->is_valid_escape( $offset + 1 );
		}

		if ( $this->is_ident_start( $byte1 ) || $byte1 >= 0x80 ) {
			return true;
		}

		if ( '\\' === $char1 ) {
			return $this->is_valid_escape( $offset );
		}

		return false;
	}

	/**
	 * Checks if byte can start an identifier (ASCII only).
	 *
	 * @param int $byte Byte value.
	 * @return bool
	 */
	private function is_ident_start( int $byte ): bool {
		return ( $byte >= 0x41 && $byte <= 0x5A ) || // A-Z
		       ( $byte >= 0x61 && $byte <= 0x7A ) || // a-z
		       0x5F === $byte;                        // _
	}
}
