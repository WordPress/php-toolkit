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
	private $self_weight   = 1;
	private $self_done     = false;
	private $self_progress = 0;
	private $self_caption  = '';
	private $weight;
	private $sub_trackers    = array();
	private $split_performed = false;

	/**
	 * Most recently updated tracker or sub-tracker, used to expose
	 * the latest caption.
	 *
	 * One of:
	 *
	 * * Tracker instance – the last sub-tracker that was updated
	 * * null – when this tracker was updated more recently than
	 *          any of its sub-trackers
	 *
	 * @var Tracker|null
	 */
	private $last_updated_tracker = null;

	public $events;

	public function __construct( $options = array() ) {
		$this->weight       = $options['weight'] ?? 1;
		$this->self_caption = $options['caption'] ?? '';
		$this->events       = new EventDispatcher();
	}

	/**
	 * ```
	 * split([
	 *   'alpha' => 6000,
	 *   'beta',             // null → implicit 1
	 *   'gamma' => null,    // explicit null → implicit 1
	 * ]);
	 * ```
	 * …behaves like weights [6000,1,1] ⇒ normalised to [0.99967,0.00017,0.00017]
	 */
	public function split( $definitions ) {
		if ( $this->sub_trackers ) {
			throw new LogicException( 'split() must be called once and before any stage().' );
		}

		if ( is_int( $definitions ) ) {
			$definitions = range( 0, $definitions );
		}

		$items      = array();          // [slug, rawWeight|null, caption].
		$fixed_sum  = 0.0;
		$null_count = 0;

		foreach ( $definitions as $key => $value ) {
			if ( is_array( $value ) ) {
				$slug    = is_string( $key ) ? $key : ( $value['slug'] ?? $value[2] ?? "tracker_$key" );
				$weight  = $value['ratio'] ?? $value['weight'] ?? $value[0] ?? null;
				$caption = $value['caption'] ?? $value[1] ?? '';
			} else {
				$slug    = is_string( $key ) ? $key : $value;  // scalar value is slug.
				$weight  = null;
				$caption = '';
			}
			if ( isset( $this->sub_trackers[ $slug ] ) ) {
				throw new LogicException( esc_html( "Duplicate slug '$slug'." ) );
			}
			if ( null === $weight ) {
				++$null_count;
			} elseif ( $weight <= 0 ) {
				throw new InvalidArgumentException( 'Weights must be positive numbers or null.' );
			} else {
				$fixed_sum += $weight;
			}
			$items[] = array( $slug, $weight, $caption );
		}

		if ( array() === $items ) {
			throw new InvalidArgumentException( 'split() needs at least one entry.' );
		}

		$scale = 1.0 / ( $fixed_sum + $null_count ? $fixed_sum + $null_count : 1 ); // if all null, fixedSum=0, nullCount>0.

		foreach ( $items as [$slug, $raw, $caption] ) {
			$norm_weight = ( $raw ?? 1 ) * $scale;  // null counts as 1 before scaling.
			$this->createSubTracker( $slug, $norm_weight, $caption );
		}

		$this->split_performed = true;
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
	 * @param  $weight float The weight of the new stage, as a decimal value between 0 and 1.
	 * @param  $caption string The caption for the new stage, which will be used as the progress caption for the sub-tracker.
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
		if ( $this->split_performed ) {
			throw new LogicException( 'stage() is not allowed after split().' );
		}
		$weight = $weight ?? $this->self_weight;

		return $this->createSubTracker( count( $this->sub_trackers ), $weight, $caption );
	}

	/** ────────────── ArrayAccess: slug-aware, read-only ───────────── */
	public function offsetExists( $offset ): bool {
		return is_string( $offset )
			? isset( $this->sub_trackers[ $offset ] )
			: array_key_exists( $offset, array_values( $this->sub_trackers ) );
	}

	public function offsetGet( $offset ): Tracker {
		if ( is_string( $offset ) ) {
			if ( ! isset( $this->sub_trackers[ $offset ] ) ) {
				throw new OutOfBoundsException( "Unknown tracker slug '$offset'." );
			}

			return $this->sub_trackers[ $offset ];
		}
		$list = array_values( $this->sub_trackers );
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
		if ( $this->self_weight - $weight < - 0.00001 ) {
			throw new LogicException( "Adding stage weight $weight would exceed total 1.0." );
		}
		$this->self_weight -= $weight;

		$sub_tracker                 = new self(
			array(
				'weight'  => $weight,
				'caption' => $caption,
			)
		);
		$this->sub_trackers[ $slug ] = $sub_tracker;

		$sub_tracker->events->addListener(
			ProgressEvent::class,
			function () use ( $sub_tracker ) {
				$this->last_updated_tracker = $sub_tracker;
				$this->notifyProgress();
			}
		);
		$sub_tracker->events->addListener(
			DoneEvent::class,
			function () {
				if ( $this->isDone() ) {
					$this->notifyDone();
				}
			}
		);

		return $sub_tracker;
	}

	public function increment( $value = 1, $caption = null ) {
		$this->set( $this->getProgress() + $value, $caption );
	}

	/**
	 * @param  float       $value
	 * @param  string|null $caption
	 */
	public function set( $value, $caption = null ) {
		if ( $value < $this->self_progress ) {
			throw new InvalidArgumentException( "Progress cannot go backwards (tried updating to $value when it already was $this->self_progress)" );
		}
		// Don't report the same progress twice.
		if ( $this->self_progress === $value && ( null === $caption || $this->self_caption === $caption ) ) {
			return;
		}
		$this->self_progress = min( $value, 100 );
		if ( null !== $caption ) {
			$this->self_caption = $caption;
		}

		$this->last_updated_tracker = null;

		$this->notifyProgress();
		if ( $this->self_progress + 0.00001 >= 100 ) {
			$this->finish();
		}
	}

	public function setCaption( $caption ) {
		$this->self_caption         = $caption;
		$this->last_updated_tracker = null;
		$this->notifyProgress();
	}

	public function finish() {
		$this->self_done            = true;
		$this->self_progress        = 100;
		$this->last_updated_tracker = null;
		$this->notifyProgress();
		$this->notifyDone();
	}

	public function getCaption() {
		// If this tracker was most recently updated, return its caption.
		if ( null === $this->last_updated_tracker ) {
			return $this->self_caption;
		}

		// Otherwise return the caption of the most recently updated sub-tracker.
		return $this->last_updated_tracker->getCaption();
	}

	public function isDone() {
		return $this->getProgress() + 0.00001 >= 100;
	}

	public function getProgress() {
		if ( $this->self_done ) {
			return 100;
		}
		$sum = array_reduce(
			$this->sub_trackers,
			function ( $sum, $tracker ) {
				return $sum + $tracker->getProgress() * $tracker->getWeight();
			},
			$this->self_progress * $this->self_weight
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
