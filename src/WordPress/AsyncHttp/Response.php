<?php

namespace WordPress\AsyncHttp;

class Response {

	const STATE_ENQUEUED  = 'STATE_ENQUEUED';
	const STATE_SOCKET_OPEN = 'STATE_SOCKET_OPEN';
	const STATE_FAILED    = 'STATE_FAILED';
	const STATE_FINISHED  = 'STATE_FINISHED';
	public $state         = self::STATE_ENQUEUED;

	public $protocol;
	public $statusCode;
	public $statusMessage;
    public $headers;
    public $body_stream;
    public $internal_body_stream;
	public $buffer = '';
	private $request;

    public function __construct(Request $request) {
        $this->request = $request;
    }

	public function get_request()
	{
		return $this->request;
	}

	public function get_status_code()
	{
		return $this->statusCode;
	}

	public function get_status_message()
	{
		return $this->statusMessage;
	}

	public function get_protocol()
	{
		return $this->protocol;		
	}

	public function get_header( $name ) {
		if($this->headers === null) {
			return false;
		}

		return $this->headers[ strtolower($name) ] ?? null;
	}

	public function get_headers()
	{
		return $this->headers;
	}

	public function get_body_stream()
	{
		return $this->body_stream;
	}

	public function is_enqueued() {
		return $this->state === self::STATE_ENQUEUED;
	}

	public function is_socket_open() {
		return $this->state === self::STATE_SOCKET_OPEN;
	}

	public function is_failed() {
		return $this->state === self::STATE_FAILED;
	}

	public function is_finished() {
		return $this->state === self::STATE_FINISHED;
	}

}
