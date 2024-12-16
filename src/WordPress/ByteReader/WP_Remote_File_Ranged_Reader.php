<?php

namespace WordPress\ByteReader;

/**
 * Streams bytes from a remote file. Supports seeking to a specific offset and
 * requesting sub-ranges of the file.
 *
 * Usage:
 *
 * $file = new WP_Remote_File_Ranged_Reader('https://example.com/file.txt');
 * $file->seek(0);
 * $file->request_bytes(100);
 * while($file->next_chunk()) {
 *     var_dump($file->get_bytes());
 * }
 * $file->seek(600);
 * $file->request_bytes(40);
 * while($file->next_chunk()) {
 *     var_dump($file->get_bytes());
 * }
 *
 * @TODO: Abort in-progress requests when seeking to a new offset.
 */
class WP_Remote_File_Ranged_Reader extends WP_Byte_Reader {

	private $url;
	private $remote_file_length;

	private $current_reader;
	private $offset_in_remote_file = 0;
	private $default_expected_chunk_size = 10 * 1024; // 10 KB
	private $expected_chunk_size = 10 * 1024; // 10 KB
	private $stop_after_chunk = false;

	/**
	 * Creates a seekable reader for the remote file.
	 * Detects support for range requests and falls back to saving the entire
	 * file to disk when the remote server does not support range requests.
	 */
	static public function create( $url ) {
		$remote_file_reader = new WP_Remote_File_Ranged_Reader( $url );
		/**
		 * We don't **need** the content-length header to be present.
		 *
		 * However, this reader is only used to read remote ZIP files,
		 * we do need to know the length of the file to be able to read
		 * the central directory index.
		 *
		 * Let's revisit this check once we need to read other types of
		 * files.
		 */
		if(false === $remote_file_reader->length()) {
			return self::save_to_disk( $url );
		}

		/**
		 * Try to read the first two bytes of the file to confirm that
		 * the remote server supports range requests.
		 */
		$remote_file_reader->seek_to_chunk(0, 2);
		if(false === $remote_file_reader->next_bytes()) {
			return self::save_to_disk( $url );
		}

		$bytes = $remote_file_reader->get_bytes();
		if(strlen($bytes) !== 2) {
			// Oops! We're streaming the entire file to disk now. Let's
			// redirect the output to a local file and provide the caller
			// with a regular file reader.
			return self::redirect_output_to_disk( $remote_file_reader );
		}

		// The remote server supports range requests, good! We can use this reader.
		// Let's return to the beginning of the file before returning.
		$remote_file_reader->seek(0);
		return $remote_file_reader;
	}

	static private function save_to_disk( $url ) {
		$remote_file_reader = new WP_Remote_File_Reader( $url );
		return self::redirect_output_to_disk( $remote_file_reader );
	}

	static private function redirect_output_to_disk( WP_Byte_Reader $reader ) {
		$file_path = tempnam(sys_get_temp_dir(), 'wp-remote-file-reader-') . '.epub';
		$file = fopen($file_path, 'w');
		// We may have a bytes chunk available at this point.
		if($reader->get_bytes()) {
			fwrite($file, $reader->get_bytes());
		}
		// Keep streaming the file until we're done.
		while($reader->next_bytes()) {
			fwrite($file, $reader->get_bytes());
		}
		fclose($file);
		if($reader->get_last_error()) {
			// How should we log this error?
			return false;
		}
		return WP_File_Reader::create( $file_path );
	}

	public function __construct( $url ) {
		$this->url = $url;
	}

	public function next_bytes(): bool {
		while( true ) {
			if ( null === $this->current_reader ) {
				$this->create_reader();
			}
			// Advance the offset by the length of the current chunk.
			if ( $this->current_reader->get_bytes() ) {
				$this->offset_in_remote_file += strlen( $this->current_reader->get_bytes() );
			}

			// We've reached the end of the remote file, we're done.
			if ( $this->offset_in_remote_file >= $this->length() - 1 ) {
				return false;
			}

			// We've reached the end of the current chunk, request the next one.
			if ( false === $this->current_reader->next_bytes() ) {
				if ( $this->stop_after_chunk ) {
					return false;
				}
				$this->current_reader = null;
				continue;
			}

			// We've got a chunk, return it.
			return true;
		}
	}

	public function length() {
		$this->ensure_content_length();
		if ( null === $this->remote_file_length ) {
			return false;
		}
		return $this->remote_file_length;
	}

	private function create_reader() {
		$this->current_reader = new WP_Remote_File_Reader(
			$this->url,
			array(
				'headers' => array(
					// @TODO: Detect when the remote server doesn't support range requests,
					//        do something sensible. We could either stream the entire file,
					//        or give up.
					'Range' => 'bytes=' . $this->offset_in_remote_file . '-' . (
						$this->offset_in_remote_file + $this->expected_chunk_size - 1
					),
				),
			)
		);
	}

	public function seek_to_chunk($offset, $length) {
		$this->current_reader->seek($offset);
		$this->expected_chunk_size = $length;
		$this->stop_after_chunk = true;
	}

	public function seek( $offset ): bool {
		$this->offset_in_remote_file = $offset;
		// @TODO cancel any pending requests
		$this->current_reader = null;
		$this->expected_chunk_size = $this->default_expected_chunk_size;
		$this->stop_after_chunk = false;
		return true;
	}

	public function tell(): int {
		return $this->offset_in_remote_file;
	}

	public function is_finished(): bool {
		return false;
	}

	public function get_bytes(): ?string {
		return $this->current_reader->get_bytes();
	}

	public function get_last_error(): ?string {
		// @TODO: Preserve the error information when the current reader
		//        is reset.
		return $this->current_reader->get_last_error();
	}

	private function ensure_content_length() {
		if ( null !== $this->remote_file_length ) {
			return $this->remote_file_length;
		}
		if(null === $this->current_reader) {
			$this->current_reader = new WP_Remote_File_Reader( $this->url );
		}
		$this->remote_file_length = $this->current_reader->length();
		return $this->remote_file_length;
	}

}
