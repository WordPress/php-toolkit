<?php

namespace WordPress\Zip;

use InvalidArgumentException;
use WordPress\ByteStream\ReadStream\ByteReadStream;

/**
 * Represents a file entry in a ZIP file.
 *
 * The file entry is structured as follows in the ZIP binary format:
 *
 * Offset    Bytes    Description
 *   0        4    Local file header signature = 0x04034b50 (PK♥♦ or "PK\3\4")
 *   4        2    Version needed to extract (minimum)
 *   6        2    General purpose bit flag
 *   8        2    Compression method; e.g. none = 0, DEFLATE = 8 (or "\0x08\0x00")
 *   10       2    File last modification time
 *   12       2    File last modification date
 *   14       4    CRC-32 of uncompressed data
 *   18       4    Compressed size (or 0xffffffff for ZIP64)
 *   22       4    Uncompressed size (or 0xffffffff for ZIP64)
 *   26       2    File name length (n)
 *   28       2    Extra field length (m)
 *   30       n    File name
 *   30+n     m    Extra field
 *
 * @param  resource  $stream
 */
class FileEntry {

	const SIGNATURE = 0x04034b50;

	/**
	 * The size of the ZIP file entry header in bytes.
	 *
	 * @var int
	 */
	const HEADER_SIZE = 26;

	/**
	 * @var int
	 */
	public $version = 2;

	/**
	 * @var int
	 */
	public $general_purpose = 0;

	/**
	 * @var int
	 */
	public $compression_method;

	/**
	 * @var int
	 */
	public $last_modified_time;

	/**
	 * @var int
	 */
	public $last_modified_date;

	/**
	 * @var int
	 */
	public $crc;

	/**
	 * @var int
	 */
	public $compressed_size;

	/**
	 * @var int
	 */
	public $uncompressed_size;

	/**
	 * @var int
	 */
	public $path_length = 0;

	/**
	 * @var int
	 */
	public $extra_length = 0;

	/**
	 * @var string
	 */
	public $path;

	/**
	 * @var string
	 */
	public $extra;

	/**
	 * @var ByteReadStream
	 */
	public $body_reader;

	public function __construct(
		array $header_fields
	) {
		$valid_properties = array_keys( get_object_vars( $this ) );
		foreach ( $header_fields as $key => $value ) {
			if ( ! in_array( $key, $valid_properties ) ) {
				throw new InvalidArgumentException( "Invalid property: $key. Expected one of: " . implode( ', ', $valid_properties ) );
			}
			$this->$key = $value;
		}

		// Convert Unix timestamp to DOS date/time format
		if ( null === $this->last_modified_date ) {
			// DOS date format: bits 0-4: day, bits 5-8: month, bits 9-15: years since 1980
			$dt                     = getdate( $this->last_modified_time );
			$this->last_modified_date = ( ( $dt['year'] - 1980 ) << 9 ) |
			                          ( $dt['mon'] << 5 ) |
			                          $dt['mday'];
		}

		if ( null === $this->last_modified_time ) {
			// DOS time format: bits 0-4: seconds/2, bits 5-10: minutes, bits 11-15: hours
			$dt                     = getdate( $this->last_modified_time );
			$this->last_modified_time = ( $dt['hours'] << 11 ) |
			                          ( $dt['minutes'] << 5 ) |
			                          ( floor( $dt['seconds'] / 2 ) );
		}

		if ( null !== $this->path ) {
			$this->path_length = strlen( $this->path );
		}

		if ( null !== $this->extra ) {
			$this->extra_length = strlen( $this->extra );
		}
	}
}
