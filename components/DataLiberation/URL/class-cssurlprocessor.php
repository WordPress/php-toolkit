<?php

namespace WordPress\DataLiberation\URL;

use WP_HTML_Text_Replacement;

use function WordPress\Encoding\codepoint_to_utf8_bytes;

require_once __DIR__ . '/class-cssprocessor.php';

/**
 * Provides URL specific helpers on top of the CSSProcessor tokenizer.
 */
class CSSUrlProcessor {
	/**
	 * @var string
	 */
	private $css;

	/**
	 * @var array<int, array>
	 */
	private $url_tokens = array();

	/**
	 * @var int
	 */
	private $bytes_already_parsed = 0;

	/**
	 * @var int|null
	 */
	private $url_starts_at = null;

	/**
	 * @var int|null
	 */
	private $url_length = null;

	/**
	 * @var string|null
	 */
	private $matched_url = null;

	/**
	 * @var string|null
	 */
	private $decoded_url = null;

	/**
	 * @var WP_HTML_Text_Replacement[]
	 */
	private $lexical_updates = array();

	/**
	 * @param string $css CSS source without wrapping braces.
	 */
	public function __construct( string $css ) {
		$this->css = $css;
		$this->collect_url_tokens( $css );
	}

	/**
	 * Moves the cursor to the next URL token, if available.
	 *
	 * @return bool
	 */
	public function next_url(): bool {
		$this->matched_url   = null;
		$this->decoded_url   = null;
		$this->url_starts_at = null;
		$this->url_length    = null;

		foreach ( $this->url_tokens as $token ) {
			if ( $token['token_end'] <= $this->bytes_already_parsed ) {
				continue;
			}

			$this->url_starts_at       = $token['value_start'];
			$this->url_length          = $token['value_length'];
			$this->bytes_already_parsed = $token['token_end'];

			return true;
		}

		$this->bytes_already_parsed = strlen( $this->css );
		return false;
	}

	/**
	 * Returns the raw (decoded) URL for the current match.
	 *
	 * @return string|false
	 */
	public function get_raw_url() {
		if ( null === $this->url_starts_at || null === $this->url_length ) {
			return false;
		}

		if ( null !== $this->decoded_url ) {
			return $this->decoded_url;
		}

		if ( null === $this->matched_url ) {
			$this->matched_url = substr( $this->css, $this->url_starts_at, $this->url_length );
		}

		$this->decoded_url = $this->decode_css_escapes( $this->matched_url );
		return $this->decoded_url;
	}

	/**
	 * Replaces the currently matched URL with a new value.
	 *
	 * @param string $new_url Replacement URL without quoting.
	 * @return bool
	 */
	public function set_raw_url( string $new_url ): bool {
		if ( null === $this->url_starts_at || null === $this->url_length ) {
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
	 * Returns the updated CSS with all replacements applied.
	 *
	 * @return string
	 */
	public function get_updated_css(): string {
		$this->apply_lexical_updates();
		return $this->css;
	}

	/**
	 * Determines whether the current URL is a data URI.
	 *
	 * @return bool
	 */
	public function is_data_uri(): bool {
		if ( null === $this->url_starts_at || null === $this->url_length ) {
			return false;
		}

		if ( $this->url_length < 5 ) {
			return false;
		}

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
	 * Applies pending lexical updates to the CSS buffer.
	 */
	private function apply_lexical_updates(): void {
		if ( ! count( $this->lexical_updates ) ) {
			return;
		}

		ksort( $this->lexical_updates );

		$bytes_already_copied = 0;
		$output_buffer        = '';

		foreach ( $this->lexical_updates as $diff ) {
			$shift = strlen( $diff->text ) - $diff->length;
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
	}

	/**
	 * Collects URL-bearing tokens from the token stream.
	 */
	private function collect_url_tokens( string $css ): void {
		$processor = new CSSProcessor( $css );
		$all_tokens = array();

		while ( $processor->next_token() ) {
			$all_tokens[] = array(
				'type'         => $processor->get_token_type(),
				'start'        => $processor->get_token_start(),
				'length'       => $processor->get_token_length(),
				'end'          => $processor->get_token_start() + $processor->get_token_length(),
				'name'         => $processor->get_token_name(),
				'value_start'  => $processor->get_token_value_start(),
				'value_length' => $processor->get_token_value_length(),
			);
		}

		$count = count( $all_tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			$token = $all_tokens[ $i ];
			if ( CSSProcessor::TOKEN_URL === $token['type'] ) {
				$this->url_tokens[] = array(
					'token_index'  => $i,
					'value_start'  => $token['value_start'],
					'value_length' => $token['value_length'],
					'token_end'    => $token['end'],
				);
				continue;
			}

			if ( CSSProcessor::TOKEN_FUNCTION === $token['type'] && 0 === strcasecmp( $token['name'], 'url' ) ) {
				$meta = $this->extract_url_from_function( $i, $all_tokens );
				if ( null !== $meta ) {
					$this->url_tokens[] = $meta;
				}
			}
		}

		usort(
			$this->url_tokens,
			static function ( array $a, array $b ): int {
				return $a['value_start'] <=> $b['value_start'];
			}
		);
	}

	/**
	 * Extracts URL metadata from a function token named "url".
	 *
	 * @param int   $index  Token index of the function token.
	 * @param array $tokens All tokens from the CSS.
	 * @return array|null
	 */
	private function extract_url_from_function( int $index, array $tokens ): ?array {
		$count = count( $tokens );
		$pos   = $index + 1;

		while ( $pos < $count && CSSProcessor::TOKEN_WHITESPACE === $tokens[ $pos ]['type'] ) {
			$pos++;
		}

		if ( $pos >= $count ) {
			return null;
		}

		$value_token = $tokens[ $pos ];
		if ( CSSProcessor::TOKEN_STRING !== $value_token['type'] ) {
			return null;
		}

		$closing_pos = $pos + 1;
		while ( $closing_pos < $count && CSSProcessor::TOKEN_WHITESPACE === $tokens[ $closing_pos ]['type'] ) {
			$closing_pos++;
		}

		if ( $closing_pos >= $count ) {
			return null;
		}

		$closing = $tokens[ $closing_pos ];
		if ( CSSProcessor::TOKEN_RIGHT_PAREN !== $closing['type'] ) {
			return null;
		}

		return array(
			'token_index'  => $index,
			'value_start'  => $value_token['value_start'],
			'value_length' => $value_token['value_length'],
			'token_end'    => $closing['end'],
		);
	}

	/**
	 * Decodes CSS escape sequences.
	 *
	 * @param string $value Encoded string.
	 * @return string
	 */
	protected function decode_css_escapes( string $value ): string {
		$length = strlen( $value );
		$result = '';
		$at     = 0;

		while ( $at < $length ) {
			$span = strcspn( $value, '\\', $at );
			if ( $span > 0 ) {
				$result .= substr( $value, $at, $span );
				$at     += $span;
			}

			if ( $at >= $length ) {
				break;
			}

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
				$result .= codepoint_to_utf8_bytes( hexdec( $hex ) );
				$at     += $hex_len;

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

if ( ! class_exists( __NAMESPACE__ . '\\CSSURLProcessor', false ) ) {
	class_alias( __NAMESPACE__ . '\\CSSUrlProcessor', __NAMESPACE__ . '\\CSSURLProcessor' );
}
