<?php

namespace WordPress\Zip;

use WordPress\ByteStream\ByteTransformer\ChecksumTransformer;
use WordPress\ByteStream\ReadStream\DeflateReadStream;
use WordPress\ByteStream\ReadStream\TransformedReadStream;
use WordPress\ByteStream\WriteStream\ByteWriteStream;
use WordPress\Filesystem\Filesystem;

use function WordPress\Filesystem\wp_join_unix_paths;

class ZipEncoder {

	private $output;
	private $central_directory = array();
	private $bytes_written     = 0;

	public function __construct( ByteWriteStream $output ) {
		$this->output = $output;
	}

	public function append_from_filesystem( Filesystem $filesystem, $path = '/' ) {
		foreach ( $filesystem->ls( $path ) as $entry ) {
			$entry_path = wp_join_unix_paths( $path, $entry );
			if ( $filesystem->is_dir( $entry_path ) ) {
				$this->append_from_filesystem( $filesystem, $entry_path );
			} else {
				$this->append_file(
					new FileEntry(
						array(
							'path'        => $entry_path,
							'body_reader' => $filesystem->open_read_stream( $entry_path ),
						)
					)
				);
			}
		}
	}

	/**
	 * Appends a file entry to the zip file.
	 */
	public function append_file( FileEntry $entry ) {
		$this->compute_file_hash_and_size( $entry );
		$this->recordFileForCentralDirectory( $entry );
		$this->append_file_entry_header( $entry );

		try {
			if ( ZipDecoder::COMPRESSION_DEFLATE === $entry->compression_method ) {
				$body_stream = new DeflateReadStream( $entry->body_reader, ZLIB_ENCODING_RAW, 9 );
			} else {
				$body_stream = $entry->body_reader;
			}

			while ( $bytes = $body_stream->pull( 10 ) ) {
				$this->output->append_bytes( $body_stream->consume( $bytes ) );
			}
			$this->bytes_written += $entry->compressed_size;
		} finally {
			if ( ZipDecoder::COMPRESSION_DEFLATE === $entry->compression_method ) {
				$body_stream->close_reading();
			}
		}
	}

	/**
	 * Computes file hash and size for a ZIP archive entry.
	 *
	 * This method calculates the CRC32, uncompressed size, and compressed size
	 * for the given file entry. It handles deflate compression when needed.
	 *
	 * @param  FileEntry $entry The file entry to compute hash and size for.
	 *
	 * @note This function is designed to handle large files without loading them entirely
	 * into memory. It reads and compresses the file in chunks, making it suitable for streaming
	 * large files effectively.
	 */
	private function compute_file_hash_and_size( FileEntry $entry ) {
		// Pass 1: Calculate the CRC32, uncompressed size, and compressed size.
		if ( ZipDecoder::COMPRESSION_DEFLATE === $entry->compression_method ) {
			$reader = new DeflateReadStream( $entry->body_reader, ZLIB_ENCODING_RAW, 9 );
		} else {
			$reader = $entry->body_reader;
		}
		$stream = new TransformedReadStream(
			$reader,
			array(
				'checksum' => new ChecksumTransformer( 'crc32b' ),
			)
		);

		while ( true ) {
			$n = $stream->pull( 10 );
			if ( 0 === $n ) {
				break;
			}
			$stream->consume( $n );
		}

		if ( ZipDecoder::COMPRESSION_DEFLATE === $entry->compression_method ) {
			$reader->close_reading();
		}

		$entry->compressed_size   = $reader->tell();
		$entry->uncompressed_size = $entry->body_reader->length();
		$entry->crc               = hexdec( $stream['checksum']->get_hash() );

		// Reset the reader to the beginning of the file.
		$entry->body_reader->seek( 0 );
	}


