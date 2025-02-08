<?php

namespace WordPress\Git;

use WordPress\Filesystem\Filesystem;
use WordPress\Git\Diff\MergeEngine;
use WordPress\Git\Model\Commit;
use WordPress\Git\Model\Tree;
use WordPress\Git\Model\TreeEntry;
use WordPress\Git\Protocol\GitProtocolEncoderPipe;

use function WordPress\Filesystem\wp_canonicalize_path;
use function WordPress\Filesystem\wp_join_paths;

class GitRepository {

	/**
	 * The filesystem root where the repository index files are stored.
	 *
	 * @var Filesystem
	 */
	private $fs;

	/**
	 * Structured data parsed from the repository `config` file.
	 *
	 * @var array
	 */
	private $parsed_config;

	/**
	 * @var MergeEngine
	 */
	private $diff_engine;

	private const DELETE_PLACEHOLDER = 'DELETE_PLACEHOLDER';

	public function __construct(
		Filesystem $fs,
		$options = array()
	) {
		$this->fs          = $fs;
		$this->diff_engine = $options['diff_engine'] ?? new MergeEngine();
		$this->initialize_filesystem( $options );
	}

	public function get_object_storage_filesystem() {
		return $this->fs;
	}

	private function initialize_filesystem( $options = array() ) {
		$paths = array(
			'objects',
			'refs',
			'refs/heads',
			'refs/remotes',
		);
		foreach ( $paths as $path ) {
			if ( ! $this->fs->is_dir( $path ) ) {
				$this->fs->mkdir( $path );
			}
		}
		if ( ! $this->fs->is_file( 'HEAD' ) ) {
			// Initialize the repository with a default branch
			$default_branch = $options['default_branch'] ?? 'trunk';
			$this->set_ref_head( 'HEAD', "ref: refs/heads/{$default_branch}\n" );
			$this->set_ref_head( "refs/heads/{$default_branch}", Commit::NULL_HASH );
		}
	}

	public function add_remote( $name, $url ) {
		$this->set_config_value( array( 'remote', $name, 'url' ), $url );
		$path = wp_join_paths( 'refs/remotes', $name );
		if ( ! $this->fs->is_dir( $path ) ) {
			$this->fs->mkdir( $path );
		}
		// @TODO: support fetch option
		// $this->set_config_value(['remote', $name, 'fetch'], '+refs/heads/*:refs/remotes/' . $name . '/*');
	}

	public function get_remote( $name ) {
		$this->parse_config();
		$key = 'remote "' . $name . '"';
		return $this->parsed_config[ $key ] ?? null;
	}

    public function get_remote_client( $name = 'origin', $options = array() ) {
        $remote_url = $this->get_config_value( array( 'remote', $name, 'url' ) );
        return new GitRemote( $this, $name, array_merge( $options, array( 'url' => $remote_url ) ) );
    }

	public function set_config_value( $key, $value ) {
		$this->parse_config();
		list($section, $key) = $this->parse_config_key( $key );

		if ( ! isset( $this->parsed_config[ $section ] ) ) {
			$this->parsed_config[ $section ] = array();
		}
		$this->parsed_config[ $section ][ $key ] = $value;
		$this->write_config();
	}

	public function get_config_value( $key ) {
		$this->parse_config();
		list($section, $key) = $this->parse_config_key( $key );
		return $this->parsed_config[ $section ][ $key ] ?? null;
	}

	private function parse_config_key( $key ) {
		if ( is_string( $key ) ) {
			$key = explode( '.', $key );
		}
		$section_name   = array_shift( $key );
		$trailing_key   = array_pop( $key );
		$section_subkey = implode( '.', $key );

		$section = $section_name;
		if ( $section_subkey ) {
			$section .= ' "' . $section_subkey . '"';
		}
		return array( $section, $trailing_key );
	}

	private function parse_config() {
		if ( ! $this->parsed_config ) {
			if ( ! $this->fs->is_file( 'config' ) ) {
				$this->parsed_config = array();
				return;
			}
			$this->parsed_config = parse_ini_string( $this->fs->get_contents( 'config' ), true, INI_SCANNER_RAW );
		}
	}

