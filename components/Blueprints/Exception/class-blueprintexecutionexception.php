<?php

namespace WordPress\Blueprints\Exception;

use Exception;
use Throwable;
use WordPress\Blueprints\Validator\ValidationError;

class BlueprintExecutionException extends Exception {
	/**
	 * @var ValidationError|null
	 */
	public $schema_error;

	public function __construct( string $message, $code = 0, ?Throwable $previous = null, ?ValidationError $schema_error = null ) {
		parent::__construct( $message, $code, $previous );
		$this->schema_error = $schema_error;
	}
}
