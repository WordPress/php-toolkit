<?php

namespace WordPress\Zip;

use InvalidArgumentException;

/**
 * Represents a central directory entry in a ZIP file.
 *
 * The central directory entry is structured as follows in the ZIP binary format:
 *
 * Offset Bytes Description
 *   0        4    Central directory file header signature = 0x02014b50
 *   4        2    Version made by
 *   6        2    Version needed to extract (minimum)
 *   8        2    General purpose bit flag
 *   10       2    Compression method
 *   12       2    File last modification time
 *   14       2    File last modification date
 *   16       4    CRC-32 of uncompressed data
 *   20       4    Compressed size (or 0xffffffff for ZIP64)
 *   24       4    Uncompressed size (or 0xffffffff for ZIP64)
 *   28       2    File name length (n)
 *   30       2    Extra field length (m)
 *   32       2    File comment length (k)
 *   34       2    Disk number where file starts (or 0xffff for ZIP64)
 *   36       2    Internal file attributes
 *   38       4    External file attributes
 *   42       4    Relative offset of local file header (or 0xffffffff for ZIP64). This is the number of bytes between the start of the first disk on which the file occurs, and the start of the local file header. This allows software reading the central directory to locate the position of the file inside the ZIP file.
 *   46       n    File name
 *   46+n     m    Extra field
 *   46+n+m   k    File comment
 */
class CentralDirectoryEntry {

	const SIGNATURE = 0x02014b50;

	/**
	 * The size of the ZIP file entry header in bytes.
	 *
	 * @var int
	 */
	const HEADER_SIZE = 42;

	public $first_byte_at;
	public $version_created = 2;
	public $version_needed = 2;
	public $general_purpose = 0;
	public $compression_method = 0;
	public $last_modified_time = 0;
	public $last_modified_date = 0;
	public $crc;
	public $compressed_size;
	public $uncompressed_size;
	public $disk_number = 0;
	public $internal_attributes = 0;
	public $external_attributes = 0;
	public $path_length;
	public $extra_length;
	public $file_comment_length;
	public $path;
	public $extra;
	public $file_comment;

	public function __construct( $header_fields ) {
		$valid_properties = array_keys( get_object_vars( $this ) );
		foreach ( $header_fields as $key => $value ) {
			if ( ! in_array( $key, $valid_properties ) ) {
				throw new InvalidArgumentException( "Invalid property: $key. Expected one of: " . implode( ', ', $valid_properties ) );
			}
			$this->$key = $value;
		}

		if ( null !== $this->path ) {
			$this->path_length = strlen( $this->path );
		}

		if ( null !== $this->extra ) {
			$this->extra_length = strlen( $this->extra );
		}

		if ( null !== $this->file_comment ) {
			$this->file_comment_length = strlen( $this->file_comment );
		}
	}

	public function is_directory() {
		return substr_compare( $this->path, '/', - strlen( '/' ) ) === 0;
	}
}
