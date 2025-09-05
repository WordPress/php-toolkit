<?php

namespace WordPress\Blueprints\Steps;

use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;
use WordPress\Filesystem\FilesystemException;

/**
 * Represents the 'rm' (remove file) step.
 */
class RmStep implements StepInterface {
	/**
	 * @var string
	 */
	public $path;

	/**
	 * @param  string $path  The file path to remove.
	 */
	public function __construct( string $path ) {
		$this->path = $path;
	}

	public function run( Runtime $runtime, Tracker $tracker ) {
		$tracker->setCaption( 'Removing ' . $this->path );

		$filesystem = $runtime->getTargetFilesystem();
		$path       = $this->path;

		if ( ! $filesystem->exists( $path ) ) {
			throw new FilesystemException( sprintf( 'Path does not exist: %s', $path ) );
		}

		if ( $filesystem->is_dir( $path ) ) {
			$filesystem->rmdir( $path, array( 'recursive' => true ) );
		} else {
			$filesystem->rm( $path );
		}
	}
}
