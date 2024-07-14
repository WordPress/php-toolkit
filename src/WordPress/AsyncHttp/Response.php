<?php

namespace WordPress\AsyncHttp;

class Response {


	public $protocol;
	public $statusCode;
	public $statusMessage;
    public $headers = [];

    public $raw_response_stream;
    public $decoded_response_stream;
    public $event_loop_decoded_response_stream;

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
		if(false === $this->get_headers()) {
			return false;
		}

		return $this->headers[ strtolower($name) ] ?? null;
	}

	public function get_headers()
	{
		if(!$this->headers) {
			return false;
		}

		return $this->headers;
	}

	public function consume_buffer($length)
	{
		$buffer = substr($this->buffer, 0, $length);
		$this->buffer = substr($this->buffer, $length);
		return $buffer;
	}

}
