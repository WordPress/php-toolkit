<?php

namespace WordPress\Blueprints\DataReference;

use WordPress\Blueprints\Resources\DataReference\The;

/**
 * Represents a path in the Blueprint Execution Context.
 */
class ExecutionContextPath extends DataReference {
	/**
	 * @var string The path.
	 */
	protected $path;

	/**
	 * Constructor.
	 *
	 * @param  string $path  The path.
	 */
	public function __construct( string $path ) {
		$this->path = $path;
		parent::__construct( $path );
	}

	/**
	 * Get the path.
	 *
	 * @return string The path.
	 */
	public function get_path(): string {
		return $this->path;
	}

	public function get_filename(): string {
		return basename( $this->path );
	}

	/**
	 * Checks if a string is a valid context-relative path.
	 * A valid path must start with either '/' or './'.
	 * At this stage, we're not yet concerned whether the file actually
	 * exists. We're only validating that the path format is correct
	 * according to the Blueprint specification.
	 *
	 * @param $path The path to check.
	 *
	 * @return bool Whether the path is valid.
	 */
	public static function is_valid( $path ): bool {
		if ( ! is_string( $path ) ) {
			return false;
		}
		if ( strpos( $path, './' ) === 0 || strpos( $path, '/' ) === 0 ) {
			return true;
		}
		return false;
	}

	/**
	 * Get a human-readable name for this reference.
	 * Used in the progress tracker.
	 *
	 * @return string The human-readable name.
	 */
	public function get_human_readable_name(): string {
		return $this->path;
	}
}
