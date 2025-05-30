<?php

use PHPUnit\Framework\TestCase;
use WordPress\ByteStream\WriteStream\FileWriteStream;

class FileWriteStreamTest extends TestCase {
	public function testExample() {
		// Example test case
		$this->assertTrue( true );
	}

	public function testCreateFileWriterFromPath() {
		$writer = FileWriteStream::from_path( 'test.txt' );
		$this->assertInstanceOf( FileWriteStream::class, $writer );
	}

	public function testAppendBytesToFile() {
		$writer = FileWriteStream::from_path( 'test.txt' );
		$writer->append_bytes( 'Hello' );
		$this->assertFileExists( 'test.txt' );
	}

	public function testCloseFileWriter() {
		$writer = FileWriteStream::from_path( 'test.txt' );
		$writer->close_writing();

		// We just want to see there are no exceptions thrown
		$this->assertTrue( true );
	}
}
