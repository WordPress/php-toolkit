<?php

namespace WordPress\Blueprints\Steps;

use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;

class ImportThemeStarterContentStep implements StepInterface {
	/**
	 * Optional slug of the theme to import content from.
	 * If null, might imply the currently active theme.
	 * @var string|null
	 */
	public $themeSlug;

	/**
	 * @param  string|null  $themeSlug  Optional theme slug.
	 */
	public function __construct( ?string $themeSlug = null ) {
		$this->themeSlug = $themeSlug;
	}

	public function run( Runtime $runtime, Tracker $tracker ) {
		$tracker->setCaption( 'Importing theme starter content' . ( $this->themeSlug ? ' for ' . $this->themeSlug : '' ) );
		$runtime->evalPhpCodeInSubProcess(
			file_get_contents( __DIR__ . '/scripts/ImportThemeStarterContent/import.php' ),
			[
				'THEME_SLUG' => $this->themeSlug,
			]
		);
	}
}
