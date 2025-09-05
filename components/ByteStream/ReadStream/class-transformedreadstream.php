<?php

namespace WordPress\ByteStream\ReadStream;

use ArrayAccess;
use ReturnTypeWillChange;
use WordPress\ByteStream\ByteStreamException;
use WordPress\ByteStream\ByteTransformer\ByteTransformer;

class TransformedReadStream extends BaseByteReadStream implements ArrayAccess {

	/**
	 * @var ByteReadStream
	 */
	private $reader;

	/**
	 * @var ByteTransformer[]
	 */
	private $filters = array();

	private $filters_flushed = false;

	public function __construct( ByteReadStream $reader, array $filters = array() ) {
		$this->reader  = $reader;
		$this->filters = $filters;
	}

	public function get_upstream_reader(): ByteReadStream {
		return $this->reader;
	}

	protected function internal_pull( $max_bytes ): string {
		$bytes_pulled = $this->reader->pull( $max_bytes );
		if ( $bytes_pulled === 0 ) {
			if ( $this->reader->reached_end_of_data() && ! $this->filters_flushed ) {
				return $this->flush_filters();
			}

			return '';
		}

		$chunk = $this->reader->consume( $bytes_pulled );
		foreach ( $this->filters as $filter ) {
			$chunk = $filter->filter_bytes( $chunk );
			if ( $chunk === false ) {
				return '';
			}
		}

		return $chunk;
	}

	private function flush_filters(): string {
		$this->filters_flushed = true;

		$flush = '';
		foreach ( $this->filters as $filter ) {
			$flush  = $filter->filter_bytes( $flush );
			$flush .= $filter->flush();
		}

		return $flush;
	}

	/** @disregard P1038 */
	#[ReturnTypeWillChange]
	public function offsetGet( $offset ) {
		if ( ! isset( $this->filters[ $offset ] ) ) {
			throw new ByteStreamException( sprintf( 'Filter %s not found', $offset ) );
		}

		return $this->filters[ $offset ];
	}

	/** @disregard P1038 */
	#[ReturnTypeWillChange]
	public function offsetExists( $offset ) {
		return isset( $this->filters[ $offset ] );
	}

	/** @disregard P1038 */
	#[ReturnTypeWillChange]
	public function offsetSet( $offset, $value ) {
		throw new ByteStreamException( 'Filters are immutable' );
	}

	/** @disregard P1038 */
	#[ReturnTypeWillChange]
	public function offsetUnset( $offset ) {
		throw new ByteStreamException( 'Filters are immutable' );
	}

	public function length(): ?int {
		return null;
	}

	protected function internal_reached_end_of_data(): bool {
		return $this->filters_flushed;
	}

	protected function seek_outside_of_buffer( int $target_offset ): void {
		throw new ByteStreamException( 'Seek is not supported on TransformedProducer' );
	}

	protected function internal_close_reading(): void {
		$this->filters_flushed = true;
	}
}
