<?php

namespace WordPress\ByteStream;

use WordPress\ByteStream\ReadStream\BaseByteReadStream;

class FileReadWriteStream extends BaseByteReadStream implements BytePipe {

	/** @var resource|null */
	private $file_pointer;

	private $is_write_closed = false;

	public static function from_path( string $path, bool $truncate = false ): self {
		$mode         = $truncate ? 'w+b' : 'a+b';
		$file_pointer = fopen( $path, $mode );
		if ( ! $file_pointer ) {
			throw new ByteStreamException( esc_html( "Cannot open $path" ) );
		}

		return new self( $file_pointer, filesize( $path ) );
	}

	public static function from_resource( $file_pointer ): self {
		if ( ! is_resource( $file_pointer ) ) {
			throw new ByteStreamException( 'Invalid resource' );
		}

		return new self( $file_pointer, fstat( $file_pointer )['size'] );
	}

	public function __construct( $file_pointer, $expected_length = null ) {
		$this->file_pointer    = $file_pointer;
		$this->expected_length = $expected_length;
	}

	protected function internal_pull( $n ): string {
		return fread( $this->file_pointer, $n );
	}

	protected function internal_reached_end_of_data(): bool {
		return ! is_resource( $this->file_pointer ) || feof( $this->file_pointer );
	}

	protected function seek_outside_of_buffer( int $target_offset ): void {
		$this->buffer                   = '';
		$this->bytes_already_forgotten  = $target_offset;
		$this->offset_in_current_buffer = 0;
		if ( 0 !== fseek( $this->file_pointer, $target_offset ) ) {
			throw new ByteStreamException( 'fseek() failed' );
		}
	}

	public function append_bytes( string $bytes ): void {
		if ( $this->is_write_closed ) {
			throw new ByteStreamException( 'Cannot append after close_writing()' );
		}
		if ( '' === $bytes ) {
			return;
		}

		$offset_before_append = ftell( $this->file_pointer );
		fseek( $this->file_pointer, 0, SEEK_END );

		$len = fwrite( $this->file_pointer, $bytes );
		if ( false === $len || $len !== strlen( $bytes ) ) {
			throw new ByteStreamException( 'fwrite() failed' );
		}
		fflush( $this->file_pointer ); // ensures visibility for concurrent readers.

		// Rewind to the starting offset so we don't affect the reading position.
		fseek( $this->file_pointer, $offset_before_append );

		$this->expected_length += strlen( $bytes );
	}

	public function close_writing(): void {
		$this->is_write_closed = true;
		$this->maybe_close_file_pointer();
	}

	protected function internal_close_reading(): void {
		$this->maybe_close_file_pointer();
	}

	private function maybe_close_file_pointer(): void {
		if ( $this->is_read_closed && $this->is_write_closed && $this->file_pointer ) {
			fclose( $this->file_pointer );
			$this->file_pointer = null;
		}
	}
}
