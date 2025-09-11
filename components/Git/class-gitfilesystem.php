<?php

namespace WordPress\Git;

use DateMalformedStringException;
use DateTime;
use WordPress\ByteStream\ReadStream\ByteReadStream;
use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\FilesystemException;
use WordPress\Filesystem\Layer\ChrootLayer;
use WordPress\Filesystem\Mixin\BufferedWriteStreamViaPutContents;
use WordPress\Filesystem\Mixin\CopyRecursiveViaStreaming;

class GitFilesystem implements Filesystem {

	use CopyRecursiveViaStreaming;
	use BufferedWriteStreamViaPutContents;

	/**
	 * @var GitRepository
	 */
	private $repo;
	private $auto_push;
	/**
	 * @var GitRemote
	 */
	private $remote;
	private $write_stream;
	private $amend_time_window;

	public static function create( GitRepository $repo, $options = array() ) {
		return new ChrootLayer(
			new GitFilesystem( $repo, $options ),
			$options['root'] ?? '/'
		);
	}

	/**
	 * @internal Use the static create() method instead.
	 */
	private function __construct(
		GitRepository $repo,
		$options = array()
	) {
		$this->repo              = $repo;
		$this->auto_push         = $options['auto_push'] ?? false;
		$this->amend_time_window = $options['amend_time_window'] ?? false;
		// if ( false !== amend_time_window ) {.

		// }.
		if ( $this->auto_push ) {
			$this->remote = $options['remote'] ?? null;
			if ( ! $this->remote ) {
				throw new FilesystemException( 'GitRemote remote is required when auto_push is enabled' );
			}
		}
	}

	public function get_repository(): GitRepository {
		return $this->repo;
	}

	public function ls( $path = '/' ) {
		try {
			return array_keys(
				$this->repo->read_object_by_path( $path )->as_tree()->entries
			);
		} catch ( GitException $e ) {
			return array();
		}
	}

	public function is_dir( $path ) {
		try {
			$reader = $this->repo->read_object_by_path( $path );

			return 'tree' === $reader->get_object_type_name();
		} catch ( GitException $e ) {
			return false;
		}
	}

	public function is_file( $path ) {
		try {
			$reader = $this->repo->read_object_by_path( $path );

			return 'blob' === $reader->get_object_type_name();
		} catch ( GitException $e ) {
			return false;
		}
	}

	public function exists( $path ) {
		$children = $this->ls( dirname( $path ) );

		return in_array( basename( $path ), $children, true );
	}

	public function get_contents( $path ) {
		return $this->open_read_stream( $path )->consume_all();
	}

	public function open_read_stream( $path ): ByteReadStream {
		return $this->repo->read_object_by_path( $path );
	}

	public function mkdir( $path, $options = array() ) {
		// Git doesn't support empty directories so we must create an empty file.
		return $this->commit(
			array(
				'updates' => array(
					$path . '/.gitkeep' => '',
				),
			)
		);
	}

	public function rm( $path ) {
		if ( $this->is_dir( $path ) ) {
			return false;
		}

		return $this->commit(
			array(
				'deletes' => array(
					$path,
				),
			)
		);
	}

	public function rmdir( $path, $options = array() ) {
		if ( ! $this->is_dir( $path ) ) {
			return false;
		}
		// There are no empty directories in Git. We're assuming
		// there are always files in the directory.
		if ( ! $options['recursive'] ) {
			return false;
		}

		return $this->commit(
			array(
				'deletes' => array(
					$path,
				),
			)
		);
	}

	public function rename( $from_path, $to_path, $options = array() ) {
		if ( $this->is_file( $from_path ) ) {
			$this->copy( $from_path, $to_path, $options );
			$this->rm( $from_path );
		} elseif ( $this->is_dir( $from_path ) ) {
			$this->commit(
				array(
					'move_trees' => array(
						$from_path => $to_path,
					),
				)
			);
		} else {
			throw new FilesystemException( sprintf( 'Path is not a file or directory: %s', $from_path ) );
		}
	}

	public function put_contents( $path, $contents, $options = array() ) {
		if ( $this->write_stream ) {
			throw new FilesystemException( 'Cannot open a new write stream while another write stream is open.' );
		}
		$this->commit(
			array(
				'updates' => array(
					$path => $contents,
				),
			)
		);
	}

	private function commit( $options ) {
		if ( ! $this->auto_push ) {
			$this->repo->commit( $options );

			return true;
		}

		$should_amend = $this->should_amend_last_commit();
		if ( ! $should_amend ) {
			$this->graceful_push();
			$this->repo->commit( $options );

			return true;
		}

		$this->repo->commit(
			array_merge(
				$options,
				array(
					'amend' => true,
				)
			)
		);

		return true;
	}

	private function graceful_push() {
		try {
			$this->remote->push();
		} catch ( GitRemoteException $e ) {
			// If push failed, there could be new remote commits.
			// Pull and retry.
			$this->remote->pull();

			// If pull succeeded, try pushing again.
			$this->remote->push();
		}
	}

	private function should_amend_last_commit() {
		if ( false === $this->amend_time_window ) {
			return false;
		}

		try {
			$head_commit_hash = $this->repo->get_branch_tip( 'HEAD' );
		} catch ( GitException $e ) {
			return false;
		}

		$head_commit = $this->repo->read_object( $head_commit_hash )->as_commit();
		/**
		 * Amending merge commits in auto_push mode is not supported yet. It seems to involve
		 * additional complexity for no apparent benefit. We can just create a new commit instead.
		 */
		if ( count( $head_commit->parents ) > 1 ) {
			return false;
		}

		try {
			$head_commit_time = $head_commit->get_author_date_time();
		} catch ( DateMalformedStringException $e ) {
			return false;
		}
		$now               = new DateTime();
		$time_since_commit = (float) $now->format( 'U' ) - (float) $head_commit_time->format( 'U' );
		if ( $time_since_commit > $this->amend_time_window ) {
			return false;
		}

		$full_branch_name   = $this->get_repository()->get_current_branch_name();
		$short_branch_name  = 0 === strncmp( $full_branch_name, 'refs/heads/', strlen( 'refs/heads/' ) ) ? substr(
			$full_branch_name,
			11
		) : $full_branch_name;
		$remote_name        = $this->remote->get_name();
		$remote_branch_name = "refs/remotes/{$remote_name}/{$short_branch_name}";
		$remote_branch_hash = $this->get_repository()->get_branch_tip( $remote_branch_name );

		// Very naively check whether we've already pushed this commit to the remote.
		// @TODO: Either improve the graph algebra here or use "Draft: " prefix in these.
		// amended commits (and remove it before pushing?).
		return $remote_branch_hash !== $head_commit_hash;
	}

	public function get_meta(): array {
		return array();
	}
}
