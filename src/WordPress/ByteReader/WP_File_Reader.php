<?php

namespace WordPress\ByteReader;

use WordPress\Error\WordPressException;

class WP_File_Reader extends WP_Byte_Reader {

	const STATE_STREAMING = '#streaming';
	const STATE_FINISHED  = '#finished';

	protected $file_path;
	protected $chunk_size;
	protected $file_pointer;
	protected $offset_in_file;
	protected $output_bytes	= '';
	protected $last_chunk_size = 0;
	protected $state = self::STATE_STREAMING;

	static public function create( $file_path, $chunk_size = 8096 ) {
		if(!file_exists($file_path)) {
            throw new WordPressException(sprintf( 'File %s does not exist', $file_path ));
		}
		if(!is_file($file_path)) {
            throw new WordPressException(sprintf( '%s is not a file', $file_path ));
		}
		return new self( $file_path, $chunk_size );
	}

	private function __construct( $file_path, $chunk_size ) {
		$this->file_path  = $file_path;
		$this->chunk_size = $chunk_size;
	}

	public function length(): ?int {
		return filesize( $this->file_path );
	}

	public function tell(): int {
		// Save the previous offset, not the current one.
		// This way, after resuming, the next read will yield the same $output_bytes
		// as we have now.
		return $this->offset_in_file - $this->last_chunk_size;
	}

	public function seek( $offset_in_file ): bool {
		if ( ! is_int( $offset_in_file ) ) {
			throw new WordPressException('Cannot set a file reader cursor to a non-integer offset.');
		}
		$this->offset_in_file  = $offset_in_file;
		$this->last_chunk_size = 0;
		$this->output_bytes	= '';
		if ( $this->file_pointer ) {
			if ( false === fseek( $this->file_pointer, $this->offset_in_file ) ) {
				return false;
			}
		}
		return true;
	}

	public function close(): bool {
		if(!$this->file_pointer) {
			throw new WordPressException('File pointer is not open');
		}
		if(!fclose($this->file_pointer)) {
			throw new WordPressException('Failed to close file pointer');
		}
		$this->file_pointer = null;
		$this->state = static::STATE_FINISHED;
		return true;
	}

	public function is_finished(): bool {
		return ! $this->output_bytes && $this->state === static::STATE_FINISHED;
	}

	public function get_bytes(): string {
		return $this->output_bytes;
	}

	public function next_bytes(): bool {
		$this->output_bytes	= '';
		$this->last_chunk_size = 0;
		if ( $this->is_finished() ) {
			return false;
		}
		if ( ! $this->file_pointer ) {
			$this->file_pointer = fopen( $this->file_path, 'r' );
            if(false === $this->file_pointer) {
                throw new WordPressException(sprintf('Failed to open the file: %s', $this->file_path));
            }
			if ( $this->offset_in_file ) {
				fseek( $this->file_pointer, $this->offset_in_file );
			}
		}
		$bytes = fread( $this->file_pointer, $this->chunk_size );
		if ( ! $bytes && feof( $this->file_pointer ) ) {
			$this->state = static::STATE_FINISHED;
			return false;
		}
		$this->last_chunk_size = strlen( $bytes );
		$this->offset_in_file += $this->last_chunk_size;
		$this->output_bytes   .= $bytes;
		return true;
	}
}
