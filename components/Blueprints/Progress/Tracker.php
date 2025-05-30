<?php

namespace WordPress\Blueprints\Progress;

use ArrayAccess;
use InvalidArgumentException;
use LogicException;
use OutOfBoundsException;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function array_key_exists;
use function array_values;
use function is_string;

/**
 * The ProgressTracker class is a tool for tracking progress in an operation that is
 * divided into multiple stages. It allows you to create sub-trackers for each stage,
 * with vidual weights and captions. The main tracker automatically calculates the
 * progress based on the weighted sum of each sub-tracker's progress. This makes it easy
 * to keep track of a complex, multi-stage process and report progress in a user-friendly way.
 *
 * After creating the sub-trackers, you can call the set() method to update the progress
 * of the current stage. You can also call the finish() method to mark the current stage
 * as complete and move on to the next one. Alternatively, you can call the fillSlowly()
 * method to simulate progress filling up slowly to 100% before calling finish().
 *
 * @example
 * ```ts
 * const tracker = new ProgressTracker();
 * tracker.addEventListener('progress', (e) => {
 *        console.log(
 *                e.detail.progress,
 *                e.detail.caption
 *        );
 * });
 *
 * const stage1 = tracker.stage(0.5, 'Calculating pi digits');
 * const stage2 = tracker.stage(0.5, 'Downloading data');
 *
 * await calc100DigitsOfPi();
 * stage1.finish();
 *
 * await fetchWithProgress(function onProgress(loaded, total) {
 *        stage2.set( loaded / total * 100);
 * });
 * stage2.finish();
 */
class Tracker implements ArrayAccess {
	private $selfWeight = 1;
	private $selfDone = false;
	private $selfProgress = 0;
	private $selfCaption = '';
	private $weight;
	private $subTrackers = array();
	private $splitPerformed = false;

	/**
	 * Most recently updated tracker or sub-tracker, used to expose
	 * the latest caption.
	 *
	 * One of:
	 *
	 * * Tracker instance – the last sub-tracker that was updated
	 * * null – when this tracker was updated more recently than
	 *          any of its sub-trackers
	 */
	private $lastUpdatedTracker = null;

	public $events;

	public function __construct( $options = array() ) {
		$this->weight      = $options['weight'] ?? 1;
		$this->selfCaption = $options['caption'] ?? '';
		$this->events      = new EventDispatcher();
	}

	/**
	 * split([
	 *   'alpha' => 6000,
	 *   'beta',             // null → implicit 1
	 *   'gamma' => null,    // explicit null → implicit 1
	 * ]);
	 * …behaves like weights [6000,1,1] ⇒ normalised to [0.99967,0.00017,0.00017]
	 */
	public function split( $definitions ) {
		if ( $this->subTrackers ) {
			throw new LogicException( 'split() must be called once and before any stage().' );
		}

		if ( is_int( $definitions ) ) {
			$definitions = range( 0, $definitions );
		}

		$items     = [];          // [slug, rawWeight|null, caption]
		$fixedSum  = 0.0;
		$nullCount = 0;

		foreach ( $definitions as $key => $value ) {
			if ( is_array( $value ) ) {
				$slug    = is_string( $key ) ? $key : ( $value['slug'] ?? $value[2] ?? "tracker_$key" );
				$weight  = $value['ratio'] ?? $value['weight'] ?? $value[0] ?? null;
				$caption = $value['caption'] ?? $value[1] ?? '';
			} else {
				$slug    = is_string( $key ) ? $key : $value;  // scalar value is slug
				$weight  = null;
				$caption = '';
			}
			if ( isset( $this->subTrackers[ $slug ] ) ) {
				throw new LogicException( "Duplicate slug '$slug'." );
			}
			if ( $weight === null ) {
				$nullCount ++;
			} elseif ( $weight <= 0 ) {
				throw new InvalidArgumentException( 'Weights must be positive numbers or null.' );
			} else {
				$fixedSum += $weight;
			}
			$items[] = [ $slug, $weight, $caption ];
		}

		if ( $items === [] ) {
			throw new InvalidArgumentException( 'split() needs at least one entry.' );
		}

		$scale = 1.0 / ( $fixedSum + $nullCount ?: 1 ); // if all null, fixedSum=0, nullCount>0

		foreach ( $items as [$slug, $raw, $caption] ) {
			$normWeight = ( $raw ?? 1 ) * $scale;  // null counts as 1 before scaling
			$this->createSubTracker( $slug, $normWeight, $caption );
		}

		$this->splitPerformed = true;
	}

