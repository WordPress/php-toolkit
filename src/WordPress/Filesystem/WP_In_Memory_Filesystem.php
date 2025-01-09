<?php

namespace WordPress\Filesystem;

use WordPress\Error\WordPressException;

/**
 * Represents an in-memory filesystem.
 */
class WP_In_Memory_Filesystem extends WP_Abstract_Filesystem {

	private $files = [];
	private $last_file_reader = null;
    private $write_stream = null;

	public function __construct() {
		$this->files['/'] = [
			'type' => 'dir',
			'contents' => []
		];
	}

	public function ls($parent = '/') {
		$parent = wp_canonicalize_path($parent);
		if (!isset($this->files[$parent]) || $this->files[$parent]['type'] !== 'dir') {
			return false;
		}
		return array_keys($this->files[$parent]['contents']);
	}

	public function is_dir($path) {
		$path = wp_canonicalize_path($path);
		return isset($this->files[$path]) && $this->files[$path]['type'] === 'dir';
	}

	public function is_file($path) {
		$path = wp_canonicalize_path($path);
		return isset($this->files[$path]) && $this->files[$path]['type'] === 'file';
	}

	public function exists($path) {
		$path = wp_canonicalize_path($path);
		return isset($this->files[$path]);
	}

	private function get_parent_dir($path) {
		$path = wp_canonicalize_path($path);
		$parent = dirname($path);
		if($parent === '.') {
			return '/';
		}
		return $parent;
	}

	public function open_read_stream($path) {
        $path = wp_canonicalize_path($path);
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
			throw new WordPressException('No file reader is open.');
		}
		return $this->last_file_reader->next_bytes();
	}

	public function get_file_chunk() {
		if(!$this->last_file_reader) {
			throw new WordPressException('No file reader is open.');
		}
		return $this->last_file_reader->get_bytes();
	}

	public function get_streamed_file_length() {
		if(!$this->last_file_reader) {
			throw new WordPressException('No file reader is open.');
		}
		return $this->last_file_reader->length();
	}

	public function get_last_error() {
		if(!$this->last_file_reader) {
			throw new WordPressException('No file reader is open.');
		}
		return $this->last_file_reader->get_last_error();
	}

	public function close_read_stream() {
		if(!$this->last_file_reader) {
			throw new WordPressException('No file reader is open.');
		}
		$this->last_file_reader->close();
		$this->last_file_reader = null;
		return true;
	}

	public function rename($old_path, $new_path) {
		$old_path = wp_canonicalize_path($old_path);
		$new_path = wp_canonicalize_path($new_path);
		if (!$this->exists($old_path)) {
			throw new WordPressException('The old path does not exist.');
		}

		$parent = $this->get_parent_dir($new_path);
		if (!$this->is_dir($parent)) {
			throw new WordPressException('The parent directory does not exist.');
		}

		$this->files[$new_path] = $this->files[$old_path];
		unset($this->files[$old_path]);
		return true;
	}

	public function mkdir($path) {
		$path = wp_canonicalize_path($path);
		if ($this->exists($path)) {
            if($this->is_dir($path)) {
                return false;
            } else {
                throw new WordPressException('The path already exists but is not a directory.');
            }
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
		$path = wp_canonicalize_path($path);
		if (!$this->is_file($path)) {
			throw new WordPressException('The path is not a file and cannot be removed.');
		}

		$parent = $this->get_parent_dir($path);
		unset($this->files[$parent]['contents'][basename($path)]);
		unset($this->files[$path]);
		return true;
	}

	public function rmdir($path, $options = []) {
		$path = wp_canonicalize_path($path);
		$recursive = $options['recursive'] ?? false;
		if (!$this->is_dir($path)) {
			throw new WordPressException('The path is not a directory and cannot be removed.');
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

	public function put_contents($path, $data, $options = []) {
		$path = wp_canonicalize_path($path);
		$parent = $this->get_parent_dir($path);
		if (!$this->is_dir($parent)) {
			throw new WordPressException('The parent directory does not exist.');
		}

		$this->files[$path] = [
			'type' => 'file',
			'contents' => $data
		];
		$this->files[$parent]['contents'][basename($path)] = true;
		return true;
	}

    public function open_write_stream($path) {
        if($this->write_stream) {
            throw new WordPressException('Cannot open a new write stream while another write stream is open.');
        }
        $this->write_stream = [
            'path' => $path,
            'contents' => '',
        ];
        return true;
    }

    public function append_bytes($data) {
        if(!$this->write_stream) {
            throw new WordPressException('Cannot append bytes to a write stream that is not open.');
        }
        $path = $this->write_stream['path'];
        if(!isset($this->files[$path])) {
            $this->put_contents($path, '');
        }
        $this->files[$path]['contents'] .= $data;
        return true;
    }

    public function close_write_stream() {
        if(!$this->write_stream) {
            throw new WordPressException('Cannot close a write stream that is not open.');
        }
        $this->write_stream = null;
        return true;
    }

}
