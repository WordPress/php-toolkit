<?php

namespace WordPress\AsyncHttp;

class Request {

	const STATE_ENQUEUED           = 'STATE_ENQUEUED';
	const STATE_WILL_ENABLE_CRYPTO = 'STATE_WILL_ENABLE_CRYPTO';
	const STATE_WILL_SEND_HEADERS  = 'STATE_WILL_SEND_HEADERS';
	const STATE_WILL_SEND_BODY     = 'STATE_WILL_SEND_BODY';
	const STATE_SENT               = 'STATE_SENT';
	const STATE_RECEIVING_HEADERS  = 'STATE_RECEIVING_HEADERS';
	const STATE_RECEIVING_BODY     = 'STATE_RECEIVING_BODY';
	const STATE_RECEIVED           = 'STATE_RECEIVED';
	const STATE_FAILED             = 'STATE_FAILED';
	const STATE_FINISHED           = 'STATE_FINISHED';

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
		if($this->state === self::STATE_ENQUEUED) {
			$this->method = $method;
		} else {
			trigger_error('Cannot change method after the request has been sent', E_USER_WARNING);
		}
		return $this;
	}

	public function set_headers(array $headers)
	{
		if($this->state === self::STATE_ENQUEUED) {
			$this->headers = $headers;
		} else {
			trigger_error('Cannot change headers after the request has been sent', E_USER_WARNING);
		}
		return $this;
	}

	public function set_http_version(string $http_version)
	{
		if($this->state === self::STATE_ENQUEUED) {
			$this->http_version = $http_version;
		} else {
			trigger_error('Cannot change http_version after the request has been sent', E_USER_WARNING);
		}

		return $this;
	}

	public function set_upload_body_stream($upload_body_stream)
	{
		if($this->state === self::STATE_ENQUEUED) {
			$this->upload_body_stream = $upload_body_stream;
		} else {
			trigger_error('Cannot change upload_body_stream after the request has been sent', E_USER_WARNING);
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

	public function set_error($error)
	{
		$this->error = $error;
		$this->state = self::STATE_FAILED;

		$this->response->error = $error;
		$this->response->state = self::STATE_FAILED;

		if($this->http_socket) {
			fclose($this->http_socket);
			$this->http_socket = null;
		}
	}

	/**
	 * @return Response
	 */
	public function get_response() {
		return $this->response;
	}

}
