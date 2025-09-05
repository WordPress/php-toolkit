<?php

namespace WordPress\Blueprints\Steps;

use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;

/**
 * Represents the 'activatePlugin' step.
 */
class ActivatePluginStep implements StepInterface {
	// Inline PHP script to avoid reading a static script.php file via.
	// file_get_contents() inside the built blueprints.phar file.
	const ACTIVATE_PLUGIN_SCRIPT = <<<'PHP'
<?php

define( 'WP_ADMIN', true );
require_once getenv( 'DOCROOT' ) . '/wp-load.php';
require_once getenv( 'DOCROOT' ) . '/wp-admin/includes/plugin.php';

// Set current user to admin
set_current_user( get_users( array( 'role' => 'Administrator' ) )[0] );

$pluginPath = getenv( 'PLUGIN_PATH' );
if ( ! is_dir( $pluginPath ) ) {
	activate_plugin( $pluginPath );
	die();
}

foreach ( ( glob( $pluginPath . '/*.php' ) ?: array() ) as $file ) {
	$info = get_plugin_data( $file, false, false );
	if ( ! empty( $info['Name'] ) ) {
		activate_plugin( $file );
		die();
	}
}

// If we got here, the plugin was not found.
exit( 1 );
PHP;

	/**
	 * Path to the plugin directory or entry file.
	 * Examples: '/wordpress/wp-content/plugins/plugin-name', 'plugin-name/plugin-name.php'
	 *
	 * @var string
	 */
	public $plugin_path;

	/**
	 * @param  string $pluginPath  Path to the plugin directory or entry file.
	 */
	public function __construct( string $plugin_path ) {
		$this->plugin_path = $plugin_path;
	}

	/**
	 * Executes the activatePlugin step.
	 */
	public function run( Runtime $runtime, Tracker $tracker ) {
		$tracker->setCaption( 'Activating plugin ' . ( $this->plugin_path ?? '' ) );
		$runtime->evalPhpCodeInSubProcess(
			self::ACTIVATE_PLUGIN_SCRIPT,
			array(
				'PLUGIN_PATH' => $this->plugin_path,
			)
		);
	}
}
