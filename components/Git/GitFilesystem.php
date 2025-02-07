<?php

namespace WordPress\Git;

use WordPress\ByteStream\ReadStream\ByteReadStream;
use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\FilesystemException;
use WordPress\Filesystem\Layer\ChrootLayer;
use WordPress\Filesystem\Mixin\BufferedWriteStreamViaPutContents;
use WordPress\Filesystem\Mixin\CopyRecursiveViaStreaming;
use WordPress\Filesystem\Mixin\RenameFileViaCopyAndRm;

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
		$this->repo      = $repo;
		$this->auto_push = $options['auto_push'] ?? false;
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
			return $reader->get_object_type_name() === 'tree';
		} catch ( GitException $e ) {
			return false;
		}
	}

	public function is_file( $path ) {
		try {
			$reader = $this->repo->read_object_by_path( $path );
			return $reader->get_object_type_name() === 'blob';
		} catch ( GitException $e ) {
			return false;
		}
	}

	public function exists( $path ) {
		return $this->is_file( $path ) || $this->is_dir( $path );
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
		} else if ( $this->is_dir( $from_path ) ) {
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
		$this->repo->commit( $options );

		/**
		 * Auto push if enabled
		 *
		 * This is a risky, best-effort PoC for automatic synchronization
		 * of changes with the remote repository. There's no conflict
		 * resolution here, only force overwriting of changes both locally
		 * and in the remote repository.
		 *
		 * Let's re-work this once the notes management prototype is more mature.
		 */
		if ( $this->auto_push ) {
			try {
				$this->remote->force_push_one_commit();
			} catch ( GitException $e ) {
				// If push failed, force pull and retry
				$this->remote->pull(
                    $this->get_repository()->get_current_branch_name(),
                    [ 'force' => true ]
                );

				// If pull succeeded, try committing and pushing again
				$this->repo->commit( $options );
				$this->remote->force_push_one_commit();
			}
		}
		return true;
	}
}
