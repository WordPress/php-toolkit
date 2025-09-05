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

		$min_child             = null;
		$min_descendants_count = PHP_INT_MAX;

		/**
		 * Choose the child with the fewest children as the most probable cause.
		 *
		 * Rationale: we're looking for the shape that's the closest to the data we've got.
		 */
		foreach ( $this->children as $child ) {
			$current_child_descendants_count = count( $child->children );
			if ( $current_child_descendants_count < $min_descendants_count ) {
				$min_descendants_count = $current_child_descendants_count;
				$min_child             = $child;
			}
		}

		// Collapse all required-field-missing errors into a single error.
		if ( 'required-field-missing' === $min_child->code ) {
			$missing_fields = array();
			foreach ( $this->children as $child ) {
				if ( 'required-field-missing' === $child->code ) {
					$missing_fields[] = $child->context['missingField'];
				}
			}
			if ( count( $missing_fields ) > 1 ) {
				return new ValidationError(
					$min_child->pointer,
					'required-field-missing',
					sprintf( 'Missing required fields: %s.', implode( ', ', $missing_fields ) ),
					$min_child->context,
					$min_child->children
				);
			}
		}

		return $min_child;
	}
}
