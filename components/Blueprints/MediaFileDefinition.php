<?php

namespace WordPress\Blueprints;

use WordPress\Blueprints\DataReference\DataReference;

class MediaFileDefinition {
	/**
	 * @var DataReference
	 */
	public $source;
	/**
	 * @var string|null
	 */
	public $title;
	/**
	 * @var string|null
	 */
	public $description;
	/**
	 * @var string|null
	 */
	public $alt;
	/**
	 * @var string|null
	 */
	public $caption;

	static public function fromArray( array $data ): self {
		$instance              = new self();
		$instance->source      = $data['source'];
		$instance->title       = $data['title'] ?? null;
		$instance->description = $data['description'] ?? null;
		$instance->alt         = $data['alt'] ?? null;
		$instance->caption     = $data['caption'] ?? null;

		return $instance;
	}
}
