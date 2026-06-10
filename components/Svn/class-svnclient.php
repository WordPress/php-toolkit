<?php

namespace WordPress\Svn;

use WordPress\Filesystem\LocalFilesystem;
use WordPress\Svn\Protocol\DavSession;
use WordPress\Svn\Protocol\RaSvnSession;

/**
 * A Subversion client in pure PHP.
 *
 * Supports checking out, updating, and committing to Subversion
 * repositories over both svn:// (the ra_svn protocol) and http:// /
 * https:// (the DAV-based protocol, requires a Subversion 1.7+ server).
 * svn:externals are checked out as nested working copies.
 *
 *     $client = new SvnClient( array( 'username' => 'alice', 'password' => 's3cret' ) );
 *     $client->checkout( 'https://develop.svn.wordpress.org/trunk', '/tmp/wordpress-develop' );
 *     // ...edit files...
 *     $client->add( '/tmp/wordpress-develop', 'src/new-file.php' );
 *     $client->commit( '/tmp/wordpress-develop', 'Add a new file.' );
 *     $client->update( '/tmp/wordpress-develop' );
 *
 * Working copies use this component's own `.svn` administrative format –
 * they are not interchangeable with working copies created by the
 * official `svn` client. The repositories themselves are fully
 * interoperable, of course.
 *
 * All working copy paths handed to this class are paths within the
 * configured Filesystem (the local disk by default) and use forward
 * slashes on every platform.
 */
class SvnClient {
	/**
	 * @var array
	 */
	private $options;

	/**
	 * @var \WordPress\Filesystem\Filesystem
	 */
	private $filesystem;

	/**
	 * @param array $options {
	 *     @type string     $username    Username for authenticated operations.
	 *     @type string     $password    Password for authenticated operations.
	 *     @type Filesystem $filesystem  Filesystem to place working copies on.
	 *                                   Defaults to the local disk.
	 *     @type int        $timeout_ms  Network read timeout. Default 60000.
	 *     @type Client     $http_client Custom HttpClient\Client for http(s) sessions.
	 * }
	 */
	public function __construct( $options = array() ) {
		$this->options    = $options;
		$this->filesystem = isset( $options['filesystem'] ) ? $options['filesystem'] : LocalFilesystem::create();
	}

	/**
	 * Opens a repository session for a URL. Useful for repository-level
	 * operations that need no working copy, such as listing directories.
	 *
	 * @param  string $url  An svn://, http://, or https:// repository URL.
	 * @return SvnSession
	 * @throws SvnException When the URL scheme is not supported.
	 */
	public function open_session( $url ) {
		$scheme = parse_url( $url, PHP_URL_SCHEME );
		if ( 'svn' === $scheme ) {
			return RaSvnSession::connect( $url, $this->options );
		}
		if ( 'http' === $scheme || 'https' === $scheme ) {
			return DavSession::connect( $url, $this->options );
		}

		throw new SvnException( "Unsupported repository URL scheme '{$scheme}'. Use svn://, http://, or https://." );
	}

	/**
	 * @param  string $url  A repository URL.
	 * @return int The youngest revision of the repository.
	 */
	public function latest_revision( $url ) {
		$session = $this->open_session( $url );
		try {
			return $session->get_latest_revision();
		} finally {
			$session->close();
		}
	}

	/**
	 * Checks out a repository URL into a new working copy.
	 *
	 * @param  string $url      The URL to check out.
	 * @param  string $path     Where to create the working copy. Must not
	 *                          exist yet, or be an empty directory.
	 * @param  array  $options  {
	 *     @type int    $revision          Revision to check out. Default: the latest.
	 *     @type string $depth             'empty', 'files', 'immediates', or
	 *                                     'infinity' (default).
	 *     @type bool   $ignore_externals  Skip svn:externals. Default false.
	 * }
	 * @return array The checkout result: revision plus lists of affected paths.
	 * @throws SvnException When the target is in the way or the server errors.
	 */
	public function checkout( $url, $path, $options = array() ) {
		return $this->checkout_internal( $url, $path, $options, 0 );
	}

