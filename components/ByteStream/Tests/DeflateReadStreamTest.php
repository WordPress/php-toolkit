<?php

use PHPUnit\Framework\TestCase;
use WordPress\ByteStream\ByteStreamException;
use WordPress\ByteStream\MemoryPipe;
use WordPress\ByteStream\ReadStream\DeflateReadStream;
use WordPress\ByteStream\ReadStream\InflateReadStream;

class DeflateReadStreamTest extends TestCase {

	public function testDeflateReaderNextBytesWithSeek() {
		$text          = file_get_contents( __DIR__ . '/fixtures/preface-to-pygmalion.txt' );
		$stringReader  = new MemoryPipe( $text );
		$deflateReader = new DeflateReadStream( $stringReader, ZLIB_ENCODING_DEFLATE );

		$inflateReader = new InflateReadStream( $deflateReader );

		$inflateReader->seek( 998 );
		$this->assertEquals( 40, $inflateReader->pull( 40 ) );
		$this->assertEquals( 'apologize to public meetings in a very c', $inflateReader->peek( 40 ) );

		$inflateReader->seek( 0 );
		$this->assertEquals( 21, $inflateReader->pull( 21 ) );
		$this->assertEquals( 'PREFACE TO PYGMALION.', $inflateReader->peek( 21 ) );

		$inflateReader->seek( 200 );
		$this->assertEquals( 10, $inflateReader->pull( 10 ) );
		$this->assertEquals( 'language, ', $inflateReader->peek( 10 ) );

		$inflateReader->seek( 10 );
		$this->assertEquals( 10, $inflateReader->pull( 10 ) );
		$this->assertEquals( ' PYGMALION', $inflateReader->peek( 10 ) );
	}

	public function testDeflateReaderEndOfData() {
		$pygmalionText = file_get_contents( __DIR__ . '/fixtures/preface-to-pygmalion.txt' );
		$stringReader  = new MemoryPipe( $pygmalionText );
		$deflateReader = new DeflateReadStream( $stringReader, ZLIB_ENCODING_DEFLATE );
		$inflateReader = new InflateReadStream( $deflateReader );

		$text = $inflateReader->consume_all();

		$this->assertEquals( $pygmalionText, $text );
		$this->assertTrue( $deflateReader->reached_end_of_data() );
	}

	public function testDeflateReaderClose() {
		$text          = file_get_contents( __DIR__ . '/fixtures/preface-to-pygmalion.txt' );
		$stringReader  = new MemoryPipe( $text );
		$deflateReader = new DeflateReadStream( $stringReader );

		$deflateReader->pull( 10 );
		$deflateReader->close_reading();
		$this->expectException( ByteStreamException::class );
		$this->assertFalse( $deflateReader->pull( 10 ) );
	}
}
