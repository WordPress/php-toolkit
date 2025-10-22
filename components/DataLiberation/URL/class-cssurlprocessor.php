<?php

namespace WordPress\DataLiberation\URL;

use Rowbot\URL\URL;
use WP_HTML_Text_Replacement;

use function WordPress\Encoding\codepoint_to_utf8_bytes;

/**
 * Finds and replaces URLs declared using a url() notation
 * in a CSS block body (without the trailing braces). An
 * example of such a block body is the content of a style=""
 * HTML attribute.
 *
 * This class was initially created to migrate background-image
 * URLs in CSS blocks during a WXR import.
 *
 * Usage:
 *
 * ```php
 * $css_block_body = <<<CSS
 * /* John picked this photo: *\/
 * background: url("/pictures/johns-pic.jpg");
 * content: "Ever heard about url() notation? Like this: url(/jane/picture.jpg)";
 * CSS;
 *
 * $processor = new CSSUrlProcessor( $css_block_body );
 * while ( $processor->next_url() ) {
 *     $processor->set_raw_url( '/new-image.jpg' );
 * }
 * echo $processor->get_updated_css();
 * ```
 *
 * Output:
 *
 * ```php
 * /* John picked this photo: *\/
 * background: url("/new-image.jpg");
 * content: "Ever heard about url() notation? Like this: url(/jane/picture.jpg)";
 * ```
 */
class CSSUrlProcessor {

	/**
	 * The CSS block to process (without the trailing braces).
	 *
	 * @var string
	 */
	private $css;
	private $url_starts_at;
	private $url_length;
	private $bytes_already_parsed = 0;

	/**
	 * @var string
	 */
	private $matched_url;

	/**
	 * @var string
	 */
	private $decoded_url;

	/**
	 * @var URL
	 */
	private $parsed_url;

	/**
	 * The base URL for the parsing algorithm.
	 *
	 * @var string|null
	 */
	private $base_url;

	/**
	 * @see \WP_HTML_Tag_Processor
	 * @var WP_HTML_Text_Replacement[]
	 */
	private $lexical_updates = array();

	public function __construct( $css, $base_url = null ) {
		$this->css      = $css;
		$this->base_url = $base_url;
	}

	/**
	 * Finds the next URL in the CSS content.
	 *
	 * Uses a state machine parser to handle arbitrarily large data URIs (1MB+)
	 * which would otherwise hit PCRE limits.
	 *
	 * @return bool True if a URL was found, false otherwise.
	 */
	public function next_url() {
		$this->matched_url   = null;
		$this->decoded_url   = null;
		$this->parsed_url    = null;
		$this->url_starts_at = null;
		$this->url_length    = null;

		// Use state machine parser instead of regex to handle large data URIs.
		return $this->parse_next_url_state_machine();
	}

