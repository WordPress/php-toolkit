<?php

namespace WordPress\Git\Model;

class Tree {
	/**
	 * Map of name => TreeEntry instances
	 *
	 * @var array<string,TreeEntry>
	 */
	public $entries = array();

	public function __construct( $entries = array() ) {
		$this->entries = $entries;
	}

	public function has_entry( $name ) {
		return isset( $this->entries[ $name ] );
	}

	/**
	 * Add a tree entry
	 *
	 * @param  TreeEntry $entry  The entry to add
	 */
	public function add_entry( TreeEntry $entry ) {
		$this->entries[ $entry->name ] = $entry;
	}

	/**
	 * Get a tree entry by name
	 *
	 * @param  string $name  The entry name
	 *
	 * @return TreeEntry|null The entry if found, null otherwise
	 */
	public function get_entry( $name ) {
		return isset( $this->entries[ $name ] ) ? $this->entries[ $name ] : null;
	}
}
