<?php

namespace WordPress\Svn;

/**
 * Applies a server-driven tree delta to a working copy.
 *
 * This editor backs both checkouts (the server sends the whole tree)
 * and updates (the server sends only what changed since the reported
 * revision). Incoming file contents are svndiff deltas against the
 * pristine copy, applied with SvnDiffApplier.
 *
 * Local modifications are preserved:
 *
 *  - When the incoming content equals the locally modified content,
 *    the file is left alone and simply stops being "modified".
 *  - Otherwise the local file is kept as-is, the incoming content is
 *    written next to it as `<name>.r<revision>`, and the entry is
 *    marked conflicted. Conflicted files refuse to commit until
 *    SvnClient::resolved() is called.
 *  - Locally modified files deleted on the server are kept on disk as
 *    unversioned files.
 */
class WorkingCopyEditor implements SvnEditor {
	/**
	 * @var SvnWorkingCopy
	 */
	private $working_copy;

	/**
	 * @var int|null
	 */
	private $target_revision;

	/**
	 * In-flight file transmissions, path => state array.
	 *
	 * @var array
	 */
	private $file_states = array();

	/**
	 * Outcome of the drive: lists of relative paths keyed by what
	 * happened to them.
	 *
	 * @var array
	 */
	private $result = array(
		'added'      => array(),
		'updated'    => array(),
		'deleted'    => array(),
		'conflicted' => array(),
		'skipped'    => array(),
	);

	public function __construct( SvnWorkingCopy $working_copy ) {
		$this->working_copy = $working_copy;
	}

	/**
	 * @return array See $result.
	 */
	public function get_result() {
		return $this->result;
	}

	public function set_target_revision( $revision ) {
		$this->target_revision = $revision;
	}

	public function open_root() {
		$filesystem = $this->working_copy->get_filesystem();
		if ( ! $filesystem->is_dir( $this->working_copy->get_root() ) ) {
			$filesystem->mkdir( $this->working_copy->get_root(), array( 'recursive' => true ) );
		}
	}

	public function add_directory( $path ) {
		$path       = svn_normalize_relative_path( $path, false );
		$filesystem = $this->working_copy->get_filesystem();
		$disk_path  = $this->working_copy->get_disk_path( $path );
		if ( $filesystem->is_file( $disk_path ) ) {
			throw new SvnException( "Cannot create directory '{$path}': an unversioned file is in the way." );
		}
		if ( ! $filesystem->is_dir( $disk_path ) ) {
			$filesystem->mkdir( $disk_path, array( 'recursive' => true ) );
		}
		$this->working_copy->set_entry(
			$path,
			array(
				'kind'     => 'dir',
				'revision' => $this->target_revision,
			)
		);
		$this->result['added'][] = $path;
	}

	public function open_directory( $path ) {
		svn_normalize_relative_path( $path, false );
		// Nothing to do – changes arrive through dedicated calls.
	}

	public function change_directory_property( $path, $name, $value ) {
		$path  = svn_normalize_relative_path( $path );
		$entry = $this->working_copy->get_entry( $path );
		if ( null === $entry ) {
			return;
		}
		$properties = isset( $entry['props'] ) ? $entry['props'] : array();
		if ( null === $value ) {
			unset( $properties[ $name ] );
		} else {
			$properties[ $name ] = $value;
		}
		if ( count( $properties ) > 0 ) {
			$entry['props'] = $properties;
		} else {
			unset( $entry['props'] );
		}
		$this->working_copy->set_entry( $path, $entry );
	}

	public function close_directory( $path ) {
		svn_normalize_relative_path( $path );
		// Nothing to do.
	}

	public function add_file( $path ) {
		$path = svn_normalize_relative_path( $path, false );

		$this->file_states[ $path ] = array(
			'is_add'       => true,
			'had_delta'    => false,
			'applier'      => null,
			'prop_changes' => array(),
		);
	}

	public function open_file( $path ) {
		$path = svn_normalize_relative_path( $path, false );
		if ( null === $this->working_copy->get_entry( $path ) ) {
			throw new SvnException( "The server changed '{$path}' which is not part of this working copy." );
		}
		$this->file_states[ $path ] = array(
			'is_add'       => false,
			'had_delta'    => false,
			'applier'      => null,
			'prop_changes' => array(),
		);
	}

