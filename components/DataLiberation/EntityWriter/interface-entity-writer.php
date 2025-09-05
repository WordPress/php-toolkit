<?php

namespace WordPress\DataLiberation\EntityWriter;

use WordPress\DataLiberation\ImportEntity;

/**
 * The Entity Writer sends content to a destination.
 */
interface EntityWriter {

	/**
	 * Writes an entity to the destination.
	 *
	 * @param  ImportEntity $entity  The entity to write.
	 */
	public function append_entity( ImportEntity $entity );

	/**
	 * Returns a cursor position that can be used to resume processing later.
	 *
	 * This allows for processing large imports in chunks without losing your place.
	 * Not all readers support this yet.
	 *
	 * @TODO: Define a general interface for entity readers.
	 * @return string Position marker for resuming later
	 */
	public function close_writing();

	/**
	 * Returns a cursor position that can be used to resume writing later.
	 *
	 * This allows for processing large imports in chunks without losing the context,
	 * e.g. when writing to a WXR file it's important to know which tags are open.
	 *
	 * @return string Position marker for resuming later
	 */
	public function get_reentrancy_cursor();
}