	/**
	 * Brings a working copy up to date with the repository.
	 *
	 * Locally modified files that also changed in the repository are
	 * kept; the incoming version is stored next to them as
	 * `<name>.r<revision>` and the file is marked conflicted until
	 * resolved() is called.
	 *
	 * @param  string $path     The working copy root.
	 * @param  array  $options  {
	 *     @type int  $revision          Revision to update to. Default: the latest.
	 *     @type bool $ignore_externals  Skip svn:externals. Default false.
	 * }
	 * @return array The update result: revision plus lists of affected paths.
	 */
	public function update( $path, $options = array() ) {
		return $this->update_internal( $path, $options, 0 );
	}

	/**
	 * Reports the local changes in a working copy.
	 *
	 * @param  string $path  The working copy root.
	 * @return array Map of relative path => 'modified', 'added', 'deleted',
	 *               'missing', 'conflicted', 'unversioned', 'external', or
	 *               'obstructed'. Unmodified files are not reported.
	 */
	public function status( $path ) {
		return $this->open_working_copy( $path )->get_status();
	}

	/**
	 * Returns what the working copy knows about itself.
	 *
	 * @param  string $path  The working copy root.
	 * @return array { url, repository_root, uuid, revision, depth }
	 */
	public function info( $path ) {
		$working_copy = $this->open_working_copy( $path );

		return array(
			'url'             => $working_copy->get_url(),
			'repository_root' => $working_copy->get_repository_root(),
			'uuid'            => $working_copy->get_uuid(),
			'revision'        => $working_copy->get_revision(),
			'depth'           => $working_copy->get_depth(),
		);
	}

	/**
	 * Schedules unversioned files or directories for addition. Directories
	 * are added recursively. Unversioned parent directories are scheduled
	 * automatically.
	 *
	 * @param string          $path            The working copy root.
	 * @param string|string[] $relative_paths  Path(s) to add, relative to the root.
	 */
	public function add( $path, $relative_paths ) {
		$working_copy = $this->open_working_copy( $path );
		foreach ( (array) $relative_paths as $relative_path ) {
			$this->add_path( $working_copy, trim( $relative_path, '/' ) );
		}
		$working_copy->save();
	}

	/**
	 * Schedules versioned files or directories for deletion and removes
	 * them from disk. The deletion happens in the repository on the next
	 * commit.
	 *
	 * @param  string          $path            The working copy root.
	 * @param  string|string[] $relative_paths  Path(s) to delete, relative to the root.
	 * @param  array           $options         { @type bool $force  Delete even when locally modified. }
	 * @throws SvnException When a path is unversioned or locally modified.
	 */
	public function delete( $path, $relative_paths, $options = array() ) {
		$working_copy = $this->open_working_copy( $path );
		foreach ( (array) $relative_paths as $relative_path ) {
			$this->delete_path( $working_copy, trim( $relative_path, '/' ), ! empty( $options['force'] ) );
		}
		$working_copy->save();
	}

	/**
	 * Discards local changes, restoring files to their pristine state.
	 *
	 * @param string          $path            The working copy root.
	 * @param string|string[] $relative_paths  Path(s) to revert, relative to the root.
	 */
	public function revert( $path, $relative_paths ) {
		$working_copy = $this->open_working_copy( $path );
		foreach ( (array) $relative_paths as $relative_path ) {
			$this->revert_path( $working_copy, trim( $relative_path, '/' ) );
		}
		$working_copy->save();
	}

	/**
	 * Marks conflicted files as resolved, keeping their current working
	 * contents, and removes the `<name>.r<revision>` conflict files.
	 *
	 * @param string          $path            The working copy root.
	 * @param string|string[] $relative_paths  Path(s) to resolve, relative to the root.
	 */
	public function resolved( $path, $relative_paths ) {
		$working_copy = $this->open_working_copy( $path );
		$filesystem   = $working_copy->get_filesystem();
		foreach ( (array) $relative_paths as $relative_path ) {
			$relative_path = trim( $relative_path, '/' );
			$entry         = $working_copy->get_entry( $relative_path );
			if ( null === $entry || empty( $entry['conflict'] ) ) {
				continue;
			}
			if ( isset( $entry['conflict_file'] ) && $filesystem->is_file( $working_copy->get_disk_path( $entry['conflict_file'] ) ) ) {
				$filesystem->rm( $working_copy->get_disk_path( $entry['conflict_file'] ) );
			}
			unset( $entry['conflict'], $entry['conflict_file'] );
			$working_copy->set_entry( $relative_path, $entry );
		}
		$working_copy->save();
	}