	/**
	 * Fast string-based parser for CSS url() functions.
	 *
	 * Uses native string functions (strpos, strcspn, strspn) instead of
	 * character-by-character iteration for 10-100x faster performance with large URLs.
	 *
	 * @return bool True if a URL was found, false otherwise.
	 */
	private function parse_next_url_state_machine() {
		$length = strlen( $this->css );
		$i      = $this->bytes_already_parsed;

		while ( $i < $length ) {
			// Optimization: Use strcspn to skip to next interesting character in one pass.
			// Look for: u (start of url), / (comment), " (string), ' (string).
			$span = strcspn( $this->css, 'uU/"\'', $i );
			$i   += $span;

			if ( $i >= $length ) {
				return false; // Nothing found.
			}

			$char = $this->css[ $i ];

			// Check for comment.
			if ( '/' === $char && $i + 1 < $length && '*' === $this->css[ $i + 1 ] ) {
				// Skip comment using strpos (fast).
				$end_pos = strpos( $this->css, '*/', $i + 2 );
				$i       = ( false !== $end_pos ) ? $end_pos + 2 : $length;
				continue;
			}

			// Check for string.
			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
				++$i;

				while ( $i < $length ) {
					// Use strcspn to skip to next quote or backslash (fast).
					$span = strcspn( $this->css, $quote . '\\', $i );
					$i   += $span;

					if ( $i >= $length ) {
						break;
					}

					if ( '\\' === $this->css[ $i ] ) {
						$i += 2; // Skip escaped character.
						continue;
					}

					++$i; // Found unescaped quote.
					break;
				}
				continue;
			}

			// Check for url(.
			if ( $i + 4 <= $length &&
				( 'u' === $this->css[ $i ] || 'U' === $this->css[ $i ] ) &&
				( 'r' === $this->css[ $i + 1 ] || 'R' === $this->css[ $i + 1 ] ) &&
				( 'l' === $this->css[ $i + 2 ] || 'L' === $this->css[ $i + 2 ] ) &&
				( '(' === $this->css[ $i + 3 ] ) ) {
				// Found url(.
				$url_start = $i;
				$i        += 4;
			} else {
				// False positive - not 'url(', just 'u' in some other context.
				++$i;
				continue;
			}

			// Skip whitespace using strspn (fast).
			$i += strspn( $this->css, " \t\n\r", $i );

			if ( $i >= $length ) {
				return false;
			}

			// Check if quoted.
			$quote_char = $this->css[ $i ];
			if ( '"' === $quote_char || "'" === $quote_char ) {
				++$i;
				$url_value_start = $i;

				// Use strcspn to scan for closing quote OR backslash in ONE pass.
				// This is much faster than separate strpos() calls.
				while ( $i < $length ) {
					$span = strcspn( $this->css, $quote_char . '\\', $i );
					$i   += $span;

					if ( $i >= $length ) {
						return false; // No closing quote found.
					}

					if ( '\\' === $this->css[ $i ] ) {
						$i += 2; // Skip escaped character.
						continue;
					}

					// Found unescaped closing quote.
					$this->matched_url   = null; // Will be extracted lazily.
					$this->url_starts_at = $url_value_start;
					$this->url_length    = $i - $url_value_start;

					++$i; // Move past quote.

					// Skip whitespace..
					$i += strspn( $this->css, " \t\n\r", $i );

					// Expect closing ).
					if ( $i < $length && ')' === $this->css[ $i ] ) {
						++$i;
						$this->bytes_already_parsed = $i;
						return true;
					}
					return false;
				}
			} else {
				// Unquoted URL - use strcspn to find terminating characters (fast!).
				$url_value_start = $i;

				while ( $i < $length ) {
					$span = strcspn( $this->css, " \t\n\r\"'()\\", $i );
					$i   += $span;

					if ( $i >= $length ) {
						break;
					}

					if ( '\\' === $this->css[ $i ] && $i + 1 < $length ) {
						$i += 2; // Skip escaped character.
						continue;
					}

					break; // Hit terminating character.
				}

				if ( $i > $url_value_start ) {
					$this->matched_url   = null; // Will be extracted lazily.
					$this->url_starts_at = $url_value_start;
					$this->url_length    = $i - $url_value_start;

					// Skip whitespace.
					$i += strspn( $this->css, " \t\n\r", $i );

					// Expect closing ).
					if ( $i < $length && ')' === $this->css[ $i ] ) {
						++$i;
						$this->bytes_already_parsed = $i;
						return true;
					}
				}
			}

			// url( was malformed, continue from next position.
			$i = $url_start;
		}

