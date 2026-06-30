<?php

namespace WordPress\Blueprints\Tests\Unit\Steps;

use WordPress\Blueprints\DataReference\DataReference;
use WordPress\Blueprints\DataReference\ExecutionContextPath;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Steps\InstallPluginStep;

use ZipArchive;

use function WordPress\Filesystem\wp_join_unix_paths;

require_once __DIR__ . '/StepTestCase.php';

class InstallPluginStepTest extends StepTestCase {
	const PLUGIN_FILE_CONTENT = <<<'PHP'
<?php
/**
* Plugin Name: Test Plugin
* Description: A test plugin for InstallPluginStepRunner test
* Version: 1.0.0
* Author: Test
*/

// Simple plugin that does nothing
function test_plugin_init() {
// This function is just for testing
}
add_action('init', 'test_plugin_init');
PHP;

	public function testInstallPluginWithActivation() {
		$this->execution_context->mkdir(
			'test-plugin', [ 'recursive' => true ]
		);
		$this->execution_context->put_contents(
			'test-plugin/test-plugin.php',
			self::PLUGIN_FILE_CONTENT
		);

		$step = new InstallPluginStep(
			DataReference::create( './test-plugin/test-plugin.php', [
				ExecutionContextPath::class
			] ),
			true
		);

		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		// Check if plugin is installed
		$fs = $this->runtime->get_target_filesystem();
		$this->assertTrue( $fs->exists( 'wp-content/plugins/test-plugin' ) );
		$this->assertTrue( $fs->exists( 'wp-content/plugins/test-plugin/test-plugin.php' ) );

		// Check if plugin is activated
		$active_plugins = $this->runtime->eval_php_code_in_subprocess(
			<<<'PHP'
<?php
require_once getenv('WP_CORE_DIR') . '/wp-load.php';
append_output( json_encode(get_option('active_plugins')) );
PHP

		)->output_file_content;

		$active_plugins = json_decode( $active_plugins, true );
		$this->assertContains( 'test-plugin/test-plugin.php', $active_plugins );
	}

	public function testInstallPluginWithoutActivation() {
		$this->execution_context->mkdir(
			'test-plugin', [ 'recursive' => true ]
		);
		$this->execution_context->put_contents(
			'test-plugin/test-plugin.php',
			self::PLUGIN_FILE_CONTENT
		);

		$step = new InstallPluginStep(
			DataReference::create( './test-plugin/test-plugin.php', [
				ExecutionContextPath::class
			] ),
			false
		);

		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		// Check if plugin is installed
		$fs = $this->runtime->get_target_filesystem();
		$this->assertTrue( $fs->exists( 'wp-content/plugins/test-plugin' ) );
		$this->assertTrue( $fs->exists( 'wp-content/plugins/test-plugin/test-plugin.php' ) );
		$inactive_plugins = $this->runtime->eval_php_code_in_subprocess(
			<<<'PHP'
<?php
require_once getenv('WP_CORE_DIR') . '/wp-load.php';
require_once getenv('WP_CORE_DIR') . '/wp-admin/includes/plugin.php';

// Get all installed plugins
$all_plugins = get_plugins();
// Get active plugins
$active_plugins = get_option('active_plugins');
// Filter to get only inactive plugins
$inactive_plugins = array_diff(array_keys($all_plugins), $active_plugins);
append_output( json_encode($inactive_plugins) );
PHP

		)->output_file_content;
		$inactive_plugins = json_decode( $inactive_plugins, true );
		$this->assertContains( 'test-plugin/test-plugin.php', $inactive_plugins );

		// Check if plugin is activated
		$active_plugins = $this->runtime->eval_php_code_in_subprocess(
			<<<'PHP'
<?php
require_once getenv('WP_CORE_DIR') . '/wp-load.php';
append_output( json_encode(get_option('active_plugins')) );
PHP

		)->output_file_content;

		$active_plugins = json_decode( $active_plugins, true );
		$this->assertNotContains( 'test-plugin/test-plugin.php', $active_plugins );
	}

