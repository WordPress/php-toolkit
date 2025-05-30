<?php

namespace WordPress\Blueprints\Steps;

use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;

/**
 * New Step Interface
 */
interface StepInterface {
	/**
	 * Executes the step logic.
	 *
	 * @param  Runtime  $runtime  The runtime providing environment access
	 * @param  Tracker  $tracker  The tracker for reporting progress
	 *
	 * @return mixed The result of running the step
	 */
	public function run( Runtime $runtime, Tracker $tracker );
}
