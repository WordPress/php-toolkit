<?php

namespace WordPress\AsyncHttp;

class Request {

	const STATE_ENQUEUED  = 'STATE_ENQUEUED';
	const STATE_SENDING   = 'STATE_SENDING';
	const STATE_FAILED    = 'STATE_FAILED';
	const STATE_SENT      = 'STATE_SENT';
	const STATE_RECEIVING_HEADERS = 'STATE_RECEIVING_HEADERS';
	const STATE_RECEIVING_BODY = 'STATE_RECEIVING_BODY';
	const STATE_FINISHED = 'STATE_FINISHED';
	public $state         = self::STATE_ENQUEUED;

	public $url;
	public $is_ssl;
	public $method;
	public $headers;
	public $http_version;
	public $upload_body_stream;
	public $redirected_from;
	public $http_socket;

	public $error;
	protected $response;

	/**
	 * @param string $url
	 */
	public function __construct( string $url, $method='GET', $headers=[], $body_stream=null, $http_version='1.1' ) {
		$this->url = $url;
		$this->is_ssl = strpos( $url, 'https://' ) === 0;

		$this->method = $method;
		$this->headers = $headers;
		$this->upload_body_stream = $body_stream;
		$this->http_version = $http_version;
		$this->response = new Response( $this );
	}

	public function set_method(string $method)
	{
		if($this->http_socket === null) {
			$this->method = $method;
		} else {
			trigger_error('Cannot change method after request has been sent', E_USER_WARNING);
		}
		return $this;
	}

	public function set_headers(array $headers)
	{
		if($this->http_socket === null) {
			$this->headers = $headers;
		} else {
			trigger_error('Cannot change headers after request has been sent', E_USER_WARNING);
		}
		return $this;
	}

	public function set_http_version(string $http_version)
	{
		if($this->http_socket === null) {
			$this->http_version = $http_version;
		} else {
			trigger_error('Cannot change http_version after request has been sent', E_USER_WARNING);
		}

		return $this;
	}

	public function set_upload_body_stream($upload_body_stream)
	{
		if($this->http_socket === null) {
			$this->upload_body_stream = $upload_body_stream;
		} else {
			trigger_error('Cannot change upload_body_stream after request has been sent', E_USER_WARNING);
		}

		return $this;
	}

	public function set_redirected_from($request)
	{
		if($this->redirected_from === null) {
			$this->redirected_from = $request;
		} else {
			trigger_error('Cannot change redirected_from after it was already set', E_USER_WARNING);
		}
		return $this;		
	}

	public function set_http_socket($socket)
	{
		if(!$this->response->is_enqueued()) {
			trigger_error('Cannot set http_socket on a request that is not in an "enqueued" state', E_USER_WARNING);
			return $this;
		}

		if($this->http_socket !== null) {
			trigger_error('Cannot change http_socket after it was already set', E_USER_WARNING);
			return $this;
		}

		$this->http_socket = $socket;
		$this->state = self::STATE_SENDING;
		return $this;		
	}

	public function set_error($error)
	{
		$this->error = $error;
		$this->state = self::STATE_FAILED;

		$this->response->error = $error;
		$this->response->state = self::STATE_FAILED;
	}

	/**
	 * @return Response
	 */
	public function get_response() {
		return $this->response;
	}

	public function is_enqueued() {
		return $this->state === self::STATE_ENQUEUED;
	}

	public function is_sending() {
		return $this->state === self::STATE_SENDING;
	}

	public function is_sent() {
		return $this->state === self::STATE_SENT;
	}

	public function is_failed() {
		return $this->state === self::STATE_FAILED;
	}

	public function is_receiving_headers() {
		return $this->state === self::STATE_RECEIVING_HEADERS;
	}

	public function is_receiving_body() {
		return $this->state === self::STATE_RECEIVING_BODY;
	}

	public function is_finished() {
		return $this->state === self::STATE_FINISHED;
	}

}
