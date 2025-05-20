<?php

namespace WordPress\HttpServer\Response;

use WordPress\ByteStream\WriteStream\ByteWriteStream;

interface ResponseWriteStream extends ByteWriteStream {

	public function send_http_code( $code );

	public function send_header( $name, $value );
}
