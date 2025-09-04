<?php

namespace WordPress\Blueprints\Tests\Unit\Steps;

use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Steps\RmDirStep;
use WordPress\Filesystem\FilesystemException;

require_once __DIR__ . '/StepTestCase.php';

class RmDirStepTest extends StepTestCase {

	public function testRemoveEmptyDirectory() {
		$fs = $this->runtime->getTargetFilesystem();
		$fs->mkdir( 'empty_dir' );

		$step = new RmDirStep(
			'empty_dir',
			[ 'recursive' => false ]
		);

		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		$this->assertFalse(
			$fs->exists( 'empty_dir' ),
			'Failed to assert that the directory no longer exists'
		);
	}

	public function testRemoveDirectoryWithRecursiveOption() {
		$fs = $this->runtime->getTargetFilesystem();
		$fs->mkdir( 'parent/child', [ 'recursive' => true ] );
		$fs->put_contents( 'parent/file.txt', 'test content' );
		$fs->put_contents( 'parent/child/nested_file.txt', 'nested content' );

		$step = new RmDirStep(
			'parent',
			[ 'recursive' => true ]
		);

		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		$this->assertFalse(
			$fs->exists( 'parent' ),
			'Failed to assert that the parent directory no longer exists'
		);
	}

	public function testNonRecursiveRemovalFailsForNonEmptyDirectory() {
		$fs = $this->runtime->getTargetFilesystem();
		$fs->mkdir( 'non_empty_dir' );
		$fs->put_contents( 'non_empty_dir/file.txt', 'test content' );

		$step = new RmDirStep(
			'non_empty_dir',
			[ 'recursive' => false ]
		);

		$tracker = new Tracker();
		$this->expectException( FilesystemException::class );
		$step->run( $this->runtime, $tracker );
	}

	public function testRemoveDirectoryWithMultipleFilesAndNestedDirectories() {
		$fs = $this->runtime->getTargetFilesystem();
		$fs->mkdir( 'complex/nested1/sub1', [ 'recursive' => true ] );
		$fs->mkdir( 'complex/nested2', [ 'recursive' => true ] );
		$fs->put_contents( 'complex/file1.txt', 'content 1' );
		$fs->put_contents( 'complex/file2.txt', 'content 2' );
		$fs->put_contents( 'complex/nested1/file3.txt', 'content 3' );
		$fs->put_contents( 'complex/nested1/sub1/file4.txt', 'content 4' );
		$fs->put_contents( 'complex/nested2/file5.txt', 'content 5' );

		$step = new RmDirStep(
			'complex',
			[ 'recursive' => true ]
		);

		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		$this->assertFalse(
			$fs->exists( 'complex' ),
			'Failed to assert that the complex directory structure no longer exists'
		);
	}

	public function testRemoveNonExistentDirectoryFails() {
		$step = new RmDirStep(
			'nonexistent_dir',
			[ 'recursive' => false ]
		);

		$tracker = new Tracker();
		$this->expectException( FilesystemException::class );
		$step->run( $this->runtime, $tracker );
	}

	public function testRemoveFileWithRmDirFails() {
		$fs = $this->runtime->getTargetFilesystem();
		$fs->put_contents( 'test_file.txt', 'test content' );

		$step = new RmDirStep(
			'test_file.txt',
			[ 'recursive' => false ]
		);

		$tracker = new Tracker();
		$this->expectException( FilesystemException::class );
		$step->run( $this->runtime, $tracker );
	}
}
