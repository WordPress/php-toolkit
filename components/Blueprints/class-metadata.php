<?php

namespace WordPress\Blueprints;

/**
 * Container for Blueprint metadata with defaults.
 */
class Metadata {
	/**
	 * @var string
	 */
	public $name;
	/**
	 * @var string
	 */
	public $description;
	/**
	 * @var string
	 */
	public $version;
	/**
	 * @var mixed[]
	 */
	public $authors;
	/**
	 * @var string|null
	 */
	public $author_url;
	/**
	 * @var string|null
	 */
	public $donate_link;
	/**
	 * @var mixed[]
	 */
	public $tags;
	/**
	 * @var string|null
	 */
	public $license;

	/**
	 * Create a BlueprintMetadata object from an array of data.
	 *
	 * @param  array|null  $data  The metadata array from the blueprint
	 *
	 * @return self A new BlueprintMetadata object with data or defaults
	 */
	public static function fromArray( ?array $data ): self {
		if ( $data === null ) {
			return new self(
				'Untitled Blueprint',
				'No description provided'
			);
		}

		$metadata              = new self();
		$metadata->name        = $data['name'] ?? 'Untitled Blueprint';
		$metadata->description = $data['description'] ?? 'No description provided';
		$metadata->version     = $data['version'] ?? '1.0.0';
		$metadata->authors     = $data['authors'] ?? [];
		$metadata->author_url   = $data['authorUrl'] ?? null;
		$metadata->donate_link  = $data['donateLink'] ?? null;
		$metadata->tags        = $data['tags'] ?? [];
		$metadata->license     = $data['license'] ?? null;

		return $metadata;
	}
}
