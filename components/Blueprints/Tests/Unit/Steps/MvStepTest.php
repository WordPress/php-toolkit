<?php

namespace WordPress\Blueprints\Tests\Unit\Steps;

use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Steps\MvStep;
use WordPress\Filesystem\FilesystemException;

require_once __DIR__ . '/StepTestCase.php';

class MvStepTest extends StepTestCase {

	public function testMoveFile() {
		$this->runtime->get_target_filesystem()->put_contents( 'source_file.txt', 'test content' );

		$step = new MvStep(
			'source_file.txt',
			'target_file.txt'
		);

		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		$fs = $this->runtime->get_target_filesystem();
		$this->assertFalse(
			$fs->exists( 'source_file.txt' ),
			'Failed to assert that the source file no longer exists'
		);
		$this->assertTrue(
			$fs->exists( 'target_file.txt' ),
			'Failed to assert that the target file exists'
		);
		$this->assertEquals(
			'test content',
			$fs->get_contents( 'target_file.txt' ),
			'Failed to assert that the file content was preserved'
		);
	}

	public function testMoveFileToDirectory() {
		$fs = $this->runtime->get_target_filesystem();
		$fs->put_contents( 'source_file.txt', 'test content' );
		$fs->mkdir( 'target_dir' );

		$step = new MvStep(
			'source_file.txt',
			'target_dir/file.txt'
		);

		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		$this->assertFalse(
			$fs->exists( 'source_file.txt' ),
			'Failed to assert that the source file no longer exists'
		);
		$this->assertTrue(
			$fs->exists( 'target_dir/file.txt' ),
			'Failed to assert that the target file exists'
		);
	}

	public function testMoveDirectory() {
		$fs = $this->runtime->get_target_filesystem();
		$fs->mkdir( 'source_dir' );
		$fs->put_contents( 'source_dir/file.txt', 'test content' );

		$step = new MvStep(
			'source_dir',
			'target_dir'
		);

		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		$this->assertFalse(
			$fs->exists( 'source_dir' ),
			'Failed to assert that the source directory no longer exists'
		);
		$this->assertTrue(
			$fs->exists( 'target_dir' ),
			'Failed to assert that the target directory exists'
		);
		$this->assertTrue(
			$fs->exists( 'target_dir/file.txt' ),
			'Failed to assert that the target directory contains the file'
		);
	}

	public function testMoveDirectoryWithNestedContent() {
		$fs = $this->runtime->get_target_filesystem();
		$fs->mkdir( 'source_dir/nested_dir', [ 'recursive' => true ] );
		$fs->put_contents( 'source_dir/file1.txt', 'test content 1' );
		$fs->put_contents( 'source_dir/nested_dir/file2.txt', 'test content 2' );

		$step = new MvStep(
			'source_dir',
			'target_dir'
		);

		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		$this->assertFalse(
			$fs->exists( 'source_dir' ),
			'Failed to assert that the source directory no longer exists'
		);
		$this->assertTrue(
			$fs->exists( 'target_dir' ),
			'Failed to assert that the target directory exists'
		);
		$this->assertTrue(
			$fs->exists( 'target_dir/file1.txt' ),
			'Failed to assert that the target directory contains file1.txt'
		);
		$this->assertTrue(
			$fs->exists( 'target_dir/nested_dir/file2.txt' ),
			'Failed to assert that the target directory contains nested structure'
		);
		$this->assertEquals(
			'test content 2',
			$fs->get_contents( 'target_dir/nested_dir/file2.txt' ),
			'Failed to assert that the file content was preserved'
		);
	}

	public function testMoveNonexistentSourceFails() {
		$step = new MvStep(
			'nonexistent_file.txt',
			'target_file.txt'
		);

		$tracker = new Tracker();
		$this->expectException( FilesystemException::class );
		$step->run( $this->runtime, $tracker );
	}
}
