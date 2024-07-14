<?php

namespace WordPress\AsyncHttp;

class Connection {

	public $request;
	public $http_socket;
	public $response_buffer;
	public $decoded_response_stream;

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

}
