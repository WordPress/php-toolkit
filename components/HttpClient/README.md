# HttpClient

<!-- docs-site-banner -->
> **Runnable examples:** [https://wordpress.github.io/php-toolkit/reference/httpclient.html](https://wordpress.github.io/php-toolkit/reference/httpclient.html)
> Snippets execute live in WordPress Playground — edit them in your browser and see real output.
<!-- /docs-site-banner -->

An asynchronous HTTP client that works on vanilla PHP without requiring `curl` or any other extensions. It can use `curl` when available for better performance, but falls back to pure PHP sockets automatically. Supports concurrent requests, streaming responses, redirects, chunked encoding, gzip decompression, and basic auth.

## Installation

```bash
composer require wp-php-toolkit/http-client
```

## Quick Start

```php
use WordPress\HttpClient\Client;
use WordPress\HttpClient\Request;

$client = new Client();

// Fetch a URL and read the entire response body.
$stream = $client->fetch( 'https://api.example.com/data.json' );
$body   = $stream->consume_all();

// Or parse JSON directly.
$stream = $client->fetch( 'https://api.example.com/data.json' );
$data   = $stream->json();
```

## Usage

### Simple GET request

```php
use WordPress\HttpClient\Client;
use WordPress\HttpClient\Request;

$client  = new Client();
$request = new Request( 'https://wordpress.org/' );
$stream  = $client->fetch( $request );

// Wait for the response headers to arrive.
$response = $stream->await_response();
echo $response->status_code; // 200

// Read the full body.
$html = $stream->consume_all();
```

### POST request with a body

```php
use WordPress\HttpClient\Client;
use WordPress\HttpClient\Request;
use WordPress\ByteStream\MemoryPipe;

$client  = new Client();
$request = new Request( 'https://httpbin.org/post', array(
    'method'      => 'POST',
    'headers'     => array( 'content-type' => 'application/json' ),
    'body_stream' => new MemoryPipe( '{"key": "value"}' ),
) );

$stream   = $client->fetch( $request );
$response = $stream->await_response();
$body     = $stream->consume_all();
```

### Concurrent downloads

Multiple requests run concurrently, whether using the curl or socket transport:

```php
use WordPress\HttpClient\Client;
use WordPress\HttpClient\Request;

$requests = array(
    new Request( 'https://wordpress.org/latest.zip' ),
    new Request( 'https://example.com/large-file.xml' ),
);

$client = new Client();
$client->enqueue( $requests );

while ( $client->await_next_event() ) {
    $request = $client->get_request();

    switch ( $client->get_event() ) {
        case Client::EVENT_GOT_HEADERS:
            // Response headers are available.
            echo $request->response->status_code . "\n";
            break;

        case Client::EVENT_BODY_CHUNK_AVAILABLE:
            // Stream body chunks to disk as they arrive.
            $chunk = $client->get_response_body_chunk();
            file_put_contents(
                '/tmp/download-' . $request->id,
                $chunk,
                FILE_APPEND
            );
            break;

        case Client::EVENT_FINISHED:
            echo "Done: " . $request->url . "\n";
            break;

        case Client::EVENT_FAILED:
            echo "Failed: " . $request->error->message . "\n";
            break;
    }
}
```

### Choosing a transport

The client automatically picks `curl` if the extension is loaded, otherwise it uses pure PHP sockets. You can force a specific transport:

```php
// Force pure PHP sockets (no curl dependency).
$client = new Client( array( 'transport' => 'sockets' ) );

// Force curl.
$client = new Client( array( 'transport' => 'curl' ) );
```

### Response caching

Enable disk-based caching by providing a cache directory:

```php
$client = new Client( array(
    'cache_dir' => '/tmp/http-cache',
) );
```

### Redirect handling

Redirects are followed automatically (up to 5 by default). You can traverse the redirect chain through the request object:

```php
$stream  = $client->fetch( new Request( 'https://example.com/old-page' ) );
$response = $stream->await_response();

// The request object tracks the full redirect chain.
$original = $stream->get_request();
if ( $original->is_redirected() ) {
    $final_request = $original->latest_redirect();
    echo $final_request->url; // the final URL after redirects
}
```

### Custom headers

```php
$request = new Request( 'https://api.example.com/resource', array(
    'method'  => 'GET',
    'headers' => array(
        'authorization' => 'Bearer my-token',
        'accept'        => 'application/json',
    ),
) );
```

### Basic auth via URL

Credentials embedded in the URL are automatically extracted and sent as a Basic Authorization header:

```php
$request = new Request( 'https://user:pass@api.example.com/resource' );
// Sends "Authorization: Basic dXNlcjpwYXNz" header automatically.
```

## API Reference

### Client

| Method | Description |
|---|---|
| `__construct( $options )` | Create a client. Options: `transport` (`'curl'`, `'sockets'`, `'auto'`), `cache_dir` |
| `fetch( $request )` | Start a request; returns a `RequestReadStream` |
| `fetch_many( $requests )` | Start multiple requests; returns an array of `RequestReadStream` |
| `enqueue( $requests )` | Queue requests for async processing |
| `await_next_event( $query )` | Block until the next event; returns `false` when all done |
| `get_event()` | The event type from the last `await_next_event()` call |
| `get_request()` | The `Request` associated with the last event |
| `get_response_body_chunk()` | The body chunk from an `EVENT_BODY_CHUNK_AVAILABLE` event |

### Request

| Method / Property | Description |
|---|---|
| `__construct( $url, $request_info )` | Create a request. Info keys: `method`, `headers`, `body_stream`, `http_version` |
| `$url` | The request URL |
| `$method` | HTTP method (default: `'GET'`) |
| `$headers` | Associative array of headers |
| `$response` | The `Response` object (available after headers arrive) |
| `$error` | An `HttpError` if the request failed |
| `latest_redirect()` | Follow the redirect chain to the final request |
| `is_redirected()` | Whether this request was redirected |

### RequestReadStream

| Method | Description |
|---|---|
| `consume_all()` | Read the entire response body as a string |
| `json()` | Parse the response body as JSON |
| `await_response()` | Block until response headers arrive; returns a `Response` |
| `get_request()` | The underlying `Request` object |
| `length()` | Content length if known, `null` otherwise |

### Response

| Method / Property | Description |
|---|---|
| `$status_code` | HTTP status code |
| `$headers` | Associative array of response headers (lowercase keys) |
| `get_header( $name )` | Get a single header value |
| `ok()` | `true` if status is 200-399 |

### Events

| Constant | When it fires |
|---|---|
| `Client::EVENT_GOT_HEADERS` | Response headers have been received |
| `Client::EVENT_BODY_CHUNK_AVAILABLE` | A chunk of the response body is ready |
| `Client::EVENT_FINISHED` | The request completed successfully |
| `Client::EVENT_FAILED` | The request failed (check `$request->error`) |

## Requirements

- PHP 7.2+
- No external dependencies (`curl` used when available but not required)
