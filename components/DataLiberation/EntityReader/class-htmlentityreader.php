<?php

namespace WordPress\DataLiberation\EntityReader;

use WordPress\DataLiberation\DataFormatConsumer\BlocksWithMetadata;
use WordPress\DataLiberation\ImportEntity;

/**
 * Converts a single HTML file into a stream of WordPress entities.
 */
class HTMLEntityReader implements EntityReader {

	protected $block_markup;
	protected $metadata;
	protected $entities;
	protected $finished = false;
	protected $post_id;

	public function __construct( BlocksWithMetadata $blocks_with_meta, $post_id ) {
		$this->block_markup = $blocks_with_meta->get_block_markup();
		$this->metadata     = $blocks_with_meta->get_all_metadata();
		$this->post_id      = $post_id;
	}

	public function next_entity() {
		// If we're finished, we're finished.
		if ( $this->finished ) {
			return false;
		}

		// If we've already read some entities, skip to the next one.
		if ( null !== $this->entities ) {
			array_shift( $this->entities );
			if ( 0 === count( $this->entities ) ) {
				$this->finished = true;

				return false;
			}

			return true;
		}

		$post_fields    = array();
		$other_metadata = array();
		foreach ( $this->metadata as $key => $values ) {
			if ( in_array( $key, ImportEntity::POST_FIELDS, true ) ) {
				$post_fields[ $key ] = $values[0];
			} else {
				$other_metadata[ $key ] = $values[0];
			}
		}

		// Yield the post entity.
		$this->entities[] = new ImportEntity(
			'post',
			array_merge(
				$post_fields,
				array(
					'post_id'      => $this->post_id,
					'post_content' => $this->block_markup,
				)
			)
		);

		// Yield all the metadata that don't belong to the post entity.
		foreach ( $other_metadata as $key => $value ) {
			$this->entities[] = new ImportEntity(
				'post_meta',
				array(
					'post_id'    => $this->post_id,
					'meta_key'   => $key,   // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
		}

		return true;
	}

	/**
	 * Returns the current entity.
	 *
	 * @return ImportEntity|false The current entity, or false if there are no entities left.
	 */
	public function get_entity() {
		if ( $this->is_finished() ) {
			return false;
		}

		return $this->entities[0];
	}

	/**
	 * Checks if this reader has finished yet.
	 *
	 * @return bool Whether the reader has finished.
	 */
	public function is_finished(): bool {
		return $this->finished;
	}

	/**
	 * @TODO: Implement this.
	 * @return string
	 */
	public function get_reentrancy_cursor() {
		return '';
	}
}
