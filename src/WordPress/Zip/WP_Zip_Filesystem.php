<?php

namespace WordPress\Zip;

use WordPress\Filesystem\WP_Abstract_Filesystem;
use WordPress\ByteReader\WP_Byte_Reader;

class WP_Zip_Filesystem extends WP_Abstract_Filesystem {

	private $zip;
	private $byte_reader;
	private $file_chunk;
	private $file_path;
	private $central_directory;
	private $central_directory_end_header;
	private $opened_file_finished = false;

	private $state = self::STATE_OK;
	private $error_message;

	const STATE_OK = 'ok';
	const STATE_ERROR = 'error';

	const TYPE_DIR = 'dir';
	const TYPE_FILE = 'file';

	const CENTRAL_DIRECTORY_INDEX = 'central-directory-index';
	const FILE_ENTRY = 'file-entry';

	/**
	 * Don't support ZIP files with more than 2MB of central directory data.
	 *
	 * This is an arbitrary limitation. This reader is buffering the entire
	 * central directory in memory and we need to be mindful of the available
	 * resources. For those huge ZIP files where the central directory alone
	 * is megabytes large, we need a more complex, streaming reader.
	 */
	const MAX_CENTRAL_DIRECTORY_SIZE = 2 * 1024 * 1024;

	public function __construct(WP_Byte_Reader $byte_reader) {
		$this->zip = new ZipStreamReader($byte_reader);
		$this->byte_reader = $byte_reader;
	}

	public function ls($parent = '/') {
		if($this->state === self::STATE_ERROR) {
			return false;
		}
		if(false === $this->load_central_directory()) {
			return false;
		}

		$descendants = $this->central_directory;

		// Only keep the descendants of the given parent.
		$parent = trim($parent, '/') ;
		$prefix = $parent ? $parent . '/' : '';
		if(strlen($prefix) > 1) {
			$filtered_descendants = [];
			foreach($descendants as $entry) {
				$path = $entry['path'];
				if(strpos($path, $prefix) !== 0) {
					continue;
				}
				$filtered_descendants[] = $entry;
			}
			$descendants = $filtered_descendants;
		}

		// Only keep the direct children of the parent.
		$children = [];
		foreach($descendants as $entry) {
			$suffix = substr($entry['path'], strlen($prefix));
			if(str_contains($suffix, '/')) {
				continue;
			}
			// No need to include the directory itself.
			if(strlen($suffix) === 0) {
				continue;
			}
			$children[] = $suffix;
		}
		return $children;
	}

	public function is_dir($path) {
		if($this->state === self::STATE_ERROR) {
			return false;
		}
		if(false === $this->load_central_directory()) {
			return false;
		}
		$path = trim($path, '/');
		return isset($this->central_directory[$path]) && self::TYPE_DIR === $this->central_directory[$path]['type'];
	}

	public function is_file($path) {
		if($this->state === self::STATE_ERROR) {
			return false;
		}
		if(false === $this->load_central_directory()) {
			return false;
		}
		$path = trim($path, '/');
		return isset($this->central_directory[$path]) && self::TYPE_FILE === $this->central_directory[$path]['type'];
	}

	public function open_read_stream($path) {
		$this->opened_file_finished = false;
		$this->file_chunk = null;
		if($this->state === self::STATE_ERROR) {
			return false;
		}
		if(false === $this->load_central_directory()) {
			return false;
		}
		$path = trim($path, '/');
		if(!isset($this->central_directory[$path])) {
			_doing_it_wrong(
				__METHOD__, 
				sprintf('File %s not found', $path), 
				'1.0.0'
			);
			return false;
		}
		if(self::TYPE_FILE !== $this->central_directory[$path]['type']) {
			_doing_it_wrong(
				__METHOD__, 
				sprintf('Path %s is not a file', $path), 
				'1.0.0'
			);
			return false;
		}
		$this->file_path = $path;
		return $this->zip->seek_to_record($this->central_directory[$path]['firstByteAt']);
	}

