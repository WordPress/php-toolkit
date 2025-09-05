<?php

namespace WordPress\ByteStream\ReadStream;

use Exception;

class DeflateReadStream extends BaseByteReadStream {


	/**
	 * The offset of the underlying reader at the time of the first read
	 * from the DeflateReader.
	 */
	protected $delegate_offset_0;
	protected $deflate_context;

	protected $deflate_encoding;
	protected $upstream;

	public function __construct( ByteReadStream $upstream, $encoding = ZLIB_ENCODING_DEFLATE ) {
		$this->deflate_encoding = $encoding;
		$this->deflate_init();
		$this->upstream = $upstream;
	}

	protected function internal_pull( $n ): string {
		if ( null === $this->delegate_offset_0 ) {
			$this->delegate_offset_0 = $this->upstream->tell();
		}

		if ( ! $this->deflate_context ) {
			return '';
		}

		if ( $this->upstream->reached_end_of_data() ) {
			$bytes                 = deflate_add( $this->deflate_context, '', ZLIB_FINISH );
			$this->deflate_context = null;

			return $bytes;
		}

		$inflated = $this->upstream->peek( $n );
		if ( ! strlen( $inflated ) ) {
			$this->upstream->pull( $n );
			$inflated = $this->upstream->peek( $n );
		}
		$this->upstream->consume( strlen( $inflated ) );

		return deflate_add( $this->deflate_context, $inflated );
	}

	public function length(): ?int {
		// The length of the deflated stream is unknown until the stream is closed.
		return null;
	}

	protected function internal_close_reading(): void {
		$this->deflate_context = null;
	}

	protected function internal_reached_end_of_data(): bool {
		return null === $this->deflate_context && $this->upstream->reached_end_of_data();
	}

	protected function seek_outside_of_buffer( $target_offset ): void {
		if ( $target_offset < $this->tell() ) {
			$this->buffer                   = '';
			$this->bytes_already_forgotten  = 0;
			$this->offset_in_current_buffer = 0;

			$this->deflate_init();
			$this->upstream->seek( $this->delegate_offset_0 ?? 0 );
		}

		while ( $this->tell() < $target_offset ) {
			$remaining_bytes = $target_offset - $this->tell();
			$next_chunk_size = min( 50 * 1024, $remaining_bytes );
			$pulled          = $this->pull( $next_chunk_size );
			// Keep skipping bytes until we've consumed enough.
			$this->consume( min( $remaining_bytes, $pulled ) );
		}
	}

	private function deflate_init() {
		$this->deflate_context = deflate_init( $this->deflate_encoding );
		if ( ! $this->deflate_context ) {
			throw new Exception( 'Failed to initialize deflate context' );
		}
	}
}
