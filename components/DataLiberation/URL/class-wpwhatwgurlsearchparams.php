<?php

namespace WordPress\DataLiberation\URL;

/**
 * Adapter that exposes the WHATWG URLSearchParams API on top of WPWhatwgUrl.
 *
 * The PHP 8.5 native Uri\WhatWg\Url parser does not expose a URLSearchParams
 * counterpart, but the rest of the codebase (and Rowbot's URL implementation)
 * relies on $url->searchParams->get()/has()/set()/delete()/append(). This
 * class reads from and writes to the owning URL's query string so callers can
 * keep using the familiar property-based API regardless of which parser
 * backs WPURL::parse().
 *
 * Pairs are serialized as application/x-www-form-urlencoded — that matches
 * the WHATWG URLSearchParams stringifier and what Rowbot produces.
 */
class WPWhatwgUrlSearchParams implements \Countable, \IteratorAggregate {

	/** @var WPWhatwgUrl */
	private $owner;

	public function __construct( WPWhatwgUrl $owner ) {
		$this->owner = $owner;
	}

	public function __get( $name ) {
		if ( 'size' === $name ) {
			return count( $this->read_pairs() );
		}
		return null;
	}

	public function get( $name ) {
		foreach ( $this->read_pairs() as $pair ) {
			if ( $pair[0] === (string) $name ) {
				return $pair[1];
			}
		}
		return null;
	}

	public function getAll( $name ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		$values = array();
		foreach ( $this->read_pairs() as $pair ) {
			if ( $pair[0] === (string) $name ) {
				$values[] = $pair[1];
			}
		}
		return $values;
	}

	public function has( $name ) {
		foreach ( $this->read_pairs() as $pair ) {
			if ( $pair[0] === (string) $name ) {
				return true;
			}
		}
		return false;
	}

	public function set( $name, $value ) {
		$name   = (string) $name;
		$value  = (string) $value;
		$pairs  = $this->read_pairs();
		$result = array();
		$found  = false;
		foreach ( $pairs as $pair ) {
			if ( $pair[0] === $name ) {
				if ( ! $found ) {
					$result[] = array( $name, $value );
					$found    = true;
				}
				continue;
			}
			$result[] = $pair;
		}
		if ( ! $found ) {
			$result[] = array( $name, $value );
		}
		$this->write_pairs( $result );
	}

	public function append( $name, $value ) {
		$pairs   = $this->read_pairs();
		$pairs[] = array( (string) $name, (string) $value );
		$this->write_pairs( $pairs );
	}

	public function delete( $name ) {
		$name   = (string) $name;
		$result = array();
		foreach ( $this->read_pairs() as $pair ) {
			if ( $pair[0] === $name ) {
				continue;
			}
			$result[] = $pair;
		}
		$this->write_pairs( $result );
	}

	public function count(): int {
		return count( $this->read_pairs() );
	}

	public function getIterator(): \Iterator { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return new \ArrayIterator( $this->read_pairs() );
	}

	// Method name mirrors the WHATWG URLSearchParams API.
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	public function toString(): string {
		return $this->serialize( $this->read_pairs() );
	}

	public function __toString(): string {
		return $this->toString();
	}

	/**
	 * @return array<int, array{0: string, 1: string}>
	 */
	private function read_pairs() {
		$search = $this->owner->__get( 'search' );
		if ( '' === $search || '?' === $search ) {
			return array();
		}
		$query = '?' === $search[0] ? substr( $search, 1 ) : $search;
		$pairs = array();
		foreach ( explode( '&', $query ) as $segment ) {
			if ( '' === $segment ) {
				continue;
			}
			$eq = strpos( $segment, '=' );
			if ( false === $eq ) {
				$pairs[] = array( self::decode( $segment ), '' );
			} else {
				$pairs[] = array(
					self::decode( substr( $segment, 0, $eq ) ),
					self::decode( substr( $segment, $eq + 1 ) ),
				);
			}
		}
		return $pairs;
	}

	private function write_pairs( array $pairs ) {
		if ( empty( $pairs ) ) {
			$this->owner->__set( 'search', '' );
			return;
		}
		$this->owner->__set( 'search', '?' . $this->serialize( $pairs ) );
	}

	private function serialize( array $pairs ) {
		$parts = array();
		foreach ( $pairs as $pair ) {
			$parts[] = self::encode( $pair[0] ) . '=' . self::encode( $pair[1] );
		}
		return implode( '&', $parts );
	}

	private static function encode( $value ) {
		// urlencode() produces application/x-www-form-urlencoded output —
		// spaces become "+" and the rest is percent-encoded — matching the
		// WHATWG URLSearchParams stringifier. rawurlencode() would percent-
		// encode spaces as %20 instead, which is wrong here.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode
		return urlencode( $value );
	}

	private static function decode( $value ) {
		return urldecode( $value );
	}
}
