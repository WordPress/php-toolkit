<?php

namespace WordPress\Zip;

use WordPress\ByteStream\ByteStreamException;
use WordPress\ByteStream\NotEnoughDataException;
use WordPress\ByteStream\ReadStream\ByteReadStream;
use WordPress\ByteStream\ReadStream\InflateReadStream;
use WordPress\ByteStream\ReadStream\LimitedByteReadStream;

class ZipDecoder {

	const COMPRESSION_DEFLATE = 8;
	const COMPRESSION_NONE = 0;

	const STATE_SCAN = 'scan';
	const STATE_FILE_ENTRY = 'file-entry';
	const STATE_CENTRAL_DIRECTORY_ENTRY_READING = 'central-directory-entry-reading';
	const STATE_END_CENTRAL_DIRECTORY_ENTRY_READING = 'end-central-directory-entry-reading';
	const STATE_OBJECT_READY = 'object-ready';
	const STATE_COMPLETE = 'complete';

	private $state = self::STATE_SCAN;
	private $object = null;
	private $byte_reader;

	public function __construct( ByteReadStream $byte_reader ) {
		$this->byte_reader = $byte_reader;
	}

	public function reached_end_of_data(): bool {
		return self::STATE_COMPLETE === $this->state;
	}

	public function next_object(): bool {
		// If we're calling next_object() when an object is ready,
		// it means we want to scan for the next object. Let's clear
		// the state and start scanning again.
		if ( $this->state === self::STATE_OBJECT_READY ) {
			$this->after_record();
		}

		while ( true ) {
			switch ( $this->state ) {
				case self::STATE_SCAN:
					try {
						$this->byte_reader->pull( 4, ByteReadStream::PULL_EXACTLY );
					} catch ( NotEnoughDataException $e ) {
						$this->state = self::STATE_COMPLETE;

						return false;
					}
					$signature = $this->byte_reader->consume( 4 );
					$signature = unpack( 'V', $signature )[1];
					switch ( $signature ) {
						case FileEntry::SIGNATURE:
							$this->state = self::STATE_FILE_ENTRY;
							break;
						case CentralDirectoryEntry::SIGNATURE:
							$this->state = self::STATE_CENTRAL_DIRECTORY_ENTRY_READING;
							break;
						case EndCentralDirectoryEntry::SIGNATURE:
							$this->state = self::STATE_END_CENTRAL_DIRECTORY_ENTRY_READING;
							break;
						default:
							throw new ByteStreamException(
								sprintf( 'Invalid ZIP object signature %d', $signature )
							);
					}
					break;

				case self::STATE_FILE_ENTRY:
					$this->read_file_entry();
					break;

				case self::STATE_CENTRAL_DIRECTORY_ENTRY_READING:
					$this->read_central_directory_entry();
					break;

				case self::STATE_END_CENTRAL_DIRECTORY_ENTRY_READING:
					$this->read_end_central_directory_entry();
					break;

				case self::STATE_OBJECT_READY:
					return true;

				default:
					return false;
			}
		}
	}

	public function get_object() {
		return $this->object;
	}

	public function seek_to_record( $record_offset ) {
		$this->after_record();
		$this->byte_reader->seek( $record_offset );
	}

	private function read_file_entry() {
		$this->byte_reader->pull( FileEntry::HEADER_SIZE, ByteReadStream::PULL_EXACTLY );
		$data          = $this->byte_reader->consume( FileEntry::HEADER_SIZE );
		$header_fields = unpack(
			'vversion/vgeneral_purpose/vcompression_method/vlast_modified_time/vlast_modified_date/Vcrc/Vcompressed_size/Vuncompressed_size/vpath_length/vextra_length',
			$data
		);
		$this->object  = new FileEntry( $header_fields );

		$this->byte_reader->pull( $this->object->path_length, ByteReadStream::PULL_EXACTLY );
		$path               = $this->byte_reader->consume( $this->object->path_length );
		$this->object->path = self::sanitize_path( $path );

		$this->byte_reader->pull( $this->object->extra_length, ByteReadStream::PULL_EXACTLY );
		$extra               = $this->byte_reader->consume( $this->object->extra_length );
		$this->object->extra = $extra;

		$limit_reader = new LimitedByteReadStream(
			$this->byte_reader,
			$this->object->compressed_size
		);

		$is_compressed = $this->object->compression_method === self::COMPRESSION_DEFLATE;
		if ( $is_compressed ) {
			$this->object->body_reader = new InflateReadStream( $limit_reader, ZLIB_ENCODING_RAW );
		} else {
			$this->object->body_reader = $limit_reader;
		}
		$this->state = self::STATE_OBJECT_READY;
	}

