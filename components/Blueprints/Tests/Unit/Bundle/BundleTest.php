<?php

namespace WordPress\Blueprints\Tests\Unit\Validator;

use PHPUnit\Framework\TestCase;
use WordPress\Blueprints\DataReference\AbsoluteLocalPath;
use WordPress\Blueprints\Runner;
use WordPress\Blueprints\RunnerConfiguration;


class BundleTest extends TestCase {
	public function testValidBundle() {
		$config = ( new RunnerConfiguration() )
			->setBlueprint( new AbsoluteLocalPath( __DIR__ . '/fixtures/bundle/blueprint-valid.json' ) )
			->setTargetSiteUrl( 'http://127.0.0.1:2456' )
			->setDatabaseEngine( 'sqlite' );

		$runner    = new Runner( $config );
		$blueprint = $runner->parseBlueprint();
		$this->assertEmpty( $blueprint->getErrors() );
	}

	public function testInvalidBundle() {
		$config = ( new RunnerConfiguration() )
			->setBlueprint( new AbsoluteLocalPath( __DIR__ . '/fixtures/bundle/blueprint-invalid.json' ) )
			->setTargetSiteUrl( 'http://127.0.0.1:2456' )
			->setDatabaseEngine( 'sqlite' );

		$runner    = new Runner( $config );
		$blueprint = $runner->parseBlueprint();

		// Errors.
		$errors = $blueprint->getErrors();
		$this->assertIsArray( $errors );
		$this->assertArrayHasKey( 'plugins', $errors );
		$this->assertArrayHasKey( 'themes', $errors );

		// Plugin errors.
		$plugin_errors = $errors['plugins'];
		$this->assertSame( [
			[
				'line' => 5,
				'message' => 'Invalid plugin path. The path must start with "wp-content/plugins/": ./wp-content/invalid/invalid-plugin-file.php'
			],
			[
				'line' => 5,
				'message' => 'Invalid plugin path. The path must start with "wp-content/plugins/": ./wp-content/invalid/invalid-plugin-dir'
			],
			[
				'line' => 5,
				'message' => 'Invalid plugin file. File does not exist: ./wp-content/plugins/non-existent-plugin-file.php'
			],
			[
				'line' => 5,
				'message' => 'Invalid plugin directory. Directory does not exist: ./wp-content/plugins/non-existent-plugin-dir'
			],
			[
				'line' => 5,
				'message' => 'Invalid plugin zip. File does not exist: ./wp-content/plugins/non-existent-plugin-zip.zip'
			],
			[
				'line' => 5,
				'message' => 'Invalid plugin path. Expected a ".php" file, a ".zip" archive, or a directory, got "txt": ./wp-content/plugins/invalid-plugin-file.txt'
			],
			[
				'line' => 5,
				'message' => 'Invalid plugin file. Missing "Plugin Name" header.'
			],
		], $plugin_errors );

		// Theme errors.
		$theme_errors = $errors['themes'];
		$this->assertSame( [
			[
				'line' => 14,
				'message' => 'Invalid theme path. The path must start with "wp-content/themes/": ./wp-content/invalid/invalid-theme-dir'
			],
			[
				'line' => 14,
				'message' => 'Invalid theme path. The path must start with "wp-content/themes/": ./wp-content/invalid/invalid-theme-zip-dir.zip'
			],
			[
				'line' => 14,
				'message' => 'Invalid theme directory. Directory does not exist: ./wp-content/themes/non-existent-theme-dir'
			],
			[
				'line' => 14,
				'message' => 'Invalid theme zip. File does not exist: ./wp-content/themes/non-existent-theme-zip.zip'
			],
			[
				'line' => 14,
				'message' => 'Invalid theme path. Expected a ".zip" archive or a directory, got "php": ./wp-content/themes/invalid-theme-file.php'
			],
			[
				'line' => 14,
				'message' => 'Invalid theme directory. Missing "style.css" file: ./wp-content/themes/invalid-theme-dir'
			],
			[
				'line' => 14,
				'message' => 'Invalid theme ZIP. No theme directories found: ./wp-content/themes/invalid-theme-zip-dir.zip'
			],
		], $theme_errors );

		// Media errors.
		$media_errors = $errors['media'];
		$this->assertSame( [
			[
				'line' => 23,
				'message' => 'Invalid media path. The path must start with "wp-content/uploads/": ./wp-content/invalid/invalid-media-file.jpg'
			],
		], $media_errors );
	}
}
