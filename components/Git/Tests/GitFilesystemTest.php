<?php

namespace WordPress\Git\Tests;

use PHPUnit\Framework\TestCase;
use WordPress\Filesystem\InMemoryFilesystem as FilesystemInMemoryFilesystem;
use WordPress\Git\GitFilesystem;
use WordPress\Git\GitRepository;
use WordPress\Git\Model\Commit;

class GitFilesystemTest extends TestCase {

	private $repo;
	private $fs;
	private $head_oid;

	public function setUp(): void {
		parent::setUp();
		$this->repo = new GitRepository( FilesystemInMemoryFilesystem::create() );
		$this->repo->set_branch_tip( 'refs/heads/trunk', Commit::NULL_HASH );
		$this->repo->set_branch_tip( 'HEAD', 'ref: refs/heads/trunk' );
		$this->head_oid = $this->repo->commit(
			array(
				'updates' => array(
					'README.md'                    => 'Hello, world!',
					'subdirectory/hello-world.txt' => 'Hello, world!',
					'subdirectory/script.js'       => 'console.log("Hello, world!");',
				),
			)
		);
		$this->fs       = GitFilesystem::create( $this->repo );
	}

	public function test_ls_root() {
		$this->assertEquals(
			array(
				'README.md',
				'subdirectory',
			),
			$this->fs->ls( '/' )
		);
	}

	public function test_ls_subdirectory() {
		$this->assertEquals(
			array(
				'hello-world.txt',
				'script.js',
			),
			$this->fs->ls( '/subdirectory' )
		);
	}

	public function test_rename_directory() {
		// Rename the subdirectory
		$this->fs->rename( '/subdirectory', '/new-subdirectory' );

		// Check that the old directory is no longer present
		$this->assertEquals(
			array(
				'README.md',
				'new-subdirectory',
			),
			$this->fs->ls( '/' )
		);

		// Check that the new directory contains the expected files
		$this->assertEquals(
			array(
				'hello-world.txt',
				'script.js',
			),
			$this->fs->ls( '/new-subdirectory' )
		);
	}
}
