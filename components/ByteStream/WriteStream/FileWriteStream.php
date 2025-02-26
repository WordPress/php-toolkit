<?php

namespace WordPress\ByteStream\WriteStream;

use WordPress\ByteStream\ByteStreamException;

class FileWriteStream implements ByteWriteStream {

	private $fileHandle;

	/**
	 * Creates a new instance of FileWriter from a file path with a mode (truncate or append).
	 *
	 * @param string $path Path to the file.
	 * @param string $mode Writing mode: 'truncate' or 'append'.
	 *
	 * @return FileWriteStream
	 * @throws ByteStreamException If the file cannot be opened for writing.
	 */
	public static function from_path( string $path, string $mode = 'append' ): self {
		switch ( $mode ) {
			case 'truncate':
				$fileMode = 'wb'; // Write mode: truncates the file.
				break;
			case 'append':
				$fileMode = 'ab'; // Append mode: appends to the file.
				break;
			default:
				throw new ByteStreamException( "Invalid mode: $mode. Use 'truncate' or 'append'." );
		}

		$fileHandle = fopen( $path, $fileMode );
		if ( $fileHandle === false ) {
			throw new ByteStreamException( "Failed to open file at path: $path" );
		}

		return new self( $fileHandle );
	}

	/**
	 * Creates a new instance of FileWriter from an existing file handle.
	 *
	 * @param resource $fileHandle A valid file handle.
	 *
	 * @return FileWriteStream
	 * @throws ByteStreamException If the file handle is invalid.
	 */
	public static function from_resource_handle( $fileHandle ): self {
		return new self( $fileHandle );
	}

	/**
	 * Private constructor to enforce the use of static factory methods.
	 *
	 * @param resource $fileHandle
	 */
	public function __construct( $fileHandle ) {
		if ( ! is_resource( $fileHandle ) || get_resource_type( $fileHandle ) !== 'stream' ) {
			throw new ByteStreamException( 'Invalid file handle provided.' );
		}
		$this->fileHandle = $fileHandle;
	}

	/**
	 * Appends bytes to the file.
	 *
	 * @param string $bytes The data to write.
	 * @return void
	 * @throws ByteStreamException If the write operation fails.
	 */
	public function append_bytes( string $bytes ): void {
		if ( fwrite( $this->fileHandle, $bytes ) === false ) {
			throw new ByteStreamException( 'Failed to write bytes to file.' );
		}
	}

	/**
	 * Closes the file handle.
	 *
	 * @return void
	 * @throws ByteStreamException If the file handle is already closed.
	 */
	public function close_writing(): void {
		if ( $this->fileHandle === null ) {
			throw new ByteStreamException( 'File handle is already closed.' );
		}

		fclose( $this->fileHandle );
		$this->fileHandle = null;
	}
}
