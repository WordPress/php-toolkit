<?php

namespace WordPress\Git;

use WordPress\ByteStream\NotEnoughDataException;
use WordPress\ByteStream\ReadStream\BaseByteReadStream;
use WordPress\ByteStream\ReadStream\ByteReadStream;
use WordPress\ByteStream\ReadStream\InflateReadStream;
use WordPress\Git\Protocol\Parser\CommitParser;
use WordPress\Git\Protocol\Parser\TreeParser;

class GitObjectDecoder extends BaseByteReadStream {

	/**
	 * @var string|null
	 */
	private $object_header;
	/**
	 * @var string|null
	 */
	private $object_type_name;
	/**
	 * @var int|null
	 */
	private $uncompressed_length;
	/**
	 * @var int
	 */
	private $header_length = 0; // Uncompressed header length (in bytes, incl. trailing NUL).
	/** Decompressed view of the full object (header+body).
	 *
	 * @var InflateReadStream
	 */
	private $inflated_reader;

	/** Points to the body inside $inflated_reader; seek(0) == body start.
	 *
	 * @var ByteReadStream
	 */
	private $body_source;

	public function __construct( ByteReadStream $upstream ) {
		$this->inflated_reader = new InflateReadStream( $upstream );
		$this->body_source     = $this->inflated_reader;
	}

	public function get_object_type_name() {
		$this->ensure_object_header();

		return $this->object_type_name;
	}

	public function get_uncompressed_size() {
		$this->ensure_object_header();

		return $this->uncompressed_length;
	}

	/**
	 * Pulls up to $n bytes of *body* data (header is skipped automatically).
	 */
	protected function internal_pull( $n ): string {
		$this->ensure_object_header();
		$available = $this->body_source->pull( $n );

		return $this->body_source->consume( $available );
	}

	public function as_commit() {
		if ( 'commit' !== $this->get_object_type_name() ) {
			throw new GitException( sprintf( 'Object is %s, expected commit', esc_html( $this->get_object_type_name() ) ) );
		}

		return CommitParser::parse( $this->consume_all() );
	}

	public function as_tree() {
		if ( 'tree' !== $this->get_object_type_name() ) {
			throw new GitException( sprintf( 'Object is %s, expected tree', esc_html( $this->get_object_type_name() ) ) );
		}

		return TreeParser::parse_entire_tree( $this->consume_all() );
	}

	public function read_header() {
		$this->ensure_object_header();
	}

	/**
	 * Inflate until we hit the NUL terminator, then parse <type> <size>\x00.
	 */
	private function ensure_object_header(): void {
		if ( null !== $this->object_header ) {
			return;
		}

		$header = '';
		while ( true ) {
			if ( 0 === $this->inflated_reader->pull( 1, ByteReadStream::PULL_EXACTLY ) ) {
				throw new GitException( 'Unexpected end of data while reading object header' );
			}
			$byte    = $this->inflated_reader->consume( 1 );
			$header .= $byte;
			if ( "\x00" === $byte ) {
				break;
			}
		}

		$this->object_header = $header;
		$this->header_length = strlen( $header );

		$space_pos                 = strpos( $header, ' ' );
		$this->object_type_name    = substr( $header, 0, $space_pos );
		$this->uncompressed_length = (int) substr( $header, $space_pos + 1 );
		$this->expected_length     = $this->uncompressed_length;
	}

	/**
	 * External offsets are relative to the body; internally we seek past the header first.
	 */
	protected function seek_outside_of_buffer( int $target_offset ): void {
		$this->ensure_object_header();

		if ( null !== $this->length() && $target_offset > $this->length() ) {
			throw new NotEnoughDataException( 'Cannot seek past end of object body' );
		}

		$absolute_offset = $this->header_length + $target_offset;
		$this->body_source->seek( $absolute_offset );

		// Reset local buffer tracking.
		$this->buffer                   = '';
		$this->offset_in_current_buffer = 0;
		$this->bytes_already_forgotten  = $target_offset;
	}

	protected function internal_reached_end_of_data(): bool {
		return $this->body_source->reached_end_of_data();
	}

	protected function internal_close_reading(): void {
		$this->body_source->close_reading();
	}
}
