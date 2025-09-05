<?php

namespace WordPress\HttpServer\Response;

class BufferingResponseWriter implements ResponseWriteStream {

	private $http_code = 200;
	private $headers   = array();
	private $body      = '';

	public function send_http_code( $code ) {
		$this->http_code = $code;
	}

	public function send_header( $name, $value ) {
		$this->headers[ $name ] = $value;
	}

	public function get_buffered_headers() {
		return $this->headers;
	}

	public function append_bytes( $body ): void {
		$this->body .= $body;
	}

	public function get_buffered_body() {
		return $this->body;
	}

	public function close_writing(): void {
		http_response_code( $this->http_code );
		foreach ( $this->headers as $key => $value ) {
			header( $key . ': ' . $value );
		}
		header( 'Content-Length: ' . strlen( $this->body ) );

		echo $this->body;
	}
}
