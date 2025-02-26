<?php

use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\LocalFilesystem;
use WordPress\Git\GitFilesystem;
use WordPress\Git\GitRemote;
use WordPress\Git\GitRepository;
use WordPress\Merge\Diff\MyersDiffer;
use WordPress\Merge\Merge\ChunkMerger;
use WordPress\Merge\Validate\BlockMarkupMergeValidator;

interface DataSource {
	public function sync();
	public function get_current_version();
	public function get_filesystem(): Filesystem;
}

class GitDataSource implements DataSource {

	private $remote;
	private $git_repository;
	private $git_filesystem;
	private $full_branch_name;
	private $subdirectory;

	public static function create( $config ) {
		$dot_git_path = $config['.gitPath'] ?? WP_STATIC_PAGES_DIR;
		if ( ! is_dir( $dot_git_path ) ) {
			mkdir( $dot_git_path, 0777, true );
		}

		$local_fs = LocalFilesystem::create( $dot_git_path );

		/**
		 * @TODO: Use a merge strategy that understands which files
		 *        are posts and then performs a three-way merge in the
		 *        block markup domain.
		 */
		$repo = new GitRepository( $local_fs );
		$repo->add_remote( 'origin', $config['gitRepo'] );
		if ( ! $repo->branch_exists( $config['selectedBranch'] ) ) {
			$repo->create_branch( $config['selectedBranch'] );
		}
		$repo->set_branch_tip( 'HEAD', 'ref: refs/heads/' . $config['selectedBranch'] );
		$repo->checkout( $config['selectedBranch'] );
		$repo->set_config_value( 'user.name', $config['gitUserName'] );
		$repo->set_config_value( 'user.email', $config['gitUserEmail'] );

		return new GitDataSource(
			$repo,
			array(
				'remoteName' => $config['remoteName'] ?? 'origin',
				'subdirectory' => $config['subdirectory'] ?? '',
				'selectedBranch' => $config['selectedBranch'] ?? '',
			)
		);
	}

	public function __construct( $git_repository, $config ) {
		$this->subdirectory     = $config['subdirectory'] ?? '';
		$this->full_branch_name = $config['selectedBranch'] ?? '';
		$this->remote           = new GitRemote( $git_repository, $config['remoteName'] );
		$this->git_repository   = $git_repository;
		$this->git_filesystem   = GitFilesystem::create(
			$this->git_repository,
			array( 'root' => $this->subdirectory )
		);
	}

	public function sync() {
		$this->remote->pull(
			$this->full_branch_name,
		);
		$this->remote->push();
	}

	public function get_current_version() {
		return $this->git_repository->get_branch_tip();
	}

	public function get_filesystem(): Filesystem {
		return $this->git_filesystem;
	}
}

class LocalDirectoryDataSource implements DataSource {

	private $local_filesystem;

	public function __construct( $local_filesystem ) {
		$this->local_filesystem = $local_filesystem;
	}

	public function sync() {
		// No op
	}

	public function get_filesystem(): Filesystem {
		return $this->local_filesystem;
	}

	public function get_current_version() {
		return null;
	}
}
