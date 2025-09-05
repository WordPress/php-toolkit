<?php

use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\Filesystem\LocalFilesystem;
use WordPress\Zip\ZipFilesystem;

use function WordPress\Filesystem\copy_between_filesystems;
use function WordPress\Filesystem\wp_join_unix_paths;

class PluginUpdater {
	private $installed_plugin_backup_dir;
	private $wp_plugins_directory;
	private $was_plugin_active;
	private $installed_plugin_dir;
	private $installed_plugin_file;
	private $new_version_extract_to_dir;
	private $package_absolute_path;

	public function __construct( $installed_plugin_file, $package_absolute_path ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$this->wp_plugins_directory = LocalFilesystem::create( WP_PLUGIN_DIR );

		$this->installed_plugin_file = $installed_plugin_file;
		$this->installed_plugin_dir  = dirname( $installed_plugin_file );
		$plugin_slug                 = basename( $this->installed_plugin_dir );

		$this->installed_plugin_backup_dir = $plugin_slug . '_installed_backup_' . time();
		$this->new_version_extract_to_dir  = $plugin_slug . '_new_version_' . time();
		$this->package_absolute_path       = $package_absolute_path;

		if ( ! $this->wp_plugins_directory->exists( $this->installed_plugin_dir ) ) {
			throw new Exception( "Plugin directory not found: $this->installed_plugin_dir" );
		}
	}

	private function unzipPackage() {
		$this->wp_plugins_directory->mkdir( $this->new_version_extract_to_dir );

		$extension = pathinfo( $this->package_absolute_path, PATHINFO_EXTENSION );
		if ( 'zip' === $extension ) {
			$zip_fs = ZipFilesystem::create( FileReadStream::from_path( $this->package_absolute_path ) );
			copy_between_filesystems(
				array(
					'source_filesystem' => $zip_fs,
					'target_filesystem' => $this->wp_plugins_directory,
					'target_path' => $this->new_version_extract_to_dir,
					'recursive' => true,
				)
			);

			$extracted_dirs = $this->wp_plugins_directory->ls( $this->new_version_extract_to_dir );
			$extracted_dirs = array_filter(
				$extracted_dirs,
				function ( $dir ) {
					$basename = basename( $dir );
					return '__MACOSX' !== $basename && '.DS_Store' !== $basename;
				}
			);
			if ( 1 === count( $extracted_dirs ) ) {
				$potential_root_dir = wp_join_unix_paths( $this->new_version_extract_to_dir, $extracted_dirs[0] );
				if ( $this->wp_plugins_directory->is_dir( $potential_root_dir ) ) {
					return $potential_root_dir;
				}
			}

			return $this->new_version_extract_to_dir;
		} elseif ( 'php' === $extension ) {
			$plugin_name = basename( $this->package_absolute_path, '.php' );
			$this->wp_plugins_directory->mkdir( $plugin_name );
			$this->wp_plugins_directory->put_contents(
				"{$plugin_name}/{$plugin_name}.php",
				file_get_contents( $this->package_absolute_path )
			);
			return $plugin_name;
		} else {
			throw new Exception( "Unsupported plugin package extension: $extension" );
		}
	}

	public function upgrade() {
		try {
			$this->was_plugin_active = is_plugin_active( $this->installed_plugin_file );
			if ( $this->was_plugin_active ) {
				deactivate_plugins( $this->installed_plugin_file, true );
			}

			$new_version_root_directory = $this->unzipPackage();

			$this->wp_plugins_directory->rename( $this->installed_plugin_dir, $this->installed_plugin_backup_dir );
			$this->wp_plugins_directory->rename( $new_version_root_directory, $this->installed_plugin_dir );

			if ( $this->was_plugin_active ) {
				$installed_plugin_files = get_plugins( '/' . basename( $this->installed_plugin_dir ) );
				if ( empty( $installed_plugin_files ) ) {
					throw new Exception( 'No valid plugin file found in the new plugin directory' );
				}
				$this->installed_plugin_file = wp_join_unix_paths( $this->installed_plugin_dir, key( $installed_plugin_files ) );
			}

			$this->cleanup();
			return $this->installed_plugin_file;
		} catch ( Exception $e ) {
			$this->cleanup();
			return new WP_Error( 'plugin_upgrade_error', $e->getMessage() );
		}
	}

	private function cleanup() {
		if ( $this->wp_plugins_directory->exists( $this->installed_plugin_backup_dir ) ) {
			$this->wp_plugins_directory->rmdir( $this->installed_plugin_backup_dir, array( 'recursive' => true ) );
		}
		if ( $this->wp_plugins_directory->exists( $this->new_version_extract_to_dir ) ) {
			$this->wp_plugins_directory->rmdir( $this->new_version_extract_to_dir, array( 'recursive' => true ) );
		}
		if ( $this->was_plugin_active ) {
			activate_plugin( $this->installed_plugin_file );
		}
	}
}

function rpi_upgrade_plugin( $installed_plugin_file, $package_path ) {
	$updater = new PluginUpdater( $installed_plugin_file, $package_path );
	return $updater->upgrade();
}
