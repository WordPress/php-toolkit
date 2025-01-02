<?php

namespace WordPress\Filesystem;

use WordPress\ByteReader\WP_Byte_Reader;

/**
 * Abstract class for filesystem implementations.
 * 
 * It enables navigating multiple filesystem implementations in a unified way.
 * For example, WP_Zip_Filesystem and WP_Local_Filesystem are both implemented
 * as subclasses of this class.
 */
abstract class WP_Abstract_Filesystem {

	/**
	 * List the contents of a directory.
	 * 
	 * @param string $parent The path to the parent directory.
	 * @return array<string> The contents of the directory.
	 */
	abstract public function ls($parent = '/');

	/**
	 * Check if a path is a directory.
	 * 
	 * @param string $path The path to check.
	 * @return bool True if the path is a directory, false otherwise.
	 */
	abstract public function is_dir($path);

	/**
	 * Check if a path is a file.
	 * 
	 * @param string $path The path to check.
	 * @return bool True if the path is a file, false otherwise.
	 */
	abstract public function is_file($path);

	/**
	 * Start streaming a file.
	 * 
	 * @example
	 * 
	 * $fs->open_read_stream($path);
	 * while($fs->next_file_chunk()) {
	 *     $chunk = $fs->get_file_chunk();
	 *     // process $chunk
	 * }
	 * $fs->close_read_stream();
	 * 
	 * @param string $path The path to the file.
	 */
	abstract public function open_read_stream($path);

	/**
	 * Get the next chunk of a file.
	 * 
	 * @return string|false The next chunk of the file or false if the end of the file is reached.
	 */
	abstract public function next_file_chunk();

	/**
	 * Get the current chunk of a file.
	 * 
	 * @return string|false The current chunk of the file or false if no chunk is available.
	 */
	abstract public function get_file_chunk();

    /**
     * Get the length of the streamed file.
     * 
     * @return int|false The length of the file or false if the file is not streamed.
     */
    abstract public function get_streamed_file_length();

	/**
	 * Get the error message of the filesystem.
	 * 
	 * @return string|false The error message or false if no error occurred.
	 */
	abstract public function get_last_error();

	/**
	 * Close the file reader.
	 */
	abstract public function close_read_stream();

	// @TODO: Support for write methods, perhaps in a separate interface?
	// abstract public function open_write_stream($path);
    // abstract public function append_bytes($data);
    // abstract public function close_write_stream();
	// abstract public function rename($old_path, $new_path);
	// abstract public function mkdir($path);
	// abstract public function rm($path);
	// abstract public function rmdir($path, $options = []);

    public function put_contents($path, $data, $options = []) {
        if(!$this->open_write_stream($path)) {
            return false;
        }
        if(is_string($data)) {
            if(!$this->append_bytes($data)) {
                return false;
            }
        } else if(is_object($data) && $data instanceof WP_Byte_Reader) {
            while($data->next_chunk()) {
                if(!$this->append_bytes($data->get_chunk())) {
                    return false;
                }
            }
        } else {
            _doing_it_wrong(__METHOD__, 'Invalid $data argument provided. Expected a string or a WP_Byte_Reader instance. Received: ' . gettype($data), '1.0.0');
            return false;
        }
        if(!$this->close_write_stream($options)) {
            return false;
        }
        return true;
    }

    public function copy($from_path, $to_path, $options = []) {
        $to_fs = $options['to_fs'] ?? $this;
        $recursive = $options['recursive'] ?? false;
        if($this->is_dir($from_path) && !$recursive) {
            _doing_it_wrong( __METHOD__, 'Cannot copy a directory without recursive => true option', '1.0.0' );
            return false;
        }
        
        $stack = [[$from_path, $to_path]];
        while(!empty($stack)) {
            [$from_path, $to_path] = array_shift($stack);
            if($this->is_dir($from_path)) {
                if(!$to_fs->is_dir($to_path)) {
                    $to_fs->mkdir($to_path);
                }
                foreach($this->ls($from_path) as $child) {
                    $stack[] = [
                        wp_join_paths($from_path, $child),
                        wp_join_paths($to_path, $child)
                    ];
                }
            } else {
                if(false === $this->open_read_stream($from_path)) {
                    throw new \Exception('Failed to open read stream for ' . $from_path);
                    return false;
                }
                if(false === $to_fs->open_write_stream($to_path)) {
                    throw new \Exception('Failed to open write stream for ' . $to_path);
                    return false;
                }
                $chunks_written = 0;
                while($this->next_file_chunk()) {
                    if(false === $to_fs->append_bytes($this->get_file_chunk())) {
                        throw new \Exception('Failed to append bytes to ' . $to_path);
                        return false;
                    }
                    $chunks_written++;
                }
                if($chunks_written === 0) {
                    // Make sure the file receives at least one chunk
                    // so we can be sure it gets created in case the
                    // destination filesystem is lazy.
                    $to_fs->append_bytes('');
                }
                if(false === $this->close_read_stream()) {
                    throw new \Exception('Failed to close read stream for ' . $from_path);
                    return false;
                }
                if(false === $to_fs->close_write_stream()) {
                    throw new \Exception('Failed to close write stream for ' . $to_path);
                    return false;
                }
            }
        }
        return true;
    }

	/**
	 * Buffers the entire contents of a file into a string
	 * and returns it.
	 * 
	 * @param string $path The path to the file.
	 * @return string|false The contents of the file or false if the file does not exist.
	 */
	public function get_contents($path) {
		$this->open_read_stream($path);
		$body = '';
		while($this->next_file_chunk()) {
			$chunk = $this->get_file_chunk();
			if($chunk === false) {
				return false;
			}
			$body .= $chunk;
		}
		$this->close_read_stream();
		return $body;
	}

}
