# HttpServer

<!-- docs-site-banner -->
> 📚 **Runnable examples:** [https://wordpress.github.io/php-toolkit/reference/httpserver.html](https://wordpress.github.io/php-toolkit/reference/httpserver.html)
> Open the page to edit each snippet in your browser and run it in WordPress Playground.
<!-- /docs-site-banner -->

A minimal, blocking TCP-based HTTP server written in pure PHP. It is designed for CLI tools, local development servers, and test harnesses where you need a lightweight HTTP endpoint without pulling in a full web server.

## Installation

```bash
composer require wp-php-toolkit/http-server
```

## Quick Start

```php
use WordPress\HttpServer\TcpServer;
use WordPress\HttpServer\IncomingRequest;
use WordPress\HttpServer\Response\ResponseWriteStream;

$server = new TcpServer( '127.0.0.1', 8080 );

$server->set_handler( function ( IncomingRequest $request, ResponseWriteStream $response ) {
    $response->send_http_code( 200 );
    $response->send_header( 'Content-Type', 'text/plain' );
    $response->append_bytes( 'Hello, world!' );
} );

echo "Listening on http://127.0.0.1:8080\n";
$server->serve();
```

## Usage

### Routing by path

The handler receives an `IncomingRequest` which extends the HttpClient `Request` class. You can inspect the method, URL, headers, and body to decide how to respond:

```php
use WordPress\HttpServer\TcpServer;
use WordPress\HttpServer\IncomingRequest;
use WordPress\HttpServer\Response\ResponseWriteStream;

$server = new TcpServer( '127.0.0.1', 8080 );

$server->set_handler( function ( IncomingRequest $request, ResponseWriteStream $response ) {
    $parsed = $request->get_parsed_url();
    $path   = $parsed->pathname;

    if ( '/api/status' === $path && 'GET' === $request->method ) {
        $response->send_http_code( 200 );
        $response->send_header( 'Content-Type', 'application/json' );
        $response->append_bytes( '{"status": "ok"}' );
        return;
    }

    if ( '/api/echo' === $path && 'POST' === $request->method ) {
        // Read the incoming request body.
        $body = '';
        while ( ! $request->body_stream->reached_end_of_data() ) {
            $n = $request->body_stream->pull( 4096 );
            if ( $n > 0 ) {
                $body .= $request->body_stream->consume( $n );
            }
        }

        $response->send_http_code( 200 );
        $response->send_header( 'Content-Type', 'text/plain' );
        $response->append_bytes( $body );
        return;
    }

    $response->send_http_code( 404 );
    $response->send_header( 'Content-Type', 'text/plain' );
    $response->append_bytes( 'Not Found' );
} );

$server->serve();
```

### Chunked transfer encoding

For large or streaming responses, enable chunked encoding on the response writer. This sends data in chunks without needing to know the total content length upfront:

```php
use WordPress\HttpServer\TcpServer;
use WordPress\HttpServer\IncomingRequest;
use WordPress\HttpServer\Response\TcpResponseWriteStream;

$server = new TcpServer( '127.0.0.1', 8080 );

$server->set_handler( function ( IncomingRequest $request, TcpResponseWriteStream $response ) {
    $response->send_http_code( 200 );
    $response->send_header( 'Content-Type', 'text/plain' );
    $response->use_chunked_encoding();

    for ( $i = 0; $i < 10; $i++ ) {
        $response->append_bytes( "Chunk $i\n" );
    }
} );

$server->serve();
```

### Buffering the response

`BufferingResponseWriter` collects the entire response in memory before sending it. This is useful when you need to compute `Content-Length` automatically or when using `php-cgi`:

```php
use WordPress\HttpServer\Response\BufferingResponseWriter;

$writer = new BufferingResponseWriter();
$writer->send_http_code( 200 );
$writer->send_header( 'Content-Type', 'text/html' );
$writer->append_bytes( '<h1>Hello</h1>' );

// Sends all headers (including Content-Length) and the body at once.
$writer->close_writing();
```

### Streaming via php://output

`StreamingResponseWriter` writes directly to PHP's output stream using `http_response_code()` and `header()`. Use it when running behind Apache/nginx as a CGI script:

```php
use WordPress\HttpServer\Response\StreamingResponseWriter;

$writer = new StreamingResponseWriter();
$writer->send_http_code( 200 );
$writer->send_header( 'Content-Type', 'text/plain' );
$writer->append_bytes( 'streamed directly to the client' );
$writer->close_writing();
```

### Startup callback

Pass a callback to `serve()` to be notified when the server is ready to accept connections. This is handy for tests or scripts that need to know the exact host and port:

```php
$server->serve( function ( $host, $port ) {
    echo "Server ready at http://{$host}:{$port}\n";
} );
```

## API Reference

### TcpServer

| Method | Description |
|---|---|
| `__construct( $host, $port )` | Create a server bound to the given host and port |
| `set_handler( callable $handler )` | Set the request handler. Receives `(IncomingRequest, ResponseWriteStream, $socket)` |
| `serve( callable $on_accept )` | Start the blocking server loop. Optional callback fires when listening begins |

### IncomingRequest

Extends `WordPress\HttpClient\Request`.

| Method / Property | Description |
|---|---|
| `IncomingRequest::from_resource( $stream )` | Parse an HTTP request from a socket resource |
| `$method` | HTTP method (`GET`, `POST`, etc.) |
| `$url` | Full request URL |
| `$headers` | Associative array of request headers (lowercase keys) |
| `$body_stream` | A `ByteReadStream` for reading the request body |
| `get_parsed_url()` | Returns a parsed URL object with `->pathname` |
| `get_header( $name )` | Get a single header value |

### ResponseWriteStream (interface)

| Method | Description |
|---|---|
| `send_http_code( $code )` | Set the HTTP status code (must be called before writing body) |
| `send_header( $name, $value )` | Add a response header (must be called before writing body) |
| `append_bytes( $bytes )` | Write bytes to the response body |
| `close_writing()` | Finalize and close the response |

### TcpResponseWriteStream

Implements `ResponseWriteStream`. Writes directly to a TCP socket.

| Method | Description |
|---|---|
| `use_chunked_encoding()` | Enable HTTP chunked transfer encoding |
| `is_writing_closed()` | Check if the response has been finalized |

### BufferingResponseWriter

Implements `ResponseWriteStream`. Buffers the entire response in memory and sends it on `close_writing()` with an automatic `Content-Length` header.

### StreamingResponseWriter

Implements `ResponseWriteStream`. Writes headers via `header()` and body via `echo`, suitable for CGI environments.

### StatusCode

| Method | Description |
|---|---|
| `StatusCode::text( $code )` | Return the standard reason phrase for an HTTP status code (e.g., `200` -> `'OK'`) |

## Requirements

- PHP 7.2+
- No external dependencies
