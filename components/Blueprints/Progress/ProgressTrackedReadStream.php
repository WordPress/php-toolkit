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
	private $streamLength;

	public function __construct( ByteReadStream $stream, Tracker $tracker ) {
		$this->stream       = $stream;
		$this->tracker      = $tracker;
		$this->streamLength = $this->stream->length();
		$this->updateProgress(); // Initial progress update
	}

	public function length(): ?int {
		return $this->streamLength;
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
		$isEndOfData = $this->stream->reached_end_of_data();
		if ( $isEndOfData ) {
			$this->updateProgress(); // Ensure progress is 100% if end is reached
		}

		return $isEndOfData;
	}

	/**
	 * @throws ByteStreamException
	 */
	public function pull( $n, $mode = self::PULL_NO_MORE_THAN ): int {
		$bytesPulled = $this->stream->pull( $n, $mode );
		$this->updateProgress();

		return $bytesPulled;
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
		$this->updateProgress(); // Should be 100% after this

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
		if ( $this->stream->tell() === 0 ) {
			return;
		}

		if ( null === $this->streamLength || $this->streamLength === 0 ) {
			// If length is unknown or zero, we cannot meaningfully report percentage progress.
			// However, if we are at the end or length is 0, we can consider it 100%
			if ( $this->streamLength === 0 || ( $this->streamLength !== null && $this->stream->tell() >= $this->streamLength ) ) {
				// Ensure progress is set to 100 if stream is empty or fully read.
				// Only set if not already done to avoid redundant notifications.
				if ( $this->tracker->getProgress() < 100 ) {
					$this->tracker->set( 100 );
				}
			}

			return;
		}

		$progress = ( $this->stream->tell() / $this->streamLength ) * 100;
		// It's possible to seek() backwards. Let's make sure we never decrease
		// the reported progress.
		if ( $progress > $this->tracker->getProgress() ) {
			$this->tracker->set( $progress );
		}
	}

}
