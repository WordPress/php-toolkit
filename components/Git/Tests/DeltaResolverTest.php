<?php

namespace WordPress\Git\Tests;

use PHPUnit\Framework\TestCase;
use WordPress\ByteStream\MemoryPipe;
use WordPress\ByteStream\ReadStream\ProducerProducer;
use WordPress\Git\GitObjectDecoder;
use WordPress\Git\Protocol\GitProtocolEncoderPipe;
use WordPress\Git\Protocol\Parser\DeltaResolver;

class DeltaResolverTest extends TestCase {

	public function test_resolve_next_chunk() {
		$base_bytes = 'Hello, world!';

		$header      = 'blob ' . strlen( $base_bytes ) . "\000";
		$object      = new MemoryPipe(
			gzcompress( $header . $base_bytes, 9, ZLIB_ENCODING_DEFLATE )
		);
		$base_reader = new GitObjectDecoder( $object );
		$base_reader->read_header();

		$resolved_chunk = 'World? Hello, I am changed!';
		$delta_bytes    = implode(
			'',
			array(
				GitProtocolEncoderPipe::encode_variable_length( strlen( $base_bytes ) ),
				GitProtocolEncoderPipe::encode_variable_length( strlen( $resolved_chunk ) ),
				// The leftmost bit is 0 = we're consuming from the delta
				// The next 7 bits amount to 0b110 = we're consuming the next 6 bytes
				chr( 0b00000110 ),
				'World?',

				// The leftmost bit is 1 = we're copying from the base
				// The next 3 bits are 001 = we're encoding the copy length as 1 byte
				// The next 4 bits are 0000 = we're encoding the offset as 0 bytes
				chr( 0b10010000 ),
				// 7 – the number of bytes from the base to copy
				chr( 7 ),

				// The leftmost bit is 0 = we're consuming from the delta
				// The next 7 bits amount to 0b110 = we're consuming the next 13 bytes
				chr( 0b00001101 ),
				'I am changed!',
			)
		);
		$delta_reader   = new MemoryPipe( $delta_bytes );

		$resolver = new DeltaResolver( $base_reader, $delta_reader );
		$this->assertTrue( $resolver->resolve_next_chunk() );
		$this->assertEquals( 'World?', $resolver->get_resolved_chunk() );

		$this->assertTrue( $resolver->resolve_next_chunk() );
		$this->assertEquals( 'Hello, ', $resolver->get_resolved_chunk() );

		$this->assertTrue( $resolver->resolve_next_chunk() );
		$this->assertEquals( 'I am changed!', $resolver->get_resolved_chunk() );

		$this->assertFalse( $resolver->resolve_next_chunk() );
	}
}
