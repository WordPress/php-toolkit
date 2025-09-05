<?php

namespace WordPress\Zip;

use InvalidArgumentException;

/**
 * Represents the end of central directory entry in a ZIP file.
 *
 * The end of central directory entry is structured as follows in the ZIP binary format:
 *
 * Offset    Bytes    Description[33]
 *   0         4        End of central directory signature = 0x06054b50
 *   4         2        Number of this disk (or 0xffff for ZIP64)
 *   6         2        Disk where central directory starts (or 0xffff for ZIP64)
 *   8         2        Number of central directory records on this disk (or 0xffff for ZIP64)
 *   10        2        Total number of central directory records (or 0xffff for ZIP64)
 *   12        4        Size of central directory (bytes) (or 0xffffffff for ZIP64)
 *   16        4        Offset of start of central directory, relative to start of archive (or 0xffffffff for ZIP64)
 *   20        2        Comment length (n)
 *   22        n        Comment
 */
class EndCentralDirectoryEntry {

	const SIGNATURE = 0x06054b50;

	/**
	 * The size of the ZIP file entry header in bytes.
	 *
	 * @var int
	 */
	const HEADER_SIZE = 18;

	/**
	 * @var int
	 */
	public $disk_number = 0;

	/**
	 * @var int
	 */
	public $central_directory_start_disk = 0;

	/**
	 * @var int
	 */
	public $number_central_directory_records_on_this_disk;

	/**
	 * @var int
	 */
	public $number_central_directory_records;

	/**
	 * @var int
	 */
	public $central_directory_size;

	/**
	 * @var int
	 */
	public $central_directory_offset;

	/**
	 * @var int
	 */
	public $comment_length = 0;

	/**
	 * @var string
	 */
	public $comment;

	public function __construct( $header_fields ) {
		$valid_properties = array_keys( get_object_vars( $this ) );
		foreach ( $header_fields as $key => $value ) {
			if ( ! in_array( $key, $valid_properties ) ) {
				throw new InvalidArgumentException( "Invalid property: $key. Expected one of: " . implode( ', ', $valid_properties ) );
			}
			$this->$key = $value;
		}
	}
}
