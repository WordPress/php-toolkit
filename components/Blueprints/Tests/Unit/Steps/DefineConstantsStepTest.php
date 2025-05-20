<?php

namespace WordPress\Blueprints\Tests\Unit\Steps;

use Exception;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Steps\DefineConstantsStep;

class DefineConstantsStepTest extends StepTestCase {
	/**
	 * Sample wp-config.php content for testing
	 */
	const SAMPLE_WP_CONFIG = <<<'PHP'
<?php
/**
 * The base configuration for WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define('DB_NAME', 'test_db');

/** Database username */
define('DB_USER', 'root');

/** Database password */
define('DB_PASSWORD', 'password');

/** Database hostname */
define('DB_HOST', 'localhost');

/** Database charset to use in creating database tables. */
define('DB_CHARSET', 'utf8');

/** The database collate type. Don't change this if in doubt. */
define('DB_COLLATE', '');

define('WP_DEBUG', false);

/**
 * WordPress Database Table prefix.
 */
$table_prefix = 'wp_';

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
PHP;

	/**
	 * Test updating existing constants
	 */
	public function testUpdateExistingConstants() {
		$constants = [
			'WP_DEBUG' => true,
			'DB_NAME'  => 'updated_db',
		];
		$step      = new DefineConstantsStep( $constants );
		$step->run( $this->runtime, new Tracker() );
		$this->assertWordPressConstants( $constants );
	}

	/**
	 * Test adding new constants
	 */
	public function testAddNewConstants() {
		$constants = [
			'WP_MEMORY_LIMIT'            => '256M',
			'AUTOMATIC_UPDATER_DISABLED' => true,
		];
		$step      = new DefineConstantsStep( $constants );
		$step->run( $this->runtime, new Tracker() );
		$this->assertWordPressConstants( $constants );
	}

	/**
	 * Test defining constants with different data types
	 */
	public function testDefineConstantsWithDifferentTypes() {
		$constants = [
			'STRING_CONST' => 'string value',
			'BOOL_CONST'   => true,
			'INT_CONST'    => 42,
			'FLOAT_CONST'  => 3.14,
			'ARRAY_CONST'  => [ 'one', 'two', 'three' ],
			'NULL_CONST'   => null,
		];
		$step      = new DefineConstantsStep( $constants );
		$step->run( $this->runtime, new Tracker() );
		$this->assertWordPressConstants( $constants );
	}

	/**
	 * Test error handling when wp-config.php does not exist
	 */
	public function testErrorHandlingWhenWpConfigNotExists() {
		$this->runtime->getTargetFilesystem()->rm( 'wp-config.php' );
		$step = new DefineConstantsStep( [ 'WP_DEBUG' => true ] );
		$this->expectException( Exception::class );
		$step->run( $this->runtime, new Tracker() );
	}

	/**
	 * Test defining multiple constants at once
	 */
	public function testDefineMultipleConstants() {
		$constants = [
			'WP_DEBUG'            => true,
			'WP_DEBUG_LOG'        => true,
			'WP_DEBUG_DISPLAY'    => false,
			'SCRIPT_DEBUG'        => true,
			'WP_ENVIRONMENT_TYPE' => 'development',
			'WP_CACHE'            => false,
			'CONCATENATE_SCRIPTS' => false,
			'COMPRESS_SCRIPTS'    => false,
			'COMPRESS_CSS'        => false,
			'ENFORCE_GZIP'        => false,
		];
		$step      = new DefineConstantsStep( $constants );
		$step->run( $this->runtime, new Tracker() );
		$this->assertWordPressConstants( $constants );
	}

	/**
	 * Helper method to verify constants are defined in WordPress
	 *
	 * @param  array  $constants  Array of constants to check
	 *
	 * @return array Results of constant verification
	 */
	private function assertWordPressConstants( array $expected_constants ) {
		$result = $this->runtime->evalPhpCodeInSubProcess(
			<<<'PHP'
<?php
// Load WordPress environment
require_once getenv('DOCROOT') . '/wp-load.php';

// Check if constants are defined
$results = [];
$constants = json_decode(getenv('CONSTANTS'), true);

foreach ($constants as $name => $expected_value) {
$results[$name] = defined($name) ? constant($name) : null;
}

append_output( json_encode($results) );
PHP
			,
			[
				'CONSTANTS' => json_encode( $expected_constants ),
			]
		)->outputFileContent;

		$actual_constants = json_decode( $result, true );
		$this->assertEquals( $expected_constants, $actual_constants );
	}

}
