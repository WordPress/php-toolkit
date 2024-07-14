<?php

namespace WordPress\AsyncHttp\StreamWrapper;

use WordPress\AsyncHttp\Client;
use WordPress\AsyncHttp\Request;
use WordPress\Streams\StreamWrapper;

class EventLoopWrapper extends StreamWrapper {

	const SCHEME = 'async-http';

	/** @var Client */
	private $client;
	private $request;

	static public function wrap(Request $request, $http_socket, Client $client ) {
		return static::create_resource( [
			'request' => $request,
			'http_socket' => $http_socket,
			'client' => $client
		] );
	}

	protected function do_initialize() {
		$this->stream = $this->wrapper_data['http_socket'];
		$this->client = $this->wrapper_data['client'];
		$this->request = $this->wrapper_data['request'];
	}

	public function stream_read( $count ) {
		$this->client->event_loop_tick();
		return $this->client->read_bytes( $this->request, $count );
	}
}
