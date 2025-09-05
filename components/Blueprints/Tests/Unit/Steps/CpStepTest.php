<?php

namespace WordPress\Blueprints\Tests\Unit\Steps;

use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Steps\CpStep;
use WordPress\Filesystem\FilesystemException;

require_once __DIR__ . '/StepTestCase.php';

class CpStepTest extends StepTestCase {
	public function testCopyFile() {
		$this->runtime->get_target_filesystem()->put_contents( 'source_file.txt', 'test content' );

		$step = new CpStep( 'source_file.txt', 'target_file.txt' );
		$step->run( $this->runtime, new Tracker() );

		$this->assertTrue( $this->runtime->get_target_filesystem()->exists( 'source_file.txt' ) );
		$this->assertTrue( $this->runtime->get_target_filesystem()->exists( 'target_file.txt' ) );
		$this->assertEquals( 'test content', $this->runtime->get_target_filesystem()->get_contents( 'target_file.txt' ) );
	}

	public function testCopyFileToDirectory() {
		$this->runtime->get_target_filesystem()->put_contents( 'source_file.txt', 'test content' );
		$this->runtime->get_target_filesystem()->mkdir( 'target_dir' );

		$step = new CpStep( 'source_file.txt', 'target_dir/file.txt' );
		$step->run( $this->runtime, new Tracker() );

		$this->assertTrue( $this->runtime->get_target_filesystem()->exists( 'source_file.txt' ) );
		$this->assertTrue( $this->runtime->get_target_filesystem()->exists( 'target_dir/file.txt' ) );
		$this->assertEquals( 'test content', $this->runtime->get_target_filesystem()->get_contents( 'target_dir/file.txt' ) );
	}

	public function testCopyDirectoryWithRecursiveOption() {
		$this->runtime->get_target_filesystem()->mkdir( 'source_dir' );
		$this->runtime->get_target_filesystem()->put_contents( 'source_dir/file.txt', 'test content' );

		$step = new CpStep( 'source_dir', 'target_dir' );
		$step->run( $this->runtime, new Tracker() );

		$this->assertTrue( $this->runtime->get_target_filesystem()->exists( 'source_dir' ) );
		$this->assertTrue( $this->runtime->get_target_filesystem()->exists( 'target_dir' ) );
		$this->assertTrue( $this->runtime->get_target_filesystem()->exists( 'target_dir/file.txt' ) );
		$this->assertEquals( 'test content', $this->runtime->get_target_filesystem()->get_contents( 'target_dir/file.txt' ) );
	}

	public function testCopyDirectoryWithNestedContent() {
		$this->runtime->get_target_filesystem()->mkdir( 'source_dir/nested_dir', [ 'recursive' => true ] );
		$this->runtime->get_target_filesystem()->put_contents( 'source_dir/file1.txt', 'test content 1' );
		$this->runtime->get_target_filesystem()->put_contents( 'source_dir/nested_dir/file2.txt', 'test content 2' );

		$step = new CpStep( 'source_dir', 'target_dir' );
		$step->run( $this->runtime, new Tracker() );

		$this->assertTrue( $this->runtime->get_target_filesystem()->exists( 'source_dir' ) );
		$this->assertTrue( $this->runtime->get_target_filesystem()->exists( 'target_dir' ) );
		$this->assertTrue( $this->runtime->get_target_filesystem()->exists( 'target_dir/file1.txt' ) );
		$this->assertTrue( $this->runtime->get_target_filesystem()->exists( 'target_dir/nested_dir/file2.txt' ) );
		$this->assertEquals( 'test content 2', $this->runtime->get_target_filesystem()->get_contents( 'target_dir/nested_dir/file2.txt' ) );
	}

	public function testCopyDirectoryWithoutRecursiveOptionFails() {
		$this->runtime->get_target_filesystem()->mkdir( 'source_dir' );
		$this->runtime->get_target_filesystem()->put_contents( 'source_dir/file.txt', 'test content' );

		$step = new CpStep( 'source_dir', 'target_dir' );
		$step->run( $this->runtime, new Tracker() );
		$this->assertTrue( $this->runtime->get_target_filesystem()->exists( 'target_dir/file.txt' ) );
	}

	public function testCopyNonexistentSourceFails() {
		$step = new CpStep( 'nonexistent_file.txt', 'target_file.txt' );
		$this->expectException( FilesystemException::class );
		$step->run( $this->runtime, new Tracker() );
	}

	public function testCopyToExistingFile() {
		$this->runtime->get_target_filesystem()->put_contents( 'source_file.txt', 'source content' );
		$this->runtime->get_target_filesystem()->put_contents( 'target_file.txt', 'target content' );

		$step = new CpStep( 'source_file.txt', 'target_file.txt' );
		$step->run( $this->runtime, new Tracker() );

		$this->assertEquals( 'source content', $this->runtime->get_target_filesystem()->get_contents( 'target_file.txt' ) );
	}
}
