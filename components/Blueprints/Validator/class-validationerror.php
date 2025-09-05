<?php

namespace WordPress\Blueprints\Validator;

/**
 * Represents a single validation error.
 */
class ValidationError {
	/**
	 * @var string
	 */
	public $pointer;
	/**
	 * @var string
	 */
	public $code;
	/**
	 * @var string
	 */
	public $message;
	/**
	 * @var array
	 */
	public $context = array();
	/**
	 * @var ValidationError[]
	 */
	public $children = array();

	/**
	 * @param  string            $pointer  JSON Pointer like /steps/0/data/url
	 * @param  string            $code  short, stable key: required, type-mismatch …
	 * @param  string            $message  human sentence
	 * @param  array             $context  expected/actual/allowed, always associative
	 * @param  ValidationError[] $children  nested causes
	 */
	public function __construct( string $pointer, string $code, string $message, array $context = array(), array $children = array() ) {
		$this->pointer  = $pointer;
		$this->code     = $code;
		$this->message  = $message;
		$this->context  = $context;
		$this->children = $children;
	}

	public function getPath(): array {
		$path_string = substr( $this->pointer, 2 );
		if ( ! $path_string ) {
			return array();
		}

		return explode( '/', $path_string );
	}

	public function getPrettyPath(): string {
		$segments = array( 'Blueprint root' );
		foreach ( $this->getPath() as $segment ) {
			if ( ctype_digit( $segment ) ) {
				$segment = (int) $segment;
			}
			$segments[] = '[' . json_encode( $segment ) . ']';
		}

		return implode( '', $segments );
	}

	/**
	 * Gets the most probable cause of this validation error.
	 * If this error has no children, it is the most probable cause.
	 * Otherwise, it recursively calls getMostProbableCause on its children
	 * and returns the one with the fewest descendants (naïve: first child if counts are equal).
	 */
	public function getMostProbableCause(): ?ValidationError {
		if ( empty( $this->children ) ) {
			return null;
		}

		$minChild            = null;
		$minDescendantsCount = PHP_INT_MAX;

		/**
		 * Choose the child with the fewest children as the most probable cause.
		 *
		 * Rationale: we're looking for the shape that's the closest to the data we've got.
		 */
		foreach ( $this->children as $child ) {
			$currentChildDescendantsCount = count( $child->children );
			if ( $currentChildDescendantsCount < $minDescendantsCount ) {
				$minDescendantsCount = $currentChildDescendantsCount;
				$minChild            = $child;
			}
		}

		// Collapse all required-field-missing errors into a single error
		if ( $minChild->code === 'required-field-missing' ) {
			$missingFields = array();
			foreach ( $this->children as $child ) {
				if ( $child->code === 'required-field-missing' ) {
					$missingFields[] = $child->context['missingField'];
				}
			}
			if ( count( $missingFields ) > 1 ) {
				return new ValidationError(
					$minChild->pointer,
					'required-field-missing',
					sprintf( 'Missing required fields: %s.', implode( ', ', $missingFields ) ),
					$minChild->context,
					$minChild->children
				);
			}
		}

		return $minChild;
	}
}
