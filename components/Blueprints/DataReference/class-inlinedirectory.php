<?php

namespace WordPress\Blueprints\DataReference;

use InvalidArgumentException;
use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\InMemoryFilesystem;

use function WordPress\Filesystem\wp_join_unix_paths;

/**
 * Represents a directory that is inlined within the Blueprint JSON document.
 */
class InlineDirectory extends DataReference {
	/**
	 * @var string The directory name.
	 */
	protected $name;

	/**
	 * @var array The directory children.
	 */
	protected $children;

	/**
	 * Constructor.
	 *
	 * @param  array $data  The blueprint data array.
	 */
	public function __construct( array $data ) {
		if ( ! isset( $data['directoryName'] ) || ! isset( $data['files'] ) || ! is_array( $data['files'] ) ) {
			throw new InvalidArgumentException( 'Invalid inline directory data' );
		}

		$this->name = $data['directoryName'];

		$children = array();
		foreach ( $data['files'] as $file_name => $child ) {
			if ( is_string( $child ) ) {
				$children[ $file_name ] = new InlineFile(
					array(
						'filename' => $file_name,
						'content' => $child,
					)
				);
			} elseif ( self::is_valid( $child ) ) {
				$children[ $file_name ] = new self( $child );
			} else {
				throw new InvalidArgumentException( 'Invalid inline directory child' );
			}
		}

		$this->children = $children;
		parent::__construct( $data );
	}

	/**
	 * Get the directory name.
	 *
	 * @return string The directory name.
	 */
	public function get_name(): string {
		return $this->name;
	}

	public function get_filename(): string {
		return $this->name;
	}

	/**
	 * Get the directory children.
	 *
	 * @return array The directory children.
	 */
	public function get_children(): array {
		return $this->children;
	}

	public function as_filesystem(): Filesystem {
		$fs = InMemoryFilesystem::create();

		$add_to_fs = function ( $children, $base_path = '' ) use ( &$add_to_fs, $fs ) {
			foreach ( $children as $child ) {
				if ( $child instanceof InlineFile ) {
					$path = wp_join_unix_paths( $base_path, $child->get_filename() );
					$fs->put_contents( $path, $child->get_content() );
				} elseif ( $child instanceof InlineDirectory ) {
					$dir_path = wp_join_unix_paths( $base_path, $child->get_name() );
					$fs->mkdir( $dir_path, array( 'recursive' => true ) );
					$add_to_fs( $child->get_children(), $dir_path );
				}
			}
		};

		$add_to_fs( $this->children );

		return $fs;
	}

	public function as_directory(): Directory {
		return new Directory( $this->as_filesystem(), $this->get_name() );
	}


	/**
	 * Check if an array represents a valid inline directory.
	 *
	 * @param  array $data  The array to check.
	 *
	 * @return bool Whether the array is valid.
	 */
	public static function is_valid( $data ): bool {
		return is_array( $data ) && isset( $data['directoryName'] ) && isset( $data['files'] ) && is_array( $data['files'] );
	}

	/**
	 * Get a human-readable name for this reference.
	 * Used in the progress tracker.
	 *
	 * @return string The human-readable name.
	 */
	public function get_human_readable_name(): string {
		return 'Inline directory: ' . $this->name;
	}
}
