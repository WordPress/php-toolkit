<?php
// phpcs:disable Generic.Classes.DuplicateClassName.Found,Generic.Files.OneObjectStructurePerFile.MultipleFound

namespace WordPress\DataLiberation\URL;

use WP_HTML_Text_Replacement;

/**
 * Public URLInTextProcessor adapter backed by the native candidate scanner.
 *
 * The native extension intentionally exposes a narrow scanner surface. This
 * wrapper keeps the public PHP API intact: native code finds candidate URL
 * strings, then PHP performs the same WHATWG validation and text replacement
 * bookkeeping as the fallback implementation.
 */
class NativeURLInTextProcessorWrapper {
	private const CANDIDATE_CACHE_MAX = 4096;

	private $text;
	private $bytes_already_parsed = 0;
	private $matched_url;
	private $parsed_url;
	private $url_starts_at;
	private $url_length;
	private $did_prepend_protocol;
	private $base_url;
	private $base_protocol;
	private $native_processor;
	private $fallback_processor;
	private $lexical_updates = array();

	private static $candidate_cache      = array();
	private static $candidate_cache_ring = array();
	private static $candidate_cache_next = 0;

	public function __construct( $text, $base_url = null ) {
		$this->text          = $text;
		$this->base_url      = $base_url;
		$this->base_protocol = $base_url ? parse_url( $base_url, PHP_URL_SCHEME ) : null;

		if ( preg_match( '/[^\x00-\x7F]/', $text ) ) {
			$this->fallback_processor = new PHPURLInTextProcessor( $text, $base_url );
			return;
		}

		$native_class           = 'WordPress\\DataLiberation\\URL\\NativeURLInTextProcessor';
		$this->native_processor = new $native_class( $text, $base_url );
	}

	public function next_url() {
		if ( null !== $this->fallback_processor ) {
			return $this->fallback_processor->next_url();
		}

		while ( $this->native_processor->next_url() ) {
			$this->matched_url          = $this->native_processor->get_raw_url();
			$this->parsed_url           = null;
			$this->url_starts_at        = null;
			$this->url_length           = null;
			$this->did_prepend_protocol = false;

			if ( false === $this->matched_url || '' === $this->matched_url || '::' === $this->matched_url ) {
				continue;
			}

			$url_starts_at = strpos( $this->text, $this->matched_url, $this->bytes_already_parsed );
			if ( false === $url_starts_at ) {
				continue;
			}
			$this->bytes_already_parsed = $url_starts_at + strlen( $this->matched_url );
			$this->url_starts_at        = $url_starts_at;
			$this->url_length           = strlen( $this->matched_url );

			$had_protocol = method_exists( $this->native_processor, 'had_protocol' )
				? $this->native_processor->had_protocol()
				: WPURL::has_http_https_protocol( $this->matched_url );

			$cache_key = $this->base_url . "\0" . ( $had_protocol ? '1' : '0' ) . "\0" . $this->matched_url;
			$cached    = self::candidate_cache_get( $cache_key );
			if ( null !== $cached ) {
				if ( false === $cached ) {
					continue;
				}
				$this->parsed_url           = $cached['parsed_url'];
				$this->did_prepend_protocol = $cached['did_prepend_protocol'];

				return true;
			}

			$parsed_url = $this->parse_and_validate_candidate( $had_protocol );
			if ( false === $parsed_url ) {
				self::candidate_cache_set( $cache_key, false );
				continue;
			}

			self::candidate_cache_set(
				$cache_key,
				array(
					'parsed_url'           => $parsed_url,
					'did_prepend_protocol' => $this->did_prepend_protocol,
				)
			);

			$this->parsed_url = $parsed_url;

			return true;
		}

		return false;
	}

	public function get_raw_url() {
		if ( null !== $this->fallback_processor ) {
			return $this->fallback_processor->get_raw_url();
		}

		return $this->matched_url ?? false;
	}

	public function get_parsed_url() {
		if ( null !== $this->fallback_processor ) {
			return $this->fallback_processor->get_parsed_url();
		}

		return $this->parsed_url ?? false;
	}

	public function set_raw_url( $new_url ) {
		if ( null !== $this->fallback_processor ) {
			return $this->fallback_processor->set_raw_url( $new_url );
		}

		if ( null === $this->matched_url ) {
			return false;
		}

		if ( $this->did_prepend_protocol ) {
			$new_url = substr( $new_url, strpos( $new_url, '://' ) + 3 );
		}

		$this->matched_url                             = $new_url;
		$this->lexical_updates[ $this->url_starts_at ] = new WP_HTML_Text_Replacement(
			$this->url_starts_at,
			$this->url_length,
			$new_url
		);

		return true;
	}

	public function get_updated_text() {
		if ( null !== $this->fallback_processor ) {
			return $this->fallback_processor->get_updated_text();
		}

		$this->apply_lexical_updates();

		return $this->text;
	}

	private function parse_and_validate_candidate( $had_protocol ) {
		$preprocessed_url = $this->matched_url;
		if ( $this->base_url && $this->base_protocol && ! $had_protocol ) {
			$preprocessed_url           = WPURL::ensure_protocol( $preprocessed_url, $this->base_protocol );
			$this->did_prepend_protocol = true;
		}

		$parsed_url = WPURL::parse( $preprocessed_url, $this->base_url );
		if ( false === $parsed_url ) {
			return false;
		}

		if ( $parsed_url->protocol && ! in_array( $parsed_url->protocol, array( 'http:', 'https:' ), true ) ) {
			return false;
		}

		if ( $parsed_url->username || $parsed_url->password ) {
			return false;
		}

		if ( ! $had_protocol ) {
			$last_dot_position = strrpos( $parsed_url->hostname, '.' );
			if ( false === $last_dot_position ) {
				return false;
			}

			$tld = substr( $parsed_url->hostname, $last_dot_position + 1 );
			if ( ! WPURL::is_known_public_domain( $tld ) ) {
				return false;
			}
		}

		return $parsed_url;
	}

	private function apply_lexical_updates() {
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

			$output_buffer .= substr( $this->text, $bytes_already_copied, $diff->start - $bytes_already_copied );
			if ( $diff->start === $this->url_starts_at ) {
				$this->url_starts_at = strlen( $output_buffer );
				$this->url_length    = strlen( $diff->text );
			}
			$output_buffer       .= $diff->text;
			$bytes_already_copied = $diff->start + $diff->length;
		}

		$this->text            = $output_buffer . substr( $this->text, $bytes_already_copied );
		$this->lexical_updates = array();
	}

	private static function candidate_cache_get( $key ) {
		return array_key_exists( $key, self::$candidate_cache )
			? self::$candidate_cache[ $key ]
			: null;
	}

	private static function candidate_cache_set( $key, $value ) {
		if ( ! array_key_exists( $key, self::$candidate_cache ) ) {
			if ( count( self::$candidate_cache_ring ) < self::CANDIDATE_CACHE_MAX ) {
				self::$candidate_cache_ring[] = $key;
			} else {
				$evicted_key = self::$candidate_cache_ring[ self::$candidate_cache_next ];
				unset( self::$candidate_cache[ $evicted_key ] );
				self::$candidate_cache_ring[ self::$candidate_cache_next ] = $key;
			}

			self::$candidate_cache_next = ( self::$candidate_cache_next + 1 ) % self::CANDIDATE_CACHE_MAX;
		}

		self::$candidate_cache[ $key ] = $value;
	}
}
