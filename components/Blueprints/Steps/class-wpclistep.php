<?php

namespace WordPress\Blueprints\Steps;

use Exception;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;

/**
 * Represents the 'wp-cli' step.
 */
class WPCLIStep implements StepInterface {
	/**
	 * The WP-CLI command arguments string (e.g., "plugin install woocommerce --activate").
	 * @var string
	 */
	public $command;

	/**
	 * Optional path to the WP-CLI executable.
	 * @var string|null
	 */
	public $wpCliPath;

	/**
	 * @param  string  $command  The WP-CLI command string.
	 * @param  string|null  $wpCliPath  Optional path to WP-CLI executable.
	 */
	public function __construct( string $command, ?string $wpCliPath = null ) {
		$this->command   = $command;
		$this->wpCliPath = $wpCliPath;
	}

	public function run( Runtime $runtime, Tracker $tracker ) {
		$tracker->setCaption( 'Running WP-CLI command: ' . $this->command );
		$command = $this->command;
		if ( substr( $command, 0, 3 ) !== 'wp ' ) {
			throw new Exception( 'WP-CLI command must start with "wp ".' );
		}
		
		$command = implode(' ', [
			$this->wpCliPath ?? $runtime->getWpCliPath(),
			// For Docker compatibility. If we got this far, the Blueprint runner was already
			// allowed to run as root.
			'--allow-root',
			'--path=' . $runtime->getConfiguration()->getTargetSiteRoot(),
			substr($command, 3),
		]);
		$process = $runtime->startShellCommand( $command );
		$process->mustRun();
	}
}
