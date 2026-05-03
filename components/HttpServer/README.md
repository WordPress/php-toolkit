---
slug: httpserver
title: HttpServer
install: wp-php-toolkit/http-server

see_also:
  - cli | CLI | Expose a local browser UI from a command-line tool.
  - httpclient | HttpClient | Test client code against a small local fixture server.
---

A minimal blocking TCP HTTP server in pure PHP. For CLI tools and tests, not for production traffic.

## Why this exists

<p>Sometimes a PHP tool needs a tiny local HTTP surface: a test fixture server, a webhook receiver during development, a CLI tool with a browser UI, or a demo endpoint for another component. Pulling in a production web framework would obscure the example and add dependencies the toolkit avoids.</p>

<p>The HttpServer component is intentionally small: a blocking TCP server, incoming request objects, and response writers. It is useful for local tools and tests. It is not a replacement for nginx, Apache, php-fpm, RoadRunner, Swoole, or a production application server.</p>

## Hello world on port 8080

<p class="callout"><strong>Run on your machine:</strong> the Playground sandbox does not allow processes to bind listening TCP ports. Save this snippet locally and run <code>php hello-server.php</code>.</p>

<!-- snippet:
filename: hello-server.php
runnable: false
-->
```php
<?php
require __DIR__ . '/vendor/autoload.php';

use WordPress\HttpServer\TcpServer;
use WordPress\HttpServer\IncomingRequest;
use WordPress\HttpServer\Response\ResponseWriteStream;

$server = new TcpServer( '127.0.0.1', 8080 );

$server->set_handler( function ( IncomingRequest $request, ResponseWriteStream $response ) {
	$response->send_http_code( 200 );
	$response->send_header( 'Content-Type', 'text/plain' );
	$response->append_bytes( "Hello from " . $request->method . " " . $request->url . "\n" );
} );

$server->serve( function ( $host, $port ) {
	echo "Listening on http://{$host}:{$port}\n";
} );
```

## A tiny JSON router

<p class="callout"><strong>Run on your machine:</strong> needs a listening port. Once running, try <code>curl localhost:8080/api/status</code>.</p>

<p>Build a CLI tool with a web UI by switching on the parsed path and method.</p>

<!-- snippet:
filename: mini-router.php
runnable: false
-->
```php
<?php
require __DIR__ . '/vendor/autoload.php';

use WordPress\HttpServer\TcpServer;
use WordPress\HttpServer\IncomingRequest;
use WordPress\HttpServer\Response\ResponseWriteStream;

$server = new TcpServer( '127.0.0.1', 8080 );

$server->set_handler( function ( IncomingRequest $request, ResponseWriteStream $response ) {
	$path = $request->get_parsed_url()->pathname;

	if ( '/api/status' === $path ) {
		$response->send_http_code( 200 );
		$response->send_header( 'Content-Type', 'application/json' );
		$response->append_bytes( json_encode( array(
			'ok'     => true,
			'pid'    => getmypid(),
			'memory' => memory_get_usage( true ),
		) ) );
		return;
	}

	if ( '/api/echo' === $path && 'POST' === $request->method ) {
		$body = '';
		while ( ! $request->body_stream->reached_end_of_data() ) {
			$n = $request->body_stream->pull( 4096 );
			if ( $n > 0 ) $body .= $request->body_stream->consume( $n );
		}
		$response->send_http_code( 200 );
		$response->send_header( 'Content-Type', 'text/plain' );
		$response->append_bytes( $body );
		return;
	}

	$response->send_http_code( 404 );
	$response->append_bytes( "Not found\n" );
} );

$server->serve();
```

## Buffered response with auto Content-Length

<p>Use <code>BufferingResponseWriter</code> when you want the framework to compute <code>Content-Length</code> for you, or when the runtime is CGI-shaped and expects the full body up front. This one runs anywhere — no socket required.</p>

<!-- snippet:
filename: buffered-writer.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use WordPress\HttpServer\Response\BufferingResponseWriter;

$writer = new BufferingResponseWriter();
$writer->send_http_code( 200 );
$writer->send_header( 'Content-Type', 'text/html' );
$writer->append_bytes( '<!doctype html><title>Hi</title><h1>Hello</h1>' );
$writer->append_bytes( '<p>Buffered body, sent at the end.</p>' );

ob_start();
$writer->close_writing();
$response_body = ob_get_clean();

echo "headers before send:\n";
foreach ( $writer->get_buffered_headers() as $name => $value ) {
	echo "{$name}: {$value}\n";
}
echo "\nbody:\n" . $response_body;
```

<!-- expected-output -->
```
headers before send:
Content-Type: text/html

body:
<!doctype html><title>Hi</title><h1>Hello</h1><p>Buffered body, sent at the end.</p>
```
