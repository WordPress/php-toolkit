<?php

namespace WordPress\HttpServer\Response;

use RuntimeException;
use WordPress\ByteStream\WriteStream\ByteWriteStream;
use WordPress\ByteStream\WriteStream\TransformedWriteStream;
use WordPress\HttpClient\ByteStream\ChunkedEncoderByteTransformer;
use WordPress\HttpServer\StatusCode;

class TcpResponseWriteStream implements ResponseWriteStream {
	/**
	 * @var ByteWriteStream
	 */
	private $upstream;

	/**
	 * @var int
	 */
	public $http_code = 200;

	/**
	 * @var array
	 */
	private $headers = array();

	/**
	 * @var bool
	 */
	private $headers_sent = false;

	/**
	 * @var bool
	 */
	private $closed = false;

	/**
	 * @var ByteWriteStream
	 */
	private $writer;

	public function __construct( ByteWriteStream $upstream ) {
		$this->upstream = $upstream;
		$this->writer   = new TransformedWriteStream( $this->upstream, array() );
	}

	public function send_http_code( $code ) {
		if ( $this->headers_sent ) {
			throw new RuntimeException( 'Cannot set HTTP code after headers have been sent' );
		}
		$this->http_code = (int) $code;
	}

	public function send_header( $name, $value ) {
		if ( $this->headers_sent ) {
			throw new RuntimeException( 'Cannot send header after headers have been sent' );
		}
		$lname                   = strtolower( $name );
		$this->headers[ $lname ] = array( $name, $value );
	}

	/**
	 * Enables streaming bytes to the client without sending the HTTP headers first.
	 */
	public function dangerously_mark_headers_as_sent() {
		$this->headers_sent = true;
	}

	private function send_headers_if_needed() {
		if ( $this->headers_sent ) {
			return;
		}

		// Status line.
		$status_text = StatusCode::text( $this->http_code );
		$this->upstream->append_bytes( "HTTP/1.1 {$this->http_code} {$status_text}\r\n" );

		// Headers.
		foreach ( $this->headers as [$name, $value] ) {
			$this->upstream->append_bytes( "{$name}: {$value}\r\n" );
		}

		// End of headers.
		$this->upstream->append_bytes( "\r\n" );

		$this->headers_sent = true;
	}

	public function use_chunked_encoding() {
		$this->writer['chunked'] = new ChunkedEncoderByteTransformer();
		$this->send_header( 'Transfer-Encoding', 'chunked' );
	}

	public function append_bytes( string $bytes ): void {
		if ( $this->closed ) {
			throw new RuntimeException( 'Cannot write to closed ResponseWriteStream' );
		}
		$this->send_headers_if_needed();
		$this->writer->append_bytes( $bytes );
	}

	public function is_writing_closed(): bool {
		return $this->closed;
	}

	public function close_writing(): void {
		if ( $this->closed ) {
			throw new RuntimeException( 'ResponseWriteStream already closed' );
		}
		$this->send_headers_if_needed();
		$this->writer->close_writing();
		$this->closed = true;
	}
}
