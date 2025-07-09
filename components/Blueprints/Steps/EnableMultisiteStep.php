<?php

namespace WordPress\Blueprints\Steps;

use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;

/**
 * Represents the 'enableMultisite' step.
 */
class EnableMultisiteStep implements StepInterface {
	/**
	 * Optional path to the WP-CLI executable.
	 * @var string|null
	 */
	public $wpCliPath;

	/**
	 * @param  string|null  $wpCliPath  Optional path to WP-CLI executable.
	 */
	public function __construct( ?string $wpCliPath = null ) {
		$this->wpCliPath = $wpCliPath;
	}

	public function run( Runtime $runtime, Tracker $tracker ) {
		$tracker->setCaption( 'Enabling WordPress multisite' );
		
		$wp_cli_path = $this->wpCliPath ?? $runtime->getWpCliPath();
		$site_url = $runtime->getConfiguration()->getTargetSiteUrl();
		
		// Convert existing WordPress installation to multisite
		$process = $runtime->startShellCommand( [
			'php',
			$wp_cli_path,
			'core',
			'multisite-convert',
			// For Docker compatibility. If we got this far, Blueprint runner was already
			// allowed to run as root.
			'--allow-root',
			'--url=' . $site_url,
			'--title=Multisite Network',
		] );
		$process->mustRun();
	}
}