	private function recordFileForCentralDirectory( FileEntry $file_entry ) {
		$this->central_directory[] = new CentralDirectoryEntry(
			array(
				'version_created'     => 2,
				'version_needed'      => 2,
				'general_purpose'     => $file_entry->general_purpose,
				'compression_method'  => $file_entry->compression_method,
				'last_modified_time'   => $file_entry->last_modified_time,
				'last_modified_date'   => $file_entry->last_modified_date,
				'crc'                => $file_entry->crc,
				'compressed_size'     => $file_entry->compressed_size,
				'uncompressed_size'   => $file_entry->uncompressed_size,
				'disk_number'         => 0,
				'internal_attributes' => 0,
				'external_attributes' => 0,
				'first_byte_at'        => $this->bytes_written,
				'path'               => $file_entry->path,
				'extra'              => $file_entry->extra,
				'file_comment'        => '',
			)
		);
	}

	public function close() {
		$this->flushCentralDirectory();
	}

	/**
	 * Writes the central directory and its end record to the ZIP archive stream.
	 *
	 * This method writes all the central directory entries stored and then writes
	 * the end of central directory record, finalizing the ZIP archive structure.
	 */
	private function flushCentralDirectory() {
		$central_directory_offset = $this->bytes_written;

		// Write all central directory entries.
		foreach ( $this->central_directory as $entry ) {
			$this->append_central_directory_entry( $entry );
		}

		$this->append_end_central_directory_entry(
			new EndCentralDirectoryEntry(
				array(
					'number_central_directory_records_on_this_disk' => count( $this->central_directory ),
					'number_central_directory_records'           => count( $this->central_directory ),
					'central_directory_size'                    => $this->bytes_written - $central_directory_offset,
					'central_directory_offset'                  => $central_directory_offset,
				)
			)
		);
	}

	private function append_file_entry_header( FileEntry $entry ) {
		$header = pack(
			'VvvvvvVVVvv',
			FileEntry::SIGNATURE,
			$entry->version,
			$entry->general_purpose,
			$entry->compression_method,
			$entry->last_modified_time,
			$entry->last_modified_date,
			$entry->crc,
			$entry->compressed_size,
			$entry->uncompressed_size,
			$entry->path_length,
			$entry->extra_length
		) . $entry->path . $entry->extra;

		$this->output->append_bytes( $header );
		$this->bytes_written += strlen( $header );
	}

	/**
	 * Appends a central directory entry to the zip file.
	 *
	 * @param  CentralDirectoryEntry $entry
	 */
	protected function append_central_directory_entry( CentralDirectoryEntry $entry ) {
		$object = pack(
			'VvvvvvvVVVvvvvvVV',
			CentralDirectoryEntry::SIGNATURE,
			$entry->version_created,
			$entry->version_needed,
			$entry->general_purpose,
			$entry->compression_method,
			$entry->last_modified_time,
			$entry->last_modified_date,
			$entry->crc,
			$entry->compressed_size,
			$entry->uncompressed_size,
			$entry->path_length,
			$entry->extra_length,
			$entry->file_comment_length,
			$entry->disk_number,
			$entry->internal_attributes,
			$entry->external_attributes,
			$entry->first_byte_at
		) . $entry->path . $entry->extra . $entry->file_comment;

		$this->output->append_bytes( $object );
		$this->bytes_written += strlen( $object );
	}

	/**
	 * Writes the end of central directory entry to a zip file.
	 */
	protected function append_end_central_directory_entry( EndCentralDirectoryEntry $entry ) {
		$object = pack(
			'VvvvvVVv',
			EndCentralDirectoryEntry::SIGNATURE,
			$entry->disk_number,
			$entry->central_directory_start_disk,
			$entry->number_central_directory_records_on_this_disk,
			$entry->number_central_directory_records,
			$entry->central_directory_size,
			$entry->central_directory_offset,
			$entry->comment_length
		) . $entry->comment;

		$this->output->append_bytes( $object );
		$this->bytes_written += strlen( $object );
	}
}
