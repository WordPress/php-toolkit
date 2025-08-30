<?php

use PHPUnit\Framework\TestCase;
use WordPress\ByteStream\ByteStreamException;
use WordPress\ByteStream\FileReadWriteStream;

class FileReadWriteStreamTest extends TestCase {
	private $testFile = __DIR__ . '/fixtures/test-rw.txt';

	protected function setUp(): void {
		if ( file_exists( $this->testFile ) ) {
			unlink( $this->testFile );
		}
	}

	protected function tearDown(): void {
		if ( file_exists( $this->testFile ) ) {
			unlink( $this->testFile );
		}
	}

	public function testLongDistanceSeek() {
		$stream = FileReadWriteStream::from_path( __DIR__ . '/fixtures/pygmalion.html' );

		$stream->seek( $stream->length() - 83 );
		$stream->pull( 83 );
		$last_bytes             = $stream->consume( 83 );
		$expected_last_83_bytes = 'subscribe to our email newsletter to hear about new eBooks.
</div>
</body>
</html>
';
		$this->assertEquals( $expected_last_83_bytes, $last_bytes );

		$stream->seek( 0 );
		$stream->pull( 83 );
		$first_bytes             = $stream->consume( 83 );
		$expected_first_83_bytes = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
		"http://www.w3.org/TR/xh';
		$this->assertEquals( $expected_first_83_bytes, $first_bytes );

		$stream->seek( $stream->length() - 83 );
		$stream->pull( 40 );
		$last_bytes = $stream->consume( 40 );

		$stream->pull( 43 );
		$last_bytes .= $stream->consume( 43 );
		$this->assertEquals( $expected_last_83_bytes, $last_bytes );
	}

	public function testCreateFromPathAndWriteRead() {
		$stream = FileReadWriteStream::from_path( $this->testFile, true );
		$this->assertInstanceOf( FileReadWriteStream::class, $stream );

		$stream->append_bytes( 'Hello' );
		$stream->append_bytes( ' World' );
		$stream->close_writing();

		// Seek to start and read
		$stream->seek( 0 );
		$stream->pull( 11 );
		$data = $stream->consume( 11 );
		$this->assertEquals( 'Hello World', $data );
	}

	public function testSeekAndPartialRead() {
		file_put_contents( $this->testFile, 'abcdefghij' );
		$stream = FileReadWriteStream::from_path( $this->testFile );
		$stream->seek( 3 );
		$stream->pull( 4 );
		$data = $stream->consume( 4 );
		$this->assertEquals( 'defg', $data );
	}

	public function testAppendAfterRead() {
		$stream = FileReadWriteStream::from_path( $this->testFile, true );
		$stream->append_bytes( '12345' );
		$stream->seek( 0 );
		$stream->pull( 5 );
		$this->assertEquals( '12345', $stream->consume( 5 ) );
		$stream->append_bytes( '6789' );
		$stream->seek( 5 );
		$stream->pull( 4 );
		$this->assertEquals( '6789', $stream->consume( 4 ) );
	}

	public function testCloseWritingPreventsFurtherWrite() {
		$stream = FileReadWriteStream::from_path( $this->testFile, true );
		$stream->append_bytes( 'foo' );
		$stream->close_writing();
		$this->expectException( ByteStreamException::class );
		$stream->append_bytes( 'bar' );
	}

	public function testCloseReadingPreventsFurtherRead() {
		$stream = FileReadWriteStream::from_path( $this->testFile, true );
		$stream->append_bytes( 'foo' );
		$stream->close_reading();
		$this->expectException( ByteStreamException::class );
		$stream->pull( 1 );
	}

	public function testBufferTrimming() {
		$stream = FileReadWriteStream::from_path( $this->testFile, true );
		$stream->append_bytes( str_repeat( 'a', 3000 ) );
		$stream->seek( 0 );
		$stream->pull( 3000, FileReadWriteStream::PULL_EXACTLY );
		$stream->consume( 3000 );
		// After consuming, buffer should be trimmed to max_lookbehind_bytes (2048)
		$reflection = new ReflectionClass( $stream );
		$bufferProp = $reflection->getProperty( 'buffer' );
		$bufferProp->setAccessible( true );
		$this->assertLessThanOrEqual( 2048, strlen( $bufferProp->getValue( $stream ) ) );
	}

	public function testFromResource() {
		$fp = fopen( $this->testFile, 'w+b' );
		fwrite( $fp, 'foobar' );
		rewind( $fp );
		$stream = FileReadWriteStream::from_resource( $fp, 6 );
		$stream->pull( 6 );
		$this->assertEquals( 'foobar', $stream->consume( 6 ) );
		$stream->close_writing();
		$stream->close_reading();
	}
}
