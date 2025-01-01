<?php

namespace WordPress\Filesystem;

/**
 * Stores files in SQLite database.
 */
class WP_SQLite_Filesystem extends WP_Abstract_Filesystem {

	private $db;
	private $last_file_reader = null;

	public function __construct($db_path = ':memory:') {
		$this->db = new \SQLite3($db_path);
		$this->db->exec('
			CREATE TABLE IF NOT EXISTS files (
				path TEXT PRIMARY KEY,
				type TEXT NOT NULL,
				contents BLOB
			);
			CREATE TABLE IF NOT EXISTS directory_entries (
				parent_path TEXT,
				name TEXT,
				PRIMARY KEY (parent_path, name)
			);
		');

		// Create root directory if it doesn't exist
		$stmt = $this->db->prepare('INSERT OR IGNORE INTO files (path, type) VALUES (?, ?)');
		$stmt->bindValue(1, '/', SQLITE3_TEXT);
		$stmt->bindValue(2, 'dir', SQLITE3_TEXT);
		$stmt->execute();
	}

	public function ls($parent = '/') {
		$parent = wp_canonicalize_path($parent);
		$parent = rtrim($parent, '/');
		$stmt = $this->db->prepare('
			SELECT name FROM directory_entries 
			WHERE parent_path = ?
		');
		$stmt->bindValue(1, $parent, SQLITE3_TEXT);
		$result = $stmt->execute();
		
		$entries = [];
		while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
			$entries[] = $row['name'];
		}
		return $entries;
	}

	public function is_dir($path) {
		$path = wp_canonicalize_path($path);
		$stmt = $this->db->prepare('
			SELECT type FROM files 
			WHERE path = ? AND type = ?
		');
		$stmt->bindValue(1, $path, SQLITE3_TEXT);
		$stmt->bindValue(2, 'dir', SQLITE3_TEXT);
		$result = $stmt->execute();
		return $result->fetchArray() !== false;
	}

	public function is_file($path) {
		$path = wp_canonicalize_path($path);
		$stmt = $this->db->prepare('
			SELECT type FROM files 
			WHERE path = ? AND type = ?
		');
		$stmt->bindValue(1, $path, SQLITE3_TEXT);
		$stmt->bindValue(2, 'file', SQLITE3_TEXT);
		$result = $stmt->execute();
		return $result->fetchArray() !== false;
	}

	public function exists($path) {
		$path = wp_canonicalize_path($path);
		$stmt = $this->db->prepare('
			SELECT 1 FROM files 
			WHERE path = ?
		');
		$stmt->bindValue(1, $path, SQLITE3_TEXT);
		$result = $stmt->execute();
		return $result->fetchArray() !== false;
	}

	private function get_parent_dir($path) {
		$path = wp_canonicalize_path($path);
		$path = rtrim($path, '/');
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
		$stmt = $this->db->prepare('SELECT contents FROM files WHERE path = ?');
		$stmt->bindValue(1, $path, SQLITE3_TEXT);
		$result = $stmt->execute();
		$row = $result->fetchArray(SQLITE3_ASSOC);
		$this->last_file_reader = \WordPress\ByteReader\WP_String_Reader::create($row['contents']);
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

	public function get_last_error() {
		if(!$this->last_file_reader) {
			return false;
		}
		return $this->last_file_reader->get_last_error();
	}

	public function close_read_stream() {
		if(!$this->last_file_reader) {
			return false;
		}
		$this->last_file_reader->close();
		$this->last_file_reader = null;
		return true;
	}

	public function rename($old_path, $new_path) {
		$old_path = wp_canonicalize_path($old_path);
		$new_path = wp_canonicalize_path($new_path);
		if (!$this->exists($old_path)) {
			return false;
		}

		$parent = $this->get_parent_dir($new_path);
		if (!$this->is_dir($parent)) {
			return false;
		}

		$this->db->exec('BEGIN TRANSACTION');
		try {
			// Update the file path
			$stmt = $this->db->prepare('UPDATE files SET path = ? WHERE path = ?');
			$stmt->bindValue(1, $new_path, SQLITE3_TEXT);
			$stmt->bindValue(2, $old_path, SQLITE3_TEXT);
			$stmt->execute();

			// Update directory entries
			$old_parent = $this->get_parent_dir($old_path);
			$stmt = $this->db->prepare('
				DELETE FROM directory_entries 
				WHERE parent_path = ? AND name = ?
			');
			$stmt->bindValue(1, $old_parent, SQLITE3_TEXT);
			$stmt->bindValue(2, basename($old_path), SQLITE3_TEXT);
			$stmt->execute();

			$stmt = $this->db->prepare('
				INSERT INTO directory_entries (parent_path, name)
				VALUES (?, ?)
			');
			$stmt->bindValue(1, $parent, SQLITE3_TEXT);
			$stmt->bindValue(2, basename($new_path), SQLITE3_TEXT);
			$stmt->execute();

			$this->db->exec('COMMIT');
			return true;
		} catch (\Exception $e) {
			$this->db->exec('ROLLBACK');
			return false;
		}
	}

	public function mkdir($path) {
		$path = wp_canonicalize_path($path);
		if ($this->exists($path)) {
			return false;
		}

		$parent = $this->get_parent_dir($path);
		if (!$this->is_dir($parent)) {
			return false;
		}

		$this->db->exec('BEGIN TRANSACTION');
		try {
			$stmt = $this->db->prepare('
				INSERT INTO files (path, type)
				VALUES (?, ?)
			');
			$stmt->bindValue(1, $path, SQLITE3_TEXT);
			$stmt->bindValue(2, 'dir', SQLITE3_TEXT);
			$stmt->execute();

			$stmt = $this->db->prepare('
				INSERT INTO directory_entries (parent_path, name)
				VALUES (?, ?)
			');
			$stmt->bindValue(1, $parent, SQLITE3_TEXT);
			$stmt->bindValue(2, basename($path), SQLITE3_TEXT);
			$stmt->execute();

			$this->db->exec('COMMIT');
			return true;
		} catch (\Exception $e) {
			$this->db->exec('ROLLBACK');
			return false;
		}
	}

	public function rm($path) {
		$path = wp_canonicalize_path($path);
		if (!$this->is_file($path)) {
			return false;
		}

		$this->db->exec('BEGIN TRANSACTION');
		try {
			$parent = $this->get_parent_dir($path);
			
			$stmt = $this->db->prepare('DELETE FROM files WHERE path = ?');
			$stmt->bindValue(1, $path, SQLITE3_TEXT);
			$stmt->execute();

			$stmt = $this->db->prepare('
				DELETE FROM directory_entries 
				WHERE parent_path = ? AND name = ?
			');
			$stmt->bindValue(1, $parent, SQLITE3_TEXT);
			$stmt->bindValue(2, basename($path), SQLITE3_TEXT);
			$stmt->execute();

			$this->db->exec('COMMIT');
			return true;
		} catch (\Exception $e) {
			$this->db->exec('ROLLBACK');
			return false;
		}
	}

	public function rmdir($path, $options = []) {
		$path = wp_canonicalize_path($path);
		$recursive = $options['recursive'] ?? false;
		if (!$this->is_dir($path)) {
			return false;
		}

		$this->db->exec('BEGIN TRANSACTION');
		try {
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
			
			$stmt = $this->db->prepare('DELETE FROM files WHERE path = ?');
			$stmt->bindValue(1, $path, SQLITE3_TEXT);
			$stmt->execute();

			$stmt = $this->db->prepare('
				DELETE FROM directory_entries 
				WHERE parent_path = ? AND name = ?
			');
			$stmt->bindValue(1, $parent, SQLITE3_TEXT);
			$stmt->bindValue(2, basename($path), SQLITE3_TEXT);
			$stmt->execute();

			$this->db->exec('COMMIT');
			return true;
		} catch (\Exception $e) {
			$this->db->exec('ROLLBACK');
			return false;
		}
	}

	public function put_contents($path, $data, $options = []) {
		$path = wp_canonicalize_path($path);
		$parent = $this->get_parent_dir($path);
		if (!$this->is_dir($parent)) {
			return false;
		}

		$this->db->exec('BEGIN TRANSACTION');
		try {
			$stmt = $this->db->prepare('
				INSERT OR REPLACE INTO files (path, type, contents)
				VALUES (?, ?, ?)
			');
			$stmt->bindValue(1, $path, SQLITE3_TEXT);
			$stmt->bindValue(2, 'file', SQLITE3_TEXT);
			$stmt->bindValue(3, $data, SQLITE3_BLOB);
			$stmt->execute();

			$stmt = $this->db->prepare('
				INSERT OR REPLACE INTO directory_entries (parent_path, name)
				VALUES (?, ?)
			');
			$stmt->bindValue(1, $parent, SQLITE3_TEXT);
			$stmt->bindValue(2, basename($path), SQLITE3_TEXT);
			$stmt->execute();

			$this->db->exec('COMMIT');
			return true;
		} catch (\Exception $e) {
			$this->db->exec('ROLLBACK');
			return false;
		}
	}

}