	private function write_config() {
		$this->parse_config();
		$lines = array();
		foreach ( $this->parsed_config as $section => $key_value_pairs ) {
			$lines[] = "[{$section}]";
			foreach ( $key_value_pairs as $key => $value ) {
				$lines[] = "    {$key} = {$value}";
			}
		}
		$this->fs->put_contents( 'config', implode( "\n", $lines ) );
	}

	public function read_object( $oid ) {
		$object_path = $this->get_storage_path( $oid );
		if ( ! $this->fs->is_file( $object_path ) ) {
			throw new GitException(
				sprintf(
					'Object %s not found',
					$oid
				)
			);
		}

		$producer = new GitObjectDecoder(
			$this->fs->open_read_stream( $this->get_storage_path( $oid ) )
		);
		$producer->read_header();
		return $producer;
	}

	public function read_object_by_path( $path, $commit_hash = null ) {
		$oid = $this->find_hash_by_path( $path, $commit_hash );
		return $this->read_object( $oid );
	}

	public function find_hash_by_path( $path, $commit_hash = null ) {
        $commit_hash = $commit_hash ?? $this->get_ref_head( 'HEAD' );
        $commit        = $this->read_object( $commit_hash )->as_commit();
        $root_tree_oid = $commit->tree;

		if ( $root_tree_oid === null ) {
			throw new GitPathDoesNotExistException( sprintf( 'Could not resolve root tree to lookup path: %s', $path ) );
		}

		$path     = trim( $path, '/' );
		$next_oid = $root_tree_oid;

		if ( ! empty( $path ) ) {
			$path_segments = explode( '/', $path );
			foreach ( $path_segments as $segment ) {
				$tree = $this->read_object( $next_oid )->as_tree();
				if ( ! $tree->has_entry( $segment ) ) {
					throw new GitPathDoesNotExistException( sprintf( 'Path not found: %s', $path ) );
				}
				$next_oid = $tree->get_entry( $segment )->hash;
			}
		}

		return $next_oid;
	}

	public function new_object_open_write_stream( $object_type_name, $object_length ) {
		return new GitObjectEncoder( $this, $object_type_name, $object_length );
	}

	public function has_object( $oid ) {
		return $this->fs->is_file( $this->get_storage_path( $oid ) );
	}

	public function has_blobs_from_commit( $commit_hash, $path='/' ) {
		if(!$this->has_object($commit_hash)) {
            return false;
        }

        try{
            $tree = $this->find_hash_by_path($path, $commit_hash);
        } catch(GitPathDoesNotExistException $e) {
            return false;
        }
        
        $stack = [$tree];
        while(!empty($stack)) {
            $hash = array_pop($stack);
            if(!$this->has_object($hash)) {
                return false;
            }
            $object = $this->read_object($hash);
            if($object->get_object_type_name() === 'tree') {
                foreach($object->as_tree()->entries as $entry) {
                    $stack[] = $entry->hash;
                }
            }
        }
        return true;
	}

	public function find_objects_added_in( $new_commit_hash, $old_commit_hash = Commit::NULL_HASH, $options = array() ) {
		$new_commit       = $this->read_object( $new_commit_hash )->as_commit();
		$old_tree_hash    = Commit::NULL_HASH;
		$old_objects_oids = array();
		if ( ! Commit::is_null_hash( $old_commit_hash ) ) {
			$old_commit_repository                = $options['old_commit_repository'] ?? $this;
			$old_commit                           = $old_commit_repository->read_object( $old_commit_hash )->as_commit();
			$old_tree_hash                        = $old_commit->tree;
			$old_objects_oids                     = array_flip(
				get_all_descendant_oids_in_tree( $old_commit_repository, $old_tree_hash )
			);
			$old_objects_oids[ $old_commit_hash ] = true;
		}

		$new_objects_oids = array();
		// Optimization – don't process the same tree more than once.
		$processed_trees = array();

		while ( $new_commit_hash !== $old_commit_hash && ! Commit::is_null_hash( $new_commit_hash ) ) {
			$new_commit                           = $this->read_object( $new_commit_hash )->as_commit();
			$new_objects_oids[ $new_commit_hash ] = true;
			$tree_oid                             = $new_commit->tree;
			$new_objects_oids[ $tree_oid ]        = true;
			if ( ! isset( $processed_trees[ $tree_oid ] ) ) {
				$descendants = get_all_descendant_oids_in_tree( $this, $tree_oid );
				foreach ( $descendants as $descendant ) {
					$new_objects_oids[ $descendant ] = true;
				}
			}
			$processed_trees[ $tree_oid ] = true;
			$new_commit_hash              = $new_commit->get_first_parent_hash();
		}

		$diff = array_diff_key( $new_objects_oids, $old_objects_oids );
		return array_keys( $diff );
	}

