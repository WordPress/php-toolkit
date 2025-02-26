<?php

namespace WordPress\Filesystem;

use WordPress\ByteStream\ReadStream\ByteReadStream;
use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\ByteStream\WriteStream\ByteWriteStream;
use WordPress\ByteStream\WriteStream\FileWriteStream;
use WordPress\Filesystem\Layer\ChrootLayer;

/**
 * Represents the currently available filesystem.
 */
class LocalFilesystem implements Filesystem {

	use Mixin\PutContentsViaWriteStream;
	use Mixin\GetContentsViaReadStream;
	use Mixin\RmdirRecursive;
	use Mixin\MkdirRecursive;
	use Mixin\CopyDirectoryRecursive;

	private $root;

	public static function create( $root = '/' ) {
		if ( ! is_dir( $root ) ) {
			if ( false === mkdir( $root, 0755, true ) ) {
				throw new FilesystemException( sprintf( 'Root directory did not exist and could not be created: %s', $root ) );
			}
		}
		return new ChrootLayer(
			new LocalFilesystem( $root ),
			$root
		);
	}

	/**
	 * Use LocalFilesystem::create() to ensure the correct filesystem layers are applied.
	 */
	private function __construct( $root ) {
		$this->root = $root;
	}

	/**
	 * for mkdir_recursive()
	 */
	public function get_root(): string {
		return $this->root;
	}

	public function ls( $path = '/' ) {
		$dh = opendir( $path );
		if ( false === $dh ) {
			throw new FilesystemException(
				sprintf( 'Failed to open directory: %s', $path )
			);
		}

		$children = array();
		while ( true ) {
			$filename = readdir( $dh );
			if ( $filename === false ) {
				break;
			}
			if ( '.' === $filename || '..' === $filename ) {
				continue;
			}
			$children[] = $filename;
		}
		closedir( $dh );

		return $children;
	}

	public function is_dir( $path ) {
		return is_dir( $path );
	}

	public function is_file( $path ) {
		return is_file( $path );
	}

	public function exists( $path ) {
		return file_exists( $path );
	}

	public function rename( $old_path, $new_path, $options = array() ) {
		return rename(
			$old_path,
			$new_path
		);
	}

	public function copy_file( $from_path, $to_path, $options ) {
		if ( false === copy( $from_path, $to_path ) ) {
			throw new FilesystemException(
				sprintf( 'Failed to copy file: %s to %s', $from_path, $to_path )
			);
		}
	}

	protected function mkdir_single( $path, $options = array() ) {
		if ( $this->exists( $path ) ) {
			throw new FilesystemException(
				sprintf( 'Path already exists: %s', $path )
			);
		}
		if ( false === mkdir( $path ) ) {
			throw new FilesystemException(
				sprintf( 'Failed to create directory: %s', $path )
			);
		}
	}

	public function rm( $path ) {
		if ( false === unlink( $path ) ) {
			throw new FilesystemException(
				sprintf( 'Failed to remove file: %s', $path )
			);
		}
	}

	protected function rmdir_single( $path, $options = array() ) {
		if ( false === rmdir( $path ) ) {
			throw new FilesystemException(
				sprintf( 'Failed to remove directory: %s', $path )
			);
		}
	}

	public function put_contents( $path, $data, $options = array() ) {
		if ( false === file_put_contents(
			$path,
			$data
		) ) {
			throw new FilesystemException(
				sprintf( 'Failed to write to file: %s', $path )
			);
		}
	}

	public function open_write_stream( $path ): ByteWriteStream {
		return FileWriteStream::from_path( $path, 'truncate' );
	}

	public function open_read_stream( $path ): ByteReadStream {
		return FileReadStream::from_path( $path );
	}
}
