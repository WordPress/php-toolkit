<?php

namespace WordPress\Blueprints\Steps;

use Symfony\Component\Process\Exception\ProcessFailedException;
use WordPress\Blueprints\Exception\BlueprintExecutionException;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;

/**
 * Represents the 'enableMultisite' step.
 */
class EnableMultisiteStep implements StepInterface {
	public function run( Runtime $runtime, Tracker $tracker ) {
		$tracker->setCaption( 'Enabling multisite' );

		$code =
		<<<'PHP'
<?php
/*
 * This code is mirroring the "wp core multisite-convert" command behavior.
 * See: https://github.com/wp-cli/core-command/blob/f157fb37dae1d13fe7318452f932917161e83e53/src/Core_Command.php#L505
 */

require_once getenv( 'DOCROOT' ) . '/wp-load.php';
require_once getenv( 'DOCROOT' ) . '/wp-admin/includes/upgrade.php';

// need to register the multisite tables manually for some reason
foreach ( $wpdb->tables( 'ms_global' ) as $table => $prefixed_table ) {
	$wpdb->$table = $prefixed_table;
}

install_network();

// Get multisite arguments
$site_id     = 1;
$base        = '/';
$title       = sprintf( '%s Sites', get_option( 'blogname' ) );
$admin_email = get_option( 'admin_email' );
$subdomains  = false;

// Get the base domain
$siteurl = get_option( 'siteurl' );
$domain  = (string) preg_replace( '|https?://|', '', $siteurl );
$slash   = strpos( $domain, '/' );
if ( false !== $slash ) {
	$domain = substr( $domain, 0, $slash );
}

// Eagerly check for custom ports
if ( strpos( $domain, ':' ) !== false ) {
	throw new Exception(
		sprintf(
			'The current host is "%s", but WordPress multisites do not support custom ports.',
			$domain
		)
	);
}

$result = populate_network(
	$site_id,
	$domain,
	$admin_email,
	$title,
	$base,
	$subdomains
);

$site_id = $wpdb->get_var( "SELECT id FROM $wpdb->site" );
$site_id = ( null === $site_id ) ? 1 : (int) $site_id;

if ( $result instanceof WP_Error ) {
	throw new Exception(
		sprintf(
			'Error: [%s] %s',
			$result->get_error_code(),
			$result->get_error_message()
		)
	);
}

// delete_site_option() cleans the alloptions cache to prevent dupe option
delete_site_option( 'upload_space_check_disabled' );
update_site_option( 'upload_space_check_disabled', 1 );

$wp_config_constants = array(
	'WP_ALLOW_MULTISITE'   => true,
	'MULTISITE'            => true,
	'SUBDOMAIN_INSTALL'    => $subdomains,
	'DOMAIN_CURRENT_SITE'  => $domain,
	'PATH_CURRENT_SITE'    => $base,
	'SITE_ID_CURRENT_SITE' => $site_id,
	'BLOG_ID_CURRENT_SITE' => 1,
);

append_output( json_encode( $wp_config_constants ) );
PHP;

		try {
			$result = $runtime->evalPhpCodeInSubProcess( $code );
		} catch ( ProcessFailedException $e ) {
			throw new BlueprintExecutionException( $e->getMessage() );
		}

		if ( '' === $result->outputFileContent ) {
			throw new BlueprintExecutionException( 'Failed to enable multisite' );
		}

		// Reuse DefineConstantsStep to set the multisite constants.
		$wpConfigConstants   = json_decode( $result->outputFileContent, true );
		$defineConstantsStep = new DefineConstantsStep( $wpConfigConstants );
		$defineConstantsStep->run( $runtime, $tracker );
	}
}