	public function set_ref_head( $ref, $oid ) {
		$path = $this->resolve_ref_file_path( $ref );
		return $this->fs->put_contents( $path, $oid );
	}

	public function delete_ref( $ref ) {
		$path = $this->resolve_ref_file_path( $ref );
		return $this->fs->rm( $path );
	}

    public function checkout( $ref ) {
        if(!$this->has_object($ref)) {
            // Symref
            $ref = 'ref: ' . $ref;
        }
        $this->set_ref_head('HEAD', $ref);
    }

    public function get_current_branch_name() {
        return $this->get_ref_head( 'HEAD', array( 'follow_symrefs' => false ) );
    }

	public function get_ref_head( $ref = 'HEAD', $options = array() ) {
		while ( true ) {
			if ( $this->has_object( $ref ) ) {
				return $ref;
			}
			$path = $this->resolve_ref_file_path( $ref );
			if ( ! $path ) {
				throw new GitException( 'Failed to resolve ref file path: ' . $ref );
			}
			if ( ! $this->fs->is_file( $path ) ) {
				throw new GitException( 'Ref file not found: ' . $path );
			}
			$ref = trim( $this->fs->get_contents( $path ) );
			if ( str_starts_with( $ref, 'ref: ' ) && ( $options['follow_symrefs'] ?? true ) ) {
				continue;
			}
			return $ref;
		}
	}

	private function resolve_ref_file_path( $ref ) {
		$ref = trim( $ref );
		if ( str_starts_with( $ref, 'ref: ' ) ) {
			$ref = trim( substr( $ref, 5 ) );
		}
		if (
			str_contains( $ref, '/' ) &&
			! str_starts_with( $ref, 'refs/heads/' ) &&
			! str_starts_with( $ref, 'refs/remotes/' )
		) {
			_doing_it_wrong( __METHOD__, 'Invalid ref name: ' . $ref, '1.0.0' );
			return false;
		}
		if ( str_contains( $ref, '../' ) ) {
			_doing_it_wrong( __METHOD__, 'Invalid ref name: ' . $ref, '1.0.0' );
			return false;
		}

		// Make sure all the directories leading up to the ref exist
		$parent_path = dirname( $ref );
		if ( ! $this->fs->exists( $parent_path ) ) {
			$this->fs->mkdir( $parent_path, array( 'recursive' => true ) );
		}

		return $ref;
	}

	public function branch_exists( $ref ) {
		$path = $this->resolve_ref_file_path( $ref );
		return $path && $this->fs->is_file( $path );
	}

	/**
	 * Shorthand for adding an object to the repository.
	 */
	public function add_object( $type_name, $content ) {
		$object_writer = $this->new_object_open_write_stream( $type_name, strlen( $content ) );
		$object_writer->append_bytes( $content );
		$object_writer->close_writing();
		return $object_writer->get_hash();
	}

	public function get_storage_path( $oid ) {
		return 'objects/' . $oid[0] . $oid[1] . '/' . substr( $oid, 2 );
	}

