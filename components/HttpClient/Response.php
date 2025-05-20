<?php

namespace WordPress\HttpClient;

use WordPress\HttpServer\StatusCode;

class Response {

	public $protocol;
	public $status_code;
	public $status_message;
	public $headers = array();
	public $request;

	public $received_bytes = 0;
	public $total_bytes = null;

	public function __construct( Request $request ) {
		$this->request = $request;
	}

	public function get_header( $name ) {
		return $this->headers[ strtolower( $name ) ] ?? null;
	}

	public function get_reason_phrase() {
		return StatusCode::text( $this->status_code );
	}

	public function ok() {
		return $this->status_code >= 200 && $this->status_code < 400;
	}
}
