<?php

namespace WordPress\AsyncHttp;

class InternalRequestState {
	const STATE_ENQUEUED  = 'STATE_ENQUEUED';
	const STATE_SOCKET_OPEN = 'STATE_SOCKET_OPEN';
	const STATE_FINISHED  = 'STATE_FINISHED';
	public $state         = self::STATE_ENQUEUED;
	public $protocol;
	public $statusCode;
	public $statusMessage;
    public $headers;
    public $body_stream;
	public $buffer = '';

	public $request;
	public $response;


	/**
	 * @param $stream
	 */
	public function __construct( $request ) {
		$this->request = $request;
		$this->response = new Response( $this );
	}

	public function is_finished() {
		return $this->state === self::STATE_FINISHED;
	}

	public function get_header( $name ) {
		if($this->headers === null) {
			return false;
		}

		return $this->headers[ strtolower($name) ] ?? null;
	}
}
