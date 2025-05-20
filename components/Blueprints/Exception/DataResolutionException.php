<?php

namespace WordPress\Blueprints\Exception;

use Throwable;

/**
 * Exception thrown when there is an error resolving data references.
 */
class DataResolutionException extends BlueprintExecutionException {
	public function __construct( string $message, $code = 0, ?Throwable $previous = null ) {
		parent::__construct( $message, $code, $previous );
	}
}
