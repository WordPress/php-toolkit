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

		$fs = $this->runtime->getTargetFilesystem();
		$this->assertTrue( $fs->exists( 'wp-content/themes/test-theme' ) );
		$this->assertTrue( $fs->exists( 'wp-content/themes/test-theme/style.css' ) );
		$this->assertTrue( $fs->exists( 'wp-content/themes/test-theme/index.php' ) );

		$active_theme = $this->runtime->evalPhpCodeInSubProcess(
			<<<'PHP'
<?php
require_once getenv('DOCROOT') . '/wp-load.php';
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

		$fs = $this->runtime->getTargetFilesystem();
		$this->assertTrue( $fs->exists( 'wp-content/themes/test-theme' ) );
		$this->assertTrue( $fs->exists( 'wp-content/themes/test-theme/style.css' ) );
		$this->assertTrue( $fs->exists( 'wp-content/themes/test-theme/index.php' ) );

		$active_theme = $this->runtime->evalPhpCodeInSubProcess(
			<<<'PHP'
<?php
require_once getenv('DOCROOT') . '/wp-load.php';
append_output( get_option('stylesheet') );
PHP

		)->output_file_content;

		$this->assertNotEquals( 'test-theme', trim( $active_theme ) );
	}

	public function testInstallThemeFromZip() {
		$zip_file = wp_join_unix_paths( $this->execution_context_path, 'zipped-test-theme.zip' );
		$zip      = new ZipArchive();
		if ( $zip->open( $zip_file, ZipArchive::CREATE ) === true ) {
			$zip->addFromString( 'test-theme/style.css', self::THEME_STYLE_CSS_CONTENT );
			$zip->addFromString( 'test-theme/index.php', self::THEME_INDEX_PHP_CONTENT );
			$zip->close();
		}

		$step = new InstallThemeStep(
			DataReference::create( './zipped-test-theme.zip', [
				ExecutionContextPath::class
			] ),
			true
		);

		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		$fs = $this->runtime->getTargetFilesystem();
		$this->assertTrue( $fs->exists( 'wp-content/themes/test-theme' ) );
		$this->assertTrue( $fs->exists( 'wp-content/themes/test-theme/style.css' ) );
		$this->assertTrue( $fs->exists( 'wp-content/themes/test-theme/index.php' ) );

		$active_theme = $this->runtime->evalPhpCodeInSubProcess(
			<<<'PHP'
<?php
require_once getenv('DOCROOT') . '/wp-load.php';
append_output( get_option('stylesheet') );
PHP

		)->output_file_content;

		$this->assertEquals( 'test-theme', trim( $active_theme ) );
	}
}
