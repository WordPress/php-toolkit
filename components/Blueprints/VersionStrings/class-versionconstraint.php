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

	public function get_min(): ?object {
		return $this->min;
	}

	public function get_max(): ?object {
		return $this->max;
	}

	public function get_recommended(): ?object {
		return $this->recommended;
	}

	/**
	 * Validate the constraint for logical consistency.
	 * Returns an array of error messages (empty if valid).
	 */
	public function validate(): array {
		$errors = array();
		if ( null !== $this->min && null !== $this->max ) {
			if ( $this->min->is( '>', $this->max ) ) {
				$errors[] = sprintf( 'min (%s) is greater than max (%s)', $this->min, $this->max );
			}
		}
		if ( null !== $this->recommended ) {
			if ( null !== $this->min && ! $this->recommended->is( '>=', $this->min ) ) {
				$errors[] = sprintf( 'recommended (%s) must be between min (%s) and max', $this->recommended, $this->min );
			}
			if ( null !== $this->max && ! $this->recommended->is( '<=', $this->max ) ) {
				$errors[] = sprintf( 'recommended (%s) was not between min (%s) and max (%s)', $this->recommended, $this->min, $this->max );
			}
		}

		return $errors;
	}

	/**
	 * Checks if a version string satisfies the constraint.
	 */
	public function satisfied_by( Version $version ): bool {
		if ( null !== $this->min && ! $version->is( '>=', $this->min ) ) {
			return false;
		}
		if ( null !== $this->max && ! $version->is( '<=', $this->max ) ) {
			return false;
		}

		return true;
	}

	public function __toString(): string {
		$parts = array();
		if ( null !== $this->min ) {
			$parts[] = "min: {$this->min}";
		}
		if ( null !== $this->max ) {
			$parts[] = "max: {$this->max}";
		}
		if ( null !== $this->recommended ) {
			$parts[] = "recommended: {$this->recommended}";
		}

		return sprintf( 'VersionConstraint(%s)', implode( ', ', $parts ) );
	}
}
