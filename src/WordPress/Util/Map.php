<?php

namespace WordPress\Util;

use ArrayAccess;
use IteratorAggregate;
use Traversable;

class Map implements ArrayAccess, IteratorAggregate {
	private $pairs = array();

	public function __construct() {
	}

	public function values()
	{
		return array_map(function($pair) {
			return $pair[1];
		}, $this->pairs);		
	}

	public function offsetExists( $offset ): bool {
		foreach ( $this->pairs as $pair ) {
			if ( $pair[0] === $offset ) {
				return true;
			}
		}

		return false;
	}

	#[\ReturnTypeWillChange]
	public function offsetGet( $offset ) {
		foreach ( $this->pairs as $pair ) {
			if ( $pair[0] === $offset ) {
				return $pair[1];
			}
		}

		// TODO Evaluate waring: 'ext-json' is missing in composer.json
		throw new \Exception( 'Stream for resource ' . json_encode( $offset ) . ' not found' );
	}

	#[\ReturnTypeWillChange]
	public function offsetSet( $offset, $value ) {
		foreach ( $this->pairs as $k => $pair ) {
			if ( $pair[0] === $offset ) {
				$this->pairs[ $k ] = array( $offset, $value );

				return;
			}
		}
		$this->pairs[] = array( $offset, $value );
	}

	#[\ReturnTypeWillChange]
	public function offsetUnset( $offset ) {
		foreach ( $this->pairs as $i => $pair ) {
			if ( $pair[0] === $offset ) {
				unset( $this->pairs[ $i ] );
			}
		}
	}

	public function getIterator(): Traversable {
		return new ArrayPairIterator( array_values( $this->pairs ) );
	}
}
