<?php

namespace WordPress\DataLiberation\URL;

/**
 * Small bounded cache for URL rewrite hot paths.
 *
 * The cache intentionally uses fixed-size ring eviction instead of tracking
 * frequency or recency. These rewrite paths mostly benefit from avoiding
 * repeated work for the same few URLs inside a large import batch, and the
 * constant-memory ring keeps that optimization predictable.
 */
class URLRewriteCache {
	const DEFAULT_MAX_ENTRIES = 4096;

	private $max_entries;
	private $values = array();
	private $ring   = array();
	private $next   = 0;

	public function __construct( $max_entries = self::DEFAULT_MAX_ENTRIES ) {
		$this->max_entries = max( 1, (int) $max_entries );
	}

	public function get( $key ) {
		return array_key_exists( $key, $this->values )
			? $this->values[ $key ]
			: null;
	}

	public function set( $key, $value ) {
		if ( ! array_key_exists( $key, $this->values ) ) {
			if ( count( $this->ring ) < $this->max_entries ) {
				$this->ring[] = $key;
			} else {
				$evicted_key = $this->ring[ $this->next ];
				unset( $this->values[ $evicted_key ] );
				$this->ring[ $this->next ] = $key;
			}

			$this->next = ( $this->next + 1 ) % $this->max_entries;
		}

		$this->values[ $key ] = $value;
	}
}