	private function read_central_directory_entry() {
		$this->byte_reader->pull( CentralDirectoryEntry::HEADER_SIZE, ByteReadStream::PULL_EXACTLY );
		$data          = $this->byte_reader->consume( CentralDirectoryEntry::HEADER_SIZE );
		$header_fields = unpack(
			'vversion_created/vversion_needed/vgeneral_purpose/vcompression_method/vlast_modified_time/vlast_modified_date/Vcrc/Vcompressed_size/Vuncompressed_size/vpath_length/vextra_length/vfile_comment_length/vdisk_number/vinternal_attributes/Vexternal_attributes/Vfirst_byte_at',
			$data
		);
		$this->object  = new CentralDirectoryEntry( $header_fields );

		$this->byte_reader->pull( $this->object->path_length, ByteReadStream::PULL_EXACTLY );
		$path_bytes         = $this->byte_reader->consume( $this->object->path_length );
		$this->object->path = self::sanitize_path( $path_bytes );

		$this->byte_reader->pull( $this->object->extra_length, ByteReadStream::PULL_EXACTLY );
		$extra_bytes         = $this->byte_reader->consume( $this->object->extra_length );
		$this->object->extra = $extra_bytes;

		$this->byte_reader->pull( $this->object->file_comment_length, ByteReadStream::PULL_EXACTLY );
		$file_comment_bytes        = $this->byte_reader->consume( $this->object->file_comment_length );
		$this->object->file_comment = $file_comment_bytes;
		$this->state               = self::STATE_OBJECT_READY;
	}

	private function read_end_central_directory_entry() {
		$this->byte_reader->pull( EndCentralDirectoryEntry::HEADER_SIZE, ByteReadStream::PULL_EXACTLY );
		$data          = $this->byte_reader->consume( EndCentralDirectoryEntry::HEADER_SIZE );
		$header_fields = unpack(
			'vdisk_number/vcentral_directory_start_disk/vnumber_central_directory_records_on_this_disk/vnumber_central_directory_records/Vcentral_directory_size/Vcentral_directory_offset/vcomment_length',
			$data
		);
		$this->object  = new EndCentralDirectoryEntry(
			$header_fields
		);

		$this->byte_reader->pull( $this->object->comment_length, ByteReadStream::PULL_EXACTLY );
		$comment_bytes         = $this->byte_reader->consume( $this->object->comment_length );
		$this->object->comment = $comment_bytes;
		$this->state           = self::STATE_OBJECT_READY;
	}

	private function after_record() {
		if ( $this->object instanceof FileEntry ) {
			// Skip past the file bytes
			$this->object->body_reader->consume_all();
			$this->object->body_reader->close_reading();
		}
		$this->state  = self::STATE_SCAN;
		$this->object = null;
	}

	/**
	 * Normalizes the parsed path to prevent directory traversal,
	 * a.k.a zip slip attacks.
	 *
	 * In ZIP, paths are arbitrary byte sequences. Nothing prevents
	 * a ZIP file from containing a path such as /etc/passwd or
	 * ../../../../etc/passwd.
	 *
	 * This function normalizes paths found in the ZIP file.
	 *
	 * @TODO: Scrutinize the implementation of this function. Consider
	 *        unicode characters in the path, including ones that are
	 *        just embelishments of the following character. Consider
	 *        the impact of **all** seemingly "invalid" byte sequences,
	 *        e.g. spaces, ASCII control characters, etc. What will the
	 *        OS do when it receives a path containing .{null byte}./etc/passwd?
	 */
	public static function sanitize_path( $path ) {
		// Replace multiple slashes with a single slash.
		$path = preg_replace( '#/+#', '/', $path );
		// Remove all the leading ../ segments.
		$path = preg_replace( '#^(\.\./)+#', '', $path );
		// Remove all the /./ and /../ segments.
		$path = preg_replace( '#/\.\.?/#', '/', $path );

		return $path;
	}
}
