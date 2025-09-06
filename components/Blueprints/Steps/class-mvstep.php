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
	public $from_path;
	/**
	 * @var string
	 */
	public $to_path;

	/**
	 * @param  string $from_path  The source path to move from.
	 * @param  string $to_path  The destination path to move to.
	 */
	public function __construct( string $from_path, string $to_path ) {
		$this->from_path = $from_path;
		$this->to_path   = $to_path;
	}

	public function run( Runtime $runtime, Tracker $tracker ) {
		$tracker->setCaption( 'Moving from ' . $this->from_path . ' to ' . $this->to_path );
		$runtime->get_target_filesystem()->rename( $this->from_path, $this->to_path );
	}
}
