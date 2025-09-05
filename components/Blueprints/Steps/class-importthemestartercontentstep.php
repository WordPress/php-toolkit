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
	public $theme_slug;

	/**
	 * @param  string|null  $themeSlug  Optional theme slug.
	 */
	public function __construct( ?string $theme_slug = null ) {
		$this->theme_slug = $theme_slug;
	}

	public function run( Runtime $runtime, Tracker $tracker ) {
		$tracker->setCaption( 'Importing theme starter content' . ( $this->theme_slug ? ' for ' . $this->theme_slug : '' ) );
		// Inline PHP script to avoid reading a static script.php file via
		// file_get_contents() inside the built blueprints.phar file.
		$runtime->evalPhpCodeInSubProcess(
			<<<'PHP'
<?php

/**
 * Ensure that the customizer loads as an admin user.
 *
 * For compatibility with themes, this MUST be run prior to theme inclusion, which is why this is a plugins_loaded filter instead
 * of running _wp_customize_include() manually after load.
 */
function importThemeStarterContent_plugins_loaded() {
	// Set as the admin user, this ensures we can customize the site.
	wp_set_current_user(
		get_users( [ 'role' => 'Administrator' ] )[0]
	);

	// Force the site to be fresh, although it should already be.
	add_filter( 'pre_option_fresh_site', '__return_true' );

	/*
		* Simulate this request as the customizer loading with the current theme in preview mode.
		*
		* See _wp_customize_include()
		*/
	$_REQUEST['wp_customize']    = 'on';
	$_REQUEST['customize_theme'] = getenv( "THEME_SLUG" ) ?: get_stylesheet();

	/*
		* Claim this is a ajax request saving settings, to avoid the preview filters being applied.
		*/
	$_REQUEST['action'] = 'customize_save';
	add_filter( 'wp_doing_ajax', '__return_true' );

	$_GET = $_REQUEST;
}

$wp_filter['plugins_loaded'][0]['importThemeStarterContent_plugins_loaded'] = array( 'function'      => 'importThemeStarterContent_plugins_loaded',
                                                                                     'accepted_args' => 0,
);

require getenv( "DOCROOT" ) . '/wp-load.php';

// Return early if there's no starter content.
if ( ! get_theme_starter_content() ) {
	return;
}

// Import the Starter Content.
$wp_customize->import_theme_starter_content();

// Publish the changeset, which publishes the starter content.
wp_publish_post( $wp_customize->changeset_post_id() );

PHP
			,
		);
	}
}