	/**
	 * Commits the local changes of a working copy as a new revision.
	 *
	 * Changes inside svn:externals are not committed – commit each
	 * external's working copy separately, like the official client.
	 *
	 * @param  string $path     The working copy root.
	 * @param  string $message  The log message.
	 * @return array|null Commit info { revision, author, date }, or null
	 *                    when there was nothing to commit.
	 * @throws SvnException When conflicts exist or the server rejects the commit.
	 */
	public function commit( $path, $message ) {
		$working_copy = $this->open_working_copy( $path );
		$status       = $working_copy->get_status();

		$conflicted = array_keys( $status, 'conflicted', true );
		if ( count( $conflicted ) > 0 ) {
			throw new SvnException( 'Cannot commit: conflicts remain in ' . implode( ', ', $conflicted ) . '. Resolve them first.' );
		}

		$operations = $this->status_to_operations( $working_copy, $status );
		if ( 0 === count( $operations ) ) {
			return null;
		}

		$session = $this->open_session( $working_copy->get_url() );
		try {
			$commit_info = $session->commit( $message, $operations );
		} finally {
			$session->close();
		}

		$this->record_committed_changes( $working_copy, $operations, $commit_info['revision'] );

		return $commit_info;
	}

	/**
	 * Opens the working copy at a path.
	 *
	 * @param  string $path  The working copy root.
	 * @return SvnWorkingCopy
	 */
	private function open_working_copy( $path ) {
		return SvnWorkingCopy::open( $this->filesystem, $path );
	}

	private function checkout_internal( $url, $path, $options, $externals_depth ) {
		$url = rtrim( $url, '/' );
		if ( SvnWorkingCopy::is_working_copy( $this->filesystem, $path ) ) {
			throw new SvnException( "'{$path}' is already a working copy. Use update() instead." );
		}
		if ( $this->filesystem->is_file( $path ) ) {
			throw new SvnException( "Cannot check out into '{$path}': a file is in the way." );
		}
		if ( $this->filesystem->is_dir( $path ) && count( $this->filesystem->ls( $path ) ) > 0 ) {
			throw new SvnException( "Cannot check out into '{$path}': the directory is not empty." );
		}

		$depth   = isset( $options['depth'] ) ? $options['depth'] : 'infinity';
		$session = $this->open_session( $url );
		try {
			$revision = isset( $options['revision'] ) ? $options['revision'] : $session->get_latest_revision();

			$working_copy = SvnWorkingCopy::initialize(
				$this->filesystem,
				$path,
				array(
					'url'             => $session->get_session_url(),
					'repository_root' => $session->get_repository_root(),
					'uuid'            => $session->get_uuid(),
					'revision'        => $revision,
					'depth'           => $depth,
				)
			);

			$editor = new WorkingCopyEditor( $working_copy );
			$session->drive_update(
				$revision,
				array(
					array(
						'path'        => '',
						'revision'    => $revision,
						'start_empty' => true,
					),
				),
				$editor,
				array( 'depth' => $depth )
			);
		} finally {
			$session->close();
		}

		$result = $editor->get_result();
		if ( empty( $options['ignore_externals'] ) ) {
			$result['externals'] = $this->sync_externals( $working_copy, $externals_depth );
		}
		$result['revision'] = $revision;

		return $result;
	}

