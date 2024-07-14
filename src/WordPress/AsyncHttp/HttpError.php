<?php

namespace WordPress\AsyncHttp;

class HttpError {
	public $message;

	public function __construct( $message ) {
		$this->message = $message;
	}
}