	/**
	 * Merge two branches.
	 *
     * @TODO: Sparse merge that only processes specific paths
	 * @TODO: Implement a streaming merge. The current implementation buffers
	 *        everything into memory and will fail for large merges.
	 * @TODO: Do not change the HEAD ref.
	 *
	 * @param string $ref The branch to merge.
     * @param array $options An associative array of options. {
     *     @type string $path The path to merge files at. The other paths will be ignored.
     * }
	 * @return string The hash of the merge commit.
	 */
	public function merge( $ref, $options = array() ) {
        $path = $options['path'] ?? '/';

		$commit_hash1 = $this->get_ref_head( 'HEAD' );
		$commit_hash2 = $this->get_ref_head( $ref );

		$common_ancestor_commit_hash = $this->find_first_common_ancestor( $commit_hash1, $commit_hash2 );
		$current_branch_diff_root    = $this->diff_commits( $commit_hash1, $common_ancestor_commit_hash, $path );
		$merged_branch_diff_root     = $this->diff_commits( $commit_hash2, $common_ancestor_commit_hash, $path );

		$tree_stack = array( array( $merged_branch_diff_root, $current_branch_diff_root, $path ) );
		$updates    = array();
		$deletes    = array();
		while ( ! empty( $tree_stack ) ) {
			list($merged_branch_diff, $current_branch_diff, $parent_path) = array_pop( $tree_stack );
			foreach ( $merged_branch_diff as $name => $merged_entry ) {
				$path = wp_join_paths( $parent_path, $name );
				if ( $merged_entry === self::DELETE_PLACEHOLDER ) {
					$deletes[] = $path;
					continue;
				}
				$current_entry = $current_branch_diff[ $name ] ?? null;
				$is_text_diff  = is_array( $merged_entry->content ) && isset( $merged_entry->content['type'] ) && $merged_entry->content['type'] === 'text_diff';
				if ( $is_text_diff ) {
					$current_content = $this->read_object_by_path( $path, $common_ancestor_commit_hash )->consume_all();
					if ( ! $current_entry ) {
						$updates[ $path ] = $this->diff_engine->apply_text_diff(
							$current_content,
							$merged_entry->content['diff']
						);
						continue;
					}
					$text_diffs       = $this->diff_engine->three_way_merge_blob(
						$current_entry->content['diff'],
						$merged_entry->content['diff']
					);
					$merged_content   = $this->diff_engine->apply_text_diff(
						$current_content,
						$text_diffs
					);
					$updates[ $path ] = $merged_content;
				} elseif ( is_array( $merged_entry->content ) ) {
					$tree_stack[] = array(
						$merged_entry->content,
						$current_entry !== null ? $current_entry->content : array(),
						$path,
					);
				}
			}
		}

		return $this->commit(
			array(
				'message' => 'Merge commit ' . $commit_hash2 . ' into ' . $commit_hash1,
				'updates' => $updates,
				'deletes' => $deletes,
                'parents' => [
                    $commit_hash1,
                    $commit_hash2,
                ]
			)
		);
	}

	/**
	 * Find the common ancestor of two references.
	 *
	 * TODO: Support commits with multiple parents.
	 *
	 * @param string $ref1 The first reference.
	 * @param string $ref2 The second reference.
	 * @return string The common ancestor hash.
	 */
	public function find_first_common_ancestor( $commit_hash1, $commit_hash2 ) {
		// If both refs point to the same commit, return it immediately.
		if ( $commit_hash1 === $commit_hash2 ) {
			return $commit_hash1;
		}

		// Use two pointers to traverse the commit history of both refs.
		$visited = array();
		while ( ! Commit::is_null_hash( $commit_hash1 ) || ! Commit::is_null_hash( $commit_hash2 ) ) {
			if ( ! Commit::is_null_hash( $commit_hash1 ) ) {
				if ( isset( $visited[ $commit_hash1 ] ) ) {
					return $commit_hash1;
				}
				$visited[ $commit_hash1 ] = true;
				$commit1                  = $this->read_object( $commit_hash1 )->as_commit();
				$commit_hash1             = $commit1->get_first_parent_hash();
			}

			if ( ! Commit::is_null_hash( $commit_hash2 ) ) {
				if ( isset( $visited[ $commit_hash2 ] ) ) {
					return $commit_hash2;
				}
				$visited[ $commit_hash2 ] = true;
				$commit2                  = $this->read_object( $commit_hash2 )->as_commit();
				$commit_hash2             = $commit2->get_first_parent_hash();
			}
		}

		// No common ancestor found.
		throw new GitException( 'No common ancestor found for ' . $commit_hash1 . ' and ' . $commit_hash2 );
	}

    public function get_nth_ancestor_hash($n, $commit_hash=null) {
        $commit_hash = $options['commit_hash'] ?? $this->get_ref_head('HEAD');

        for($i = 0; $i < $n; $i++) {
            $commit_hash = $this->read_object($commit_hash)->as_commit()->parents[0];
        }

        return $commit_hash;
    }