	private function update_internal( $path, $options, $externals_depth ) {
		$working_copy = $this->open_working_copy( $path );
		$session      = $this->open_session( $working_copy->get_url() );
		try {
			$revision = isset( $options['revision'] ) ? $options['revision'] : $session->get_latest_revision();

			// Describe the working copy state to the server. Commits leave
			// the committed entries at a newer revision than the rest of
			// the working copy, and reporting those entries individually
			// makes the server send deltas against the right bases.
			$report = array(
				array(
					'path'        => '',
					'revision'    => $working_copy->get_revision(),
					'start_empty' => false,
				),
			);
			foreach ( $working_copy->get_entries() as $entry_path => $entry ) {
				if ( '' === $entry_path || isset( $entry['schedule'] ) ) {
					continue;
				}
				if ( $entry['revision'] !== $working_copy->get_revision() ) {
					$report[] = array(
						'path'        => $entry_path,
						'revision'    => $entry['revision'],
						'start_empty' => false,
					);
				}
			}

			$editor = new WorkingCopyEditor( $working_copy );
			$session->drive_update(
				$revision,
				$report,
				$editor,
				array( 'depth' => $working_copy->get_depth() )
			);
		} finally {
			$session->close();
		}

		$result = $editor->get_result();
		if ( empty( $options['ignore_externals'] ) ) {
			$result['externals'] = $this->sync_externals( $working_copy, $externals_depth );
		}
		$result['revision'] = $revision;

		return $result;
	}

	/**
	 * Checks out or updates every svn:externals definition found in the
	 * working copy as a nested working copy.
	 *
	 * @param  SvnWorkingCopy $working_copy     The parent working copy.
	 * @param  int            $externals_depth  Current nesting depth, to break cycles.
	 * @return array Map of external target path => { url, revision }.
	 */
	private function sync_externals( SvnWorkingCopy $working_copy, $externals_depth ) {
		if ( $externals_depth >= SvnWorkingCopy::MAX_EXTERNALS_DEPTH ) {
			return array();
		}

		$definitions = array();
		foreach ( $working_copy->get_entries() as $entry_path => $entry ) {
			if ( 'dir' !== $entry['kind'] || ! isset( $entry['props']['svn:externals'] ) ) {
				continue;
			}
			$directory_url = $working_copy->get_url() . ( '' === $entry_path ? '' : '/' . $entry_path );
			$externals     = svn_parse_externals(
				$entry['props']['svn:externals'],
				$directory_url,
				$working_copy->get_repository_root()
			);
			foreach ( $externals as $external ) {
				$target_path                 = ( '' === $entry_path ? '' : $entry_path . '/' ) . $external['target'];
				$definitions[ $target_path ] = $external;
			}
		}

		$recorded = array();
		foreach ( $definitions as $target_path => $external ) {
			$disk_path = $working_copy->get_disk_path( $target_path );
			if ( SvnWorkingCopy::is_working_copy( $this->filesystem, $disk_path ) ) {
				$nested = SvnWorkingCopy::open( $this->filesystem, $disk_path );
				if ( $nested->get_url() !== $external['url'] ) {
					// The external now points elsewhere. Re-checkouts of
					// switched externals are not supported yet; leave the
					// existing checkout alone.
					continue;
				}
				$this->update_internal(
					$disk_path,
					null === $external['revision'] ? array() : array( 'revision' => $external['revision'] ),
					$externals_depth + 1
				);
			} else {
				$this->checkout_internal(
					$external['url'],
					$disk_path,
					null === $external['revision'] ? array() : array( 'revision' => $external['revision'] ),
					$externals_depth + 1
				);
			}
			$recorded[ $target_path ] = array(
				'url'      => $external['url'],
				'revision' => $external['revision'],
			);
		}

		$working_copy->set_externals( $recorded );
		$working_copy->save();

		return $recorded;
	}

