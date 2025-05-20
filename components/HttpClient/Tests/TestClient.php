<?php

namespace WordPress\HttpClient\Tests;

use WordPress\HttpClient\Client;
use WordPress\HttpClient\Response;

class TestClient extends Client {

	public function getConcurrency() {
		return $this->concurrency;
	}

	public function getMaxRedirects() {
		return $this->max_redirects;
	}

	public function getTimeout() {
		return $this->timeout;
	}

	public function getRequests() {
		return $this->requests;
	}

	public function simulateEvent( $event, $request ) {
		$this->events[ $request->id ][ $event ] = true;
	}

	public function simulateError( $request, $error ) {
		$this->set_error( $request, $error );
	}

	public function simulateRedirect( $request, $url ) {
		$request->response              = new Response( $request );
		$request->response->status_code = 301;
		$request->response->headers     = array(
			'location' => $url,
		);
		$this->handle_redirects( array( $request ) );
	}

	public function getRedirectCount( $request ) {
		$count = 0;
		while ( $request->redirected_to ) {
			++ $count;
			$request = $request->redirected_to;
		}

		return $count;
	}
}