	public function change_file_property( $path, $name, $value ) {
		$path = svn_normalize_relative_path( $path, false );
		$this->file_states[ $path ]['prop_changes'][ $name ] = $value;
	}

	public function apply_textdelta( $path, $base_checksum ) {
		$path  = svn_normalize_relative_path( $path, false );
		$state = &$this->file_states[ $path ];
		$base  = '';
		if ( ! $state['is_add'] ) {
			$entry = $this->working_copy->get_entry( $path );
			if ( isset( $entry['checksum'] ) ) {
				$base = $this->working_copy->read_pristine( $entry['checksum'] );
			}
		}
		if ( null !== $base_checksum && md5( $base ) !== $base_checksum ) {
			throw new SvnException( "Checksum mismatch on the delta base of '{$path}': the working copy is corrupted." );
		}
		$state['had_delta'] = true;
		$state['applier']   = new SvnDiffApplier( $base );
	}

	public function write_textdelta_chunk( $path, $svndiff_bytes ) {
		$path = svn_normalize_relative_path( $path, false );
		$this->file_states[ $path ]['applier']->append_bytes( $svndiff_bytes );
	}

	public function textdelta_end( $path ) {
		$path = svn_normalize_relative_path( $path, false );
		$this->file_states[ $path ]['applier']->finish();
	}

	public function close_file( $path, $text_checksum ) {
		$path  = svn_normalize_relative_path( $path, false );
		$state = $this->file_states[ $path ];
		unset( $this->file_states[ $path ] );

		$working_copy = $this->working_copy;
		$entry        = $working_copy->get_entry( $path );
		$properties   = ( null !== $entry && isset( $entry['props'] ) ) ? $entry['props'] : array();

		// Resolve the new repository-normal-form contents.
		if ( $state['had_delta'] ) {
			$new_contents = $state['applier']->get_target();
		} elseif ( null !== $entry && isset( $entry['checksum'] ) ) {
			$new_contents = $working_copy->read_pristine( $entry['checksum'] );
		} else {
			$new_contents = '';
		}
		if ( null !== $text_checksum && md5( $new_contents ) !== $text_checksum ) {
			throw new SvnException( "Checksum mismatch on '{$path}': expected {$text_checksum}, got " . md5( $new_contents ) . '.' );
		}

		// Resolve the new properties.
		$new_properties = $properties;
		foreach ( $state['prop_changes'] as $name => $value ) {
			if ( null === $value ) {
				unset( $new_properties[ $name ] );
			} else {
				$new_properties[ $name ] = $value;
			}
		}

		$new_entry = array(
			'kind'     => 'file',
			'revision' => $this->target_revision,
			'checksum' => $working_copy->store_pristine( $new_contents ),
		);
		if ( count( $new_properties ) > 0 ) {
			$new_entry['props'] = $new_properties;
		}

		$filesystem  = $working_copy->get_filesystem();
		$disk_path   = $working_copy->get_disk_path( $path );
		$disk_exists = $filesystem->is_file( $disk_path );

		// Compare normalized forms so that eol-style translation never
		// reads as a content difference.
		$local_contents      = null;
		$normalized_incoming = SvnWorkingCopy::translate_from_disk( $new_contents, $new_properties );
		if ( $disk_exists ) {
			$local_contents = SvnWorkingCopy::translate_from_disk(
				$filesystem->get_contents( $disk_path ),
				$new_properties
			);
		}

		if ( $state['is_add'] ) {
			if ( $disk_exists && $local_contents !== $normalized_incoming ) {
				// An unversioned file with different content is in the way.
				// Keep it and store the incoming version next to it.
				$working_copy->write_working_file( $path . '.r' . $this->target_revision, $new_contents, $new_properties );
				$new_entry['conflict']        = true;
				$new_entry['conflict_file']   = $path . '.r' . $this->target_revision;
				$this->result['conflicted'][] = $path;
			} else {
				if ( ! $disk_exists ) {
					$working_copy->write_working_file( $path, $new_contents, $new_properties );
				}
				$this->result['added'][] = $path;
			}
			$working_copy->set_entry( $path, $new_entry );

			return;
		}

		$locally_modified = $disk_exists && $working_copy->is_file_modified( $path, $entry );

		if ( ! $locally_modified ) {
			$working_copy->write_working_file( $path, $new_contents, $new_properties );
			$this->result['updated'][] = $path;
		} elseif ( $local_contents === $normalized_incoming ) {
			// The local edit and the incoming change are identical.
			$this->result['updated'][] = $path;
		} elseif ( ! $state['had_delta'] ) {
			// Only properties changed; local content edits can stay.
			$this->result['updated'][] = $path;
		} else {
			// Both sides changed the file. Keep the local version, store
			// the incoming one as <name>.r<revision>, and flag the conflict.
			$working_copy->write_working_file( $path . '.r' . $this->target_revision, $new_contents, $new_properties );
			$new_entry['conflict']        = true;
			$new_entry['conflict_file']   = $path . '.r' . $this->target_revision;
			$this->result['conflicted'][] = $path;
		}

		$working_copy->set_entry( $path, $new_entry );
	}

