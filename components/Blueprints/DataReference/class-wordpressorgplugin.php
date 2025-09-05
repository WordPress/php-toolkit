<?php

namespace WordPress\Blueprints\DataReference;

class WordPressOrgPlugin extends DataReference {

	/**
	 * @var string The plugin slug.
	 */
	protected $slug;

	/**
	 * @var string|null The plugin version.
	 */
	protected $version;

	/**
	 * Create a WordPress.org plugin reference.
	 *
	 * @param  string $reference  The reference to parse, e.g. "gutenberg" or "gutenberg@14.0.1"
	 */
	public function __construct( $reference ) {
		$parts         = explode( '@', $reference );
		$this->slug    = $parts[0];
		$this->version = isset( $parts[1] ) ? $parts[1] : null;

		parent::__construct( $reference );
	}

	/**
	 * Get the plugin slug.
	 *
	 * @return string The plugin slug.
	 */
	public function get_slug(): string {
		return $this->slug;
	}

	/**
	 * Get the plugin version.
	 *
	 * @return string|null The plugin version.
	 */
	public function get_version(): ?string {
		return $this->version;
	}

	/**
	 * Check if a string is a valid WordPress.org plugin reference.
	 * Valid formats are: "plugin-slug" or "plugin-slug@version"
	 *
	 * @param  string $reference  The reference to check.
	 *
	 * @return bool Whether the reference is valid.
	 */
	public static function is_valid( string $reference ): bool {
		// Simple slug
		if ( preg_match( '/^[a-zA-Z0-9_-]+$/', $reference ) ) {
			return true;
		}

		// Slug with version
		if ( preg_match( '/^[a-zA-Z0-9_-]+@(latest|[0-9]+\.[0-9]+(\.[0-9]+)?)$/', $reference ) ) {
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
		if ( $this->version ) {
			return "Plugin: {$this->slug} (version {$this->version})";
		}

		return "Plugin: {$this->slug}";
	}

}
