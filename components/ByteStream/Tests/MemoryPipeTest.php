<?php

use PHPUnit\Framework\TestCase;
use WordPress\ByteStream\ByteStreamException;
use WordPress\ByteStream\MemoryPipe;
use WordPress\ByteStream\NotEnoughDataException;
use WordPress\ByteStream\ReadStream\ByteReadStream;

class MemoryPipeTest extends TestCase {

	public function testConstructWithPreloadedBytes() {
		$pipe = new MemoryPipe( 'Hello World' );

		$this->assertSame( 11, $pipe->length() );
		$this->assertSame( 0, $pipe->tell() );
		$this->assertFalse( $pipe->reached_end_of_data() );
		$this->assertSame( 'Hello', $pipe->peek( 5 ) );
		$this->assertSame( 'Hello', $pipe->consume( 5 ) );
		$this->assertSame( 5, $pipe->tell() );
		$this->assertSame( ' World', $pipe->consume_all() );
		$this->assertSame( 11, $pipe->tell() );
		$this->assertTrue( $pipe->reached_end_of_data() );
	}

	public function testConstructWithExpectedLengthOnly() {
		$pipe = new MemoryPipe( null, 25 );

		$this->assertSame( 25, $pipe->length() );
		$this->assertSame( 0, $pipe->tell() );
		$this->assertFalse( $pipe->reached_end_of_data() );
	}

	public function testConstructWithoutArguments() {
		$pipe = new MemoryPipe();

		$this->assertNull( $pipe->length() );
		$this->assertSame( 0, $pipe->tell() );
		$this->assertFalse( $pipe->reached_end_of_data() );
	}

	public function testConstructThrowsExceptionWhenBothBytesAndExpectedLengthProvided() {
		$this->expectException( ByteStreamException::class );
		$this->expectExceptionMessage( 'A MemoryPipe accepts either a non-empty string representing the entire data, or an expected length when the data is not available yet. It does not accept both arguments.' );

		new MemoryPipe( 'preloaded-data', 50 );
	}

	public function testConstructAllowsEmptyStringAndExpectedLength() {
		$pipe = new MemoryPipe( '', 10 );

		$this->assertSame( 0, $pipe->length() );
	}

	public function testAppendBytesSuccessfully() {
		$pipe = new MemoryPipe();

		$pipe->append_bytes( 'First Chunk; ' );
		$pipe->append_bytes( 'Second Chunk' );
		$pipe->close_writing();

		$this->assertSame( 'First Chunk; Second Chunk', $pipe->consume_all() );
		$this->assertSame( 25, $pipe->tell() );
	}

	public function testAppendBytesThrowsExceptionWhenWritingIsClosed() {
		$pipe = new MemoryPipe();
		$pipe->append_bytes( 'initial' );
		$pipe->close_writing();

		$this->expectException( ByteStreamException::class );
		$this->expectExceptionMessage( 'Cannot append bytes to a closed stream.' );

		$pipe->append_bytes( 'more data' );
	}

	public function testAppendBytesThrowsExceptionWhenExceedingExpectedLength() {
		$pipe = new MemoryPipe( null, 8 );
		$pipe->append_bytes( '12345' );
		$pipe->consume( 5 );

		$this->expectException( ByteStreamException::class );
		$this->expectExceptionMessage( 'Appending bytes to the stream would exceed the expected length.' );

		$pipe->append_bytes( '6789' );
	}

	public function testCloseWritingLocksStreamAndCalculatesExpectedLength() {
		$pipe = new MemoryPipe();
		$this->assertNull( $pipe->length() );

		$pipe->append_bytes( 'WordPress' );
		$pipe->append_bytes( ' PHP Toolkit' );

		$pipe->close_writing();

		$this->assertSame( 21, $pipe->length() );
		$this->assertSame( 'WordPress PHP Toolkit', $pipe->consume_all() );
		$this->assertTrue( $pipe->reached_end_of_data() );
	}

