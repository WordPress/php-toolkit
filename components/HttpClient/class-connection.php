<?php

namespace WordPress\HttpClient;

class Connection {

	public $request;
	public $http_socket;
	public $response_buffer         = '';
	public $decoded_response_stream = null;
	public $started_at              = null;
	public $last_activity_at        = null;

	public function __construct( Request $request ) {
		$this->request = $request;
	}

	public function consume_buffer( $length = null ) {
		if ( null === $length ) {
			$length = strlen( $this->response_buffer );
		}
		$buffer                = substr( $this->response_buffer, 0, $length );
		$this->response_buffer = substr( $this->response_buffer, $length );

		return $buffer;
	}

	public function mark_activity() {
		$this->last_activity_at = microtime( true );
	}

	/**
	 * Milliseconds since the connection last sent or received bytes,
	 * or since it was opened if no bytes have moved yet.
	 */
	public function idle_time_ms() {
		$since = null !== $this->last_activity_at ? $this->last_activity_at : $this->started_at;
		if ( null === $since ) {
			return 0;
		}
		return ( microtime( true ) - $since ) * 1000;
	}

	public function time_elapsed_ms() {
		if ( null === $this->started_at ) {
			return 0;
		}
		return ( microtime( true ) - $this->started_at ) * 1000;
	}
}
