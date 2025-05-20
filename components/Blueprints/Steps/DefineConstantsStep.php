<?php

namespace WordPress\Blueprints\Steps;

use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;

/**
 * Represents the 'defineConstants' step.
 */
class DefineConstantsStep implements StepInterface {
	/**
	 * An associative array of constant names to their values (string, bool, int, float).
	 * @var array<string, scalar>
	 */
	public $constants;

	/**
	 * @param  array<string, scalar>  $constants  Constants to define.
	 */
	public function __construct( array $constants ) {
		$this->constants = $constants;
	}

	/**
	 * Executes the defineConstants step.
	 */
	public function run( Runtime $runtime, Tracker $tracker ) {
		$tracker->setCaption( 'Defining wp-config constants' );
		$runtime->evalPhpCodeInSubProcess(
			file_get_contents( __DIR__ . '/scripts/DefineWpConfigConsts/define.php' ),
			array( 'CONSTS' => json_encode( $this->constants ) )
		);
	}
}
