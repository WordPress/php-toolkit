<?php

namespace WordPress\DataLiberation\URL;

use Rowbot\URL\URL;
use WP_HTML_Text_Replacement;

use function WordPress\Encoding\codepoint_to_utf8_bytes;

/**
 * Finds and replaces URLs within CSS content (e.g., style attribute values).
 *
 * This processor specifically handles url() functions in CSS, detecting them
 * while properly skipping over comments and strings to avoid false matches.
 *
 * The regex pattern used is designed to:
 * 1. Skip CSS comments (/* ... *\/)
 * 2. Skip quoted strings ("..." and '...')
 * 3. Match url(...) with quoted or unquoted URL values
 * 4. Handle whitespace and comments within url() properly
 */
class CSSUrlProcessor {

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
	 * The regular expression pattern used for matching URL candidates
	 * from the CSS.
	 *
	 * This regex:
	 * 1. Skips things we must not search inside (comments, strings)
	 * 2. Matches url(...) outside of those
	 *
	 * @var string
	 */
	private $regex = <<<REGEX
/
		# 1) Skip strings and comments – we must not search inside those:
		(?:
			\/\*[^*]*\*+(?:[^\/\*][^*]*\*+)*\/        # comment
			| "(?:[^"\\\\\r\n]|\\\\.)*"               # "string"
			| '(?:[^'\\\\\r\n]|\\\\.)*'               # 'string'
		)(*SKIP)(*F)
		|
		# 2) Match url(...) outside of those:
		(?i)\burl                                           # case-insensitive url
		\(
			(?:(?>\s)*)  # skip whitespaces (comments are not allowed inside url())
			(?:
				(?P<q>["'])                                 # quoted URL
					(?P<url_quoted>(?:\\\\.|(?!\k<q>).)*?)
				\k<q>
			|
				(?P<url_unquoted>(?:\\\\[^\r\n]|[^"'\(\)\\\\\s])+)
			)
			(?:(?>\s)*)  # skip whitespaces (comments are not allowed inside url())
		\)
/x
REGEX;

	/**
	 * @see \WP_HTML_Tag_Processor
	 * @var WP_HTML_Text_Replacement[]
	 */
	private $lexical_updates = array();

	/**
	 * The full match including url(...) wrapper
	 *
	 * @var string
	 */
	private $full_match;

	/**
	 * The byte position where the full match starts
	 *
	 * @var int
	 */
	private $full_match_start;

	/**
	 * The length of the full match
	 *
	 * @var int
	 */
	private $full_match_length;

	public function __construct( $css, $base_url = null ) {
		$this->css      = $css;
		$this->base_url = $base_url;
	}

	/**
	 * Finds the next URL in the CSS content.
	 *
	 * @return bool True if a URL was found, false otherwise.
	 */
	public function next_url() {
		$this->matched_url       = null;
		$this->decoded_url       = null;
		$this->parsed_url        = null;
		$this->url_starts_at     = null;
		$this->url_length        = null;
		$this->full_match        = null;
		$this->full_match_start  = null;
		$this->full_match_length = null;

		$matches = array();
		$found   = preg_match( $this->regex, $this->css, $matches, PREG_OFFSET_CAPTURE, $this->bytes_already_parsed );
		if ( 1 !== $found ) {
			return false;
		}

		// Determine which capture group matched.
		if ( isset( $matches['url_quoted'] ) && '' !== $matches['url_quoted'][0] ) {
			$this->matched_url   = $matches['url_quoted'][0];
			$this->url_starts_at = $matches['url_quoted'][1];
			$this->url_length    = strlen( $this->matched_url );
		} elseif ( isset( $matches['url_unquoted'] ) && '' !== $matches['url_unquoted'][0] ) {
			$this->matched_url   = $matches['url_unquoted'][0];
			$this->url_starts_at = $matches['url_unquoted'][1];
			$this->url_length    = strlen( $this->matched_url );
		} else {
			return false;
		}

		// Store the full match for context.
		$this->full_match        = $matches[0][0];
		$this->full_match_start  = $matches[0][1];
		$this->full_match_length = strlen( $this->full_match );

		// Update the parsing position.
		$this->bytes_already_parsed = $this->full_match_start + $this->full_match_length;

		// Parse the URL.
		$this->decoded_url = $this->decode_css_escapes( $this->matched_url );
		$parsed_url        = WPURL::parse( $this->decoded_url, $this->base_url );
		$this->parsed_url  = ( false === $parsed_url ) ? false : $parsed_url;

		return true;
	}

	/**
	 * Gets the raw URL that was matched.
	 *
	 * @return string|false The raw URL or false if no URL is currently matched.
	 */
	public function get_raw_url() {
		if ( null === $this->matched_url ) {
			return false;
		}

		if ( null !== $this->decoded_url ) {
			return $this->decoded_url;
		}

		return $this->matched_url;
	}

	/**
	 * Gets the parsed URL object.
	 *
	 * @return URL|false The parsed URL or false if no URL is currently matched.
	 */
	public function get_parsed_url() {
		if ( null === $this->parsed_url ) {
			return false;
		}

		return $this->parsed_url;
	}

	/**
	 * Replaces the currently matched URL with a new one.
	 *
	 * @param string $new_url The new URL to replace the current one with.
	 * @return bool True if the URL was set, false otherwise.
	 */
	public function set_raw_url( $new_url ) {
		if ( null === $this->matched_url ) {
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
	 * – Exactly 6 hexadecimal digits: "\000026B" ("&B")
	 * - 1-6 hex digits optionally followed by whitespace: "\20 B" or "\000020 B" ("&B")
	 * - A backslash followed by any non-hex character: \( becomes (
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#consume-escaped-code-point
	 * @see https://www.w3.org/TR/CSS22/syndata.html#tokenization
	 * @see https://www.w3.org/TR/CSS21/syndata.html#escaped-characters
	 *
	 * @param  string $value The CSS value to decode.
	 * @return string The decoded value.
	 */
	protected function decode_css_escapes( string $value ): string {
		$length = strlen( $value );
		$result = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $value[ $i ];

			if ( '\\' !== $char ) {
				$result .= $char;
				continue;
			}

			++$i;

			if ( $i >= $length ) {
				break;
			}

			$hex = '';
			$j   = $i;

			while ( $j < $length && strlen( $hex ) < 6 && $this->is_hex_digit( $value[ $j ] ) ) {
				$hex .= $value[ $j ];
				++$j;
			}

			if ( '' !== $hex ) {
				$result .= codepoint_to_utf8_bytes( hexdec( $hex ) );
				$i       = $j - 1;

				while ( $j < $length && $this->is_css_whitespace( $value[ $j ] ) ) {
					if ( "\r" === $value[ $j ] && $j + 1 < $length && "\n" === $value[ $j + 1 ] ) {
						++$j;
					}
					++$j;
				}

				$i = $j - 1;
				continue;
			}

			$next = $value[ $i ];

			if ( $this->is_line_break( $next ) ) {
				if ( "\r" === $next && $i + 1 < $length && "\n" === $value[ $i + 1 ] ) {
					++$i;
				}
				continue;
			}

			$result .= $next;
		}

		return $result;
	}

	private function is_hex_digit( string $char ): bool {
		return (bool) preg_match( '/^[0-9a-fA-F]$/', $char );
	}

	private function is_css_whitespace( string $char ): bool {
		return ' ' === $char || "\n" === $char || "\r" === $char || "\t" === $char || "\f" === $char;
	}

	private function is_line_break( string $char ): bool {
		return "\n" === $char || "\r" === $char || "\f" === $char;
	}
}
