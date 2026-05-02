<?php

use WordPress\HttpServer\Response\ResponseWriteStream;

class WP_Origin_Buffering_Response implements ResponseWriteStream {
	private $http_code = 200;
	private $headers   = array();
	private $body      = '';

	public function send_http_code( $code ) {
		$this->http_code = $code;
	}

	public function send_header( $name, $value ) {
		$this->headers[ $name ] = $value;
	}

	public function append_bytes( $body ): void {
		$this->body .= $body;
	}

	public function close_writing(): void {
	}

	public function to_rest_response() {
		$response = new WP_REST_Response( $this->body, $this->http_code );
		foreach ( $this->headers as $name => $value ) {
			$response->header( $name, $value );
		}

		return $response;
	}
}
