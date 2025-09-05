<?php

namespace WordPress\Blueprints\DataReference;

use InvalidArgumentException;

/**
 * Represents a file that is inlined within the Blueprint JSON document.
 */
class InlineFile extends DataReference {
	/**
	 * @var string The filename.
	 */
	protected $filename;

	/**
	 * @var string The content.
	 */
	protected $content;

	/**
	 * Constructor.
	 *
	 * @param  array $definition  The inline file definition containing filename and content.
	 */
	public function __construct( array $definition ) {
		if ( ! isset( $definition['filename'] ) || ! isset( $definition['content'] ) ) {
			throw new InvalidArgumentException( 'Invalid inline file definition' );
		}

		$this->filename = $definition['filename'];
		$this->content  = $definition['content'];
		parent::__construct( $definition );
	}

	/**
	 * Get the filename.
	 *
	 * @return string The filename.
	 */
	public function get_filename(): string {
		return $this->filename;
	}

	/**
	 * Get the content.
	 *
	 * @return string The content.
	 */
	public function get_content(): string {
		return $this->content;
	}

	/**
	 * Check if an array represents a valid inline file.
	 *
	 * @param  array $data  The array to check.
	 *
	 * @return bool Whether the array is valid.
	 */
	public static function is_valid( $data ): bool {
		return is_array( $data ) && isset( $data['filename'] ) && isset( $data['content'] );
	}

	/**
	 * Get a human-readable name for this reference.
	 * Used in the progress tracker.
	 *
	 * @return string The human-readable name.
	 */
	public function get_human_readable_name(): string {
		return 'Inline file: ' . basename( $this->filename );
	}
}
