<?php

namespace WordPress\Blueprints\Tests\Unit\Steps;

use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Steps\EnableMultisiteStep;

class EnableMultisiteStepTest extends StepTestCase {
	/**
	 * Test enabling multisite
	 */
	public function testEnableMultisite() {
		$step = new EnableMultisiteStep();
		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		// Verify multisite is enabled by checking for multisite constants in wp-config.php
		$this->assertMultisiteEnabled();
	}

	/**
	 * Test enabling multisite with custom WP-CLI path
	 */
	public function testEnableMultisiteWithCustomWpCliPath() {
		$customWpCliPath = $this->runtime->getWpCliPath(); // Use the same path for testing
		$step = new EnableMultisiteStep( $customWpCliPath );
		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		// Verify multisite is enabled
		$this->assertMultisiteEnabled();
	}

	/**
	 * Helper to verify multisite is enabled
	 */
	private function assertMultisiteEnabled() {
		$result = $this->runtime->evalPhpCodeInSubProcess(
			<<<'PHP'
<?php
require_once getenv('DOCROOT') . '/wp-load.php';

// Check if multisite constants are defined
$is_multisite = defined('MULTISITE') && MULTISITE;
$is_subdomain = defined('SUBDOMAIN_INSTALL') && SUBDOMAIN_INSTALL;

append_output( json_encode([
    'multisite' => $is_multisite,
    'subdomain_install' => $is_subdomain,
    'multisite_function' => function_exists('is_multisite') && is_multisite()
]) );
PHP
		)->outputFileContent;

		$multisite_status = json_decode( $result, true );

		$this->assertTrue(
			$multisite_status['multisite'],
			'WordPress multisite should be enabled (MULTISITE constant should be true)'
		);

		$this->assertTrue(
			$multisite_status['multisite_function'],
			'WordPress is_multisite() function should return true'
		);
	}
}