<?php

namespace WordPress\Git\Tests;

use PHPUnit\Framework\TestCase;
use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Git\GitRepository;
use WordPress\Git\Protocol\Parser\GitProtocolDecoder;

class GitProtocolDecoderTest extends TestCase {

	public function test_protocol_reader_wordpress_develop() {
		$repo = new GitRepository( InMemoryFilesystem::create() );

		$upstream = FileReadStream::from_path( __DIR__ . '/fixtures/wordpress-develop-response-no-blobs.bin' );
		$reader   = new GitProtocolDecoder(
			$upstream,
			array( 'write_to_repository' => $repo )
		);

		while ( $reader->next_token() ) {
			$reader->get_token_type();
		}

		$upstream->close_reading();

		// We just want to see there are no exceptions thrown
		$this->assertTrue( true );
	}
}
