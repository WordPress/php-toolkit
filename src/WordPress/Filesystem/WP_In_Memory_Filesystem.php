<?php

namespace WordPress\Filesystem;

/**
 * Represents an in-memory filesystem.
 */
class WP_In_Memory_Filesystem extends WP_Abstract_Filesystem {

	private $files = [];
	private $last_file_reader = null;

	public function __construct() {
		$this->files['/'] = [
			'type' => 'dir',
			'contents' => []
		];
	}

	public function ls($parent = '/') {
		$parent = rtrim($parent, '/');
		if (!isset($this->files[$parent]) || $this->files[$parent]['type'] !== 'dir') {
			return false;
		}
		return array_keys($this->files[$parent]['contents']);
	}

	public function is_dir($path) {
		return isset($this->files[$path]) && $this->files[$path]['type'] === 'dir';
	}

	public function is_file($path) {
		return isset($this->files[$path]) && $this->files[$path]['type'] === 'file';
	}

	public function exists($path) {
		return isset($this->files[$path]);
	}

	private function get_parent_dir($path) {
		$path = rtrim($path, '/');
		$parent = dirname($path);
		if($parent === '.') {
			return '/';
		}
		return $parent;
	}

	public function open_file_stream($path) {
		if($this->last_file_reader) {
			$this->last_file_reader->close();
		}
		if (!$this->is_file($path)) {
			return false;
		}
		$this->last_file_reader = \WordPress\ByteReader\WP_String_Reader::create($this->files[$path]['contents']);
		return true;
	}

	public function next_file_chunk() {
		if(!$this->last_file_reader) {
			return false;
		}
		return $this->last_file_reader->next_bytes();
	}

	public function get_file_chunk() {
		if(!$this->last_file_reader) {
			return false;
		}
		return $this->last_file_reader->get_bytes();
	}

	public function get_streamed_file_length() {
		if(!$this->last_file_reader) {
			return false;
		}
		return $this->last_file_reader->length();
	}

	public function get_error_message() {
		if(!$this->last_file_reader) {
			return false;
		}
		return $this->last_file_reader->get_last_error();
	}

	public function close_file_stream() {
		if(!$this->last_file_reader) {
			return false;
		}
		$this->last_file_reader->close();
		$this->last_file_reader = null;
		return true;
	}

	public function rename($old_path, $new_path) {
		if (!$this->exists($old_path)) {
			return false;
		}

		$parent = $this->get_parent_dir($new_path);
		if (!$this->is_dir($parent)) {
			return false;
		}

		$this->files[$new_path] = $this->files[$old_path];
		unset($this->files[$old_path]);
		return true;
	}

	public function mkdir($path) {
		if ($this->exists($path)) {
			return false;
		}

		$parent = $this->get_parent_dir($path);
		if (!$this->is_dir($parent)) {
			return false;
		}

		$this->files[$path] = [
			'type' => 'dir',
			'contents' => []
		];
		$this->files[$parent]['contents'][basename($path)] = true;
		return true;
	}

	public function rm($path) {
		if (!$this->is_file($path)) {
			return false;
		}

		$parent = $this->get_parent_dir($path);
		unset($this->files[$parent]['contents'][basename($path)]);
		unset($this->files[$path]);
		return true;
	}

	public function rmdir($path, $options = []) {
		$recursive = $options['recursive'] ?? false;
		if (!$this->is_dir($path)) {
			return false;
		}

		if ($recursive) {
			$path = rtrim($path, '/');
			foreach($this->ls($path) as $child) {
				if($this->is_dir($path . '/' . $child)) {
					$this->rmdir($path . '/' . $child, $options);
				} else {
					$this->rm($path . '/' . $child);
				}
			}
		}

		$parent = $this->get_parent_dir($path);
		unset($this->files[$parent]['contents'][basename($path)]);
		unset($this->files[$path]);
		return true;
	}

	public function put_contents($path, $data) {
		$parent = $this->get_parent_dir($path);
		if (!$this->is_dir($parent)) {
			return false;
		}

		$this->files[$path] = [
			'type' => 'file',
			'contents' => $data
		];
		$this->files[$parent]['contents'][basename($path)] = true;
		return true;
	}

}
