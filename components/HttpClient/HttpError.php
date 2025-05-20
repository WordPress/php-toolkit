<?php

namespace WordPress\HttpClient;

use Exception;

class HttpError extends Exception {
	public $message;

	public function __construct( $message ) {
		$this->message = $message;
	}

	public function __toString() {
		return $this->message;
	}
}
