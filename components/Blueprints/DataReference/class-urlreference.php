<?php

namespace WordPress\Blueprints\DataReference;

use WordPress\DataLiberation\URL\WPURL;

/**
 * Represents a HTTP or HTTPS URL reference.
 */
class URLReference extends DataReference {
	/**
	 * @var string The URL.
	 */
	protected $url;

	/**
	 * Constructor.
	 *
	 * @param  string $url  The URL.
	 */
	public function __construct( string $url ) {
		$this->url = $url;
		parent::__construct( $url );
	}

	/**
	 * Get the URL.
	 *
	 * @return string The URL.
	 */
	public function get_url(): string {
		return $this->url;
	}

	public function get_filename(): string {
		return basename( WPURL::parse( $this->url )->pathname );
	}

	/**
	 * Check if a string is a valid URL reference.
	 *
	 * @param  string $url  The URL to check.
	 *
	 * @return bool Whether the URL is valid.
	 */
	public static function is_valid( $url ): bool {
		return is_string( $url ) && ( strpos( $url, 'http://' ) === 0 || strpos( $url, 'https://' ) === 0 );
	}

	/**
	 * Get a human-readable name for this reference.
	 * Used in the progress tracker.
	 *
	 * @return string The human-readable name.
	 */
	public function get_human_readable_name(): string {
		return $this->url;
	}

}
