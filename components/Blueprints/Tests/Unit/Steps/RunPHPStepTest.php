<?php

namespace WordPress\Blueprints\Tests\Unit\Steps;

use Exception;
use WordPress\Blueprints\DataReference\InlineFile;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Steps\RunPHPStep;

use function WordPress\Filesystem\wp_join_unix_paths;

class RunPHPStepTest extends StepTestCase {

	/**
	 * Test running simple PHP code
	 */
	public function testRunSimplePHPCode() {
		$output_file = wp_join_unix_paths( $this->runtime->getConfiguration()->getTargetSiteRoot(), 'output.txt' );
		
		$step = new RunPHPStep(new InlineFile(
			'script.php',
			<<<PHP
<?php 
file_put_contents(getenv('DOCROOT') . '/output.txt', 'Hello World');
PHP
		));

		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		$this->assertFileExists( $output_file );
		$this->assertEquals( 'Hello World', file_get_contents( $output_file ) );
	}

	/**
	 * Test running PHP code that creates a file
	 */
	public function testRunPHPCodeCreatingFile() {
		$test_file_path = wp_join_unix_paths( $this->runtime->getConfiguration()->getTargetSiteRoot(), 'test_file.txt' );
		$output_file = wp_join_unix_paths( $this->runtime->getConfiguration()->getTargetSiteRoot(), 'output.txt' );
		$test_content = 'This is a test file created by PHP';

		$step = new RunPHPStep(
			new InlineFile(
				'script.php',
				<<<PHP
<?php
\$docroot = getenv('DOCROOT');
\$test_file_path = \$docroot . '/test_file.txt';
file_put_contents(\$test_file_path, 'This is a test file created by PHP');
file_put_contents(\$docroot . '/output.txt', 'File created');
PHP
			)
		);

		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		$this->assertFileExists( $output_file );
		$this->assertEquals( 'File created', file_get_contents( $output_file ) );
		$this->assertFileExists( $test_file_path );
		$this->assertEquals( $test_content, file_get_contents( $test_file_path ) );
	}

	/**
	 * Test running PHP code that loads WordPress
	 */
	public function testRunPHPCodeWithWordPress() {
		$output_file = wp_join_unix_paths( $this->runtime->getConfiguration()->getTargetSiteRoot(), 'output.txt' );
		
		$step = new RunPHPStep(
			new InlineFile(
				'script.php',
				<<<PHP
<?php
require_once getenv('DOCROOT') . '/wp-load.php';

// Create a test option
update_option('test_option', 'test_value');

// Write the option value to an output file
file_put_contents(getenv('DOCROOT') . '/output.txt', get_option('test_option'));
PHP
			)
		);

		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		$this->assertFileExists( $output_file );
		$this->assertEquals( 'test_value', file_get_contents( $output_file ) );

		// Verify the option was actually set in WordPress
		$option_value = $this->runtime->evalPhpCodeInSubProcess(
			<<<'PHP'
<?php
require_once getenv('DOCROOT') . '/wp-load.php';
append_output( get_option('test_option') );
PHP

		)->outputFileContent;

		$this->assertEquals( 'test_value', $option_value );
	}

	/**
	 * Test running PHP code that returns complex data
	 */
	public function testRunPHPCodeReturningComplexData() {
		$output_file = wp_join_unix_paths( $this->runtime->getConfiguration()->getTargetSiteRoot(), 'output.txt' );
		
		$step = new RunPHPStep(
			new InlineFile(
				'script.php',
				<<<PHP
<?php
\$data = [
    'string' => 'Hello',
    'number' => 42,
    'boolean' => true,
    'array' => [1, 2, 3],
    'object' => (object)['name' => 'Test']
];

file_put_contents(getenv('DOCROOT') . '/output.txt', json_encode(\$data));
PHP
			)
		);

		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		$this->assertFileExists( $output_file );
		$data = json_decode( file_get_contents( $output_file ), true );

		$this->assertIsArray( $data );
		$this->assertEquals( 'Hello', $data['string'] );
		$this->assertEquals( 42, $data['number'] );
		$this->assertTrue( $data['boolean'] );
		$this->assertEquals( [ 1, 2, 3 ], $data['array'] );
		$this->assertEquals( [ 'name' => 'Test' ], $data['object'] );
	}

	/**
	 * Test running PHP code with syntax error
	 */
	public function testRunPHPCodeWithSyntaxError() {
		$step = new RunPHPStep(
			new InlineFile(
				'script.php',
				'<?php echo "Missing semicolon" echo "Another string";'
			)
		);

		$tracker = new Tracker();

		// The code contains a syntax error, so we expect an exception
		$this->expectException( Exception::class );
		$step->run( $this->runtime, $tracker );
	}
}
