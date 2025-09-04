<?php

namespace WordPress\HttpServer\Response;

class StreamingResponseWriter implements ResponseWriteStream {

	public function send_http_code( $code ) {
		http_response_code( $code );
	}

	public function send_header( $name, $value ) {
		header( "$name: $value" );
	}

	public function append_bytes( $body ): void {
		echo $body;
	}

	public function close_writing(): void {
		flush();
	}
}
