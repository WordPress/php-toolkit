<?php

namespace WordPress\Blueprints\DataReference;

use InvalidArgumentException;

/**
 * Represents a reference to a remote git repository.
 */
class GitPath extends DataReference {
	/**
	 * @var string The git repository URL.
	 */
	protected $git_repository;

	/**
	 * @var string|null The git reference (branch, tag, commit).
	 */
	protected $ref;

	/**
	 * @var string|null The path within the repository.
	 */
	protected $path;

	/**
	 * Constructor.
	 */
	public function __construct(
		$definition
	) {
		if ( ! isset( $definition['gitRepository'] ) ) {
			throw new InvalidArgumentException( 'Invalid git path data' );
		}
		$this->git_repository = $definition['gitRepository'];
		$this->ref            = $definition['ref'] ?? null;
		$this->path           = $definition['path'] ?? null;
		parent::__construct($definition);
	}

	/**
	 * Get the git repository URL.
	 *
	 * @return string The git repository URL.
	 */
	public function get_git_repository(): string {
		return $this->git_repository;
	}

	/**
	 * Get the git reference.
	 *
	 * @return string|null The git reference.
	 */
	public function get_ref(): ?string {
		return $this->ref;
	}

	/**
	 * Get the path within the repository.
	 *
	 * @return string|null The path.
	 */
	public function get_path(): ?string {
		return $this->path;
	}

	public function get_filename(): string {
		return basename( $this->path );
	}

	/**
	 * Check if an array represents a valid git path.
	 *
	 * @param  array  $data  The array to check.
	 *
	 * @return bool Whether the array is valid.
	 */
	public static function is_valid( $data ): bool {
		return is_array( $data ) && isset( $data['gitRepository'] );
	}

	/**
	 * Get a human-readable name for this reference.
	 * Used in the progress tracker.
	 *
	 * @return string The human-readable name.
	 */
	public function get_human_readable_name(): string {
		$ref  = $this->ref ? "#{$this->ref}" : "";
		$path = $this->path ? "/{$this->path}" : "";

		return "Git: {$this->git_repository}{$ref}{$path}";
	}
}
