<?php

namespace WordPress\Blueprints\Tests\Unit\Steps;

use Exception;
use WordPress\Blueprints\DataReference\DataReference;
use WordPress\Blueprints\DataReference\ExecutionContextPath;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Steps\UnzipStep;

use ZipArchive;

use function WordPress\Filesystem\wp_join_unix_paths;

require_once __DIR__ . '/StepTestCase.php';

class UnzipStepTest extends StepTestCase {

	public function setUp(): void {
		parent::setUp();
		$zip_file = wp_join_unix_paths( $this->execution_context_path, 'test_zip.zip' );
		if ( file_exists( $zip_file ) ) {
			unlink( $zip_file );
		}

		$zip = new ZipArchive();
		if ( $zip->open( $zip_file, ZipArchive::CREATE ) !== true ) {
			throw new Exception( 'Failed to create zip file' );
		}
		$zip->addFromString( 'test_zip.txt', 'This is a test file content' );
		$zip->close();
	}

	public function testUnzipFile() {
		$step = new UnzipStep(
			DataReference::create( './test_zip.zip', [
				ExecutionContextPath::class
			] ),
			'extract_dir'
		);

		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		// Check if files were extracted correctly
		$fs = $this->runtime->get_target_filesystem();
		$this->assertTrue( $fs->exists( 'extract_dir' ) );
		$this->assertTrue( $fs->exists( 'extract_dir/test_zip.txt' ) );

		// Verify the content of the extracted file
		$content = $fs->get_contents( 'extract_dir/test_zip.txt' );
		$this->assertEquals( 'This is a test file content', $content );
	}

	public function testUnzipToExistingDirectory() {
		// Create the target directory first
		$fs = $this->runtime->get_target_filesystem();
		$fs->mkdir( 'existing_dir', [ 'recursive' => true ] );

		$step = new UnzipStep(
			DataReference::create( './test_zip.zip', [
				ExecutionContextPath::class
			] ),
			'existing_dir'
		);

		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		// Check if files were extracted correctly
		$this->assertTrue( $fs->exists( 'existing_dir/test_zip.txt' ) );
	}

	public function testUnzipWithNestedDirectories() {
		// Create a zip with nested directories
		$zip_file = wp_join_unix_paths( $this->execution_context_path, 'nested_test.zip' );
		$zip      = new ZipArchive();
		if ( $zip->open( $zip_file, ZipArchive::CREATE ) === true ) {
			$zip->addFromString( 'folder1/test1.txt', 'Test file 1' );
			$zip->addFromString( 'folder1/folder2/test2.txt', 'Test file 2' );
			$zip->close();
		}

		$step = new UnzipStep(
			DataReference::create( './nested_test.zip', [
				ExecutionContextPath::class
			] ),
			'nested_extract'
		);

		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		// Check if nested directories and files were extracted correctly
		$fs = $this->runtime->get_target_filesystem();
		$this->assertTrue( $fs->exists( 'nested_extract/folder1/test1.txt' ) );
		$this->assertTrue( $fs->exists( 'nested_extract/folder1/folder2/test2.txt' ) );
	}
}
