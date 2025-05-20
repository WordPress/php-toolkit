<?php

namespace WordPress\Blueprints\Steps;

use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;

/**
 * Represents the 'activateTheme' step.
 */
class ActivateThemeStep implements StepInterface {
	/**
	 * The name of the theme folder inside wp-content/themes/.
	 * @var string
	 */
	public $themeFolderName;

	/**
	 * @param  string  $themeFolderName  The name of the theme folder.
	 */
	public function __construct( string $themeFolderName ) {
		$this->themeFolderName = $themeFolderName;
	}

	/**
	 * Executes the activateTheme step.
	 */
	public function run( Runtime $runtime, Tracker $tracker ) {
		$tracker->setCaption( 'Activating theme ' . $this->themeFolderName );
		$runtime->evalPhpCodeInSubProcess(
			file_get_contents( __DIR__ . '/scripts/ActivateTheme/wp_activate_theme.php' ),
			[
				'THEME_FOLDER_NAME' => $this->themeFolderName,
			]
		);
	}
}
