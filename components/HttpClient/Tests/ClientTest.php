<?php

namespace WordPress\HttpClient\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use WordPress\HttpClient\Client;
use WordPress\HttpClient\Request;

class ClientTest extends TestCase {

	protected function withServer( callable $callback, $scenario = 'default', $host = '127.0.0.1', $port = 8950 ) {
		$serverRoot = __DIR__ . '/test-server';
		$server     = new Process( [
			'php',
			"$serverRoot/run.php",
			$host,
			$port,
			$scenario,
		], $serverRoot );
		$server->start();
		try {
			$attempts = 0;
			while ( $server->isRunning() ) {
				$output = $server->getIncrementalOutput();
				if ( strncmp( $output, 'Server started on http://', strlen( 'Server started on http://' ) ) === 0 ) {
					break;
				}
				usleep( 40000 );
				if ( ++ $attempts > 20 ) {
					$this->fail( 'Server did not start' );
				}
			}
			$callback( "http://{$host}:{$port}" );
		} finally {
			$server->stop( 0 );
		}
	}

	/**
	 * Helper to consume the entire response body for a request using the event loop.
	 */
	protected function consume_entire_body( Client $client, Request $request ) {
		$client->enqueue( $request );
		$body = '';
		while ( $client->await_next_event( [ 'requests' => [ $request ] ] ) ) {
			switch ( $client->get_event() ) {
				case Client::EVENT_BODY_CHUNK_AVAILABLE:
					$body .= $client->get_response_body_chunk();
					break;
				case Client::EVENT_FAILED:
					throw $request->error;
				case Client::EVENT_FINISHED:
					return $body;
			}
		}

		return $body;
	}

	/**
	 * @dataProvider httpMethodProvider
	 */
	public function test_http_methods( $method ) {
		$this->withServer( function ( $url ) use ( $method ) {
			$client  = new Client();
			$request = new Request( "$url/echo-method", [ 'method' => $method ] );
			$body    = $this->consume_entire_body( $client, $request );
			$this->assertEquals( $method, $body );
		}, 'echo-method' );
	}

	public function httpMethodProvider() {
		return [
			[ 'GET' ],
			[ 'POST' ],
			[ 'PUT' ],
			[ 'DELETE' ],
			[ 'PATCH' ],
			[ 'OPTIONS' ],
			[ 'HEAD' ],
		];
	}

	/**
	 * @dataProvider statusCodeProvider
	 */
	public function test_status_codes( $status, $expectedBody ) {
		$this->withServer( function ( $url ) use ( $status, $expectedBody ) {
			$client  = new Client();
			$request = new Request( "$url/status/$status" );
			$body    = $this->consume_entire_body( $client, $request );
			$this->assertEquals( $status, $request->response->status_code );

			if ( $expectedBody ) {
				$this->assertEquals( $expectedBody, $body );
			}
		}, 'status' );
	}

	public function statusCodeProvider() {
		return [
			[ 200, 'OK' ],
			[ 204, null ],
			[ 301, null ],
			[ 302, null ],
			[ 400, 'Bad Request' ],
			[ 404, 'Not Found' ],
			[ 500, 'Internal Server Error' ],
		];
	}

	/**
	 * @dataProvider encodingProvider
	 */
	public function test_encodings( $encoding, $expectedBody ) {
		$this->withServer( function ( $url ) use ( $encoding, $expectedBody ) {
			$client  = new Client();
			$request = new Request( "$url/encoding/$encoding" );
			$body    = $this->consume_entire_body( $client, $request );
			$this->assertEquals( $expectedBody, $body );
		}, 'encoding' );
	}

	public function encodingProvider() {
		return [
			[ 'identity', 'plain' ],
			[ 'chunked', 'chunked' ],
			[ 'gzip', 'gzipped' ],
		];
	}

	/**
	 * @dataProvider errorProvider
	 */
	public function test_errors( $scenario, $expectedError ) {
		$this->withServer( function ( $url ) use ( $scenario, $expectedError ) {
			$client  = new Client( [ 'timeout' => 1 ] );
			$request = new Request( "$url/error/$scenario" );
			$client->enqueue( $request );

			while ( $client->await_next_event( [ 'requests' => [ $request ] ] ) ) {
				switch ( $client->get_event() ) {
					case Client::EVENT_FAILED:
						$this->assertStringStartsWith( $expectedError, $request->error->message );
						return;
				}
			}
			$this->fail( 'Request should have errored' );
		}, 'error' );
	}

	public function errorProvider() {
		return [
			[ 'broken-connection', 'Connection closed while reading response headers.' ],
			[ 'invalid-response', 'Malformed HTTP headers received from the server.' ],
			// @TODO: Treat timeouts as errors. Right now they're just a reason to
			//        break out of the await_next_event() loop.
			//        Actually, maybe we do need two types of timeouts:
			//        - await_next_event() timeout to enable fast context switching
			//        - response read timeout – treated as an error – to avoid indefinite blocking
			// [ 'timeout', 'Failed to write request bytes - fwrite(): Send of' ],
		];
	}

	/**
	 * @dataProvider headerProvider
	 */
	public function test_headers( $headerName, $headerValue ) {
		$this->withServer( function ( $url ) use ( $headerName, $headerValue ) {
			$client  = new Client();
			$request = new Request( "$url/headers/$headerName" );
			$body    = $this->consume_entire_body( $client, $request );
			$this->assertStringContainsString( $headerValue, $body );
		}, 'headers' );
	}

	public function headerProvider() {
		return [
			[ 'X-Test-Header', 'X-Test-Header: test-value' ],
			[ 'X-Long-Header', 'X-Long-Header: ' . str_repeat( 'a', 1000 ) ],
			[ 'X-Multi-Header', 'X-Multi-Header: value1,value2' ],
		];
	}

	/**
	 * @dataProvider bodyProvider
	 */
	public function test_body_types( $type, $expectedLength ) {
		$this->withServer( function ( $url ) use ( $type, $expectedLength ) {
			$client  = new Client();
			$request = new Request( "$url/body/$type" );
			$body    = $this->consume_entire_body( $client, $request );
			$this->assertEquals( $expectedLength, strlen( $body ) );
		}, 'body' );
	}

	public function bodyProvider() {
		return [
			[ 'empty', 0 ],
			[ 'small', 5 ],
			[ 'large', 10000 ],
			[ 'binary', 256 ],
		];
	}

	/**
	 * @dataProvider streamingProvider
	 */
	public function test_streaming( $type, $expectedChunks ) {
		$this->withServer( function ( $url ) use ( $type, $expectedChunks ) {
			$client  = new Client();
			$request = new Request( "$url/stream/$type" );
			$client->enqueue( $request );
			$chunks = [];
			while ( $client->await_next_event( [ 'requests' => [ $request ] ] ) ) {
				switch ( $client->get_event() ) {
					case Client::EVENT_BODY_CHUNK_AVAILABLE:
						$chunks[] = $client->get_response_body_chunk();
						break;
					case Client::EVENT_FAILED:
						throw $request->error;
					case Client::EVENT_FINISHED:
						break 2;
				}
			}
			$this->assertCount( $expectedChunks, $chunks );
		}, 'stream' );
	}

	public function streamingProvider() {
		return [
			[ 'slow', 5 ],
			[ 'fast', 10 ],
		];
	}

	// Add more data providers and test methods for:
	// - partial reads
	// - connection reuse
	// - malformed headers
	// - keep-alive/close
	// - etc.
}