    /**
     * Returns parents of the specified commit.
     * 
     * @return array A list of parent commits hashes.
     */
    public function get_ancestors_hashes($options = array()) {
        $commit_hash = $options['commit_hash'] ?? $this->get_ref_head('HEAD');
        $on_missing = $options['on_missing'] ?? 'throw'; // throw | return-early
        $limit = $options['count'] ?? -1;

		$found_parents = array();
        $enqueued_parents = array( $commit_hash );
		while ( ! empty ( $enqueued_parents ) ) {
            $next_parent_hash = array_pop( $enqueued_parents );
			if ( Commit::is_null_hash( $next_parent_hash ) ) {
                continue;
            }

            if ( ! $this->has_object( $next_parent_hash ) ) {
                if($on_missing === 'throw') {
                    throw new GitException('Parent commit of ' . $next_parent_hash . ' not found.');
                } else {
                    continue;
                }
            }

            $parents = $this->read_object( $next_parent_hash )->as_commit()->parents;

            $found_parents = array_merge(
                $found_parents,
                $parents
            );

            $enqueued_parents = array_merge(
                $enqueued_parents,
                $parents
            );

            if($limit !== -1 && count($found_parents) >= $limit) {
                break;
            }
		}

        return $found_parents;
    }


	/**
	 * @TODO: Don't commit without a "force" option if the
	 *        changeset didn't actually change the root tree oid.
	 */
	public function commit( $options = array() ) {
		// First process all blob updates
		$updates    = $options['updates'] ?? array();
		$deletes    = $options['deletes'] ?? array();
		$move_trees = $options['move_trees'] ?? array();

		// Track which trees need updating
		$changed_trees = array(
			'/' => new Tree(),
		);

		// Process blob updates
		foreach ( $updates as $path => $content ) {
			$path     = '/' . ltrim( $path, '/' );
			$blob_oid = $this->add_object( 'blob', $content );
			$this->mark_tree_path_changed( $changed_trees, dirname( $path ) );
			$basename = basename( $path );
			if ( $basename === '' ) {
				throw new GitException( 'Cannot commit a file with an empty filename' );
			}
			$changed_trees[ dirname( $path ) ]->entries[ basename( $path ) ] = new TreeEntry(
				array(
					'name' => $basename,
					'mode' => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
					'hash' => $blob_oid,
				)
			);
		}

		// Process deletes
		foreach ( $deletes as $path ) {
			$path = '/' . ltrim( $path, '/' );
			if ( ! $this->read_object_by_path( dirname( $path ) ) ) {
				_doing_it_wrong( __METHOD__, 'File not found in HEAD: ' . $path, '1.0.0' );
				return false;
			}
			$this->mark_tree_path_changed( $changed_trees, dirname( $path ) );
			$changed_trees[ dirname( $path ) ]->entries[ basename( $path ) ] = self::DELETE_PLACEHOLDER;
		}

		// Process tree moves
		foreach ( $move_trees as $old_path => $new_path ) {
			$old_path = '/' . ltrim( $old_path, '/' );
			$new_path = '/' . ltrim( $new_path, '/' );
			if ( ! $this->read_object_by_path( $old_path ) ) {
				_doing_it_wrong( __METHOD__, 'Path not found in HEAD: ' . $old_path, '1.0.0' );
				return false;
			}
			$this->mark_tree_path_changed( $changed_trees, dirname( $old_path ) );
			$this->mark_tree_path_changed( $changed_trees, dirname( $new_path ) );

			$changed_trees[ dirname( $old_path ) ]->entries[ basename( $old_path ) ] = self::DELETE_PLACEHOLDER;
			$new_basename = basename( $new_path );
			if ( $new_basename === '' ) {
				throw new GitException( 'Cannot rename a file to an empty filename' );
			}
			$changed_trees[ dirname( $new_path ) ]->entries[ $new_basename ] = new TreeEntry(
				array(
					'name' => $new_basename,
					'mode' => TreeEntry::FILE_MODE_DIRECTORY,
					'hash' => $this->find_hash_by_path( $old_path ),
				)
			);
		}

		$is_amend = isset( $options['amend'] ) && $options['amend'];

		// Process trees bottom-up recursively
		$root_tree_oid = $this->commit_tree( '/', $changed_trees );
		$head          = $this->get_ref_head( 'HEAD' );
		if ( $this->has_object( $head ) ) {
			$current_commit = $this->read_object( $head )->as_commit();
			$old_tree_hash  = $current_commit->tree;
		} else {
			$old_tree_hash = Commit::NULL_HASH;
		}

		if (
			$root_tree_oid === $old_tree_hash &&
			! $is_amend
		) {
			// Nothing has changed, skip creating a new empty commit.
			return $current_commit->hash;
		}

		// Create a new commit object
        $options['tree'] = $root_tree_oid;
		if ( ! isset($options['parents']) && $this->get_ref_head( 'HEAD' ) ) {
			$options['parents'] = [ $head ];
		}

        if ( $is_amend && ! $options['message'] ) {
            $options['message'] = $this->read_object( $head )->as_commit()->message;
        }
		$commit_oid = $this->add_object(
			'commit',
			$this->create_commit_string( $options )
		);

		// Update HEAD
		$head_ref = $this->get_ref_head( 'HEAD', array( 'follow_symrefs' => false ) );
		if ( $this->branch_exists( $head_ref ) ) {
			$this->set_ref_head( $head_ref, $commit_oid );
		} else {
            $this->set_ref_head( 'HEAD', $commit_oid );
        }

		if ( isset( $options['amend'] ) && $options['amend'] && isset( $options['parents'] ) ) {
			$commit_oid = $this->squash( $commit_oid, $options['parents'][0] );
		}

		return $commit_oid;
	}

