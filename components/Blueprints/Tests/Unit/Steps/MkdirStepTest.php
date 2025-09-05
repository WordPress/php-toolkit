<?php

namespace WordPress\Blueprints\Tests\Unit\Steps;

use WordPress\Blueprints\Exception\BlueprintExecutionException;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Steps\MkdirStep;
use WordPress\Filesystem\FilesystemException;

require_once __DIR__ . '/StepTestCase.php';

class MkdirStepTest extends StepTestCase {

	public function testCreateDirectoryWhenUsingRelativePath() {
		$path = 'dir';
		$step = new MkdirStep(
			$path
		);

		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		$fs = $this->runtime->get_target_filesystem();
		$this->assertTrue( $fs->exists( $path ) );
	}

	public function testCreateDirectoryWhenUsingAbsolutePath() {
		$absolute_path = '/dir';

		$step = new MkdirStep(
			$absolute_path
		);

		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		$fs = $this->runtime->get_target_filesystem();
		$this->assertTrue(
			$fs->exists( $absolute_path ),
			sprintf( 'Failed to assert that the directory exists: %s', $absolute_path )
		);
	}

	public function testCreateDirectoryRecursively() {
		$path = 'dir/subdir';
		$step = new MkdirStep(
			$path,
			[ 'recursive' => true ]
		);

		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		$fs = $this->runtime->get_target_filesystem();
		$this->assertTrue( $fs->exists( $path ) );
	}

	public function testCreateReadableAndWritableDirectory() {
		$path = 'dir';
		$step = new MkdirStep(
			$path
		);

		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		$fs = $this->runtime->get_target_filesystem();
		$this->assertTrue( $fs->exists( $path ) );
	}

	public function testThrowExceptionWhenCreatingDirectoryAndItAlreadyExists() {
		$path = 'dir';
		$fs   = $this->runtime->get_target_filesystem();
		$fs->mkdir( $path );

		$step = new MkdirStep(
			$path
		);

		$tracker = new Tracker();
		$this->expectException( BlueprintExecutionException::class );
		$this->expectExceptionMessageMatches( "/Path already exists:/" );
		$step->run( $this->runtime, $tracker );
	}
}
