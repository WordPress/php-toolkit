<?php

namespace WordPress\Blueprints\VersionStrings;

class VersionConstraint {
	/**
	 * @var Version|null
	 */
	private $min;
	/**
	 * @var Version|null
	 */
	private $max;
	/**
	 * @var Version|null
	 */
	private $recommended;

	public function __construct(
		?object $min = null,
		?object $max = null,
		?object $recommended = null
	) {
		$this->min         = $min;
		$this->max         = $max;
		$this->recommended = $recommended;
	}

	public function getMin(): ?object {
		return $this->min;
	}

	public function getMax(): ?object {
		return $this->max;
	}

	public function getRecommended(): ?object {
		return $this->recommended;
	}

	/**
	 * Validate the constraint for logical consistency.
	 * Returns an array of error messages (empty if valid).
	 */
	public function validate(): array {
		$errors = array();
		if ( $this->min !== null && $this->max !== null ) {
			if ( $this->min->is( '>', $this->max ) ) {
				$errors[] = sprintf( 'min (%s) is greater than max (%s)', $this->min, $this->max );
			}
		}
		if ( $this->recommended !== null ) {
			if ( $this->min !== null && ! $this->recommended->is( '>=', $this->min ) ) {
				$errors[] = sprintf( 'recommended (%s) must be between min (%s) and max', $this->recommended, $this->min );
			}
			if ( $this->max !== null && ! $this->recommended->is( '<=', $this->max ) ) {
				$errors[] = sprintf( 'recommended (%s) was not between min (%s) and max (%s)', $this->recommended, $this->min, $this->max );
			}
		}

		return $errors;
	}

	/**
	 * Checks if a version string satisfies the constraint.
	 */
	public function satisfiedBy( Version $version ): bool {
		if ( $this->min !== null && ! $version->is( '>=', $this->min ) ) {
			return false;
		}
		if ( $this->max !== null && ! $version->is( '<=', $this->max ) ) {
			return false;
		}

		return true;
	}

	public function __toString(): string {
		$parts = array();
		if ( $this->min !== null ) {
			$parts[] = "min: {$this->min}";
		}
		if ( $this->max !== null ) {
			$parts[] = "max: {$this->max}";
		}
		if ( $this->recommended !== null ) {
			$parts[] = "recommended: {$this->recommended}";
		}

		return sprintf( 'VersionConstraint(%s)', implode( ', ', $parts ) );
	}
}
