<?php

require_once getenv( 'DOCROOT' ) . '/wp-load.php';

define( 'WP_ADMIN', true );

// Define a dummy skin for the upgrader.
if ( ! class_exists( '\WP_Upgrader_Skin', false ) ) {
	require_once getenv( 'DOCROOT' ) . '/wp-admin/includes/class-wp-upgrader.php';

	class Blueprint_WP_Upgrader_Skin extends WP_Upgrader_Skin {
		public $destination;
		public $options = array(
			'type'   => '',
			'title'  => '',
			'url'    => '',
			'nonce'  => '',
			'plugin' => '',
			'api'    => null,
			'extra'  => array(),
		);
		public $result = null;

		public function add_strings() {
		}

		public function set_upgrader( &$upgrader ) {
			if ( is_object( $upgrader ) ) {
				$this->upgrader = &$upgrader;
			}
			$this->add_strings();
		}

		public function set_result( $result ) {
			$this->result = $result;
		}

		public function request_filesystem_credentials( $error = false, $context = '', $allow_relaxed_file_ownership = false ) {
			return true;
		}

		public function error( $errors ) {
			if ( is_string( $errors ) ) {
				$this->feedback( $errors );

				return;
			}
			if ( is_wp_error( $errors ) && $errors->has_errors() ) {
				foreach ( $errors->get_error_messages() as $message ) {
					if ( $errors->get_error_data() && is_string( $errors->get_error_data() ) ) {
						$this->feedback( $message . ': ' . esc_html( strip_tags( $errors->get_error_data() ) ) );
					} else {
						$this->feedback( $message );
					}
				}
			}
		}

		public function feedback( $string, ...$args ) {
			// For debugging
			error_log( sprintf( $string, ...$args ) );
		}

		public function header() {
		}

		public function footer() {
		}

		public function bulk_header() {
		}

		public function bulk_footer() {
		}

		public function before( $title = '' ) {
		}

		public function after( $title = '' ) {
		}
	}
}

require_once getenv( 'DOCROOT' ) . '/wp-load.php';
require_once getenv( 'DOCROOT' ) . '/wp-admin/includes/plugin.php';
require_once getenv( 'DOCROOT' ) . '/wp-admin/includes/file.php';
require_once getenv( 'DOCROOT' ) . '/wp-admin/includes/plugin-install.php';
require_once getenv( 'DOCROOT' ) . '/wp-admin/includes/class-wp-upgrader.php';

// Ensure filesystem access is properly set up
WP_Filesystem();

// Set current user to admin
$admins = get_users( array( 'role' => 'Administrator' ) );
if ( ! empty( $admins ) ) {
	wp_set_current_user( $admins[0]->ID );
} else {
	error_log( "No admin user found to perform plugin installation." );
	exit( 1 );
}

$plugin_zip_path = getenv( 'PLUGIN_ZIP_PATH' );
if ( ! $plugin_zip_path ) {
	error_log( "PLUGIN_ZIP_PATH environment variable not set." );
	exit( 1 );
}

if ( ! file_exists( $plugin_zip_path ) ) {
	error_log( "Plugin zip file not found at " . $plugin_zip_path );
	exit( 1 );
}

// List files from the plugin zip
$zip = new ZipArchive();
if ( $zip->open( $plugin_zip_path ) !== true ) {
	error_log( "Failed to open plugin zip file: " . $plugin_zip_path );
	exit( 1 );
}

error_log( "Plugin zip contents:" );
for ( $i = 0; $i < $zip->numFiles; $i ++ ) {
	$filename = $zip->getNameIndex( $i );
	$stats    = $zip->statIndex( $i );
	$size     = $stats['size'];
	$is_dir   = substr( $filename, - 1 ) === '/';

	error_log( sprintf(
		"%s%s (%s bytes)",
		$filename,
		$is_dir ? " [directory]" : "",
		$size
	) );
}

// Extract plugin slug from the zip file
$plugin_slug = '';
// Check the first directory in the zip file
if ( $zip->numFiles > 0 ) {
	$first_entry = $zip->getNameIndex( 0 );
	// Most plugin zips have a top-level directory that is the plugin slug
	if ( strpos( $first_entry, '/' ) !== false ) {
		$plugin_slug = explode( '/', $first_entry )[0];
	}
}

$zip->close();

// Make sure the destination directory is writable
$wp_plugin_dir = WP_PLUGIN_DIR;
if ( ! is_writable( $wp_plugin_dir ) ) {
	error_log( "Plugin directory is not writable: " . $wp_plugin_dir );
	// Try to fix permissions
	@chmod( $wp_plugin_dir, 0755 );
	if ( ! is_writable( $wp_plugin_dir ) ) {
		exit( 1 );
	}
}

// Use the Plugin_Upgrader class to install the plugin.
$skin     = new Blueprint_WP_Upgrader_Skin();
$upgrader = new Plugin_Upgrader( $skin );

// If we have a plugin slug from the zip, create the target directory first
$target_directory = null;
if ( ! empty( $plugin_slug ) ) {
	$target_directory = WP_PLUGIN_DIR . '/' . $plugin_slug;

	// Remove existing directory if it exists
	if ( is_dir( $target_directory ) ) {
		$GLOBALS['wp_filesystem']->delete( $target_directory, true );
	}

	// Create the directory
	$GLOBALS['wp_filesystem']->mkdir( $target_directory );

	error_log( "Created target directory: " . $target_directory );
}

// Install the plugin
$result = $upgrader->install( $plugin_zip_path, array(
	'overwrite_package' => true,
	'destination'       => $target_directory,
) );

// Check for filesystem errors
if ( $GLOBALS['wp_filesystem']->errors->has_errors() ) {
	foreach ( $GLOBALS['wp_filesystem']->errors->get_error_messages() as $message ) {
		error_log( "Filesystem error: " . $message );
	}
	exit( 1 );
}

if ( is_wp_error( $result ) ) {
	error_log( "Failed to install plugin (1): " . $result->get_error_message() );
	exit( 1 );
}

if ( $result === false || $result === null ) {
	// Check skin for errors if $result is not specific.
	if ( isset( $skin->result ) && is_wp_error( $skin->result ) ) {
		error_log( "Failed to install plugin (2): " . $skin->result->get_error_message() );
	} else {
		error_log( "Failed to install plugin for an unknown reason." );
	}
	exit( 1 );
}

// Installation successful, find the main plugin file.
$plugin_folder_name = ! empty( $plugin_slug ) ? $plugin_slug : ( $upgrader->result['destination_name'] ?? null );
if ( ! $plugin_folder_name ) {
	error_log( "Could not determine plugin folder name after installation." );
	exit( 1 );
}

// Get all plugins within the newly installed folder.
$plugins_in_folder = get_plugins( '/' . $plugin_folder_name );
if ( empty( $plugins_in_folder ) ) {
	error_log( "Could not find any plugin files in the installed folder: " . $plugin_folder_name );
	exit( 1 );
}
// The key of the first plugin entry is the relative path needed for activation.
reset( $plugins_in_folder );

// The key of the first plugin entry is the relative path needed for activation.
$plugin_file_relative_path = key( $plugins_in_folder );

// Output the relative path of the main plugin file.
$output = $plugin_folder_name . '/' . $plugin_file_relative_path;
if ( function_exists( 'append_output' ) ) {
	append_output( $output );
} else {
	echo $output;
}

exit( 0 );
