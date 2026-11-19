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

	public function testInstallPluginFromZipWithDotRootPreservesExistingPlugins() {
		$target_filesystem = $this->runtime->get_target_filesystem();
		$target_filesystem->put_contents(
			'wp-content/plugins/existing-plugin.php',
			self::PLUGIN_FILE_CONTENT
		);

		$zip_file = wp_join_unix_paths( $this->execution_context_path, 'dot-root-plugin.zip' );
		$zip      = new ZipArchive();
		if ( $zip->open( $zip_file, ZipArchive::CREATE ) === true ) {
			$zip->addEmptyDir( './' );
			$zip->addFromString( './test-plugin.php', self::PLUGIN_FILE_CONTENT );
			$zip->close();
		}

		$step = new InstallPluginStep(
			DataReference::create( './dot-root-plugin.zip', [
				ExecutionContextPath::class
			] ),
			false
		);

		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		$target_filesystem->put_contents( 'wp-content/plugins/dot-root-plugin/obsolete.txt', 'old package content' );
		$this->execution_context->mkdir( 'reinstall' );
		$this->execution_context->copy( 'dot-root-plugin.zip', 'reinstall/dot-root-plugin.zip' );
		$reinstall = new InstallPluginStep(
			DataReference::create( './reinstall/dot-root-plugin.zip', [ ExecutionContextPath::class ] ),
			false
		);
		$reinstall->run( $this->runtime, new Tracker() );

		$this->assertFalse( $target_filesystem->exists( 'wp-content/plugins/dot-root-plugin/obsolete.txt' ) );
		$this->assertTrue( $target_filesystem->exists( 'wp-content/plugins/existing-plugin.php' ) );
		$this->assertTrue( $target_filesystem->exists( 'wp-content/plugins/dot-root-plugin/test-plugin.php' ) );
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

	public function test_flat_plugin_package_stays_inside_upgrader_working_directory() {
		$slug     = 'staging-test-plugin';
		$zip_name = $slug . '.zip';
		$zip      = new ZipArchive();
		$this->assertTrue( $zip->open( wp_join_unix_paths( $this->execution_context_path, $zip_name ), ZipArchive::CREATE ) );
		$this->assertTrue( $zip->addFromString( 'test-plugin.php', self::PLUGIN_FILE_CONTENT ) );
		$this->assertTrue( $zip->close() );

		$step = new InstallPluginStep(
			DataReference::create( './' . $zip_name, array( ExecutionContextPath::class ) ),
			false
		);

		$filesystem = $this->runtime->get_target_filesystem();
		$filesystem->mkdir( 'wp-content/mu-plugins', array( 'recursive' => true ) );
		// Record the final source before WordPress moves the files and cleans up staging.
		$filesystem->put_contents(
			'wp-content/mu-plugins/record-upgrader-source.php',
			<<<'PHP'
<?php
add_filter(
	'upgrader_source_selection',
	static function ( $source, $working_dir ) {
		if ( ! is_wp_error( $source ) ) {
			file_put_contents(
				__DIR__ . '/upgrader-source.json',
				json_encode( array( realpath( $source ), realpath( $working_dir ) ) )
			);
		}
		return $source;
	},
	PHP_INT_MAX,
	2
);
PHP
		);

		$step->run( $this->runtime, new Tracker() );

		$record = 'wp-content/mu-plugins/upgrader-source.json';
		$this->assertTrue( $filesystem->exists( $record ), 'WordPress should select a source for the package.' );
		list( $source, $working_dir ) = json_decode( $filesystem->get_contents( $record ), true );

		$this->assertIsString( $source, 'The selected package source should exist.' );
		$this->assertIsString( $working_dir, 'The original working directory should still exist.' );

		$source      = str_replace( '\\', '/', $source );
		$working_dir = str_replace( '\\', '/', $working_dir );
		$this->assertSame( $slug, basename( $source ) );
		if ( $slug === basename( $working_dir ) ) {
			// Older WordPress versions already use the package basename.
			$this->assertSame( $working_dir, $source );
		} else {
			$this->assertStringStartsWith( $working_dir . '/', $source );
		}
	}
}
