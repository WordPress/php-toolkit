<?php

require_once getenv( 'DOCROOT' ) . '/wp-load.php';

define( 'WP_ADMIN', true );

// Load required WordPress files
require_once getenv( 'DOCROOT' ) . '/wp-load.php';
require_once getenv( 'DOCROOT' ) . '/wp-admin/includes/file.php';
require_once getenv( 'DOCROOT' ) . '/wp-admin/includes/theme.php';
require_once getenv( 'DOCROOT' ) . '/wp-admin/includes/class-wp-upgrader.php';
require_once getenv( 'DOCROOT' ) . '/wp-admin/includes/misc.php';

// Define show_message function if it doesn't exist (fallback)
if ( ! function_exists( 'show_message' ) ) {
	function show_message( $message ) {
		if ( is_wp_error( $message ) ) {
			if ( $message->get_error_data() && is_string( $message->get_error_data() ) ) {
				$message = $message->get_error_message() . ': ' . $message->get_error_data();
			} else {
				$message = $message->get_error_message();
			}
		}
		echo "$message\n";
	}
}

// Ensure filesystem access is properly set up
WP_Filesystem();

// Set current user to an administrator to ensure permissions for theme installation
$admins = get_users( array( 'role' => 'Administrator' ) );
if ( ! empty( $admins ) ) {
	wp_set_current_user( $admins[0]->ID );
} else {
	error_log( "Blueprint Error: No admin user found to perform theme installation." );
	exit( 1 );
}

// Get the path to the theme zip file from environment variable
$theme_zip_path = getenv( 'THEME_ZIP_PATH' );
if ( ! $theme_zip_path ) {
	error_log( "Blueprint Error: THEME_ZIP_PATH environment variable not set." );
	exit( 1 );
}

// Check if the theme zip file exists
if ( ! file_exists( $theme_zip_path ) ) {
	error_log( "Blueprint Error: Theme zip file not found at " . $theme_zip_path );
	exit( 1 );
}

// Make sure the destination directory is writable
$wp_theme_dir = WP_CONTENT_DIR . '/themes';
if ( ! is_writable( $wp_theme_dir ) ) {
	error_log( "Blueprint Error: Theme directory is not writable: " . $wp_theme_dir );
	// Try to fix permissions
	@chmod( $wp_theme_dir, 0755 );
	if ( ! is_writable( $wp_theme_dir ) ) {
		exit( 1 );
	}
}

// Extract theme slug from the zip file
$theme_slug = '';
$zip        = new ZipArchive();
if ( $zip->open( $theme_zip_path ) === true ) {
	// Check the first directory in the zip file
	if ( $zip->numFiles > 0 ) {
		$first_entry = $zip->getNameIndex( 0 );
		// Most theme zips have a top-level directory that is the theme slug
		if ( strpos( $first_entry, '/' ) !== false ) {
			$theme_slug = explode( '/', $first_entry )[0];
		}
	}
	$zip->close();
}

// Target directory for the theme
$target_directory = null;
if ( ! empty( $theme_slug ) ) {
	$target_directory = $wp_theme_dir . '/' . $theme_slug;

	// Remove existing directory if it exists
	if ( is_dir( $target_directory ) ) {
		$GLOBALS['wp_filesystem']->delete( $target_directory, true );
	}

	// Create the directory
	$GLOBALS['wp_filesystem']->mkdir( $target_directory );
}

// Use the Theme_Upgrader class to install the theme
$upgrader = new Theme_Upgrader();
$result   = $upgrader->install( $theme_zip_path, array(
	'overwrite_package' => true,
	'destination'       => $target_directory,
) );

// Check for filesystem errors
if ( $GLOBALS['wp_filesystem']->errors->has_errors() ) {
	foreach ( $GLOBALS['wp_filesystem']->errors->get_error_messages() as $message ) {
		error_log( "Blueprint Error: Filesystem error: " . $message );
	}
	exit( 1 );
}

// Check for installation errors reported by the upgrader directly
if ( is_wp_error( $result ) ) {
	error_log( "Blueprint Error: Failed to install theme: " . $result->get_error_message() );
	exit( 1 );
}

// Check for null or false result, which also indicates failure
if ( $result === false || $result === null ) {
	error_log( "Blueprint Error: Failed to install theme for an unknown reason." );
	exit( 1 );
}

// Installation successful, get the theme folder name (stylesheet) from the result array
$theme_folder_name = ! empty( $theme_slug ) ? $theme_slug : ( $upgrader->result['destination_name'] ?? null );
if ( ! $theme_folder_name ) {
	error_log( "Blueprint Error: Could not determine theme folder name after installation." );
	exit( 1 );
}

// Output the theme folder name (stylesheet) either to a file or stdout
if ( function_exists( 'append_output' ) ) {
	append_output( $theme_folder_name );
} else {
	echo $theme_folder_name;
}

// Exit with success status code
exit( 0 );
