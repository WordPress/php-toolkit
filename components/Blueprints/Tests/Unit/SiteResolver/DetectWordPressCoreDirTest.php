<?php

namespace WordPress\Blueprints\Tests\Unit\SiteResolver;

use PHPUnit\Framework\TestCase;
use WordPress\Blueprints\SiteResolver\ExistingSiteResolver;

use function WordPress\Filesystem\wp_unix_sys_get_temp_dir;

/**
 * Tests for ExistingSiteResolver::detect_wordpress_core_dir().
 *
 * On WP Cloud sites, the WordPress core files (wp-load.php, wp-admin/,
 * wp-includes/) live in a subdirectory like __wp__/ while wp-content/
 * stays in the web root. The detection method must find wp-load.php
 * in both standard and split layouts.
 */
class DetectWordPressCoreDirTest extends TestCase {
	/**
	 * @var string
	 */
	private $temp_dir;

	protected function setUp(): void {
		$this->temp_dir = wp_unix_sys_get_temp_dir() . '/wp_core_detect_' . uniqid();
		mkdir( $this->temp_dir, 0755, true );
	}

	protected function tearDown(): void {
		$this->remove_directory( $this->temp_dir );
	}

	/**
	 * Standard layout: wp-load.php is in the web root.
	 */
	public function test_detects_standard_layout() {
		touch( $this->temp_dir . '/wp-load.php' );

		$result = ExistingSiteResolver::detect_wordpress_core_dir( $this->temp_dir );

		$this->assertSame( $this->temp_dir, $result );
	}

	/**
	 * WP Cloud layout: wp-load.php lives in __wp__/ subdirectory.
	 */
	public function test_detects_wp_cloud_layout_with___wp___subdirectory() {
		// Web root has wp-content but not wp-load.php.
		mkdir( $this->temp_dir . '/wp-content', 0755, true );

		// WordPress core is in __wp__/.
		$wp_core = $this->temp_dir . '/__wp__';
		mkdir( $wp_core, 0755, true );
		touch( $wp_core . '/wp-load.php' );

		$result = ExistingSiteResolver::detect_wordpress_core_dir( $this->temp_dir );

		$this->assertSame( $wp_core, $result );
	}

	/**
	 * Custom subdirectory layout: wp-load.php in an arbitrary subdirectory.
	 */
	public function test_detects_custom_subdirectory_layout() {
		$wp_core = $this->temp_dir . '/core';
		mkdir( $wp_core, 0755, true );
		touch( $wp_core . '/wp-load.php' );

		$result = ExistingSiteResolver::detect_wordpress_core_dir( $this->temp_dir );

		$this->assertSame( $wp_core, $result );
	}

	/**
	 * No WordPress installation: returns null when wp-load.php is not
	 * found anywhere.
	 */
	public function test_returns_null_when_no_wordpress_found() {
		// Empty directory, no wp-load.php anywhere.
		$result = ExistingSiteResolver::detect_wordpress_core_dir( $this->temp_dir );

		$this->assertNull( $result );
	}

	/**
	 * Deeply nested wp-load.php should NOT be detected. Only the
	 * web root and its immediate subdirectories are searched.
	 */
	public function test_does_not_detect_deeply_nested_wp_load() {
		$deep = $this->temp_dir . '/a/b/c';
		mkdir( $deep, 0755, true );
		touch( $deep . '/wp-load.php' );

		$result = ExistingSiteResolver::detect_wordpress_core_dir( $this->temp_dir );

		$this->assertNull( $result );
	}

	/**
	 * Non-existent directory: returns null gracefully.
	 */
	public function test_returns_null_for_nonexistent_directory() {
		$result = ExistingSiteResolver::detect_wordpress_core_dir( '/nonexistent/path/' . uniqid() );

		$this->assertNull( $result );
	}

	private function remove_directory( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$entries = scandir( $dir );
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) ) {
				$this->remove_directory( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}
}
