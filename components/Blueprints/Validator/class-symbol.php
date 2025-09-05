<?php

namespace WordPress\Blueprints\Validator;

// @TODO: Reconsider the need for the Symbol class. We use it.
// as a unique reference that can't be possibly brought.
// in with the validated data.
class Symbol {
	/**
	 * @var string
	 */
	public $value;

	public function __construct( string $value ) {
		$this->value = $value;
	}

	public function __toString(): string {
		return $this->value;
	}
}
