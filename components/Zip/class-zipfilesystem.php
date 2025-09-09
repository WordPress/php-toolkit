<?php

namespace WordPress\Zip;

use WordPress\ByteStream\ReadStream\ByteReadStream;
use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\FilesystemException;
use WordPress\Filesystem\Layer\ChrootLayer;
use WordPress\Filesystem\Mixin\GetContentsViaReadStream;
use WordPress\Filesystem\Mixin\ReadOnlyFilesystem;

class ZipFilesystem implements Filesystem {

	use ReadOnlyFilesystem;
	use GetContentsViaReadStream;

	private $zip;
	private $byte_reader;

	private $central_directory;
	private $central_directory_end_header;

	private $last_file_stream;

	const TYPE_DIR  = 'dir';
	const TYPE_FILE = 'file';

	const CENTRAL_DIRECTORY_INDEX = 'central-directory-index';
	const FILE_ENTRY              = 'file-entry';

	/**
	 * Don't support ZIP files with more than 2MB of central directory data.
	 *
	 * This is an arbitrary limitation. This reader is buffering the entire
	 * central directory in memory and we need to be mindful of the available
	 * resources. For those huge ZIP files where the central directory alone
	 * is megabytes large, we need a more complex, streaming reader.
	 */
	const MAX_CENTRAL_DIRECTORY_SIZE = 2 * 1024 * 1024;

	public static function create( ByteReadStream $byte_reader ) {
		return new ChrootLayer(
			new ZipFilesystem( $byte_reader ),
			'/'
		);
	}

	private function __construct( ByteReadStream $byte_reader ) {
		$this->zip         = new ZipDecoder( $byte_reader );
		$this->byte_reader = $byte_reader;
	}

	public function ls( $dir = '/' ) {
		$this->load_central_directory();
		$descendants = $this->central_directory;

		// Only keep the descendants of the given parent.
		$dir    = trim( $dir, '/' );
		$prefix = $dir ? $dir . '/' : '';
		if ( strlen( $prefix ) > 1 ) {
			$filtered_descendants = array();
			foreach ( $descendants as $entry ) {
				$path = $entry->path;
				if ( 0 !== strpos( $path, $prefix ) ) {
					continue;
				}
				$filtered_descendants[] = $entry;
			}
			$descendants = $filtered_descendants;
		}

		// Only keep the direct children of the parent.
		$children = array();
		foreach ( $descendants as $entry ) {
			$suffix = rtrim( substr( $entry->path, strlen( $prefix ) ), '/' );
			if ( false !== strpos( $suffix, '/' ) ) {
				continue;
			}
			// No need to include the directory itself.
			if ( 0 === strlen( $suffix ) ) {
				continue;
			}
			$children[] = $suffix;
		}

		return $children;
	}

	public function is_dir( $path ) {
		if ( '/' === $path ) {
			return true;
		}
		$this->load_central_directory();
		$path = trim( $path, '/' ) . '/';

		return isset( $this->central_directory[ $path ] ) && $this->central_directory[ $path ]->is_directory();
	}

	public function is_file( $path ) {
		$this->load_central_directory();
		$path = trim( $path, '/' );

		return isset( $this->central_directory[ $path ] ) && ! $this->central_directory[ $path ]->is_directory();
	}

	public function exists( $path ) {
		return $this->is_file( $path ) || $this->is_dir( $path );
	}

	public function open_read_stream( $path ): ByteReadStream {
		if ( null !== $this->last_file_stream && ! $this->last_file_stream->reached_end_of_data() ) {
			throw new FilesystemException(
				'ZipFilesystem cannot open a read stream while another read stream is open'
			);
		}
		$this->load_central_directory();
		$path = trim( $path, '/' );
		if ( ! isset( $this->central_directory[ $path ] ) ) {
			throw new FilesystemException(
				sprintf( 'File %s not found', $path )
			);
		}
		if ( $this->central_directory[ $path ]->is_directory() ) {
			throw new FilesystemException(
				sprintf( 'Path %s is not a file', $path )
			);
		}
		$this->zip->seek_to_record( $this->central_directory[ $path ]->first_byte_at );
		if ( ! $this->zip->next_object() ) {
			throw new FilesystemException(
				sprintf( 'Failed to open file %s', $path )
			);
		}
		$this->last_file_stream = $this->zip->get_object()->body_reader;

		return $this->last_file_stream;
	}

	private function load_central_directory() {
		if ( null !== $this->central_directory ) {
			return true;
		}

		if ( $this->central_directory_size() >= self::MAX_CENTRAL_DIRECTORY_SIZE ) {
			throw new FilesystemException(
				sprintf(
					'Central directory size %d exceeds the maximum allowed size of %d',
					$this->central_directory_size(),
					self::MAX_CENTRAL_DIRECTORY_SIZE
				)
			);
		}

		// Read the central directory into memory.
		$this->seek_to_central_directory_index();

		$central_directory = array();
		while ( $this->zip->next_object() ) {
			$object = $this->zip->get_object();
			if ( ! ( $object instanceof CentralDirectoryEntry ) ) {
				continue;
			}
			$central_directory[ $object->path ] = $object;
		}

		// Transform the central directory into a tree structure with.
		// directories and files.
		foreach ( $central_directory as $entry ) {
			/**
			 * Directory are sometimes indicated by a path
			 * ending with a right trailing slash. Let's remove it
			 * to avoid an empty entry at the end of $path_segments.
			 */
			$path_segments = explode( '/', $entry->path );

			for ( $i = 0; $i < count( $path_segments ) - 1; $i++ ) {
				$path_so_far                             = implode( '/', array_slice( $path_segments, 0, $i + 1 ) ) . '/';
				$this->central_directory[ $path_so_far ] = new CentralDirectoryEntry(
					array(
						'path' => $path_so_far,
					)
				);
			}
			/**
			 * Only create a file entry if it's not a directory.
			 */
			if ( 0 !== substr_compare( $entry->path, '/', - strlen( '/' ) ) ) {
				$this->central_directory[ $entry->path ] = $entry;
			}
		}

		return true;
	}

	private function central_directory_size() {
		$this->collect_central_directory_end_header();

		return $this->central_directory_end_header->central_directory_size;
	}

	private function seek_to_central_directory_index() {
		$this->collect_central_directory_end_header();

		return $this->zip->seek_to_record( $this->central_directory_end_header->central_directory_offset );
	}

	private function collect_central_directory_end_header() {
		if ( null !== $this->central_directory_end_header ) {
			return;
		}

		$length = $this->byte_reader->length();
		$this->zip->seek_to_record( $length - 22 );
		if ( ! $this->zip->next_object() ) {
			throw new FilesystemException(
				'Failed to read the end central directory index at the end of the ZIP file'
			);
		}
		if ( ! ( $this->zip->get_object() instanceof EndCentralDirectoryEntry ) ) {
			throw new FilesystemException(
				sprintf(
					'Expected end central directory index at the end of the ZIP file but found %s',
					get_class( $this->zip->get_object() )
				)
			);
		}

		$this->central_directory_end_header = $this->zip->get_object();
	}

	public function get_meta(): array {
		return array();
	}
}
