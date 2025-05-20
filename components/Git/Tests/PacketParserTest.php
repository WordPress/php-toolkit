<?php

namespace WordPress\Git\Tests;

use PHPUnit\Framework\TestCase;
use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\Git\Protocol\Parser\PacketParser;
use WordPress\Git\Protocol\Parser\ProtocolDemultiplexer;

class PacketParserTest extends TestCase {

	public function test_parse_response_no_blobs() {
		$reader        = FileReadStream::from_path( __DIR__ . '/fixtures/wordpress-develop-response-no-blobs.bin' );
		$demuxer       = new ProtocolDemultiplexer( $reader );
		$packet_parser = new PacketParser();
		$token_types   = array();
		$packet_types  = array();
		while ( $demuxer->next_chunk() ) {
			switch ( $demuxer->get_stream_code() ) {
				case 'unknown':
				case 'side_band':
					$packet_parser->append_bytes( $demuxer->get_chunk() );
					break;
			}
		}
		while ( $packet_parser->next_token() ) {
			$token_types[] = $packet_parser->get_token_type();
			switch ( $packet_parser->get_token_type() ) {
				case '#packet-header':
					$packet_types[] = $packet_parser->get_packet_type();
					break;
			}
		}
		$this->assertEquals(
			array(
				'#packet-header',
				'#packet-header',
				'#packet-body',
				'#packet-footer',
				'#packet-header',
				'#packet-body',
			),
			$token_types
		);
		$this->assertEquals(
			array(
				'#flush',
				'#packet',
				'#pack',
			),
			$packet_types
		);
	}
}
