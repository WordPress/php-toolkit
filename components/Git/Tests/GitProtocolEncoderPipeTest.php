<?php

namespace WordPress\Git\Tests;

use PHPUnit\Framework\TestCase;
use WordPress\ByteStream\MemoryPipe;
use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Git\GitRepository;
use WordPress\Git\Protocol\GitProtocolEncoderPipe;
use WordPress\Git\Protocol\Parser\GitProtocolDecoder;

class GitProtocolEncoderPipeTest extends TestCase {

	public function test_encode_empty_file_and_a_text_file() {
		$fs         = InMemoryFilesystem::create();
		$repository = new GitRepository( $fs );

		$data1  = 'This is a test file';
		$stream = $repository->new_object_open_write_stream( 'blob', strlen( $data1 ) );
		$stream->append_bytes( $data1 );
		$stream->close_writing();
		$oid1 = $stream->get_hash();

		$data2  = '';
		$stream = $repository->new_object_open_write_stream( 'blob', strlen( $data2 ) );
		$stream->append_bytes( $data2 );
		$stream->close_writing();
		$oid2 = $stream->get_hash();

		$encoder = new GitProtocolEncoderPipe();
		$encoder->append_packfile(
			$repository,
			array(
				$oid1,
				$oid2,
			)
		);

		$result = $encoder->consume_all();

		$write_repo = new GitRepository( InMemoryFilesystem::create() );
		$reader     = new GitProtocolDecoder(
			new MemoryPipe( $result ),
			array( 'write_to_repository' => $write_repo )
		);

		while ( $reader->next_token() ) {
			// twiddle our thumbs
		}

		// We just want to see there are no exceptions thrown
		$this->assertTrue( true );
	}
}
