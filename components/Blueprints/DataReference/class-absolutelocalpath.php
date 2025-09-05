<?php

namespace WordPress\Blueprints\DataReference;

use WordPress\Blueprints\Resources\DataReference\The;

/**
 * Represents a path in the Blueprint Execution Context.
 */
class AbsoluteLocalPath extends DataReference {
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
		$this->path = realpath( $path );
		parent::__construct();
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
	 * Checks if a string is a valid absolute local path. Useful
	 * for resolving a Blueprint reference to an actual file before
	 * we know the execution context.
	 *
	 * Windows paths can be really comples:
	 *
	 *    https://www.fileside.app/blog/2023-03-17_windows-file-paths/
	 *
	 * Instead of parsing them, we'll just ask the OS whether the path exists.
	 *
	 * @param $path The path to check.
	 * @return bool Whether the path is valid.
	 */
	public static function is_valid( $path ): bool {
		return realpath( $path ) !== false;
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