	/**
	 * Creates a new sub-tracker with a specific weight.
	 *
	 * The weight determines what percentage of the overall progress
	 * the sub-tracker represents. For example, if the main tracker is
	 * monitoring a process that has two stages, and the first stage
	 * is expected to take twice as long as the second stage, you could
	 * create the first sub-tracker with a weight of 0.67 and the second
	 * sub-tracker with a weight of 0.33.
	 *
	 * The caption is an optional string that describes the current stage
	 * of the operation. If provided, it will be used as the progress caption
	 * for the sub-tracker. If not provided, the main tracker will look for
	 * the next sub-tracker with a non-empty caption and use that as the progress
	 * caption instead.
	 *
	 * Returns the newly-created sub-tracker.
	 *
	 * @param  weight The weight of the new stage, as a decimal value between 0 and 1.
	 * @param  caption The caption for the new stage, which will be used as the progress caption for the sub-tracker.
	 *
	 * @throws {Error} If the weight of the new stage would cause the total weight of all stages to exceed 1.
	 *
	 * @example
	 * ```ts
	 * const tracker = new ProgressTracker();
	 * const subTracker1 = tracker.stage(0.67, 'Slow stage');
	 * const subTracker2 = tracker.stage(0.33, 'Fast stage');
	 *
	 * subTracker2.set(50);
	 * subTracker1.set(75);
	 * subTracker2.set(100);
	 * subTracker1.set(100);
	 * ```
	 */
	public function stage( $weight = null, $caption = '' ) {
		if ( $this->splitPerformed ) {
			throw new LogicException( 'stage() is not allowed after split().' );
		}
		$weight = $weight ?? $this->selfWeight;

		return $this->createSubTracker( count( $this->subTrackers ), $weight, $caption );
	}

	/** ────────────── ArrayAccess: slug-aware, read-only ───────────── */
	public function offsetExists( $offset ): bool {
		return is_string( $offset )
			? isset( $this->subTrackers[ $offset ] )
			: array_key_exists( $offset, array_values( $this->subTrackers ) );
	}

	public function offsetGet( $offset ): Tracker {
		if ( is_string( $offset ) ) {
			if ( ! isset( $this->subTrackers[ $offset ] ) ) {
				throw new OutOfBoundsException( "Unknown tracker slug '$offset'." );
			}

			return $this->subTrackers[ $offset ];
		}
		$list = array_values( $this->subTrackers );
		if ( ! isset( $list[ $offset ] ) ) {
			throw new OutOfBoundsException( "No sub-tracker at index $offset." );
		}

		return $list[ $offset ];
	}

	public function offsetSet( $o, $v ): void {
		throw new LogicException( 'read-only' );
	}

	public function offsetUnset( $o ): void {
		throw new LogicException( 'read-only' );
	}

	/** ────────────────── createSubTracker() gets a slug ────────────────── */
	private function createSubTracker( string $slug, float $weight, string $caption ): Tracker {
		if ( $this->selfWeight - $weight < - 0.00001 ) {
			throw new LogicException( "Adding stage weight $weight would exceed total 1.0." );
		}
		$this->selfWeight -= $weight;

		$subTracker                 = new self( [ 'weight' => $weight, 'caption' => $caption ] );
		$this->subTrackers[ $slug ] = $subTracker;

		$subTracker->events->addListener(
			ProgressEvent::class,
			function () use ( $subTracker ) {
				$this->lastUpdatedTracker = $subTracker;
				$this->notifyProgress();
			}
		);
		$subTracker->events->addListener(
			DoneEvent::class,
			function () {
				if ( $this->isDone() ) {
					$this->notifyDone();
				}
			}
		);

		return $subTracker;
	}

	public function increment( $value = 1, $caption = null ) {
		$this->set( $this->getProgress() + $value, $caption );
	}

	/**
	 * @param  float  $value
	 * @param  string|null  $caption
	 */
	public function set( $value, $caption = null ) {
		if ( $value < $this->selfProgress ) {
			throw new InvalidArgumentException( "Progress cannot go backwards (tried updating to $value when it already was $this->selfProgress)" );
		}
		// Don't report the same progress twice
		if ( $this->selfProgress === $value && ( $caption === null || $this->selfCaption === $caption ) ) {
			return;
		}
		$this->selfProgress = min( $value, 100 );
		if ( $caption !== null ) {
			$this->selfCaption = $caption;
		}

		$this->lastUpdatedTracker = null;

		$this->notifyProgress();
		if ( $this->selfProgress + 0.00001 >= 100 ) {
			$this->finish();
		}
	}

	public function setCaption( $caption ) {
		$this->selfCaption        = $caption;
		$this->lastUpdatedTracker = null;
		$this->notifyProgress();
	}

	public function finish() {
		$this->selfDone           = true;
		$this->selfProgress       = 100;
		$this->lastUpdatedTracker = null;
		$this->notifyProgress();
		$this->notifyDone();
	}

	public function getCaption() {
		// If this tracker was most recently updated, return its caption
		if ( $this->lastUpdatedTracker === null ) {
			return $this->selfCaption;
		}

		// Otherwise return the caption of the most recently updated sub-tracker
		return $this->lastUpdatedTracker->getCaption();
	}

	public function isDone() {
		return $this->getProgress() + 0.00001 >= 100;
	}

	public function getProgress() {
		if ( $this->selfDone ) {
			return 100;
		}
		$sum = array_reduce(
			$this->subTrackers,
			function ( $sum, $tracker ) {
				return $sum + $tracker->getProgress() * $tracker->getWeight();
			},
			$this->selfProgress * $this->selfWeight
		);

		return round( $sum * 10000 ) / 10000;
	}

	public function getWeight() {
		return $this->weight;
	}

	private function notifyProgress() {
		$this->events->dispatch(
			ProgressEvent::class,
			new ProgressEvent(
				$this->getProgress(),
				$this->getCaption()
			)
		);
	}

	private function notifyDone() {
		$this->events->dispatch( DoneEvent::class, new DoneEvent() );
	}
}
