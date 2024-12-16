<?php

namespace WordPress\Filesystem;

/**
 * Represents the currently available filesystem.
 */
class WP_Filesystem extends WP_Abstract_Filesystem {

	public function __construct() {
	}

	public function ls($parent = '/') {
		$dh = opendir( $parent );
		if ( $dh === false ) {
			return false;
		}

		$children = array();
		while ( true ) {
			$filename = readdir( $dh );
			if ( $filename === false ) {
				break;
			}
			if ( '.' === $filename || '..' === $filename ) {
				continue;
			}
			$children[] = $filename;
		}
		closedir( $dh );

		return $children;
	}

	public function is_dir($path) {
		return is_dir($path);
	}

	public function is_file($path) {
		return is_file($path);
	}

	// @TODO: replace with get_file_reader($path) perhaps?
	//        but that could suggest that the reader is a separate object
	//        and that we can have multiple readers open at the same time.
	private $last_file_reader = null;
	public function start_streaming_file($path) {
		if($this->last_file_reader) {
			$this->last_file_reader->close();
		}
		$this->last_file_reader = \WordPress\ByteReader\WP_File_Reader::create($path);
		return $this->last_file_reader->next_bytes();
	}

	public function next_file_chunk() {
		return $this->last_file_reader->next_bytes();
	}

	public function get_file_chunk() {
		return $this->last_file_reader->get_bytes();
	}

	public function get_error_message() {
		return $this->last_file_reader->get_last_error();
	}

	public function close_file_reader() {
		if($this->last_file_reader) {
			$this->last_file_reader->close();
			$this->last_file_reader = null;
		}
	}

}
