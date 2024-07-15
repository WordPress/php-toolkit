<?php

namespace WordPress\AsyncHttp;

class Request {

	const STATE_ENQUEUED = 'STATE_ENQUEUED';
	const STATE_WILL_ENABLE_CRYPTO = 'STATE_WILL_ENABLE_CRYPTO';
	const STATE_WILL_SEND_HEADERS = 'STATE_WILL_SEND_HEADERS';
	const STATE_WILL_SEND_BODY = 'STATE_WILL_SEND_BODY';
	const STATE_SENT = 'STATE_SENT';
	const STATE_RECEIVING_HEADERS = 'STATE_RECEIVING_HEADERS';
	const STATE_RECEIVING_BODY = 'STATE_RECEIVING_BODY';
	const STATE_RECEIVED = 'STATE_RECEIVED';
	const STATE_FAILED = 'STATE_FAILED';
	const STATE_FINISHED = 'STATE_FINISHED';

	static private $last_id;

	public $id;

	public $state = self::STATE_ENQUEUED;

	public $url;
	public $is_ssl;
	public $method;
	public $headers;
	public $http_version;
	public $upload_body_stream;
	public $redirected_from;
	public $redirected_to;

	public $error;
	public $response;

	/**
	 * @param  string  $url
	 */
	public function __construct( string $url, $request_info = array() ) {
		$request_info = array_merge( [
			'http_version'    => '1.1',
			'method'          => 'GET',
			'headers'         => [],
			'body_stream'     => null,
			'redirected_from' => null,
		], $request_info );

		$this->id     = ++ self::$last_id;
		$this->url    = $url;
		$this->is_ssl = strpos( $url, 'https://' ) === 0;

		$this->method             = $request_info['method'];
		$this->headers            = $request_info['headers'];
		$this->upload_body_stream = $request_info['body_stream'];
		$this->http_version       = $request_info['http_version'];
		$this->redirected_from    = $request_info['redirected_from'];
		if ( $this->redirected_from ) {
			$this->redirected_from->redirected_to = $this;
		}
	}

	public function latest_redirect() {
		$request = $this;
		while ( $request->redirected_to ) {
			$request = $request->redirected_to;
		}

		return $request;
	}

}
