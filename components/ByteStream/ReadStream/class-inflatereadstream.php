<?php

namespace WordPress\ByteStream\ReadStream;

use Exception;
use WordPress\ByteStream\ByteStreamException;

class InflateReadStream extends BaseByteReadStream {

	protected $inflate_context;
	protected $inflate_encoding;
	protected $upstream;

	/**
	 * The offset of the underlying reader at the time of the first read
	 * from the InflateReader.
	 *
	 * It's the only way to seek to an earlier offset when reading from
	 * a git-like object that has a plaintext header and gzipped body:
	 *
	 * blob <size>\x00
	 * <gzip-compressed-data>
	 *
	 * If we just seeked to offset=0, we'd start inflating the plaintext,
	 * not the deflated body, and get an error.
	 *
	 * @var int|null
	 */
	protected $delegate_offset_0;

	/**
	 * Ensure properties are defined or inherited.
	 *
	 * @var bool
	 */
	protected $is_closed                = false;
	protected $buffer                   = '';
	protected $bytes_already_forgotten  = 0;
	protected $offset_in_current_buffer = 0;

	public function __construct( ByteReadStream $upstream, $encoding = ZLIB_ENCODING_DEFLATE ) {
		$this->inflate_encoding = $encoding;
		$this->inflate_init();
		$this->upstream = $upstream;
	}

	protected function internal_pull( $n ): string {
		if ( null === $this->delegate_offset_0 ) {
			$this->delegate_offset_0 = $this->upstream->tell();
		}

		if ( ! $this->inflate_context ) {
			return '';
		}

		if ( $this->upstream->reached_end_of_data() ) {
			$bytes                 = inflate_add( $this->inflate_context, '', ZLIB_FINISH );
			$this->inflate_context = null;

			return $bytes;
		}

		$n         = max( 200, $n );
		$available = $this->upstream->pull( $n );

		$inflated = inflate_add( $this->inflate_context, $this->upstream->consume( $available ) );
		if ( false === $inflated ) {
			throw new ByteStreamException( esc_html( 'Inflate error: ' . $this->get_error_string() ) );
		}

		return $inflated;
	}

	public function length(): ?int {
		return null;
	}

	protected function internal_close_reading(): void {
		$this->inflate_context = null;
	}

	protected function internal_reached_end_of_data(): bool {
		return null === $this->inflate_context && $this->upstream->reached_end_of_data();
	}

	public function seek( $offset ): void {
		if ( null !== $this->length() && $offset > $this->length() ) {
			throw new ByteStreamException( 'Cannot seek past the available data. Call append_bytes() first.' );
		}

		if ( $offset < $this->tell() ) {
			$this->upstream->seek( $this->delegate_offset_0 ?? 0 );
			$this->buffer                   = '';
			$this->bytes_already_forgotten  = 0;
			$this->offset_in_current_buffer = 0;

			$this->inflate_init();
		}

		while ( $this->tell() < $offset ) {
			$remaining_bytes = $offset - $this->tell();
			$next_chunk_size = min( 50 * 1024, $remaining_bytes );
			$pulled          = $this->pull( $next_chunk_size );
			// Keep skipping bytes until we've consumed enough.
			$this->consume( min( $remaining_bytes, $pulled ) );
		}
	}

	private function inflate_init() {
		$this->inflate_context = inflate_init( $this->inflate_encoding );
		if ( ! $this->inflate_context ) {
			throw new Exception( 'Failed to initialize inflate context' );
		}
	}

	protected function get_error_string() {
		$status = inflate_get_status( $this->inflate_context );
		switch ( $status ) {
			case ZLIB_OK:
				$error_string = 'ZLIB_OK';
				break;
			case ZLIB_STREAM_END:
				$error_string = 'ZLIB_STREAM_END';
				break;
			case ZLIB_NEED_DICT:
				$error_string = 'ZLIB_NEED_DICT';
				break;
			case ZLIB_ERRNO:
				$error_string = 'ZLIB_ERRNO';
				break;
			case ZLIB_STREAM_ERROR:
				$error_string = 'ZLIB_STREAM_ERROR';
				break;
			case ZLIB_DATA_ERROR:
				$error_string = 'ZLIB_DATA_ERROR';
				break;
			case ZLIB_BUF_ERROR:
				$error_string = 'ZLIB_BUF_ERROR';
				break;
			case ZLIB_MEM_ERROR:
				$error_string = 'ZLIB_MEM_ERROR';
				break;
			default:
				$error_string = 'Unknown error';
				break;
		}

		return "Error $status: $error_string";
	}
}