	public function testInstallPluginFromZip() {
		$zip_file = wp_join_unix_paths( $this->execution_context_path, 'zipped-test-plugin.zip' );
		$zip      = new ZipArchive();
		if ( $zip->open( $zip_file, ZipArchive::CREATE ) === true ) {
			$zip->addFromString( 'test-plugin.php', self::PLUGIN_FILE_CONTENT );
			$zip->close();
		}

		$step = new InstallPluginStep(
			DataReference::create( './zipped-test-plugin.zip', [
				ExecutionContextPath::class
			] ),
			true
		);

		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		// Check if plugin is installed
		$fs = $this->runtime->get_target_filesystem();
		$this->assertTrue( $fs->exists( 'wp-content/plugins/zipped-test-plugin' ) );
		$this->assertTrue( $fs->exists( 'wp-content/plugins/zipped-test-plugin/test-plugin.php' ) );

		// Check if plugin is activated
		$active_plugins = $this->runtime->eval_php_code_in_subprocess(
			<<<'PHP'
<?php
require_once getenv('WP_CORE_DIR') . '/wp-load.php';
append_output( json_encode(get_option('active_plugins')) );
PHP

		)->output_file_content;

		$active_plugins = json_decode( $active_plugins, true );
		$this->assertContains( 'zipped-test-plugin/test-plugin.php', $active_plugins );
	}

	public function testInstallPluginFromZipWithSubfolder() {
		$zip_file = wp_join_unix_paths( $this->execution_context_path, 'zipped-test-plugin.zip' );
		$zip      = new ZipArchive();
		if ( $zip->open( $zip_file, ZipArchive::CREATE ) === true ) {
			$zip->addFromString( 'subfolder-name/test-plugin.php', self::PLUGIN_FILE_CONTENT );
			$zip->close();
		}

		$step = new InstallPluginStep(
			DataReference::create( './zipped-test-plugin.zip', [
				ExecutionContextPath::class
			] ),
			true
		);

		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		// Check if plugin is installed
		$fs = $this->runtime->get_target_filesystem();
		$this->assertTrue( $fs->exists( 'wp-content/plugins/subfolder-name' ) );
		$this->assertTrue( $fs->exists( 'wp-content/plugins/subfolder-name/test-plugin.php' ) );

		// Check if plugin is activated
		$active_plugins = $this->runtime->eval_php_code_in_subprocess(
			<<<'PHP'
<?php
require_once getenv('WP_CORE_DIR') . '/wp-load.php';
append_output( json_encode(get_option('active_plugins')) );
PHP

		)->output_file_content;

		$active_plugins = json_decode( $active_plugins, true );
		$this->assertContains( 'subfolder-name/test-plugin.php', $active_plugins );
	}

	public function testInstallPluginFromADirectory() {
		$this->execution_context->mkdir(
			'plugin-directory', [ 'recursive' => true ]
		);
		$this->execution_context->put_contents(
			'plugin-directory/test-plugin.php',
			self::PLUGIN_FILE_CONTENT
		);

		$step = new InstallPluginStep(
			DataReference::create( './plugin-directory', [
				ExecutionContextPath::class
			] ),
			true
		);

		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		// Check if plugin is installed
		$fs = $this->runtime->get_target_filesystem();
		$this->assertTrue( $fs->exists( 'wp-content/plugins/plugin-directory/test-plugin.php' ) );

		// Check if plugin is activated
		$active_plugins = $this->runtime->eval_php_code_in_subprocess(
			<<<'PHP'
<?php
require_once getenv('WP_CORE_DIR') . '/wp-load.php';
append_output( json_encode(get_option('active_plugins')) );
PHP

		)->output_file_content;

		$active_plugins = json_decode( $active_plugins, true );
		$this->assertContains( 'plugin-directory/test-plugin.php', $active_plugins );
	}


}
