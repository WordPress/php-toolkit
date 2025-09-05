<?php

namespace WordPress\Blueprints\Progress;

use WordPress\ByteStream\ReadStream\ByteReadStream;
use WordPress\ByteStream\ReadStream\ByteStreamException;

class ProgressTrackedReadStream implements ByteReadStream {

	/**
	 * @var ByteReadStream
	 */
	private $stream;
	/**
	 * @var Tracker
	 */
	private $tracker;
	/**
	 * @var int|null
	 */
	private $stream_length;

	public function __construct( ByteReadStream $stream, Tracker $tracker ) {
		$this->stream        = $stream;
		$this->tracker       = $tracker;
		$this->stream_length = $this->stream->length();
		$this->updateProgress(); // Initial progress update.
	}

	public function length(): ?int {
		return $this->stream_length;
	}

	public function tell(): int {
		return $this->stream->tell();
	}

	/**
	 * @throws ByteStreamException
	 */
	public function seek( int $offset ) {
		$this->stream->seek( $offset );
		$this->updateProgress();
	}

	public function reached_end_of_data(): bool {
		$is_end_of_data = $this->stream->reached_end_of_data();
		if ( $is_end_of_data ) {
			$this->updateProgress(); // Ensure progress is 100% if end is reached.
		}

		return $is_end_of_data;
	}

	/**
	 * @throws ByteStreamException
	 */
	public function pull( $n, $mode = self::PULL_NO_MORE_THAN ): int {
		$bytes_pulled = $this->stream->pull( $n, $mode );
		$this->updateProgress();

		return $bytes_pulled;
	}

	/**
	 * @throws ByteStreamException
	 */
	public function peek( $n ): string {
		return $this->stream->peek( $n );
	}

	/**
	 * @throws ByteStreamException
	 */
	public function consume( $n ): string {
		$data = $this->stream->consume( $n );
		$this->updateProgress();

		return $data;
	}

	/**
	 * @throws ByteStreamException
	 */
	public function consume_all(): string {
		$data = $this->stream->consume_all();
		$this->updateProgress(); // Should be 100% after this.

		return $data;
	}

	public function close_reading(): void {
		$this->stream->close_reading();
		// Optionally ensure tracker is set to 100% if not already.
		// This depends on whether closing implies completion.
		// For now, we rely on consume_all or reaching end of data.
		// If the stream is closed prematurely, the progress will reflect the last read amount.
	}

	private function updateProgress(): void {
		if ( 0 === $this->stream->tell() ) {
			return;
		}

		if ( null === $this->stream_length || 0 === $this->stream_length ) {
			// If length is unknown or zero, we cannot meaningfully report percentage progress.
			// However, if we are at the end or length is 0, we can consider it 100%.
			if ( 0 === $this->stream_length || ( null !== $this->stream_length && $this->stream->tell() >= $this->stream_length ) ) {
				// Ensure progress is set to 100 if stream is empty or fully read.
				// Only set if not already done to avoid redundant notifications.
				if ( $this->tracker->getProgress() < 100 ) {
					$this->tracker->set( 100 );
				}
			}

			return;
		}

		$progress = ( $this->stream->tell() / $this->stream_length ) * 100;
		// It's possible to seek() backwards. Let's make sure we never decrease.
		// the reported progress.
		if ( $progress > $this->tracker->getProgress() ) {
			$this->tracker->set( $progress );
		}
	}
}