	private function add_path( SvnWorkingCopy $working_copy, $relative_path ) {
		if ( '' === $relative_path ) {
			throw new SvnException( 'Cannot add the working copy root.' );
		}
		$entry = $working_copy->get_entry( $relative_path );
		if ( null !== $entry ) {
			return;
		}
		$filesystem = $working_copy->get_filesystem();
		$disk_path  = $working_copy->get_disk_path( $relative_path );
		if ( ! $filesystem->exists( $disk_path ) ) {
			throw new SvnException( "Cannot add '{$relative_path}': no such file or directory." );
		}

		// Schedule unversioned ancestors too.
		$slash_position = strrpos( $relative_path, '/' );
		if ( false !== $slash_position ) {
			$this->add_path( $working_copy, substr( $relative_path, 0, $slash_position ) );
		}

		if ( $filesystem->is_dir( $disk_path ) ) {
			$working_copy->set_entry(
				$relative_path,
				array(
					'kind'     => 'dir',
					'schedule' => 'add',
				)
			);
			foreach ( $filesystem->ls( $disk_path ) as $child_name ) {
				if ( SvnWorkingCopy::ADMIN_DIR === $child_name ) {
					continue;
				}
				$this->add_path( $working_copy, $relative_path . '/' . $child_name );
			}
		} else {
			$working_copy->set_entry(
				$relative_path,
				array(
					'kind'     => 'file',
					'schedule' => 'add',
				)
			);
		}
	}

	private function delete_path( SvnWorkingCopy $working_copy, $relative_path, $force ) {
		$entry = $working_copy->get_entry( $relative_path );
		if ( null === $entry ) {
			throw new SvnException( "Cannot delete '{$relative_path}': not under version control." );
		}
		$filesystem = $working_copy->get_filesystem();

		$paths = array_merge( array( $relative_path ), $working_copy->get_entry_paths_under( $relative_path ) );
		if ( ! $force ) {
			foreach ( $paths as $target_path ) {
				$target_entry = $working_copy->get_entry( $target_path );
				if ( 'file' === $target_entry['kind']
					&& ! isset( $target_entry['schedule'] )
					&& $filesystem->is_file( $working_copy->get_disk_path( $target_path ) )
					&& $working_copy->is_file_modified( $target_path, $target_entry ) ) {
					throw new SvnException( "Cannot delete '{$target_path}': it has local modifications. Pass the 'force' option to delete it anyway." );
				}
			}
		}

		rsort( $paths );
		foreach ( $paths as $target_path ) {
			$target_entry = $working_copy->get_entry( $target_path );
			$disk_path    = $working_copy->get_disk_path( $target_path );
			if ( isset( $target_entry['schedule'] ) && 'add' === $target_entry['schedule'] ) {
				// Deleting a scheduled addition just unschedules it.
				$working_copy->remove_entry( $target_path );
			} else {
				$target_entry['schedule'] = 'delete';
				$working_copy->set_entry( $target_path, $target_entry );
			}
			if ( 'file' === $target_entry['kind'] && $filesystem->is_file( $disk_path ) ) {
				$filesystem->rm( $disk_path );
			} elseif ( 'dir' === $target_entry['kind'] && $filesystem->is_dir( $disk_path ) && 0 === count( $filesystem->ls( $disk_path ) ) ) {
				$filesystem->rmdir( $disk_path, array() );
			}
		}
	}

	private function revert_path( SvnWorkingCopy $working_copy, $relative_path ) {
		$entry = $working_copy->get_entry( $relative_path );
		if ( null === $entry ) {
			return;
		}
		$filesystem = $working_copy->get_filesystem();

		if ( isset( $entry['schedule'] ) && 'add' === $entry['schedule'] ) {
			// Reverting an addition leaves the file on disk, unversioned.
			$working_copy->remove_entry( $relative_path );
			foreach ( $working_copy->get_entry_paths_under( $relative_path ) as $child_path ) {
				$working_copy->remove_entry( $child_path );
			}

			return;
		}

		if ( 'dir' === $entry['kind'] ) {
			unset( $entry['schedule'] );
			$working_copy->set_entry( $relative_path, $entry );
			if ( ! $filesystem->is_dir( $working_copy->get_disk_path( $relative_path ) ) ) {
				$filesystem->mkdir( $working_copy->get_disk_path( $relative_path ), array( 'recursive' => true ) );
			}
			foreach ( $working_copy->get_entry_paths_under( $relative_path ) as $child_path ) {
				$this->revert_path( $working_copy, $child_path );
			}

			return;
		}

		unset( $entry['schedule'], $entry['conflict'] );
		if ( isset( $entry['conflict_file'] ) ) {
			if ( $filesystem->is_file( $working_copy->get_disk_path( $entry['conflict_file'] ) ) ) {
				$filesystem->rm( $working_copy->get_disk_path( $entry['conflict_file'] ) );
			}
			unset( $entry['conflict_file'] );
		}
		if ( isset( $entry['checksum'] ) ) {
			$working_copy->write_working_file(
				$relative_path,
				$working_copy->read_pristine( $entry['checksum'] ),
				isset( $entry['props'] ) ? $entry['props'] : array()
			);
		}
		$working_copy->set_entry( $relative_path, $entry );
	}

