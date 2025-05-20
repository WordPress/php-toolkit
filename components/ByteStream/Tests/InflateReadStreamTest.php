<?php

use PHPUnit\Framework\TestCase;
use WordPress\ByteStream\MemoryPipe;
use WordPress\ByteStream\ReadStream\InflateReadStream;

class InflateReadStreamTest extends TestCase {

	public function testInflateReaderNextBytesWithSeek() {
		$text           = file_get_contents( __DIR__ . '/fixtures/preface-to-pygmalion.txt' );
		$compressedData = gzdeflate( $text, - 1, ZLIB_ENCODING_DEFLATE );
		$stringReader   = new MemoryPipe( $compressedData );
		$inflateReader  = new InflateReadStream( $stringReader );

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

	public function testPartialInflateWithSeek() {
		$text   = file_get_contents( __DIR__ . '/fixtures/preface-to-pygmalion.txt' );
		$header = 'blob ' . strlen( $text ) . "\x00";
		$object = $header . gzdeflate( $text, - 1, ZLIB_ENCODING_DEFLATE );

		$stringReader  = new MemoryPipe( $object );
		$inflateReader = new InflateReadStream( $stringReader );

		$this->assertEquals( $header, $stringReader->consume( strlen( $header ) ) );

		$this->assertEquals( 21, $inflateReader->pull( 21 ) );
		$this->assertEquals( 'PREFACE TO PYGMALION.', $inflateReader->consume( 21 ) );

		$this->assertEquals( 21, $inflateReader->pull( 21 ) );
		$this->assertEquals( "\n\nA Professor of Phon", $inflateReader->consume( 21 ) );

		// Now let's seek back to offset 0 and confirm we get the same result.
		$inflateReader->seek( 0 );
		$this->assertEquals( 21, $inflateReader->pull( 21 ) );
		$this->assertEquals( 'PREFACE TO PYGMALION.', $inflateReader->consume( 21 ) );
	}
}
