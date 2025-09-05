<?php

namespace WordPress\ByteStream\WriteStream;

use WordPress\ByteStream\ByteStreamException;

class FileWriteStream implements ByteWriteStream {

	private $fileHandle;

	/**
	 * Creates a new instance of FileWriter from a file path with a mode (truncate or append).
	 *
	 * @param  string $path  Path to the file.
	 * @param  string $mode  Writing mode: 'truncate' or 'append'.
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
	 * @param  resource $fileHandle  A valid file handle.
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
	 * @param  resource $fileHandle
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
	 * @param  string $bytes  The data to write.
	 *
	 * @return void
	 * @throws ByteStreamException If the write operation fails.
	 */
	public function append_bytes( string $bytes ): void {
		$result = fwrite( $this->fileHandle, $bytes );
		/**
		 * We cannot just test for `false === $result` if we want to be
		 * compatible with PHP 7.3.
		 *
		 * The `!fwrite()` check is used for PHP 7.3 compatibility.
		 * Between PHP 7.3 and 7.4, this change was made:
		 *
		 * > fread() and fwrite() will now return FALSE if the operation failed. Previously an empty
		 * > string or 0 was returned. EAGAIN/EWOULDBLOCK are not considered failures.
		 *
		 * https://www.php.net/manual/en/migration74.incompatible.php#migration74.incompatible.core.fread-fwrite
		 */
		if ( ! $result && $bytes !== '' ) {
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
