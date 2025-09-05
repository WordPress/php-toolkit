<?php

namespace WordPress\Blueprints;

use WordPress\Blueprints\Progress\DoneEvent;
use WordPress\Blueprints\Progress\ProgressEvent;
use WordPress\Blueprints\Progress\Tracker;

/**
 * Progress logging handler that listens to Tracker progress events
 */
class ProgressObserver {
	/**
	 * @var callable
	 */
	private $log_callback;

	/**
	 * @var Runtime|null
	 */
	private $runtime;

	/**
	 * Create a new progress logger with the given logging function
	 *
	 * @param  callable  $logCallback  Function that receives progress updates
	 */
	public function __construct( ?callable $log_callback = null ) {
		$this->log_callback = $log_callback ?? function () {
			// noop
		};
	}

	/**
	 * Attach this logger to a Tracker instance
	 *
	 * @param  Tracker  $tracker  The tracker to log progress for
	 */
	public function attachTo( Tracker $tracker ) {
		$tracker->events->addListener(
			ProgressEvent::class,
			function ( ProgressEvent $event ) {
				call_user_func( $this->log_callback, $event->getProgress(), $event->getCaption(), $this->runtime );
			}
		);

		$tracker->events->addListener(
			DoneEvent::class,
			function () {
				call_user_func( $this->log_callback, 100, 'Complete', $this->runtime );
			}
		);
	}

	public function setRuntime( Runtime $runtime ) {
		$this->runtime = $runtime;
	}
}