	public function diff_commits( $previous_commit_hash, $current_commit_hash, $path='/' ) {
        $current_tree_oid = $this->find_hash_by_path($path, $current_commit_hash);
        $previous_tree_oid = $this->find_hash_by_path($path, $previous_commit_hash);

		return $this->diff_trees( $current_tree_oid, $previous_tree_oid );
	}

	public function diff_trees( $current_oid, $previous_oid ) {
		$current_tree  = $this->read_object( $current_oid )->as_tree();
		$previous_tree = $this->read_object( $previous_oid )->as_tree();

		$diff = array();
		foreach ( $current_tree->entries as $name => $current_entry ) {
			if ( ! isset( $previous_tree->entries[ $name ] ) ) {
				$diff[ $name ] = $current_entry;
				continue;
			}
			$previous_entry = $previous_tree->entries[ $name ];
			if ( $current_entry->hash === $previous_entry->hash ) {
				continue;
			}

			if ( $current_entry->mode !== $previous_entry->mode ) {
				/*
				 * @TODO: Account for a scenario when just one text line changes and
				 *        also the mode changed from executable to non-executable.
				 *        We could do a text diff in that case.
				 */
				$diff[ $name ] = $current_entry;
				continue;
			}

			$diff[ $name ] = new TreeEntry(
				array(
					'name' => $name,
					'mode' => 'diff',
					'hash' => $current_entry->hash,
				)
			);

			if ( $current_entry->mode === TreeEntry::FILE_MODE_DIRECTORY ) {
				$diff[ $name ]->content = $this->diff_trees( $current_entry->hash, $previous_entry->hash );
			} else {
				$diff[ $name ]->content = $this->diff_blobs(
					$current_entry,
					$previous_entry
				);
			}
		}

		foreach ( $previous_tree->entries as $name => $previous_entry ) {
			if ( ! isset( $current_tree->entries[ $name ] ) ) {
				$diff[ $name ] = self::DELETE_PLACEHOLDER;
			}
		}
		return $diff;
	}

	public function diff_blobs( $current_blob_entry, $previous_blob_entry ) {
		// @TODO: Support streaming diffs for large files
		$current_blob           = $this->read_object( $current_blob_entry->hash );
		$current_blob_contents  = $current_blob->consume_all();
		$current_blob_is_binary = $this->guess_if_binary_blob( $current_blob_entry->name, $current_blob_contents );

		$previous_blob           = $this->read_object( $previous_blob_entry->hash );
		$previous_blob_contents  = $previous_blob->consume_all();
		$previous_blob_is_binary = $this->guess_if_binary_blob( $previous_blob_entry->name, $previous_blob_contents );

		if ( $current_blob_is_binary && $previous_blob_is_binary ) {
			return array( 'type' => 'binary' );
		} elseif ( $current_blob_is_binary ^ $previous_blob_is_binary ) {
			return array( 'type' => 'completely_new_blob' );
		} else {
			return array(
				'type' => 'text_diff',
				'diff' => $this->diff_engine->diff( $current_blob_contents, $previous_blob_contents ),
			);
		}
	}

