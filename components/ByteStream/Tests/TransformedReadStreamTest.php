<?php

use PHPUnit\Framework\TestCase;
use WordPress\ByteStream\ReadStream\ByteReadStream;
use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\ByteStream\ReadStream\TransformedReadStream;

class TransformedReadStreamTest extends TestCase {

	public function test_basic_data_streaming() {
		$reference   = file_get_contents( __DIR__ . '/fixtures/preface-to-pygmalion.txt' );
		$reader      = FileReadStream::from_path( __DIR__ . '/fixtures/preface-to-pygmalion.txt' );
		$stream      = new TransformedReadStream( $reader );
		$accumulated = '';

		$this->assertEquals( 100, $stream->pull( 100, ByteReadStream::PULL_EXACTLY ) );
		$accumulated .= $stream->consume( 100 );

		$this->assertEquals( 100, $stream->pull( 100, ByteReadStream::PULL_EXACTLY ) );
		$accumulated .= $stream->consume( 100 );

		$remaining = strlen( $reference ) - $stream->tell();
		$this->assertEquals( $remaining, $stream->pull( $remaining, ByteReadStream::PULL_EXACTLY ) );
		$accumulated .= $stream->consume( $remaining );

		$this->assertEquals( 8704, strlen( $accumulated ) );
		$this->assertEquals( $reference, $accumulated );
	}
}
