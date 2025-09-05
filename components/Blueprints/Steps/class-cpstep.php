<?php

namespace WordPress\Blueprints\Steps;

use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;

/**
 * Represents the 'cp' (copy) step.
 */
class CpStep implements StepInterface {
	/**
	 * @var string
	 */
	public $fromPath;
	/**
	 * @var string
	 */
	public $toPath;

	/**
	 * @param  string $fromPath  The source path to copy from.
	 * @param  string $toPath  The destination path to copy to.
	 */
	public function __construct( string $fromPath, string $toPath ) {
		$this->fromPath = $fromPath;
		$this->toPath   = $toPath;
	}

	/**
	 * Executes the cp step.
	 */
	public function run( Runtime $runtime, Tracker $tracker ) {
		$tracker->setCaption( 'Copying from ' . $this->fromPath . ' to ' . $this->toPath );
		$runtime->getTargetFilesystem()->copy(
			$this->fromPath,
			$this->toPath,
			array( 'recursive' => true )
		);
	}
}
