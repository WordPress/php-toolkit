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
	 *
	 * @var string
	 */
	public $command;

	/**
	 * Optional path to the WP-CLI executable.
	 *
	 * @var string|null
	 */
	public $wp_cli_path;

	/**
	 * @param  string      $command  The WP-CLI command string.
	 * @param  string|null $wp_cli_path  Optional path to WP-CLI executable.
	 */
	public function __construct( string $command, ?string $wp_cli_path = null ) {
		$this->command     = $command;
		$this->wp_cli_path = $wp_cli_path;
	}

	public function run( Runtime $runtime, Tracker $tracker ) {
		$tracker->setCaption( 'Running WP-CLI command: ' . $this->command );
		$command = $this->command;
		if ( 'wp ' !== substr( $command, 0, 3 ) ) {
			throw new Exception( 'WP-CLI command must start with "wp ".' );
		}

		$command = implode(
			' ',
			array(
				$this->wp_cli_path ?? $runtime->get_wp_cli_path(),
				// For Docker compatibility. If we got this far, the Blueprint runner was already
				// allowed to run as root.
				'--allow-root',
				'--path=' . $runtime->get_configuration()->get_target_site_root(),
				substr( $command, 3 ),
			)
		);
		$process = $runtime->start_shell_command( $command );
		$process->mustRun();
	}
}