	/**
	 * Converts a status report into the commit operations the session
	 * layer understands.
	 *
	 * @param  SvnWorkingCopy $working_copy  The working copy being committed.
	 * @param  array          $status        Output of SvnWorkingCopy::get_status().
	 * @return array[] Commit operations, see SvnSession::commit().
	 */
	private function status_to_operations( SvnWorkingCopy $working_copy, $status ) {
		$operations = array();
		foreach ( $status as $relative_path => $state ) {
			$entry = $working_copy->get_entry( $relative_path );
			switch ( $state ) {
				case 'added':
					if ( 'dir' === $entry['kind'] ) {
						$operations[] = array(
							'op'   => 'add-directory',
							'path' => $relative_path,
						);
					} else {
						$operations[] = array(
							'op'       => 'add-file',
							'path'     => $relative_path,
							'contents' => $working_copy->read_working_file( $relative_path, $entry ),
						);
					}
					break;

				case 'modified':
					$operations[] = array(
						'op'            => 'modify-file',
						'path'          => $relative_path,
						'contents'      => $working_copy->read_working_file( $relative_path, $entry ),
						'base_revision' => $entry['revision'],
					);
					break;

				case 'deleted':
					// One delete of the topmost scheduled path covers all
					// the scheduled deletions below it.
					$slash_position = strrpos( $relative_path, '/' );
					$parent_path    = false === $slash_position ? '' : substr( $relative_path, 0, $slash_position );
					$parent_status  = isset( $status[ $parent_path ] ) ? $status[ $parent_path ] : null;
					if ( 'deleted' !== $parent_status ) {
						$operations[] = array(
							'op'            => 'delete',
							'path'          => $relative_path,
							'base_revision' => isset( $entry['revision'] ) ? $entry['revision'] : $working_copy->get_revision(),
						);
					}
					break;
			}
		}

		return $operations;
	}

	/**
	 * Updates the working copy metadata after a successful commit: the
	 * committed entries move to the new revision and their pristines
	 * match what was sent to the server.
	 *
	 * @param SvnWorkingCopy $working_copy  The committed working copy.
	 * @param array[]        $operations    The committed operations.
	 * @param int            $new_revision  The revision the commit created.
	 */
	private function record_committed_changes( SvnWorkingCopy $working_copy, $operations, $new_revision ) {
		foreach ( $operations as $operation ) {
			$relative_path = $operation['path'];
			switch ( $operation['op'] ) {
				case 'add-directory':
					$entry = $working_copy->get_entry( $relative_path );
					unset( $entry['schedule'] );
					$entry['revision'] = $new_revision;
					$working_copy->set_entry( $relative_path, $entry );
					break;

				case 'add-file':
				case 'modify-file':
					$entry = $working_copy->get_entry( $relative_path );
					unset( $entry['schedule'] );
					$entry['revision'] = $new_revision;
					$entry['checksum'] = $working_copy->store_pristine( $operation['contents'] );
					$working_copy->set_entry( $relative_path, $entry );
					break;

				case 'delete':
					$working_copy->remove_entry( $relative_path );
					foreach ( $working_copy->get_entry_paths_under( $relative_path ) as $child_path ) {
						$working_copy->remove_entry( $child_path );
					}
					break;
			}
		}
		$working_copy->save();
	}
}
