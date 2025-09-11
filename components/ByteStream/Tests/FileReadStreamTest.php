<?php

use PHPUnit\Framework\TestCase;
use WordPress\ByteStream\ByteStreamException;
use WordPress\ByteStream\ReadStream\FileReadStream;

class FileReadStreamTest extends TestCase {

	public function testStreamRemainsUsableAfterFailedFseek() {
		// Create a custom FileReadStream that simulates a non-seekable resource
		$content = str_repeat('ABCDEFGHIJ', 10); // 100 characters
		
		// Create a memory stream with the content
		$resource = fopen('php://memory', 'r+');
		fwrite($resource, $content);
		rewind($resource);

		// Create a custom FileReadStream where seek_outside_of_buffer always fails
		$stream = new class($resource, strlen($content)) extends FileReadStream {
			public function seek( int $target_offset ): void {
				// Skip all the sanity checks and pass the offset directly to seek_outside_of_buffer
				$this->seek_outside_of_buffer($target_offset);
			}
		};
		
		try {
			// Go to a place in the buffer where reading 20 bytes will yield a different
			// result than reading 20 bytes from the beginning of the file
			$stream->pull(8);
			$stream->consume(8);
			$positionBefore = $stream->tell();

			// Try to seek to the beginning - this will fail in seek_outside_of_buffer
			// because 0 is now outside the buffer range (bytes_already_forgotten > 0)
			try {
				$stream->seek(-1);
				$this->fail('Expected ByteStreamException was not thrown');
			} catch (ByteStreamException $e) {
				$this->assertEquals('Failed to seek to offset', $e->getMessage());
			}

			// Verify stream position hasn't changed and stream is still usable
			$this->assertEquals($positionBefore, $stream->tell());
			
			// Should be able to continue reading from current position
			$stream->pull(20);
			$nextData = $stream->consume(20);
			$expectedData = substr($content, $positionBefore, 20);
			$this->assertEquals($expectedData, $nextData);

		} finally {
			// Clean up resource
			if (is_resource($resource)) {
				fclose($resource);
			}
		}
	}
}