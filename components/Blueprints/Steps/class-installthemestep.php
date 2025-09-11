<?php

namespace WordPress\Blueprints\Steps;

use RuntimeException;
use WordPress\Blueprints\DataReference\DataReference;
use WordPress\Blueprints\DataReference\Directory;
use WordPress\Blueprints\DataReference\File;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;
use WordPress\ByteStream\WriteStream\FileWriteStream;
use WordPress\Zip\ZipEncoder;

use function WordPress\Filesystem\pipe_stream;
use function WordPress\Filesystem\wp_join_unix_paths;
use function WordPress\Zip\is_zip_file_stream;

/**
 * Represents the 'installTheme' step.
 */
class InstallThemeStep implements StepInterface {
	/**
	 * Theme source identifier (slug, slug@version, URL, ./path, /path).
	 *
	 * @var DataReference
	 */
	public $source;

	/**
	 * Whether to active the theme after installing it. Defaults to false.
	 *
	 * @var bool
	 */
	public $active;

	/**
	 * Whether to import the theme's starter content after installing it. Defaults to false.
	 *
	 * @var bool
	 */
	public $import_starter_content;

	/**
	 * Optional target folder name. Defaults based on source.
	 *
	 * @var string|null
	 */
	public $target_folder_name;

	/**
	 * @param  DataReference $source  Theme source identifier.
	 * @param  bool          $active  active after install?
	 * @param  bool          $import_starter_content  Import starter content?
	 * @param  string|null   $target_folder_name  Optional target folder name.
	 */
	public function __construct(
		DataReference $source,
		bool $active = false,
		bool $import_starter_content = false,
		?string $target_folder_name = null
	) {
		$this->source                 = $source;
		$this->active                 = $active;
		$this->import_starter_content = $import_starter_content;
		$this->target_folder_name     = $target_folder_name;
	}

	public function run( Runtime $runtime, Tracker $tracker ) {
		$runtime->with_temporary_directory(
			function ( $temp_dir ) use ( $runtime, $tracker ) {
				$theme_data = $runtime->resolve( $this->source );
				$tracker->setCaption( 'Installing theme ' . $theme_data->get_human_readable_name() );

				if ( $theme_data instanceof Directory ) {
						$zip_filename      = $theme_data->dirname . '.zip';
						$zip_absolute_path = wp_join_unix_paths( $temp_dir, $zip_filename );
						$zip_stream        = FileWriteStream::from_path( $zip_absolute_path, 'truncate' );
						$zip_encoder       = new ZipEncoder( $zip_stream );
						$zip_encoder->append_from_filesystem( $theme_data->filesystem );
						$zip_encoder->close();
				} elseif ( $theme_data instanceof File ) {
					$zip_filename      = preg_replace( '/\.(zip|php)$/', '', $theme_data->filename ) . '.zip';
					$zip_absolute_path = wp_join_unix_paths( $temp_dir, $zip_filename );
					$zip_stream        = FileWriteStream::from_path( $zip_absolute_path, 'truncate' );

					if ( is_zip_file_stream( $theme_data->getStream() ) ) {
						pipe_stream( $theme_data->getStream(), $zip_stream );
					} else {
						throw new RuntimeException( 'Theme is not a valid zip file.' );
					}
					$zip_stream->close_writing();
				}

				$tracker->set( 50 );

				// Inline PHP script to avoid reading a static script.php file via
				// file_get_contents() inside the built blueprints.phar file.
				$output = $runtime->eval_php_code_in_subprocess(
					<<<'PHP'
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
PHP
					,
					array( 'THEME_ZIP_PATH' => $zip_absolute_path )
				);

				$theme_folder_name = trim( $output->output_file_content );
				if ( empty( $theme_folder_name ) ) {
					throw new RuntimeException(
						'Theme installation script did not return the theme stylesheet name.'
					);
				}

				if ( $this->active ) {
						$tracker->set( 75, 'Activating theme ' . $theme_folder_name );
						$runtime->eval_php_code_in_subprocess(
							ActivateThemeStep::ACTIVATE_THEME_SCRIPT,
							array( 'THEME_FOLDER_NAME' => $theme_folder_name )
						);
				}

				$tracker->set( 100 );
			},
			''
		);
	}
}
