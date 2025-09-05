<?php

namespace WordPress\Blueprints\Steps;

use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;

/**
 * Represents the 'mv' (move) step.
 */
class MvStep implements StepInterface {
	/**
	 * @var string
	 */
	public $fromPath;
	/**
	 * @var string
	 */
	public $toPath;

	/**
	 * @param  string $fromPath  The source path to move from.
	 * @param  string $toPath  The destination path to move to.
	 */
	public function __construct( string $fromPath, string $toPath ) {
		$this->fromPath = $fromPath;
		$this->toPath   = $toPath;
	}

	public function run( Runtime $runtime, Tracker $tracker ) {
		$tracker->setCaption( 'Moving from ' . $this->fromPath . ' to ' . $this->toPath );
		$runtime->getTargetFilesystem()->rename( $this->fromPath, $this->toPath );
	}
}
