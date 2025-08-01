<?php

namespace WordPress\Blueprints\Tests\Unit\Steps;

use Exception;
use WordPress\Blueprints\Exception\BlueprintExecutionException;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Steps\EnableMultisiteStep;

class EnableMultisiteStepTest extends StepTestCase {
	public function setUp(): void {
		parent::setUp();

		// Set site URL.
		$this->runtime->evalPhpCodeInSubProcess(
			<<<'PHP'
<?php
require_once getenv('DOCROOT') . '/wp-load.php';
update_option( 'siteurl', 'http://localhost' );
update_option( 'home', 'http://localhost' );
PHP
		);
	}

	public function testEnableMultisite() {
		$step = new EnableMultisiteStep();
		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		// Verify that multisite is set up and enabled.
		$result = $this->runtime->evalPhpCodeInSubProcess(
			<<<'PHP'
<?php

// Load WordPress environment
require_once getenv('DOCROOT') . '/wp-load.php';

// Verify multisite setup
append_output(
	json_encode( [
		'is_multisite'         => is_multisite(),
		'name'                 => get_bloginfo( 'name' ),
		'wpurl'                => get_bloginfo( 'wpurl' ),
		'url'                  => get_bloginfo( 'url' ),
		'network'              => get_network(),
		'constants'            => [
			'WP_ALLOW_MULTISITE'   => defined( 'WP_ALLOW_MULTISITE' ) ? WP_ALLOW_MULTISITE : null,
			'MULTISITE'            => defined( 'MULTISITE' ) ? MULTISITE : null,
			'SUBDOMAIN_INSTALL'    => defined( 'SUBDOMAIN_INSTALL' ) ? SUBDOMAIN_INSTALL : null,
			'DOMAIN_CURRENT_SITE'  => defined( 'DOMAIN_CURRENT_SITE' ) ? DOMAIN_CURRENT_SITE : null,
			'PATH_CURRENT_SITE'    => defined( 'PATH_CURRENT_SITE' ) ? PATH_CURRENT_SITE : null,
			'SITE_ID_CURRENT_SITE' => defined( 'SITE_ID_CURRENT_SITE' ) ? SITE_ID_CURRENT_SITE : null,
			'BLOG_ID_CURRENT_SITE' => defined( 'BLOG_ID_CURRENT_SITE' ) ? BLOG_ID_CURRENT_SITE : null,
		],
	] )
);

PHP
		);

		$output = json_decode( $result->outputFileContent, true );
		$this->assertSame( true, $output['is_multisite'] );
		$this->assertSame( 'WordPress Site', $output['name'] );
		$this->assertSame( 'http://localhost', $output['wpurl'] );
		$this->assertSame( 'http://localhost', $output['url'] );

		$network = $output['network'];
		$this->assertSame( 'localhost', $network['domain'] );
		$this->assertSame( 'localhost', $network['cookie_domain'] );
		$this->assertSame( '/', $network['path'] );
		$this->assertSame( 'WordPress Site Sites', $network['site_name'] );

		$constants = $output['constants'];
		$this->assertSame( true, $constants['WP_ALLOW_MULTISITE'] );
		$this->assertSame( true, $constants['MULTISITE'] );
		$this->assertSame( false, $constants['SUBDOMAIN_INSTALL'] );
		$this->assertSame( 'localhost', $constants['DOMAIN_CURRENT_SITE'] );
		$this->assertSame( '/', $constants['PATH_CURRENT_SITE'] );
		$this->assertSame( 1, $constants['SITE_ID_CURRENT_SITE'] );
		$this->assertSame( 1, $constants['BLOG_ID_CURRENT_SITE'] );
	}


	public function testEnableMultisiteRedirectsWhenSiteNotFound() {
		$step = new EnableMultisiteStep();
		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		// Verify that multisite is set up and enabled.
		$result = $this->runtime->evalPhpCodeInSubProcess(
			<<<'PHP'
<?php

register_shutdown_function( function() {
	if ( did_action( 'ms_site_not_found' ) ) {
		append_output( 'redirected' );
	}
});

$_SERVER['HTTP_HOST'] = 'http://unknown';
$_SERVER['REQUEST_URI'] = '/';

// Load WordPress environment
require_once getenv('DOCROOT') . '/wp-load.php';
append_output( 'not_redirected' );

PHP
		);

		// In the CLI SAPI, the "header()" function is a no-op. We can only test
		// that "ms_site_not_found" was called and that the process exited early.
		$this->assertSame( 'redirected', $result->outputFileContent );
	}

	public function testEnableMultisiteFailsOnNon80Port() {
		$this->runtime->evalPhpCodeInSubProcess(
			<<<'PHP'
<?php
require_once getenv('DOCROOT') . '/wp-load.php';
update_option( 'siteurl', 'http://localhost:8080' );
PHP
		);

		$this->expectException( BlueprintExecutionException::class );
		$this->expectExceptionMessage( 'The current host is "localhost:8080", but WordPress multisites do not support custom ports.' );
		$step = new EnableMultisiteStep();
		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );
	}

	public function testEnableMultisiteFailsWhenAlreadyEnabled() {
		$step = new EnableMultisiteStep();
		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );

		$this->expectException( BlueprintExecutionException::class );
		$this->expectExceptionMessage( '[siteid_exists] The network already exists.' );
		$step->run( $this->runtime, $tracker );
	}

	public function testEnableMultisiteFailsWhenConfigInvalid() {
		$this->runtime->evalPhpCodeInSubProcess(
			<<<'PHP'
<?php
require_once getenv('DOCROOT') . '/wp-load.php';
delete_option( 'siteurl' );
PHP
		);

		$this->expectException( BlueprintExecutionException::class );
		$this->expectExceptionMessage( 'Failed to enable multisite' );
		$step = new EnableMultisiteStep();
		$tracker = new Tracker();
		$step->run( $this->runtime, $tracker );
	}
}
