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
