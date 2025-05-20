<?php

namespace WordPress\Blueprints\Steps;

use WordPress\Blueprints\DataReference\DataReference;
use WordPress\Blueprints\DataReference\Directory;
use WordPress\Blueprints\DataReference\File;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;
use WordPress\ByteStream\WriteStream\FileWriteStream;
use WordPress\Zip\FileEntry;
use WordPress\Zip\ZipDecoder;
use WordPress\Zip\ZipEncoder;

use function WordPress\Filesystem\pipe_stream;
use function WordPress\Filesystem\wp_join_unix_paths;
use function WordPress\Zip\is_zip_file_stream;

class InstallPluginStep implements StepInterface {
	/**
	 * Plugin source reference.
	 * @var DataReference
	 */
	public $source;

	/**
	 * Whether to activate the plugin after installation. Defaults to true.
	 * @var bool
	 */
	public $active;

	/**
	 * Optional key-value pairs passed to the plugin during activation.
	 * @var array<string, mixed>|null
	 */
	public $activationOptions;

	/**
	 * Behavior on installation error. Defaults to THROW_ERROR.
	 * @var string
	 */
	public $onError;

	/**
	 * @param  DataReference  $source  Plugin source reference.
	 * @param  bool  $active  Activate after install?
	 * @param  array<string, mixed>|null  $activationOptions  Optional activation data.
	 * @param  string  $onError  Error handling behavior.
	 */
	public function __construct(
		DataReference $source,
		bool $active = true,
		?array $activationOptions = null,
		string $onError = 'throw'
	) {
		$this->source            = $source;
		$this->active            = $active;
		$this->activationOptions = $activationOptions;
		$this->onError           = $onError;
	}

	public function run( Runtime $runtime, Tracker $tracker ) {
		$plugin_data = $runtime->resolve( $this->source );

		$runtime->withTemporaryDirectory( function ( $temp_dir ) use ( $runtime, $tracker, $plugin_data ) {
			$tracker->setCaption( 'Installing plugin ' . $plugin_data->get_human_readable_name() );
			if ( $plugin_data instanceof Directory ) {
				$zip_filename      = $plugin_data->dirname . '.zip';
				$zip_absolute_path = wp_join_unix_paths( $temp_dir, $zip_filename );
				$zip_stream        = FileWriteStream::from_path( $zip_absolute_path, 'truncate' );
				$zip_encoder       = new ZipEncoder( $zip_stream );
				$zip_encoder->append_from_filesystem( $plugin_data->filesystem );
				$zip_encoder->close();
			} elseif ( $plugin_data instanceof File ) {
				$zip_filename      = preg_replace( '/\.(zip|php)$/', '', $plugin_data->filename ) . '.zip';
				$zip_absolute_path = wp_join_unix_paths( $temp_dir, $zip_filename );
				$zip_stream        = FileWriteStream::from_path( $zip_absolute_path, 'truncate' );

				if ( is_zip_file_stream( $plugin_data->getStream() ) ) {
					pipe_stream( $plugin_data->getStream(), $zip_stream );
				} else {
					$zip_encoder = new ZipEncoder( $zip_stream );
					$zip_encoder->append_file( new FileEntry( [
						'path'              => $plugin_data->filename,
						'body_reader'       => $plugin_data->getStream(),
						'compressionMethod' => ZipDecoder::COMPRESSION_DEFLATE,
					] ) );
					$zip_encoder->close();
				}
				$plugin_data->getStream()->close_reading();
			}
			$zip_stream->close_writing();

			$tracker->set( 50 );
			$relative_path = $runtime->evalPhpFileInSubProcess(
				wp_join_unix_paths( __DIR__, 'scripts/InstallPlugin/wp_install_plugin.php' ),
				[ 'PLUGIN_ZIP_PATH' => $zip_absolute_path ]
			)->outputFileContent;

			if ( $this->active ) {
				$tracker->set( 75, 'Activating plugin ' . $plugin_data->get_human_readable_name() );
				$runtime->evalPhpFileInSubProcess(
					wp_join_unix_paths( __DIR__, 'scripts/ActivatePlugin/wp_activate_plugin.php' ),
					[ 'PLUGIN_PATH' => $relative_path ]
				);
			}

			$tracker->set( 100 );
		}, '' );
	}
}
