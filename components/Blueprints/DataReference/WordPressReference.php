<?php

namespace WordPress\Blueprints\DataReference;

/**
 * Class WordPressReference
 *
 * Represents a reference to a WordPress resource like a plugin, theme, or core version.
 */
class WordPressReference extends DataReference {
	/**
	 * The reference type.
	 *
	 * @var string
	 */
	protected $type;

	/**
	 * The resource slug.
	 *
	 * @var string
	 */
	protected $slug;

	/**
	 * The version constraint.
	 *
	 * @var string|null
	 */
	protected $version;

	/**
	 * Create a new WordPress reference.
	 *
	 * @param  string  $reference  The reference string.
	 * @param  string  $type  The reference type (plugin, theme, or core).
	 */
	public function __construct( string $reference, string $type ) {
		$this->type = $type;

		// Parse slug and version
		if ( strpos( $reference, '@' ) !== false ) {
			[ $this->slug, $this->version ] = explode( '@', $reference );
		} else {
			$this->slug    = $reference;
			$this->version = null;
		}
		parent::__construct();
	}

	/**
	 * Check if the reference is valid for this type.
	 *
	 * @param  mixed  $reference  The reference to check.
	 *
	 * @return bool True if the reference is valid, false otherwise.
	 */
	public static function is_valid( $reference ): bool {
		if ( ! is_string( $reference ) ) {
			return false;
		}

		// Check for a valid slug pattern (can include @ for versions)
		return preg_match( '/^[a-zA-Z0-9_-]+(@(latest|\d+\.\d+(\.\d+)?))?$/', $reference );
	}

	/**
	 * Get the type.
	 *
	 * @return string The type.
	 */
	public function getType(): string {
		return $this->type;
	}

	/**
	 * Get the slug.
	 *
	 * @return string The slug.
	 */
	public function getSlug(): string {
		return $this->slug;
	}

	/**
	 * Get the version.
	 *
	 * @return string|null The version or null if not specified.
	 */
	public function getVersion(): ?string {
		return $this->version;
	}

	/**
	 * Check if the reference has a version constraint.
	 *
	 * @return bool True if the reference has a version constraint, false otherwise.
	 */
	public function hasVersion(): bool {
		return $this->version !== null;
	}

	/**
	 * Create a plugin reference.
	 *
	 * @param  string  $reference  The reference string.
	 *
	 * @return self The plugin reference.
	 */
	public static function createPlugin( string $reference ): self {
		return new self( $reference, 'plugin' );
	}

	/**
	 * Create a theme reference.
	 *
	 * @param  string  $reference  The reference string.
	 *
	 * @return self The theme reference.
	 */
	public static function createTheme( string $reference ): self {
		return new self( $reference, 'theme' );
	}

	/**
	 * Create a WordPress core reference.
	 *
	 * @param  string  $reference  The reference string.
	 *
	 * @return self The WordPress core reference.
	 */
	public static function createWordPressCore( string $reference ): self {
		return new self( $reference, 'core' );
	}

	/**
	 * Convert the reference to a string.
	 *
	 * @return string The reference as a string.
	 */
	public function __toString(): string {
		return $this->version ? "{$this->slug}@{$this->version}" : $this->slug;
	}

	/**
	 * Get a human-readable name for this reference.
	 * Used in the progress tracker.
	 *
	 * @return string The human-readable name.
	 */
	public function get_human_readable_name(): string {
		return "WordPress: " . ( $this->version ?: 'Latest' );
	}
}
