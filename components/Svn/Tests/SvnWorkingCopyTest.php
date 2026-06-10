<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Svn\SvnException;
use WordPress\Svn\SvnWorkingCopy;

class SvnWorkingCopyTest extends TestCase {
	private function make_working_copy() {
		$filesystem = InMemoryFilesystem::create();
		$filesystem->mkdir( '/wc', array( 'recursive' => true ) );

		return array(
			SvnWorkingCopy::initialize(
				$filesystem,
				'/wc',
				array(
					'url'             => 'svn://example.com/repo/trunk',
					'repository_root' => 'svn://example.com/repo',
					'uuid'            => 'fake-uuid',
					'revision'        => 7,
				)
			),
			$filesystem,
		);
	}

	public function test_initialize_and_reopen() {
		list( $working_copy, $filesystem ) = $this->make_working_copy();

		$this->assertTrue( SvnWorkingCopy::is_working_copy( $filesystem, '/wc' ) );

		$reopened = SvnWorkingCopy::open( $filesystem, '/wc' );
		$this->assertSame( 'svn://example.com/repo/trunk', $reopened->get_url() );
		$this->assertSame( 'svn://example.com/repo', $reopened->get_repository_root() );
		$this->assertSame( 'fake-uuid', $reopened->get_uuid() );
		$this->assertSame( 7, $reopened->get_revision() );
	}

	public function test_open_rejects_plain_directories() {
		$filesystem = InMemoryFilesystem::create();
		$filesystem->mkdir( '/not-a-wc', array( 'recursive' => true ) );

		$this->expectException( SvnException::class );
		SvnWorkingCopy::open( $filesystem, '/not-a-wc' );
	}

	public function test_pristine_store_round_trips() {
		list( $working_copy ) = $this->make_working_copy();

		$checksum = $working_copy->store_pristine( "some contents\n" );
		$this->assertSame( md5( "some contents\n" ), $checksum );
		$this->assertSame( "some contents\n", $working_copy->read_pristine( $checksum ) );
	}

	public function test_entries_survive_a_save_load_cycle() {
		list( $working_copy, $filesystem ) = $this->make_working_copy();

		$working_copy->set_entry(
			'a.txt',
			array(
				'kind'     => 'file',
				'revision' => 7,
				'checksum' => md5( 'x' ),
			)
		);
		$working_copy->save();

		$reopened = SvnWorkingCopy::open( $filesystem, '/wc' );
		$this->assertSame( 'file', $reopened->get_entry( 'a.txt' )['kind'] );
		$this->assertNull( $reopened->get_entry( 'missing.txt' ) );
	}

	public function test_status_reports_each_state() {
		list( $working_copy, $filesystem ) = $this->make_working_copy();

		// normal: on disk, matches pristine.
		$checksum = $working_copy->store_pristine( "fine\n" );
		$filesystem->put_contents( '/wc/normal.txt', "fine\n" );
		$working_copy->set_entry(
			'normal.txt',
			array(
				'kind'     => 'file',
				'revision' => 7,
				'checksum' => $checksum,
			)
		);

		// modified: on disk, differs from pristine.
		$filesystem->put_contents( '/wc/modified.txt', "changed\n" );
		$working_copy->set_entry(
			'modified.txt',
			array(
				'kind'     => 'file',
				'revision' => 7,
				'checksum' => $checksum,
			)
		);

		// missing: entry without a file.
		$working_copy->set_entry(
			'missing.txt',
			array(
				'kind'     => 'file',
				'revision' => 7,
				'checksum' => $checksum,
			)
		);

		// added / deleted schedules and a conflict.
		$filesystem->put_contents( '/wc/added.txt', "new\n" );
		$working_copy->set_entry(
			'added.txt',
			array(
				'kind'     => 'file',
				'schedule' => 'add',
			)
		);
		$working_copy->set_entry(
			'deleted.txt',
			array(
				'kind'     => 'file',
				'revision' => 7,
				'checksum' => $checksum,
				'schedule' => 'delete',
			)
		);
		$filesystem->put_contents( '/wc/conflicted.txt', "mine\n" );
		$filesystem->put_contents( '/wc/conflicted.txt.r9', "theirs\n" );
		$working_copy->set_entry(
			'conflicted.txt',
			array(
				'kind'          => 'file',
				'revision'      => 7,
				'checksum'      => $checksum,
				'conflict'      => true,
				'conflict_file' => 'conflicted.txt.r9',
			)
		);

		// unversioned: on disk without an entry.
		$filesystem->put_contents( '/wc/stray.txt', "stray\n" );

		$status = $working_copy->get_status();
		$this->assertArrayNotHasKey( 'normal.txt', $status );
		$this->assertSame( 'modified', $status['modified.txt'] );
		$this->assertSame( 'missing', $status['missing.txt'] );
		$this->assertSame( 'added', $status['added.txt'] );
		$this->assertSame( 'deleted', $status['deleted.txt'] );
		$this->assertSame( 'conflicted', $status['conflicted.txt'] );
		$this->assertSame( 'unversioned', $status['stray.txt'] );
		// Conflict artifacts are bookkeeping, not unversioned files.
		$this->assertArrayNotHasKey( 'conflicted.txt.r9', $status );
	}

	public function test_eol_translation_to_disk() {
		$crlf = array( 'svn:eol-style' => 'CRLF' );
		$this->assertSame( "a\r\nb\r\n", SvnWorkingCopy::translate_to_disk( "a\nb\n", $crlf ) );
		$this->assertSame( "a\r\nb\r\n", SvnWorkingCopy::translate_to_disk( "a\r\nb\r\n", $crlf ) );

		$native = array( 'svn:eol-style' => 'native' );
		$this->assertSame( "a\nb\n", SvnWorkingCopy::translate_to_disk( "a\r\nb\r", $native ) );

		$cr = array( 'svn:eol-style' => 'CR' );
		$this->assertSame( "a\rb\r", SvnWorkingCopy::translate_to_disk( "a\nb\n", $cr ) );

		// No property: bytes pass through untouched.
		$this->assertSame( "a\r\nb\n", SvnWorkingCopy::translate_to_disk( "a\r\nb\n", array() ) );
	}

	public function test_eol_aware_modification_check() {
		list( $working_copy, $filesystem ) = $this->make_working_copy();

		// An eol-styled file whose pristine and working copy only differ
		// in line endings is NOT modified.
		$entry = array(
			'kind'     => 'file',
			'revision' => 7,
			'checksum' => $working_copy->store_pristine( "a\r\nb\r\n" ),
			'props'    => array( 'svn:eol-style' => 'CRLF' ),
		);
		$working_copy->set_entry( 'eol.txt', $entry );
		$filesystem->put_contents( '/wc/eol.txt', "a\nb\n" );
		$this->assertFalse( $working_copy->is_file_modified( 'eol.txt', $entry ) );

		$filesystem->put_contents( '/wc/eol.txt', "a\nCHANGED\n" );
		$this->assertTrue( $working_copy->is_file_modified( 'eol.txt', $entry ) );
	}
}
