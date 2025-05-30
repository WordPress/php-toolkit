<?php

namespace WordPress\HttpClient;

class Connection {

	public $request;
	public $http_socket;
	public $response_buffer = '';
	public $decoded_response_stream = null;
	public $started_at = null;

	public function __construct( Request $request ) {
		$this->request = $request;
	}

	public function consume_buffer( $length = null ) {
		if ( $length === null ) {
			$length = strlen( $this->response_buffer );
		}
		$buffer                = substr( $this->response_buffer, 0, $length );
		$this->response_buffer = substr( $this->response_buffer, $length );

		return $buffer;
	}

	public function time_elapsed_ms() {
		return (microtime( true ) - $this->started_at) * 1000;
	}
}
