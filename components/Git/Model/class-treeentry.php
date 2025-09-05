<?php

namespace WordPress\Git\Model;

use WordPress\Git\GitException;

class TreeEntry {

	/**
	 * The mode of the tree entry (file permissions)
	 *
	 * @var string
	 */
	public $mode;

	/**
	 * The name of the tree entry
	 *
	 * @var string
	 */
	public $name;

	/**
	 * The object hash this entry points to
	 *
	 * @var string
	 */
	public $hash;

	/**
	 * The content of the tree entry. Only used by the diff engine.
	 *
	 * @var string
	 */
	public $content;

	public function __construct( $data = array() ) {
		foreach ( $data as $key => $value ) {
			if ( ! property_exists( $this, $key ) ) {
				throw new GitException( "Invalid tree entry property: $key" );
			}
			$this->$key = $value;
		}
	}

	public function get_mode_bucket() {
		if ( preg_match( '/^0?4.*/', $this->mode ) ) {
			return self::FILE_MODE_DIRECTORY;
		} elseif ( preg_match( '/^1006.*/', $this->mode ) ) {
			return self::FILE_MODE_REGULAR_NON_EXECUTABLE;
		} elseif ( preg_match( '/^1007.*/', $this->mode ) ) {
			return self::FILE_MODE_REGULAR_EXECUTABLE;
		} elseif ( preg_match( '/^120.*/', $this->mode ) ) {
			return self::FILE_MODE_SYMBOLIC_LINK;
		} elseif ( preg_match( '/^160.*/', $this->mode ) ) {
			return self::FILE_MODE_COMMIT;
		}
	}

	const FILE_MODE_DIRECTORY              = '040000';
	const FILE_MODE_REGULAR_NON_EXECUTABLE = '100644';
	const FILE_MODE_REGULAR_EXECUTABLE     = '100755';
	const FILE_MODE_SYMBOLIC_LINK          = '120000';
	const FILE_MODE_COMMIT                 = '160000';

	const FILE_MODE_NAMES = array(
		self::FILE_MODE_DIRECTORY              => 'directory',
		self::FILE_MODE_REGULAR_NON_EXECUTABLE => 'regular_non_executable',
		self::FILE_MODE_REGULAR_EXECUTABLE     => 'regular_executable',
		self::FILE_MODE_SYMBOLIC_LINK          => 'symbolic_link',
		self::FILE_MODE_COMMIT                 => 'commit',
	);

	public function is_file() {
		return $this->get_mode_bucket() === self::FILE_MODE_REGULAR_NON_EXECUTABLE || $this->get_mode_bucket() === self::FILE_MODE_REGULAR_EXECUTABLE;
	}

	public function is_tree() {
		return $this->get_mode_bucket() === self::FILE_MODE_DIRECTORY;
	}
}
