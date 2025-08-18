<?php

namespace WordPress\ByteStream\ReadStream;

/**
 * A reader that limits the number of bytes that can be read.
 */
class LimitedByteReadStream extends BaseByteReadStream {
	private $upstream;
	private $limit;
	private $initial_offset;

	public function __construct( ByteReadStream $upstream, int $limit ) {
		$this->upstream       = $upstream;
		$this->limit          = $limit;
		$this->initial_offset = $upstream->tell();
	}

	protected function internal_pull( $max_bytes ): string {
		$max_bytes = min(
			$max_bytes,
			$this->limit - $this->tell()
		);
		if ( $max_bytes <= 0 ) {
			return '';
		}
		$this->upstream->pull( $max_bytes );

		return $this->upstream->consume( $max_bytes );
	}

	public function length(): ?int {
		return $this->limit;
	}

	protected function internal_reached_end_of_data(): bool {
		return $this->tell() >= $this->limit || $this->upstream->reached_end_of_data();
	}

	protected function seek_outside_of_buffer( int $target_offset ): void {
		$this->upstream->seek( $this->initial_offset + $target_offset );
	}
}
