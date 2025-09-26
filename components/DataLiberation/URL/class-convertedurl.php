<?php

namespace WordPress\DataLiberation\URL;

use Rowbot\URL\URL;

/**
 * Value object returned by WPURL::replace_base_url().
 *
 * - Cast to string to get the updated URL as a string.
 * - When the original URL was relative, casting returns a relative string against
 *   the new base.
 */
class ConvertedUrl {

	/** @var URL */
	private $url;

	/** @var string */
	private $string;

	/** @var string|null */
	private $relative_string;

	/** @var bool */
	private $was_relative;

	public function __construct( URL $url, string $string, ?string $relative_string, bool $was_relative ) {
		$this->url             = $url;
		$this->string          = $string;
		$this->relative_string = $relative_string;
		$this->was_relative    = $was_relative;
	}

	/**
	 * Returns the updated URL string. If the original was relative, returns a relative string.
	 */
	public function __toString(): string {
		if ( $this->was_relative ) {
			return $this->getRelativeString();
		}
		return $this->getString();
	}

	/**
	 * The parsed updated URL object.
	 */
	public function getConvertedUrl(): URL {
		return $this->url;
	}

	/**
	 * Whether the input URL was originally relative.
	 */
	public function wasRelative(): bool {
		return $this->was_relative;
	}

	/**
	 * Returns the absolute updated URL string.
	 */
	public function getString(): string {
		return $this->string;
	}

	/**
	 * Returns the relative string if available, otherwise constructs it from the URL.
	 */
	public function getRelativeString(): ?string {
		if ( null !== $this->relative_string ) {
			return $this->relative_string;
		}

		$relative = $this->url->pathname;
		if ( '' !== $this->url->search ) {
			$relative .= $this->url->search;
		}
		if ( '' !== $this->url->hash ) {
			$relative .= $this->url->hash;
		}

		return $relative;
	}
}
