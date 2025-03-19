<?php

namespace WordPress\Filesystem;

use WordPress\ByteStream\ReadStream\ByteReadStream;
use WordPress\ByteStream\WriteStream\ByteWriteStream;

use function WordPress\Filesystem\wp_canonicalize_path;
use function WordPress\Filesystem\wp_join_paths;

/**
 * A filesystem that overlays multiple filesystems at different mount points.
 * Unmounted paths are handled by an in-memory filesystem.
 */
class OverlayFilesystem implements Filesystem {

	/**
	 * @var array<string,Filesystem>
	 */
	private $mounts = array();

	/**
	 * @var InMemoryFilesystem
	 */
	private $memory_fs;

	/**
	 * @param array<string,Filesystem> $mounts Map of paths to filesystem instances
	 */
	public function __construct( array $mounts = array() ) {
		$this->memory_fs = InMemoryFilesystem::create();
		foreach ( $mounts as $path => $fs ) {
			$this->mount( $path, $fs );
		}
	}

	/**
	 * Mount a filesystem at the given path
	 *
	 * @param string     $path Mount point path
	 * @param Filesystem $fs Filesystem to mount
	 */
	public function mount( string $path, Filesystem $fs ) {
		$path                  = rtrim( wp_canonicalize_path( $path ), '/' );
		$this->mounts[ $path ] = $fs;
		if ( ! $this->memory_fs->exists( $path ) ) {
			$this->memory_fs->mkdir( $path, array( 'recursive' => true ) );
		}
	}

	/**
	 * Find the appropriate filesystem for a path
	 *
	 * @param string $path Path to look up
	 * @return array{string, Filesystem} Mount point and filesystem
	 */
	private function get_fs_for_path( string $path ): array {
		$path          = wp_canonicalize_path( $path );
		$longest_match = '';
		$matched_fs    = $this->memory_fs;

		foreach ( $this->mounts as $mount_point => $fs ) {
			if ( strpos( $path, $mount_point ) === 0 && strlen( $mount_point ) > strlen( $longest_match ) ) {
				$longest_match = $mount_point;
				$matched_fs    = $fs;
			}
		}

		return array( $longest_match, $matched_fs );
	}

	public function exists( $path ) {
		list($mount_point, $fs) = $this->get_fs_for_path( $path );
		$relative_path          = substr( $path, strlen( $mount_point ) );
		return $fs->exists( $relative_path );
	}

	public function is_file( $path ) {
		list($mount_point, $fs) = $this->get_fs_for_path( $path );
		$relative_path          = substr( $path, strlen( $mount_point ) );
		return $fs->is_file( $relative_path );
	}

	public function is_dir( $path ) {
		list($mount_point, $fs) = $this->get_fs_for_path( $path );
		$relative_path          = substr( $path, strlen( $mount_point ) );
		return $fs->is_dir( $relative_path );
	}

	public function mkdir( $path, $options = array() ) {
		list($mount_point, $fs) = $this->get_fs_for_path( $path );
		$relative_path          = substr( $path, strlen( $mount_point ) );
		return $fs->mkdir( $relative_path, $options );
	}

	public function rm( $path, $options = array() ) {
		list($mount_point, $fs) = $this->get_fs_for_path( $path );
		$relative_path          = substr( $path, strlen( $mount_point ) );
		return $fs->rm( $relative_path, $options );
	}

	public function rmdir( $path, $options = array() ) {
		list($mount_point, $fs) = $this->get_fs_for_path( $path );
		$relative_path          = substr( $path, strlen( $mount_point ) );
		return $fs->rmdir( $relative_path, $options );
	}

	public function ls( $path = '/' ) {
		list($mount_point, $fs) = $this->get_fs_for_path( $path );
		$relative_path          = substr( $path, strlen( $mount_point ) );
		return $fs->ls( $relative_path );
	}

	public function open_read_stream( $path ): ByteReadStream {
		list($mount_point, $fs) = $this->get_fs_for_path( $path );
		$relative_path          = substr( $path, strlen( $mount_point ) );
		return $fs->open_read_stream( $relative_path );
	}

	public function open_write_stream( $path ): ByteWriteStream {
		list($mount_point, $fs) = $this->get_fs_for_path( $path );
		$relative_path          = substr( $path, strlen( $mount_point ) );
		return $fs->open_write_stream( $relative_path );
	}

	public function copy( $source, $destination, $options = array() ) {
		list($source_mount, $source_fs) = $this->get_fs_for_path( $source );
		list($dest_mount, $dest_fs)     = $this->get_fs_for_path( $destination );

		if ( $source_fs === $dest_fs ) {
			// Same filesystem, do direct copy
			$relative_source = substr( $source, strlen( $source_mount ) );
			$relative_dest   = substr( $destination, strlen( $dest_mount ) );
			return $source_fs->copy( $relative_source, $relative_dest, $options );
		}

		// Different filesystems, copy via streams
		$contents = $this->get_contents( $source );
		return $this->put_contents( $destination, $contents, $options );
	}

	public function rename( $source, $destination, $options = array() ) {
		list($source_mount, $source_fs) = $this->get_fs_for_path( $source );
		list($dest_mount, $dest_fs)     = $this->get_fs_for_path( $destination );

		if ( $source_fs === $dest_fs ) {
			// Same filesystem, do direct rename
			$relative_source = substr( $source, strlen( $source_mount ) );
			$relative_dest   = substr( $destination, strlen( $dest_mount ) );
			return $source_fs->rename( $relative_source, $relative_dest, $options );
		}

		// Different filesystems, copy and delete
		$this->copy( $source, $destination, $options );
		return $this->rm( $source, $options );
	}

	public function get_contents( $path ) {
		list($mount_point, $fs) = $this->get_fs_for_path( $path );
		$relative_path          = substr( $path, strlen( $mount_point ) );
		return $fs->get_contents( $relative_path );
	}

	public function put_contents( $path, $contents, $options = array() ) {
		list($mount_point, $fs) = $this->get_fs_for_path( $path );
		$relative_path          = substr( $path, strlen( $mount_point ) );
		return $fs->put_contents( $relative_path, $contents, $options );
	}
}
