<?php

namespace WordPress\Blueprints\Steps;

use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;

/**
 * Represents the 'activatePlugin' step.
 */
class ActivatePluginStep implements StepInterface {
	/**
	 * Path to the plugin directory or entry file.
	 * Examples: '/wordpress/wp-content/plugins/plugin-name', 'plugin-name/plugin-name.php'
	 * @var string
	 */
	public $pluginPath;

	/**
	 * @param  string  $pluginPath  Path to the plugin directory or entry file.
	 */
	public function __construct( string $pluginPath ) {
		$this->pluginPath = $pluginPath;
	}

	/**
	 * Executes the activatePlugin step.
	 */
	public function run( Runtime $runtime, Tracker $tracker ) {
		$tracker->setCaption( 'Activating plugin ' . ( $this->pluginPath ?? '' ) );
		$runtime->evalPhpCodeInSubProcess(
			file_get_contents( __DIR__ . '/scripts/ActivatePlugin/wp_activate_plugin.php' ),
			[
				'PLUGIN_PATH' => $this->pluginPath,
			]
		);
	}

}
