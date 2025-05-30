<?php

use PHPUnit\Framework\TestCase;
use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Git\GitObjectEncoder;
use WordPress\Git\GitRepository;

class GitObjectWriteStreamTest extends TestCase {

	public function testWriteBlob() {
		$entire_text = file_get_contents( __DIR__ . '/fixtures/preface-to-pygmalion.txt' );
		$repo        = new GitRepository( InMemoryFilesystem::create() );
		$writer      = new GitObjectEncoder( $repo, 'blob', strlen( $entire_text ) );
		$writer->append_bytes( $entire_text );
		$writer->close_writing();

		$hash = $writer->get_hash();
		$this->assertEquals( 'c19c9dbac694fb04a30b0ed9741694ca0cfca0e6', $hash );
		$reader       = $repo->read_object( $hash );
		$read_content = $reader->consume_all();
		$this->assertEquals( $entire_text, $read_content );
	}
}