	public function next_file_chunk() {
		if ( $this->state === self::STATE_ERROR ) {
			return false;
		}
		if ( $this->opened_file_finished ) {
			$this->file_chunk = null;
			return false;
		}
		if ( false === $this->zip->next() ) {
			return false;
		}
		if ( ZipStreamReader::STATE_FILE_ENTRY !== $this->zip->get_state() ) {
			return false;
		}
		$this->file_chunk = $this->zip->get_file_body_chunk();
		if($this->zip->count_remaining_file_body_bytes() === 0) {
			$this->opened_file_finished = true;
		}
		return true;
	}

	public function get_streamed_file_length() {
		return $this->central_directory[$this->file_path]['fileSize'];
	}
	
	public function get_file_chunk(): string {
		return $this->file_chunk ?? '';
	}

	public function get_last_error() {
		return $this->error_message;
	}

	private function load_central_directory() {
		if($this->state === self::STATE_ERROR) {
			return false;
		}
		if(null !== $this->central_directory) {
			return true;
		}

		if($this->central_directory_size() >= self::MAX_CENTRAL_DIRECTORY_SIZE) {
			return false;
		}

		// Read the central directory into memory.
		if(false === $this->seek_to_central_directory_index()) {
			return false;
		}

		$central_directory = array();
		while($this->zip->next()) {
			if(ZipStreamReader::STATE_CENTRAL_DIRECTORY_ENTRY !== $this->zip->get_state()) {
				continue;
			}
			$central_directory[] = $this->zip->get_header();
		}

		// Transform the central directory into a tree structure with
		// directories and files.
		foreach($central_directory as $entry) {
			/**
			 * Directory are sometimes indicated by a path
			 * ending with a right trailing slash. Let's remove it
			 * to avoid an empty entry at the end of $path_segments.
			 */
			$path_segments = explode('/', $entry['path']);

			for($i=0; $i < count($path_segments)-1; $i++) {
				$path_so_far = implode('/', array_slice($path_segments, 0, $i + 1));
				if(isset($this->central_directory[$path_so_far])) {
					if(self::TYPE_DIR !== $this->central_directory[$path_so_far]['type']) {
						$this->set_error('Path stored both as a file and a directory: ' . $path_so_far);
						return false;
					}
				}
				$this->central_directory[$path_so_far] = array(
					'path' => $path_so_far,
					'type' => self::TYPE_DIR,
				);
			}
			/**
			 * Only create a file entry if it's not a directory.
			 */
			if(!str_ends_with($entry['path'], '/')) {
				$this->central_directory[$entry['path']] = $entry;
				$this->central_directory[$entry['path']]['type'] = self::TYPE_FILE;
			}
		}

		return true;
	}

	private function set_error($message) {
		$this->state = self::STATE_ERROR;
		$this->error_message = $message;
	}

	private function central_directory_size() {
		if(false === $this->collect_central_directory_end_header()) {
			return false;
		}

		return $this->central_directory_end_header['centralDirectorySize'];
	}

	private function seek_to_central_directory_index()
	{
		if(false === $this->collect_central_directory_end_header()) {
			return false;
		}

		return $this->zip->seek_to_record($this->central_directory_end_header['centralDirectoryOffset']);
	}

	private function collect_central_directory_end_header() {
		if( null !== $this->central_directory_end_header ) {
			return true;
		}

		$length = $this->byte_reader->length();
		if(true !== $this->zip->seek_to_record($length - 22)) {
			return false;
		}
		if(true !== $this->zip->next()) {
			return false;
		}
		if($this->zip->get_state() !== ZipStreamReader::STATE_END_CENTRAL_DIRECTORY_ENTRY) {
			return false;
		}

		$this->central_directory_end_header = $this->zip->get_header();
		return true;
	}

	public function close_read_stream() {
		return true;
	}

}
