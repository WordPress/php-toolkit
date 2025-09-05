<?php

namespace WordPress\Git;

use WordPress\Git\Model\TreeEntry;

function get_all_descendant_oids_in_tree( GitRepository $repository, $tree_oid, $options = array() ) {
	$oids  = array();
	$trees = array( $tree_oid );

	$object_types = $options['object_types'] ?? null;

	while ( ! empty( $trees ) ) {
		$tree_hash = array_pop( $trees );
		$tree      = $repository->read_object( $tree_hash )->as_tree();
		foreach ( $tree->entries as $entry ) {
			if ( TreeEntry::FILE_MODE_DIRECTORY === $entry->get_mode_bucket() ) {
				$trees[] = $entry->hash;
			}

			if ( null === $object_types || in_array( $entry->get_mode_bucket(), $object_types ) ) {
				$oids[ $entry->hash ] = true;
			}
		}
	}

	return array_keys( $oids );
}
