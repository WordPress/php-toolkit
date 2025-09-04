<?php

namespace WordPress\Blueprints\Tests\Unit\Steps;

use WordPress\Blueprints\DataReference\DataReference;
use WordPress\Blueprints\DataReference\ExecutionContextPath;
use WordPress\Blueprints\DataReference\InlineFile;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Steps\WriteFilesStep;

require_once __DIR__ . '/StepTestCase.php';

class WriteFilesStepTest extends StepTestCase {
	/**
	 * Test writing a file with string data
	 */
	public function testWriteFileWithStringData() {
		// Create and run the step with string data
		$step = new WriteFilesStep( [
			'test_output.txt' => new InlineFile( [
				'filename' => 'test_output.txt',
				'content' => 'String content test'
			] )
		] );

		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		// Check if file was created with correct content
		$fs = $this->runtime->getTargetFilesystem();
		$this->assertTrue( $fs->exists( 'test_output.txt' ) );
		$this->assertEquals( 'String content test', $fs->get_contents( 'test_output.txt' ) );
	}

	/**
	 * Test writing a file with data from a reference
	 */
	public function testWriteFileWithDataReference() {
		// Create a test source file
		$this->execution_context->put_contents(
			'test_source.txt',
			'Test file content'
		);

		// Create and run the step with a data reference
		$step = new WriteFilesStep( [
			'test_output_from_ref.txt' => DataReference::create( './test_source.txt', [
				ExecutionContextPath::class
			] ),
		] );

		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		// Check if file was created with correct content
		$fs = $this->runtime->getTargetFilesystem();
		$this->assertTrue( $fs->exists( 'test_output_from_ref.txt' ) );
		$this->assertEquals( 'Test file content', $fs->get_contents( 'test_output_from_ref.txt' ) );
	}

	/**
	 * Test creating nested directory structure
	 */
	public function testCreatesDirectoryStructure() {
		// Create and run the step with a nested path
		$step = new WriteFilesStep( [
			'nested/directory/structure/test.txt' => new InlineFile( [
				'filename' => 'nested/directory/structure/test.txt',
				'content' => 'Nested directory test'
			] )
		] );

		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		// Check if nested directories and file were created
		$fs = $this->runtime->getTargetFilesystem();
		$this->assertTrue( $fs->exists( 'nested/directory/structure/test.txt' ) );
		$this->assertEquals( 'Nested directory test', $fs->get_contents( 'nested/directory/structure/test.txt' ) );
	}
}