	public function delete_entry( $path ) {
		$path         = svn_normalize_relative_path( $path, false );
		$working_copy = $this->working_copy;
		$filesystem   = $working_copy->get_filesystem();
		$entry        = $working_copy->get_entry( $path );
		if ( null === $entry ) {
			return;
		}

		if ( 'file' === $entry['kind'] ) {
			$this->delete_file_entry( $path, $entry );

			return;
		}

		// Delete a directory: remove every versioned child, then the
		// directory itself when nothing unversioned remains.
		$child_paths = $working_copy->get_entry_paths_under( $path );
		rsort( $child_paths );
		foreach ( $child_paths as $child_path ) {
			$child_entry = $working_copy->get_entry( $child_path );
			if ( 'file' === $child_entry['kind'] ) {
				$this->delete_file_entry( $child_path, $child_entry );
			} else {
				$this->delete_directory_if_empty( $child_path );
				$working_copy->remove_entry( $child_path );
			}
		}
		$this->delete_directory_if_empty( $path );
		$working_copy->remove_entry( $path );
		$this->result['deleted'][] = $path;
	}

	public function close_edit() {
		if ( count( $this->file_states ) > 0 ) {
			throw new SvnException( 'The server ended the update while file transmissions were still open.' );
		}

		// All surviving entries are now at the target revision; scheduled
		// local changes keep their pending state.
		foreach ( $this->working_copy->get_entries() as $path => $entry ) {
			if ( isset( $entry['schedule'] ) ) {
				continue;
			}
			$entry['revision'] = $this->target_revision;
			$this->working_copy->set_entry( $path, $entry );
		}
		$this->working_copy->set_revision( $this->target_revision );
		$this->working_copy->save();
	}

	public function abort_edit() {
		// Persist what was applied so far: every recorded entry matches
		// what is actually on disk, which keeps the working copy usable
		// even though it now holds a mix of revisions.
		$this->working_copy->save();
	}

	private function delete_file_entry( $path, $entry ) {
		$working_copy = $this->working_copy;
		$filesystem   = $working_copy->get_filesystem();
		$disk_path    = $working_copy->get_disk_path( $path );

		if ( $filesystem->is_file( $disk_path ) && $working_copy->is_file_modified( $path, $entry ) ) {
			// Keep locally modified files on disk as unversioned files.
			$this->result['skipped'][] = $path;
		} elseif ( $filesystem->is_file( $disk_path ) ) {
			$filesystem->rm( $disk_path );
			$this->result['deleted'][] = $path;
		}
		$working_copy->remove_entry( $path );
	}

	private function delete_directory_if_empty( $path ) {
		$filesystem = $this->working_copy->get_filesystem();
		$disk_path  = $this->working_copy->get_disk_path( $path );
		if ( $filesystem->is_dir( $disk_path ) && 0 === count( $filesystem->ls( $disk_path ) ) ) {
			$filesystem->rmdir( $disk_path, array() );
		}
	}
}
