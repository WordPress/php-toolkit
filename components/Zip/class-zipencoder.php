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
	private $centralDirectory = array();
	private $bytes_written    = 0;

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
			if ( $entry->compressionMethod === ZipDecoder::COMPRESSION_DEFLATE ) {
				$body_stream = new DeflateReadStream( $entry->body_reader, ZLIB_ENCODING_RAW, 9 );
			} else {
				$body_stream = $entry->body_reader;
			}

			while ( $bytes = $body_stream->pull( 10 ) ) {
				$this->output->append_bytes( $body_stream->consume( $bytes ) );
			}
			$this->bytes_written += $entry->compressedSize;
		} finally {
			if ( $entry->compressionMethod === ZipDecoder::COMPRESSION_DEFLATE ) {
				$body_stream->close_reading();
			}
		}
	}

	/**
	 * Streams a file from disk and writes it into a ZIP archive.
	 *
	 * This method reads the source file from the given path, computes necessary
	 * metadata (CRC32 checksum, uncompressed size, and compressed size using Deflate),
	 * and then writes the appropriate file entry header and data into the ZIP archive
	 * stream. The file data is read and compressed in two passes: first to compute
	 * the CRC32 and sizes, and second to write the actual compressed data.
	 *
	 * @param  string $sourcePathOnDisk  The filesystem path to the source file to be included in the ZIP archive.
	 * @param  string $targetPathInZip  The desired path (including filename) of the file within the ZIP archive.
	 *
	 * @return number The number of bytes written to the ZIP archive stream.
	 *
	 * @note This function is designed to handle large files without loading them entirely
	 * into memory. It reads and compresses the file in chunks, making it suitable for streaming
	 * large files effectively.
	 */
	private function compute_file_hash_and_size( FileEntry $entry ) {
		// Pass 1: Calculate the CRC32, uncompressed size, and compressed size
		if ( $entry->compressionMethod === ZipDecoder::COMPRESSION_DEFLATE ) {
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
			if ( $n === 0 ) {
				break;
			}
			$stream->consume( $n );
		}

		if ( $entry->compressionMethod === ZipDecoder::COMPRESSION_DEFLATE ) {
			$reader->close_reading();
		}

		$entry->compressedSize   = $reader->tell();
		$entry->uncompressedSize = $entry->body_reader->length();
		$entry->crc              = hexdec( $stream['checksum']->get_hash() );

		// Reset the reader to the beginning of the file
		$entry->body_reader->seek( 0 );
	}


	private function recordFileForCentralDirectory( FileEntry $file_entry ) {
		$this->centralDirectory[] = new CentralDirectoryEntry(
			array(
				'versionCreated'     => 2,
				'versionNeeded'      => 2,
				'generalPurpose'     => $file_entry->generalPurpose,
				'compressionMethod'  => $file_entry->compressionMethod,
				'lastModifiedTime'   => $file_entry->lastModifiedTime,
				'lastModifiedDate'   => $file_entry->lastModifiedDate,
				'crc'                => $file_entry->crc,
				'compressedSize'     => $file_entry->compressedSize,
				'uncompressedSize'   => $file_entry->uncompressedSize,
				'diskNumber'         => 0,
				'internalAttributes' => 0,
				'externalAttributes' => 0,
				'firstByteAt'        => $this->bytes_written,
				'path'               => $file_entry->path,
				'extra'              => $file_entry->extra,
				'fileComment'        => '',
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
		$centralDirectoryOffset = $this->bytes_written;

		// Write all central directory entries
		foreach ( $this->centralDirectory as $entry ) {
			$this->append_central_directory_entry( $entry );
		}

		$this->append_end_central_directory_entry(
			new EndCentralDirectoryEntry(
				array(
					'numberCentralDirectoryRecordsOnThisDisk' => count( $this->centralDirectory ),
					'numberCentralDirectoryRecords'           => count( $this->centralDirectory ),
					'centralDirectorySize'                    => $this->bytes_written - $centralDirectoryOffset,
					'centralDirectoryOffset'                  => $centralDirectoryOffset,
				)
			)
		);
	}

	private function append_file_entry_header( FileEntry $entry ) {
		$header = pack(
			'VvvvvvVVVvv',
			FileEntry::SIGNATURE,
			$entry->version,
			$entry->generalPurpose,
			$entry->compressionMethod,
			$entry->lastModifiedTime,
			$entry->lastModifiedDate,
			$entry->crc,
			$entry->compressedSize,
			$entry->uncompressedSize,
			$entry->pathLength,
			$entry->extraLength
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
			$entry->versionCreated,
			$entry->versionNeeded,
			$entry->generalPurpose,
			$entry->compressionMethod,
			$entry->lastModifiedTime,
			$entry->lastModifiedDate,
			$entry->crc,
			$entry->compressedSize,
			$entry->uncompressedSize,
			$entry->pathLength,
			$entry->extraLength,
			$entry->fileCommentLength,
			$entry->diskNumber,
			$entry->internalAttributes,
			$entry->externalAttributes,
			$entry->firstByteAt
		) . $entry->path . $entry->extra . $entry->fileComment;

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
			$entry->diskNumber,
			$entry->centralDirectoryStartDisk,
			$entry->numberCentralDirectoryRecordsOnThisDisk,
			$entry->numberCentralDirectoryRecords,
			$entry->centralDirectorySize,
			$entry->centralDirectoryOffset,
			$entry->commentLength
		) . $entry->comment;

		$this->output->append_bytes( $object );
		$this->bytes_written += strlen( $object );
	}
}
