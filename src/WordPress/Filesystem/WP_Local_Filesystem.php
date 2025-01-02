<?php

namespace WordPress\Filesystem;

/**
 * Represents the currently available filesystem.
 */
class WP_Local_Filesystem extends WP_Abstract_Filesystem {

	private $root = '/';
    private $write_stream = null;

	public function __construct( $root = '/' ) {
		$this->root = rtrim($root, '/');
	}

	public function get_root() {
		return $this->root;
	}

	public function ls($parent = '/') {
		$fullPath = $this->get_full_path($parent);
		$dh = opendir( $fullPath );
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
		return is_dir( $this->get_full_path($path) );
	}

	public function is_file($path) {
		return is_file( $this->get_full_path($path) );
	}

	public function exists($path) {
		return file_exists( $this->get_full_path($path) );
	}

	// @TODO: replace with get_file_reader($path) perhaps?
	//        but that could suggest that the reader is a separate object
	//        and that we can have multiple readers open at the same time.
	private $last_file_reader = null;
	public function open_read_stream($path) {
		if($this->last_file_reader) {
			$this->last_file_reader->close();
		}
		$fullPath = $this->get_full_path($path);
		$this->last_file_reader = \WordPress\ByteReader\WP_File_Reader::create($fullPath);
        if(false === $this->last_file_reader) {
            return false;
        }
		return true;
	}

	public function next_file_chunk() {
		return $this->last_file_reader->next_bytes();
	}

	public function get_file_chunk() {
		return $this->last_file_reader->get_bytes();
	}

    public function get_streamed_file_length() {
        return $this->last_file_reader->length();
    }

	public function get_last_error() {
		return $this->last_file_reader->get_last_error();
	}

	public function close_read_stream() {
		if($this->last_file_reader) {
			$this->last_file_reader->close();
			$this->last_file_reader = null;
		}
	}

	// These methods are not a part of the interface, but they are useful
	// for dealing with a local filesystem.

	public function rename($old_path, $new_path) {
		return rename(
			$this->get_full_path($old_path),
			$this->get_full_path($new_path)
		);
	}

	public function mkdir($path) {
		return mkdir( $this->get_full_path($path) );
	}

	public function rm($path) {
		return unlink( $this->get_full_path($path) );
	}

	public function rmdir($path, $options = []) {
		$recursive = $options['recursive'] ?? false;
		if($recursive) {
			$path = rtrim($path, '/');
			foreach($this->ls($path) as $child) {
				if($this->is_dir($path . '/' . $child)) {
					$this->rmdir($path . '/' . $child, $options);
				} else {
					$this->rm($path . '/' . $child);
				}
			}
		}
		return rmdir(
			$this->get_full_path($path)
		);
	}

	public function put_contents($path, $data, $options = []) {
		return false !== file_put_contents(
			$this->get_full_path($path),
			$data
		);
	}

    public function open_write_stream($path) {
        if($this->write_stream) {
            _doing_it_wrong(__METHOD__, 'Cannot open a new write stream while another write stream is open.', '1.0.0');
            return false;
        }
        $this->write_stream = fopen($this->get_full_path($path), 'wb');
        return true;
    }

    public function append_bytes($data) {
        if(!$this->write_stream) {
            _doing_it_wrong(__METHOD__, 'Cannot append bytes to a write stream that is not open.', '1.0.0');
            return false;
        }
        return fwrite($this->write_stream, $data);
    }

    public function close_write_stream() {
        if(!$this->write_stream) {
            _doing_it_wrong(__METHOD__, 'Cannot close a write stream that is not open.', '1.0.0');
            return false;
        }
        fclose($this->write_stream);
        $this->write_stream = null;
        return true;
    }

	private function get_full_path($relative_path) {
		return $this->root . '/' . ltrim($relative_path, '/');
	}

}