		return false;
	}

	/**
	 * Gets the raw URL that was matched.
	 *
	 * @return string|false The raw URL or false if no URL is currently matched.
	 */
	public function get_raw_url() {
		if ( null === $this->url_starts_at ) {
			return false;
		}

		if ( null !== $this->decoded_url ) {
			return $this->decoded_url;
		}

		// Lazy extraction and decoding: only extract/decode when actually needed.
		if ( null === $this->matched_url ) {
			$this->matched_url = substr( $this->css, $this->url_starts_at, $this->url_length );
		}

		$this->decoded_url = $this->decode_css_escapes( $this->matched_url );

		return $this->decoded_url;
	}

	/**
	 * Gets the parsed URL object.
	 *
	 * @return URL|false The parsed URL or false if no URL is currently matched.
	 */
	public function get_parsed_url() {
		if ( null !== $this->parsed_url ) {
			return $this->parsed_url;
		}

		if ( $this->is_data_uri() ) {
			$this->parsed_url = null;
			return false;
		}

		$decoded_url = $this->get_raw_url();
		if ( false === $decoded_url ) {
			return false;
		}

		$parsed_url       = WPURL::parse( $decoded_url, $this->base_url );
		$this->parsed_url = ( false === $parsed_url ) ? false : $parsed_url;

		return $this->parsed_url;
	}

	/**
	 * Checks if the currently matched URL is a data URI.
	 *
	 * This is an optimized check that avoids extracting or decoding the URL
	 * by checking the first few bytes directly from the CSS string.
	 *
	 * @return bool True if the current URL is a data URI, false otherwise.
	 */
	public function is_data_uri() {
		if ( null === $this->url_starts_at || null === $this->url_length ) {
			return false;
		}

		// Check if the URL starts with 'data:' (case-insensitive).
		// We need at least 5 characters: 'd', 'a', 't', 'a', ':'.
		if ( $this->url_length < 5 ) {
			return false;
		}

		// Perform case-insensitive comparison of the first 5 bytes.
		$offset = $this->url_starts_at;
		return (
			( 'd' === $this->css[ $offset ] || 'D' === $this->css[ $offset ] ) &&
			( 'a' === $this->css[ $offset + 1 ] || 'A' === $this->css[ $offset + 1 ] ) &&
			( 't' === $this->css[ $offset + 2 ] || 'T' === $this->css[ $offset + 2 ] ) &&
			( 'a' === $this->css[ $offset + 3 ] || 'A' === $this->css[ $offset + 3 ] ) &&
			':' === $this->css[ $offset + 4 ]
		);
	}

	/**
	 * Replaces the currently matched URL with a new one.
	 *
	 * @param string $new_url The new URL to replace the current one with.
	 * @return bool True if the URL was set, false otherwise.
	 */
	public function set_raw_url( $new_url ) {
		if ( null === $this->url_starts_at ) {
			return false;
		}

		$this->matched_url                             = $new_url;
		$this->decoded_url                             = $new_url;
		$this->lexical_updates[ $this->url_starts_at ] = new WP_HTML_Text_Replacement(
			$this->url_starts_at,
			$this->url_length,
			$new_url
		);

		return true;
	}

	/**
	 * Applies all pending lexical updates to the CSS content.
	 *
	 * @return int The number of updates applied.
	 */
	private function apply_lexical_updates() {
		if ( ! count( $this->lexical_updates ) ) {
			return 0;
		}

		/*
		 * Updates must occur in lexical order; that is, each
		 * replacement must be made before all others which follow it
		 * at later string indices in the input document.
		 */
		ksort( $this->lexical_updates );

		$bytes_already_copied = 0;
		$output_buffer        = '';
		foreach ( $this->lexical_updates as $diff ) {
			$shift = strlen( $diff->text ) - $diff->length;

			// Adjust the cursor position by however much an update affects it.
			if ( $diff->start < $this->bytes_already_parsed ) {
				$this->bytes_already_parsed += $shift;
			}

			$output_buffer .= substr( $this->css, $bytes_already_copied, $diff->start - $bytes_already_copied );
			if ( $diff->start === $this->url_starts_at ) {
				$this->url_starts_at = strlen( $output_buffer );
				$this->url_length    = strlen( $diff->text );
			}
			$output_buffer       .= $diff->text;
			$bytes_already_copied = $diff->start + $diff->length;
		}

		$this->css             = $output_buffer . substr( $this->css, $bytes_already_copied );
		$this->lexical_updates = array();

		return count( $this->lexical_updates );
	}

	/**
	 * Gets the updated CSS content with all URL replacements applied.
	 *
	 * @return string The updated CSS content.
	 */
	public function get_updated_css() {
		$this->apply_lexical_updates();

		return $this->css;
	}

	/**
	 * Decodes CSS escape sequences within a URL value.
	 *
	 * CSS allows escaping characters using backslash notation. This method handles:
	 * - Hexadecimal escapes: \20 (space), \0000A0 (non-breaking space)
	 * - Single character escapes: \( \) \" \' \\
	 *
	 * Escape sequences can be:
	 * - 1-6 hex digits optionally followed by whitespace: "\20 B" or "\000020 B" ("&B")
	 * - A backslash followed by any non-hex character: \( becomes (
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#consume-escaped-code-point
	 * @see https://www.w3.org/TR/CSS22/syndata.html#tokenizatxion
	 * @see https://www.w3.org/TR/CSS21/syndata.html#escaped-characters
	 *
	 * @param  string $value The CSS value to decode.
	 * @return string The decoded value.
	 */
	protected function decode_css_escapes( string $value ): string {
		$length = strlen( $value );
		$result = '';
		$i      = 0;

		while ( $i < $length ) {
			// Find the next backslash.
			$span = strcspn( $value, '\\', $i );
			if ( $span > 0 ) {
				$result .= substr( $value, $i, $span );
				$i      += $span;
			}

			if ( $i >= $length ) {
				break;
			}

			// We're at a backslash, skip it.
			++$i;

			if ( $i >= $length ) {
				break;
			}

			// Collect up to 6 hex digits.
			$hex_len = strspn( $value, '0123456789abcdefABCDEF', $i );
			if ( $hex_len > 6 ) {
				$hex_len = 6;
			}

			if ( $hex_len > 0 ) {
				$hex     = substr( $value, $i, $hex_len );
				$result .= codepoint_to_utf8_bytes( hexdec( $hex ) );
				$i      += $hex_len;

				/**
				 * Skip trailing whitespace after hex escape.
				 */
				$ws_len = strspn( $value, " \n\r\t\f", $i );
				if ( $ws_len > 0 ) {
					// Special handling for CRLF: treat as single whitespace.
					if ( $i + 1 < $length && "\r" === $value[ $i ] && "\n" === $value[ $i + 1 ] ) {
						$i += 2;
					} else {
						// Skip a single whitespace character.
						$i += 1;
					}
				}
				continue;
			}

			// Not a hex escape, check if it's an escaped line break.
			$next = $value[ $i ];

			if ( "\n" === $next || "\f" === $next ) {
				// Escaped line break - consume it without adding to result.
				++$i;
				continue;
			}

			if ( "\r" === $next ) {
				// Escaped CR or CRLF - consume without adding to result.
				++$i;
				if ( $i < $length && "\n" === $value[ $i ] ) {
					++$i; // Consume LF in CRLF.
				}
				continue;
			}

			// Regular character escape - add the escaped character literally.
			$result .= $next;
			++$i;
		}

		return $result;
	}
}