	public function testPullNoMoreThanReturnsAvailableBytes() {
		$pipe = new MemoryPipe();
		$pipe->append_bytes( 'ABCDEFGHIJ' );

		$pulled = $pipe->pull( 5, ByteReadStream::PULL_NO_MORE_THAN );
		$this->assertSame( 5, $pulled );
		$this->assertSame( 'ABCDE', $pipe->consume( 5 ) );

		$pulled_remaining = $pipe->pull( 20, ByteReadStream::PULL_NO_MORE_THAN );
		$this->assertSame( 5, $pulled_remaining );
		$this->assertSame( 'FGHIJ', $pipe->consume( 5 ) );
	}

	public function testPullNoMoreThanThrowsWhenBufferIsExhausted() {
		$pipe = new MemoryPipe();

		$this->expectException( NotEnoughDataException::class );
		$this->expectExceptionMessage( 'Cannot pull bytes after exhausting the buffer from a MemoryPipe. You are likely missing a $pipe->reached_end_of_data() check before the pull() call.' );

		$pipe->pull( 10, ByteReadStream::PULL_NO_MORE_THAN );
	}

	public function testPullExactlyReturnsExactBytes() {
		$pipe = new MemoryPipe();
		$pipe->append_bytes( '1234567890' );

		$pulled = $pipe->pull( 6, ByteReadStream::PULL_EXACTLY );
		$this->assertSame( 6, $pulled );
		$this->assertSame( '123456', $pipe->consume( 6 ) );
	}

	public function testPullExactlyThrowsWhenNotEnoughData() {
		$pipe = new MemoryPipe();
		$pipe->append_bytes( '123' );

		$this->expectException( NotEnoughDataException::class );
		$this->expectExceptionMessage( 'Cannot pull bytes from a MemoryPipe.' );

		$pipe->pull( 10, ByteReadStream::PULL_EXACTLY );
	}

	public function testSeekWithinPreloadedBuffer() {
		$pipe = new MemoryPipe( 'abcdefghijklmnopqrstuvwxyz' );

		$pipe->seek( 10 );
		$this->assertSame( 10, $pipe->tell() );
		$this->assertSame( 'klm', $pipe->consume( 3 ) );
		$this->assertSame( 13, $pipe->tell() );

		// Seek backward to start — supported because preloaded buffer lookbehind is PHP_INT_MAX.
		$pipe->seek( 0 );
		$this->assertSame( 0, $pipe->tell() );
		$this->assertSame( 'abc', $pipe->consume( 3 ) );
	}

	public function testSeekOutsideOfBufferThrowsException() {
		$pipe = new MemoryPipe();
		$pipe->append_bytes( 'short' );

		$this->expectException( NotEnoughDataException::class );
		$this->expectExceptionMessage( 'Cannot seek past the available data. Call append_bytes() first.' );

		$pipe->seek( 50 );
	}

	public function testSeekPastStreamLengthThrowsException() {
		$pipe = new MemoryPipe( 'short' );

		$this->expectException( NotEnoughDataException::class );
		$this->expectExceptionMessage( 'Cannot seek to past the stream length (seeked to 50, stream length is 5).' );

		$pipe->seek( 50 );
	}

	public function testProducerConsumerStreamingWorkflow() {
		$pipe = new MemoryPipe();

		// Producer writes first message chunk.
		$pipe->append_bytes( '{"event":"start"}' );
		$this->assertSame( '{"event":"start"}', $pipe->consume( 17 ) );
		$this->assertSame( 17, $pipe->tell() );

		// Producer writes second message chunk.
		$pipe->append_bytes( '{"event":"stop"}' );
		$this->assertSame( '{"event":"stop"}', $pipe->consume( 16 ) );
		$this->assertSame( 33, $pipe->tell() );

		// Producer finishes streaming.
		$pipe->close_writing();
		$this->assertSame( 33, $pipe->length() );
		$this->assertTrue( $pipe->reached_end_of_data() );
	}
}