	private static function guess_if_binary_blob( $blob_name, $blob_contents ) {
		$extension = pathinfo( $blob_name, PATHINFO_EXTENSION );
		if ( in_array( $extension, array( 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'bmp', 'tiff', 'tif', 'raw', 'heic', 'heif', 'avif' ) ) ) {
			return true;
		}

		// Naively assume null bytes only occur in binary files
		if ( strpos( $blob_contents, "\0" ) !== false ) {
			return true;
		}

		return false;
	}

	public function squash( $squash_into_commit_oid, $squash_until_ancestor_oid ) {
		// Find the parent of the squashed range
		$this->read_object( $squash_until_ancestor_oid );
		$new_base_oid = $this->read_object( $squash_until_ancestor_oid )->as_commit()->get_first_parent_hash();

		// Reparent the commits from HEAD until $squash_into_commit_oid onto the parent
		// of the squashed range.
		$new_head = $this->reparent_commit_range(
			$this->get_ref_head( 'HEAD' ),
			$squash_into_commit_oid,
			$new_base_oid
		);

		// Finally, set the HEAD of the current branch to the new squashed commit.
		$current_branch = $this->get_ref_head( 'HEAD', array( 'follow_symrefs' => false ) );
		$this->set_ref_head( $current_branch, $new_head );

		return $new_head;
	}

	/**
	 * This is not a rebase()! It won't replay the changes while resolving conflicts.
	 * It just changes the parent of the specified commit range to $new_base_oid.
	 */
	public function reparent_commit_range( $head_oid, $last_ancestor_oid, $new_base_oid ) {
		// @TODO: Error handling. Exceptions would make it very convenient – maybe let's
		// use them internally?
		$commits_to_rebase = array();
		$moving_head       = $head_oid;
		while ( true ) {
			$hash                = $this->find_hash_by_path( $moving_head );
			$commits_to_rebase[] = $hash;
			if ( $hash === $last_ancestor_oid ) {
				break;
			}
			$parent = $this->read_object( $moving_head )->as_commit()->get_first_parent_hash();
			if ( Commit::is_null_hash( $parent ) ) {
				throw new GitException(
					'$last_ancestor_oid must be an ancestor of $head_oid for reparenting to work, but ' . $last_ancestor_oid . ' is not an ancestor of ' . $hash . '.',
				);
			}
			$moving_head = $parent;
		}

		// Rebase $squash_into_commit_oid and its descenrants onto the parent
		// of the squashed range.
		$new_parent_oid = $new_base_oid;
		for ( $i = count( $commits_to_rebase ) - 1; $i >= 0; $i-- ) {
			$this->read_object( $commits_to_rebase[ $i ] );
			$parsed_old_commit = $this->read_object( $commits_to_rebase[ $i ] )->as_commit();
			$new_parent_oid    = $this->add_object(
				'commit',
				$this->derive_commit_string(
					$parsed_old_commit,
					array(
						'parents' => array( $new_parent_oid ),
					)
				)
			);
		}
		$new_head_oid = $new_parent_oid;

		return $new_head_oid;
	}

	private function derive_commit_string( $parsed_commit, $updates ) {
		/**
		 * Keep the author and author_date as they are.
		 *
		 * The Git Book says:
		 *
		 * > You may be wondering what the difference is between author and committer. The
		 * > author is the person who originally wrote the patch, whereas the committer is
		 * > the person who last applied the patch. So, if you send in a patch to a project
		 * > and one of the core members applies the patch, both of you get credit — you as
		 * > the author and the core member as the committer
		 *
		 * See http://git-scm.com/book/ch2-3.html for more information.
		 */
		unset( $updates['author'] );
		unset( $updates['author_date'] );
		return $this->create_commit_string( array_merge( $parsed_commit, $updates ) );
	}

