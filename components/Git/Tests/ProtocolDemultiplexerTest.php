<?php

namespace WordPress\Git\Tests;

use PHPUnit\Framework\TestCase;
use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\ByteStream\ReadStream\ProducerProducer;
use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Git\GitRepository;
use WordPress\Git\Model\Commit;
use WordPress\Git\Model\Tree;
use WordPress\Git\Model\TreeEntry;
use WordPress\Git\Protocol\GitProtocolEncoderPipe;
use WordPress\Git\Protocol\Parser\ProtocolDemultiplexer;

class ProtocolDemultiplexerTest extends TestCase {

	public function test_parse_simple_response() {
		$repo = new GitRepository( InMemoryFilesystem::create() );
		$oid  = $repo->add_object(
			'tree',
			GitProtocolEncoderPipe::encode_tree_bytes(
				new Tree(
					array(
						new TreeEntry(
							array(
								'mode' => '100644',
								'name' => 'test.txt',
								'hash' => sha1( 'test' ),
							)
						),
					)
				)
			)
		);

		$producer = new GitProtocolEncoderPipe();
		$producer->append_packet_line( Commit::NULL_HASH . ' ' . Commit::NULL_HASH . " refs/heads/\0report-status force-update\n" );
		$producer->append_packet_line( "ef9fae98ba6dd17140b45bc657659b6c41a4ad10 HEAD\n" );
		$producer->append_packet_line( '0000' );
		$producer->append_packet_line( "unpack ok\n" );
		$producer->append_packet_line( "another line\n" );
		$producer->append_packet_line( "and another\n" );
		$producer->append_packfile( $repo, array( $oid ) );
		$producer->append_packet_line( '0000' );
		$producer->close_writing();

		$chunks  = array();
		$demuxer = new ProtocolDemultiplexer( $producer );
		while ( $demuxer->next_chunk() ) {
			switch ( $demuxer->get_stream_code() ) {
				case ProtocolDemultiplexer::STREAM_CODE_UNKNOWN:
				case ProtocolDemultiplexer::STREAM_CODE_SIDE_BAND:
					$chunks[] = $demuxer->get_chunk();
					break;
			}
		}

		$this->assertCount( 9, $chunks );
		$this->assertEquals(
			array(
				"007d0000000000000000000000000000000000000000 0000000000000000000000000000000000000000 refs/heads/\0report-status force-update\n",
				"0032ef9fae98ba6dd17140b45bc657659b6c41a4ad10 HEAD\n",
				'0000',
				"000eunpack ok\n",
				"0011another line\n",
				"0010and another\n",
			),
			array_slice( $chunks, 0, 6 )
		);
		$this->assertStringStartsWith( 'PACK', $chunks[6] );
		$this->assertEquals( 60, strlen( $chunks[6] ) );
		$this->assertEquals( 26, strlen( $chunks[7] ) );
	}

	public function test_parse_response_no_blobs() {
		$reader        = FileReadStream::from_path( __DIR__ . '/fixtures/wordpress-develop-response-no-blobs.bin' );
		$demuxer       = new ProtocolDemultiplexer( $reader );
		$chunks_counts = array();
		while ( $demuxer->next_chunk() ) {
			if ( ! isset( $chunks_counts[ $demuxer->get_stream_code() ] ) ) {
				$chunks_counts[ $demuxer->get_stream_code() ] = 0;
			}
			++ $chunks_counts[ $demuxer->get_stream_code() ];
		}
		$reader->close_reading();
		$this->assertEquals(
			array(
				'unknown'   => 3,
				'progress'  => 29,
				'side_band' => 5,
			),
			$chunks_counts
		);
	}

	public function test_parse_full_response() {
		$reader        = FileReadStream::from_path( __DIR__ . '/fixtures/wordpress-develop-response-full.bin' );
		$demuxer       = new ProtocolDemultiplexer( $reader );
		$chunks_counts = array();
		while ( $demuxer->next_chunk() ) {
			if ( ! isset( $chunks_counts[ $demuxer->get_stream_code() ] ) ) {
				$chunks_counts[ $demuxer->get_stream_code() ] = 0;
			}
			++ $chunks_counts[ $demuxer->get_stream_code() ];
		}
		$reader->close_reading();
		$this->assertEquals(
			array(
				'unknown'   => 3,
				'progress'  => 106,
				'side_band' => 4286,
			),
			$chunks_counts
		);
	}
}
