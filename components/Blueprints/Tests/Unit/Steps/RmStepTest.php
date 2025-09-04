<?php

namespace WordPress\Blueprints\Tests\Unit\Steps;

use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Steps\RmStep;
use WordPress\Filesystem\FilesystemException;

require_once __DIR__ . '/StepTestCase.php';

class RmStepTest extends StepTestCase {

	public function testRemoveFile() {
		$fs = $this->runtime->getTargetFilesystem();
		$fs->put_contents( 'test_file.txt', 'test content' );

		$step = new RmStep(
			'test_file.txt'
		);

		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		$this->assertFalse(
			$fs->exists( 'test_file.txt' ),
			'Failed to assert that the file does not exist'
		);
	}

	public function testRemoveDirectoryWhenUsingRelativePath() {
		$fs = $this->runtime->getTargetFilesystem();
		$fs->mkdir( 'test_dir' );

		$step = new RmStep(
			'test_dir'
		);

		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		$this->assertFalse(
			$fs->exists( 'test_dir' ),
			'Failed to assert that the directory does not exist'
		);
	}

	public function testRemoveDirectoryWithSubdirectory() {
		$fs = $this->runtime->getTargetFilesystem();
		$fs->mkdir( 'parent/child', [ 'recursive' => true ] );

		$step = new RmStep(
			'parent'
		);

		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		// Assert parent directory and child don't exist anymore
		$this->assertFalse(
			$fs->exists( 'parent' ),
			'Failed to assert that the parent directory does not exist'
		);
	}

	public function testRemoveDirectoryWithFile() {
		$fs = $this->runtime->getTargetFilesystem();
		// Create directory with file
		$fs->mkdir( 'dir_with_file', [ 'recursive' => true ] );
		$fs->put_contents( 'dir_with_file/test.txt', 'test content' );

		$step = new RmStep(
			'dir_with_file'
		);

		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		$this->assertFalse(
			$fs->exists( 'dir_with_file' ),
			'Failed to assert that the directory does not exist'
		);
	}

	public function testThrowExceptionWhenRemovingNonexistentDirectoryAndUsingRelativePath() {
		$step = new RmStep(
			'nonexistent_dir'
		);

		$tracker = new Tracker();
		$this->expectException( FilesystemException::class );
		$this->expectExceptionMessageMatches( '/Path does not exist:/' );

		$step->run( $this->runtime, $tracker );
	}

	public function testThrowExceptionWhenRemovingNonexistentFileAndUsingRelativePath() {
		$step = new RmStep(
			'nonexistent_file.txt'
		);

		$tracker = new Tracker();
		$this->expectException( FilesystemException::class );
		$this->expectExceptionMessageMatches( '/Path does not exist:/' );

		$step->run( $this->runtime, $tracker );
	}
}