	private function create_commit_string( $options ) {
		if ( ! isset( $options['tree'] ) ) {
			_doing_it_wrong( __METHOD__, '"tree" commit meta field is required', '1.0.0' );
			return false;
		}
		if ( ! isset( $options['author'] ) ) {
			$options['author'] = $this->get_config_value( 'user.name' ) . ' <' . $this->get_config_value( 'user.email' ) . '>';
		}
		if ( ! isset( $options['author_date'] ) ) {
			$options['author_date'] = time() . ' +0000';
		}
		if ( ! isset( $options['committer'] ) ) {
			$options['committer'] = $this->get_config_value( 'user.name' ) . ' <' . $this->get_config_value( 'user.email' ) . '>';
		}
		if ( ! isset( $options['committer_date'] ) ) {
			$options['committer_date'] = time() . ' +0000';
		}
		$options['message'] = $options['message'] ?? 'Changes';
		$commit_message     = array();
		$commit_message[]   = 'tree ' . $options['tree'];
		if ( isset( $options['parents'] ) ) {
            foreach($options['parents'] as $parent) {
			    $commit_message[] = 'parent ' . $parent;
            }
		}
		$commit_message[] = 'author ' . $options['author'] . ' ' . $options['author_date'];
		$commit_message[] = 'committer ' . $options['committer'] . ' ' . $options['committer_date'];
		$commit_message[] = "\n" . $options['message'];
		return implode( "\n", $commit_message );
	}

	private function mark_tree_path_changed( &$changed_trees, $path ) {
		while ( $path !== '/' ) {
			if ( ! isset( $changed_trees[ $path ] ) ) {
				$changed_trees[ $path ] = new Tree();
			}
			$path = dirname( $path );
		}
	}

	private function commit_tree( $path, $changed_trees ) {
		$tree_objects = array();

		// Load existing tree if it exists
		try {
			$tree_objects = $this->read_object_by_path( $path )->as_tree()->entries;
		} catch ( GitException $e ) {
			// It's fine if the tree doesn't exist
		}

		// Apply any changes to this tree
		if ( isset( $changed_trees[ $path ]->entries ) ) {
			foreach ( $changed_trees[ $path ]->entries as $name => $entry ) {
				if ( $entry === self::DELETE_PLACEHOLDER ) {
					unset( $tree_objects[ $name ] );
				} else {
					$tree_objects[ $name ] = $entry;
				}
			}
		}

		// Recursively process child trees
		foreach ( $changed_trees as $child_path => $child_tree ) {
			if ( dirname( $child_path ) === $path && $child_path !== '/' ) {
				$child_oid                               = $this->commit_tree( $child_path, $changed_trees );
				$tree_objects[ basename( $child_path ) ] = new TreeEntry(
					array(
						'name' => basename( $child_path ),
						'mode' => TreeEntry::FILE_MODE_DIRECTORY,
						'hash' => $child_oid,
					)
				);
			}
		}

		// Git seems to require alphabetical order for the tree objects.
		// Or at least GitHub rejects the push if the tree objects are not sorted.
		ksort( $tree_objects );

		// Create new tree object
		return $this->add_object(
			'tree',
			GitProtocolEncoderPipe::encode_tree_bytes( new Tree( $tree_objects ) )
		);
	}

	public function list_refs( $prefixes = array( '' ) ) {
		$refs = array();

		/**
		 * Only allow listing refs in the refs/ directory to avoid
		 * accidentally working with, say, the main .git directory.
		 *
		 * This is a starter implementation. We may need to revisit this
		 * for full compliance with Git.
		 */
		$stack = array( 'refs/heads/' );
		foreach ( $prefixes as $prefix ) {
			$path       = ltrim( wp_canonicalize_path( $prefix ), '/' );
			$first_path = $this->fs->is_dir( $path ) ? $path : dirname( $path );
			if ( str_starts_with( $first_path, 'refs/' ) ) {
				$stack[] = $first_path;
			}
		}

		while ( ! empty( $stack ) ) {
			$path = array_shift( $stack );
			if ( $this->fs->is_dir( $path ) ) {
				$ref_files = $this->fs->ls( $path );
				foreach ( $ref_files as $ref_file ) {
					$full_path = wp_join_paths( $path, $ref_file );
					array_push( $stack, $full_path );
				}
			} elseif ( $this->fs->is_file( $path ) ) {
				// Check if path matches any of the prefixes
				foreach ( $prefixes as $prefix ) {
					if ( str_starts_with( $path, $prefix ) ) {
						$hash = trim( $this->fs->get_contents( $path ) );
						if ( $hash ) {
							$ref_name          = trim( $path, '/' );
							$refs[ $ref_name ] = $hash;
						}
						break;
					}
				}
			}
		}

		// Check if we should include HEAD
		foreach ( $prefixes as $prefix ) {
			if ( $prefix === '' || str_starts_with( 'HEAD', $prefix ) ) {
				$refs['HEAD'] = $this->get_ref_head( 'HEAD' );
				break;
			}
		}

		return $refs;
	}
}
