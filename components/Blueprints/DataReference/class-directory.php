<?php

namespace WordPress\Blueprints\DataReference;

use WordPress\Filesystem\Filesystem;

/**
 * Represents a directory-like object that encapsulates a filesystem.
 * Similar to File, but for directories.
 */
class Directory extends DataReference {
	/**
	 * @var Filesystem The filesystem representing the directory content.
	 */
	public $filesystem;

	/**
	 * @var string The name of the directory.
	 */
	public $dirname;

	/**
	 * Constructor.
	 *
	 * @param  Filesystem $filesystem  The filesystem representing the directory content.
	 * @param  string     $dirname  The name of the directory.
	 */
	public function __construct( Filesystem $filesystem, string $dirname ) {
		$this->filesystem = $filesystem;
		$this->dirname    = $dirname;
		parent::__construct();
	}

	/**
	 * Get a human-readable name for this reference.
	 * Used in the progress tracker.
	 *
	 * @return string The human-readable name.
	 */
	public function get_human_readable_name(): string {
		return 'Directory: ' . $this->dirname;
	}
}
