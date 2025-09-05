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
	public $from_path;
	/**
	 * @var string
	 */
	public $to_path;

	/**
	 * @param  string $fromPath  The source path to copy from.
	 * @param  string $toPath  The destination path to copy to.
	 */
	public function __construct( string $from_path, string $to_path ) {
		$this->from_path = $from_path;
		$this->to_path   = $to_path;
	}

	/**
	 * Executes the cp step.
	 */
	public function run( Runtime $runtime, Tracker $tracker ) {
		$tracker->setCaption( 'Copying from ' . $this->from_path . ' to ' . $this->to_path );
		$runtime->get_target_filesystem()->copy(
			$this->from_path,
			$this->to_path,
			array( 'recursive' => true )
		);
	}
}
