<?php

namespace WordPress\Blueprints\Tests\Unit\Steps;

use WordPress\Blueprints\DataReference\DataReference;
use WordPress\Blueprints\DataReference\ExecutionContextPath;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Steps\InstallThemeStep;

use ZipArchive;

use function WordPress\Filesystem\wp_join_unix_paths;

require_once __DIR__ . '/StepTestCase.php';

class InstallThemeStepTest extends StepTestCase {
	const THEME_STYLE_CSS_CONTENT = <<<'CSS'
/*
Theme Name: Test Theme
Theme URI: https://example.com
Author: Test
Author URI: https://example.com
Description: A test theme for InstallThemeStep test
Version: 1.0.0
*/
body {
font-family: sans-serif;
}
CSS;

	const THEME_INDEX_PHP_CONTENT = <<<'PHP'
<?php
/**
* Main theme file
* 
* @package Test_Theme
*/

// Simple theme initialization
function test_theme_setup() {
add_theme_support( 'title-tag' );
add_theme_support( 'post-thumbnails' );
}
add_action( 'after_setup_theme', 'test_theme_setup' );
PHP;

	public function testInstallThemeWithActivation() {
		$this->execution_context->mkdir(
			'test-theme', [ 'recursive' => true ]
		);
		$this->execution_context->put_contents(
			'test-theme/style.css',
			self::THEME_STYLE_CSS_CONTENT
		);
		$this->execution_context->put_contents(
			'test-theme/index.php',
			self::THEME_INDEX_PHP_CONTENT
		);

		$step = new InstallThemeStep(
			DataReference::create( './test-theme', [
				ExecutionContextPath::class
			] ),
			true
		);

		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		$fs = $this->runtime->get_target_filesystem();
		$this->assertTrue( $fs->exists( 'wp-content/themes/test-theme' ) );
		$this->assertTrue( $fs->exists( 'wp-content/themes/test-theme/style.css' ) );
		$this->assertTrue( $fs->exists( 'wp-content/themes/test-theme/index.php' ) );

		$active_theme = $this->runtime->eval_php_code_in_subprocess(
			<<<'PHP'
<?php
require_once getenv('WP_CORE_DIR') . '/wp-load.php';
append_output( get_option('stylesheet') );
PHP

		)->output_file_content;

		$this->assertEquals( 'test-theme', trim( $active_theme ) );
	}

	public function testInstallThemeWithoutActivation() {
		$this->execution_context->mkdir(
			'test-theme', [ 'recursive' => true ]
		);
		$this->execution_context->put_contents(
			'test-theme/style.css',
			self::THEME_STYLE_CSS_CONTENT
		);
		$this->execution_context->put_contents(
			'test-theme/index.php',
			self::THEME_INDEX_PHP_CONTENT
		);

		$step = new InstallThemeStep(
			DataReference::create( './test-theme', [
				ExecutionContextPath::class
			] ),
			false
		);

		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		$fs = $this->runtime->get_target_filesystem();
		$this->assertTrue( $fs->exists( 'wp-content/themes/test-theme' ) );
		$this->assertTrue( $fs->exists( 'wp-content/themes/test-theme/style.css' ) );
		$this->assertTrue( $fs->exists( 'wp-content/themes/test-theme/index.php' ) );

		$active_theme = $this->runtime->eval_php_code_in_subprocess(
			<<<'PHP'
<?php
require_once getenv('WP_CORE_DIR') . '/wp-load.php';
append_output( get_option('stylesheet') );
PHP

		)->output_file_content;

		$this->assertNotEquals( 'test-theme', trim( $active_theme ) );
	}

	public function testInstallThemeFromZipWithDotRootPreservesExistingThemes() {
		$target_filesystem = $this->runtime->get_target_filesystem();
		$target_filesystem->mkdir(
			'wp-content/themes/existing-theme', [ 'recursive' => true ]
		);
		$target_filesystem->put_contents(
			'wp-content/themes/existing-theme/style.css',
			self::THEME_STYLE_CSS_CONTENT
		);

		$zip_file = wp_join_unix_paths( $this->execution_context_path, 'dot-root-theme.zip' );
		$zip      = new ZipArchive();
		if ( $zip->open( $zip_file, ZipArchive::CREATE ) === true ) {
			$zip->addEmptyDir( './' );
			$zip->addFromString( './style.css', self::THEME_STYLE_CSS_CONTENT );
			$zip->addFromString( './index.php', self::THEME_INDEX_PHP_CONTENT );
			$zip->close();
		}

		$step = new InstallThemeStep(
			DataReference::create( './dot-root-theme.zip', [
				ExecutionContextPath::class
			] ),
			true
		);

		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		$target_filesystem->put_contents( 'wp-content/themes/dot-root-theme/obsolete.txt', 'old package content' );
		$this->execution_context->mkdir( 'reinstall' );
		$this->execution_context->copy( 'dot-root-theme.zip', 'reinstall/dot-root-theme.zip' );
		$reinstall = new InstallThemeStep(
			DataReference::create( './reinstall/dot-root-theme.zip', [ ExecutionContextPath::class ] ),
			true
		);
		$this->assert_install_stages_package_under_working_directory( $reinstall, 'dot-root-theme' );

		$this->assertFalse( $target_filesystem->exists( 'wp-content/themes/dot-root-theme/obsolete.txt' ) );
		$this->assertTrue( $target_filesystem->exists( 'wp-content/themes/existing-theme/style.css' ) );
		$this->assertTrue( $target_filesystem->exists( 'wp-content/themes/dot-root-theme' ) );
		$this->assertTrue( $target_filesystem->exists( 'wp-content/themes/dot-root-theme/style.css' ) );
		$this->assertTrue( $target_filesystem->exists( 'wp-content/themes/dot-root-theme/index.php' ) );

		$active_theme = $this->runtime->eval_php_code_in_subprocess(
			<<<'PHP'
<?php
require_once getenv('WP_CORE_DIR') . '/wp-load.php';
append_output( get_option('stylesheet') );
PHP

		)->output_file_content;

		$this->assertEquals( 'dot-root-theme', trim( $active_theme ) );
	}
}
