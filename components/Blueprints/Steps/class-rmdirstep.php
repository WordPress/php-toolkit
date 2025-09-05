<?php

namespace WordPress\Blueprints\Steps;

use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;

/**
 * Represents the 'rmdir' (remove directory) step.
 */
class RmDirStep implements StepInterface {
	/**
	 * @var string
	 */
	public $path;

	/**
	 * @var array
	 */
	public $options;

	/**
	 * @param  string  $path  The directory path to remove.
	 */
	public function __construct( string $path, $options = [] ) {
		$this->path = $path;
		$this->options = $options;
	}

	public function run( Runtime $runtime, Tracker $tracker ) {
		$tracker->setCaption( 'Removing directory ' . $this->path );
		$runtime->getTargetFilesystem()->rmdir( $this->path, $this->options );
	}
}
