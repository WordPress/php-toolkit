<?php

namespace WordPress\Blueprints\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPress\Blueprints\RunnerConfiguration;

class RunnerConfigurationTest extends TestCase {

	/**
	 * When no WordPress core dir is set, it defaults to the target site root.
	 */
	public function test_wordpress_core_dir_defaults_to_site_root() {
		$config = new RunnerConfiguration();
		$config->set_target_site_root( '/srv/htdocs' );

		$this->assertSame( '/srv/htdocs', $config->get_wordpress_core_dir() );
	}

	/**
	 * When a WordPress core dir is explicitly set, it takes precedence
	 * over the target site root.
	 */
	public function test_wordpress_core_dir_can_be_set_independently() {
		$config = new RunnerConfiguration();
		$config->set_target_site_root( '/srv/htdocs' );
		$config->set_wordpress_core_dir( '/srv/htdocs/__wp__' );

		$this->assertSame( '/srv/htdocs', $config->get_target_site_root() );
		$this->assertSame( '/srv/htdocs/__wp__', $config->get_wordpress_core_dir() );
	}

	/**
	 * Setting WordPress core dir to null resets to the default behavior
	 * (falling back to the site root).
	 */
	public function test_wordpress_core_dir_reset_to_null_falls_back_to_site_root() {
		$config = new RunnerConfiguration();
		$config->set_target_site_root( '/srv/htdocs' );
		$config->set_wordpress_core_dir( '/srv/htdocs/__wp__' );
		$config->set_wordpress_core_dir( null );

		$this->assertSame( '/srv/htdocs', $config->get_wordpress_core_dir() );
	}
}
