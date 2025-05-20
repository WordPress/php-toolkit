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
	 * @var DataReference
	 */
	public $source;

	/**
	 * Whether to active the theme after installing it. Defaults to false.
	 * @var bool
	 */
	public $active;

	/**
	 * Whether to import the theme's starter content after installing it. Defaults to false.
	 * @var bool
	 */
	public $importStarterContent;

	/**
	 * Optional target folder name. Defaults based on source.
	 * @var string|null
	 */
	public $targetFolderName;

	/**
	 * @param  DataReference  $source  Theme source identifier.
	 * @param  bool  $active  active after install?
	 * @param  bool  $importStarterContent  Import starter content?
	 * @param  string|null  $targetFolderName  Optional target folder name.
	 */
	public function __construct(
		DataReference $source,
		bool $active = false,
		bool $importStarterContent = false,
		?string $targetFolderName = null
	) {
		$this->source               = $source;
		$this->active               = $active;
		$this->importStarterContent = $importStarterContent;
		$this->targetFolderName     = $targetFolderName;
	}

	public function run( Runtime $runtime, Tracker $tracker ) {
		$runtime->withTemporaryDirectory( function ( $temp_dir ) use ( $runtime, $tracker ) {
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
					throw new RuntimeException( "Theme is not a valid zip file." );
				}
				$zip_stream->close_writing();
			}

			$tracker->set( 50 );

			$output = $runtime->evalPhpFileInSubProcess(
				wp_join_unix_paths( __DIR__, 'scripts/InstallTheme/wp_install_theme.php' ),
				[ 'THEME_ZIP_PATH' => $zip_absolute_path ]
			);

			$theme_folder_name = trim( $output->outputFileContent );
			if ( empty( $theme_folder_name ) ) {
				throw new RuntimeException(
					"Theme installation script did not return the theme stylesheet name."
				);
			}

			if ( $this->active ) {
				$tracker->set( 75, 'Activating theme ' . $theme_folder_name );
				$runtime->evalPhpFileInSubProcess(
					wp_join_unix_paths( __DIR__, 'scripts/ActivateTheme/wp_activate_theme.php' ),
					[ 'THEME_FOLDER_NAME' => $theme_folder_name ]
				);
			}

			$tracker->set( 100 );
		}, '' );
	}
}
