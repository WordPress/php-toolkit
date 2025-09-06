<?php

namespace WordPress\Blueprints\Steps;

use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;

/**
 * Represents the 'activateTheme' step.
 */
class ActivateThemeStep implements StepInterface {

	// Inline PHP script to avoid reading a static script.php file via.
	// file_get_contents() inside the built blueprints.phar file.
	const ACTIVATE_THEME_SCRIPT = <<<'PHP'
<?php

define( 'WP_ADMIN', true );
require_once getenv( 'DOCROOT' ) . '/wp-load.php';

// Set current user to admin
set_current_user( get_users( array( 'role' => 'Administrator' ) )[0] );
switch_theme( getenv( 'THEME_FOLDER_NAME' ) );
PHP;

	/**
	 * The name of the theme folder inside wp-content/themes/.
	 *
	 * @var string
	 */
	public $theme_folder_name;

	/**
	 * @param  string $theme_folder_name  The name of the theme folder.
	 */
	public function __construct( string $theme_folder_name ) {
		$this->theme_folder_name = $theme_folder_name;
	}

	/**
	 * Executes the activateTheme step.
	 */
	public function run( Runtime $runtime, Tracker $tracker ) {
		$tracker->setCaption( 'Activating theme ' . $this->theme_folder_name );
		$runtime->eval_php_code_in_subprocess(
			self::ACTIVATE_THEME_SCRIPT,
			array(
				'THEME_FOLDER_NAME' => $this->theme_folder_name,
			)
		);
	}
}
