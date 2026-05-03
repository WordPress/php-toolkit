---
slug: httpclient
title: HttpClient
install: wp-php-toolkit/http-client

see_also: ../learn/04-talking-to-the-network.html | Tutorial — Talking to the network | Walks through a streaming downloader that resumes, fans out, and pipes bytes to disk without buffering.
see_also: bytestream | ByteStream | Stream request and response bodies.
see_also: filesystem | Filesystem | Persist large downloads without buffering them in memory.
see_also: corsproxy | CORSProxy | Bridge browser-side tools to servers without CORS headers.
---

Async HTTP client without <code>curl</code> required. Uses sockets when curl is missing, supports concurrent requests and streaming responses.

## Why this exists

<p>A plugin installer starts with one request to download <code>plugin.zip</code>. A migration then adds progress reporting, a ten-request media window, resumable downloads, and a remote ZIP reader that feeds ZipFilesystem directly. Those workflows need the same request API from the first GET to the final streamed archive.</p>

<p>The HttpClient component gives the toolkit a small request/response model, middleware for redirects and caching, concurrent fetches, and response bodies exposed as byte streams. It runs through curl when PHP provides curl and through pure PHP sockets when it does not. Callers keep the same code path.</p>

<p>Use it to fetch plugin metadata, submit import callbacks, mirror a media library, read a WXR export, or pipe a remote archive into Zip and Filesystem code.</p>

## GET a URL

<p class="callout"><strong>Network access in the demo runtime.</strong> Live request examples show the real API, but outbound HTTP in browser sandboxes may require a CORS proxy.</p>

<p>The smallest flow has three steps: create a request, wait until headers arrive, then consume the body stream. This is intentionally close to the Fetch API shape, but the body is a toolkit byte stream instead of a buffered string.</p>

<!-- snippet:
filename: get.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use WordPress\HttpClient\Client;
use WordPress\HttpClient\Request;

$client  = new Client();
$stream  = $client->fetch( new Request( 'https://example.com/' ) );

$response = $stream->await_response();
echo "status: " . $response->status_code . "\n";
echo "first 80 bytes: " . substr( $stream->consume_all(), 0, 80 ) . "\n";
```

## POST to a URL

<p>Uploads use the same shape. The only difference is that the request declares a method, request headers, and an upload body stream. Here the body is form-encoded text wrapped in <code>MemoryPipe</code>; a file upload could provide a file-backed read stream instead.</p>

<!-- snippet:
filename: post.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use WordPress\HttpClient\Client;
use WordPress\HttpClient\Request;
use WordPress\ByteStream\MemoryPipe;

$payload = http_build_query(
	array(
		'title' => 'Hello',
		'tags'  => 'http,php',
	),
	'',
	'&'
);

$client  = new Client();
$request = new Request( 'https://httpbin.org/post', array(
	'method'      => 'POST',
	'headers'     => array(
		'content-type'   => 'application/x-www-form-urlencoded',
		'content-length' => (string) strlen( $payload ),
	),
	'body_stream' => new MemoryPipe( $payload ),
) );

$response = $client->fetch( $request )->json();
echo "Server saw form title: " . $response['form']['title'] . "\n";
```

## Build a JSON request object

<p>A <code>Request</code> is just data until a client enqueues it. That makes it easy to test request construction without network access. The constructor normalizes headers, calculates <code>content-length</code> when the body stream has a known length, and moves URL credentials into an Authorization header.</p>

<!-- snippet:
filename: request-object.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use WordPress\ByteStream\MemoryPipe;
use WordPress\HttpClient\Request;

$body = new MemoryPipe( json_encode( array(
	'title' => 'Hello',
	'tags'  => array( 'docs', 'php' ),
) ) );
$body->close_writing();

$request = new Request( 'https://user:secret@api.example.test/posts', array(
	'method'      => 'POST',
	'headers'     => array( 'content-type' => 'application/json' ),
	'body_stream' => $body,
) );

echo $request->method . ' ' . $request->url . "\n";
echo "content-type: " . $request->get_header( 'content-type' ) . "\n";
echo "content-length: " . $request->get_header( 'content-length' ) . "\n";
echo "authorization: " . substr( $request->get_header( 'authorization' ), 0, 10 ) . "...\n";
```

<!-- expected-output -->
```
POST https://api.example.test/posts
content-type: application/json
content-length: 39
authorization: Basic dXNl...
```

## Parse response headers

<p>Most applications receive <code>Response</code> objects from <code>await_response()</code>. Transports, middleware, and tests sometimes need the lower-level parser: <code>Response::from_http_headers()</code> turns raw HTTP header bytes into normalized status and case-insensitive headers.</p>

<!-- snippet:
filename: parse-response.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use WordPress\HttpClient\Request;
use WordPress\HttpClient\Response;

$request = new Request( 'https://api.example.test/posts/42' );
$raw = "HTTP/1.1 201 Created\r\n"
	. "Content-Type: application/json\r\n"
	. "Location: /posts/42\r\n"
	. "Content-Length: 27\r\n\r\n";

$response = Response::from_http_headers( $raw, $request );

echo "status: " . $response->status_code . ' ' . $response->get_reason_phrase() . "\n";
echo "ok:     " . ( $response->ok() ? 'yes' : 'no' ) . "\n";
echo "type:   " . $response->get_header( 'CONTENT-TYPE' ) . "\n";
echo "size:   " . $response->total_bytes . " bytes\n";
```

<!-- expected-output -->
```
status: 201 Created
ok:     yes
type:   application/json
size:   27 bytes
```

## Pick the right reading style

<p>There are three common ways to consume a response. Start simple, then move down the table only when the workflow demands it.</p>

<table><thead><tr><th>Style</th><th>Use when</th><th>Tradeoff</th></tr></thead><tbody><tr><td><code>consume_all()</code> or <code>json()</code></td><td>Small HTML, JSON, or API responses.</td><td>Buffers the full body.</td></tr><tr><td><code>Client::await_next_event()</code></td><td>Progress bars, streaming to disk, queues, failure handling.</td><td>You own the event loop.</td></tr><tr><td>Filesystem and parser composition</td><td>Remote ZIPs, WXR files, import pipelines.</td><td>Requires a stream-aware consumer.</td></tr></tbody></table>

## Choose a transport

<p>The transport is the I/O backend. It should not change your request, response, redirect, cache, or stream code; it only changes how bytes move across the network.</p>

<table><thead><tr><th>Transport</th><th>What it does</th><th>When to choose it</th></tr></thead><tbody><tr><td><code>auto</code></td><td>Uses curl when loaded, otherwise sockets.</td><td>Application default. Best when you want portability and the fastest available backend.</td></tr><tr><td><code>sockets</code></td><td>Uses PHP stream sockets, no curl extension.</td><td>Tests, Playground-style runtimes, hosts where curl is unavailable, or proving the dependency-free path works.</td></tr><tr><td><code>curl</code></td><td>Uses the curl extension.</td><td>Hosts where curl is available and you want to compare behavior or performance explicitly.</td></tr></tbody></table>

<p><code>concurrency</code>, <code>timeout_ms</code>, <code>cache_dir</code>, redirects, and response streaming sit above the transport, so the examples later on work with either backend.</p>

<!-- snippet:
filename: transports.php
runnable: false
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use WordPress\HttpClient\Client;

$default = new Client(); // Same as array( 'transport' => 'auto' ).

$portable = new Client( array(
	'transport' => 'sockets',
) );

if ( extension_loaded( 'curl' ) ) {
	$curl = new Client( array(
		'transport' => 'curl',
	) );
}
```

## Follow redirects and inspect the final request

<p>Redirects are middleware, not transport behavior. The client follows up to five redirects by default. The original <code>Request</code> keeps a chain to the final request, so importers can log where a source URL actually landed.</p>

<!-- snippet:
filename: redirects.php
runnable: false
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use WordPress\HttpClient\Client;
use WordPress\HttpClient\Request;

$client   = new Client();
$request  = new Request( 'https://httpbin.org/redirect-to?url=https://example.com/' );
$stream   = $client->fetch( $request );
$response = $stream->await_response();
$stream->consume_all();

$final = $request->latest_redirect();
echo "original: " . $request->url . "\n";
echo "final:    " . $final->url . "\n";
echo "status:   " . $response->status_code . "\n";
```

## Cache repeatable GET responses

<p>Pass <code>cache_dir</code> to add disk caching for cacheable GET and HEAD responses. Fresh cached responses replay the same header/body events as a network response, so crawlers and importers do not need a separate cache code path. Non-GET requests invalidate matching cache entries instead of being cached.</p>

<!-- snippet:
filename: cache.php
runnable: false
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use WordPress\HttpClient\Client;
use WordPress\HttpClient\Request;

$cache_dir = sys_get_temp_dir() . '/http-cache-' . uniqid();
mkdir( $cache_dir );

$client = new Client( array( 'cache_dir' => $cache_dir ) );
$url    = 'https://httpbin.org/cache/60';

for ( $i = 1; $i <= 2; $i++ ) {
	$stream   = $client->fetch( new Request( $url ) );
	$response = $stream->await_response();
	$body     = $stream->consume_all();
	echo "request {$i}: HTTP " . $response->status_code . ', body=' . strlen( $body ) . " bytes\n";
}

echo "cache files: " . count( glob( $cache_dir . '/*' ) ) . "\n";
```

## Handle failures without losing the queue

<p>Failures arrive as events. That lets a crawler, importer, package installer, or media frontloader log one bad URL and keep processing the rest of the queue. Treat failure handling as part of the event loop, not as one global try/catch around the whole batch.</p>

<!-- snippet:
filename: failures.php
runnable: false
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use WordPress\HttpClient\Client;
use WordPress\HttpClient\Request;

$client = new Client( array( 'timeout_ms' => 5000 ) );
$client->enqueue( array(
	new Request( 'https://example.com/', array( 'method' => 'HEAD' ) ),
	new Request( 'https://example.invalid/missing' ),
) );

while ( $client->await_next_event() ) {
	$request = $client->get_request();
	$event   = $client->get_event();

	if ( Client::EVENT_GOT_HEADERS === $event ) {
		echo "ok: " . $request->url . " HTTP " . $request->response->status_code . "\n";
	} elseif ( Client::EVENT_FAILED === $event ) {
		echo "failed: " . $request->url . "\n";
	} elseif ( Client::EVENT_FINISHED === $event ) {
		echo "finished: " . $request->url . "\n";
	}
}
```

## Monitor download progress

<p>When you care about progress, use the event loop directly. Count bytes from each <code>EVENT_BODY_CHUNK_AVAILABLE</code> event and compare them with <code>Content-Length</code> when the server provides one.</p>

<!-- snippet:
filename: progress.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use WordPress\HttpClient\Client;
use WordPress\HttpClient\Request;

$url  = 'https://raw.githubusercontent.com/WordPress/php-toolkit/trunk/components/Zip/Tests/fixtures/childrens-literature.zip';
$dest = sys_get_temp_dir() . '/progress-' . uniqid() . '.zip';

$client  = new Client();
$request = new Request( $url );
$client->enqueue( array( $request ) );

$downloaded = 0;
$last_step  = -1;
@unlink( $dest );

while ( $client->await_next_event() ) {
	$event   = $client->get_event();
	$request = $client->get_request();

	if ( Client::EVENT_GOT_HEADERS === $event ) {
		echo "status: " . $request->response->status_code . "\n";
		continue;
	}

	if ( Client::EVENT_BODY_CHUNK_AVAILABLE === $event ) {
		$chunk       = $client->get_response_body_chunk();
		$downloaded += strlen( $chunk );
		file_put_contents( $dest, $chunk, FILE_APPEND );

		$total = $request->response->total_bytes;
		if ( $total ) {
			$step = min( 100, (int) floor( $downloaded / $total * 100 ) );
			if ( $step >= $last_step + 25 || 100 === $step ) {
				echo "progress: {$step}% ({$downloaded}/{$total} bytes)\n";
				$last_step = $step;
			}
		} else {
			echo "downloaded: {$downloaded} bytes\n";
		}
		continue;
	}

	if ( Client::EVENT_FINISHED === $event ) {
		echo "saved: {$dest}\n";
	} elseif ( Client::EVENT_FAILED === $event ) {
		echo "failed: " . $request->error->message . "\n";
	}
}
```

## Keep a sliding window of 10 requests

<p>For large queues, do not enqueue everything at once. Keep at most ten active requests, enqueue another as each one finishes, and let the client multiplex only that window.</p>

<!-- snippet:
filename: sliding-window.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use WordPress\HttpClient\Client;
use WordPress\HttpClient\Request;

$urls = array();
for ( $i = 1; $i <= 25; $i++ ) {
	$urls[] = 'https://example.com/?request=' . $i;
}

$client  = new Client( array( 'concurrency' => 10 ) );
$pending = $urls;
$active  = array();
$done    = 0;

$enqueue_next = function () use ( &$pending, &$active, $client ) {
	if ( ! $pending ) {
		return;
	}
	$url     = array_shift( $pending );
	$request = new Request( $url, array( 'method' => 'HEAD' ) );
	$active[ $request->id ] = $request;
	$client->enqueue( array( $request ) );
};

for ( $i = 0; $i < 10; $i++ ) {
	$enqueue_next();
}

while ( $active && $client->await_next_event() ) {
	$request = $client->get_request();
	$event   = $client->get_event();

	if ( Client::EVENT_GOT_HEADERS === $event ) {
		echo "headers {$request->id}: " . $request->response->status_code . "\n";
		continue;
	}

	if ( Client::EVENT_FINISHED === $event || Client::EVENT_FAILED === $event ) {
		unset( $active[ $request->id ] );
		$done++;
		echo "finished {$done}/25, active=" . count( $active ) . "\n";
		$enqueue_next();
	}
}
```

## Resume a partial download

<p>Resuming is an HTTP contract between you and the server. Save what you already have, send a <code>Range</code> request for the remaining bytes, and append only if the server returns <code>206 Partial Content</code>.</p>

<!-- snippet:
filename: resume-download.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use WordPress\HttpClient\Client;
use WordPress\HttpClient\Request;

$url  = 'https://raw.githubusercontent.com/WordPress/php-toolkit/trunk/components/Zip/Tests/fixtures/childrens-literature.zip';
$dest = sys_get_temp_dir() . '/resume-' . uniqid() . '.zip';

$client = new Client();

// Simulate an interrupted first attempt by downloading only the first 32 KB.
$first = new Request( $url, array(
	'headers' => array( 'range' => 'bytes=0-32767' ),
) );
$stream   = $client->fetch( $first );
$response = $stream->await_response();
file_put_contents( $dest, $stream->consume_all() );

if ( 206 !== $response->status_code ) {
	echo "Server did not honor Range; start over with a full download.\n";
	exit;
}

$downloaded = filesize( $dest );
echo "partial file: {$downloaded} bytes\n";

$resume = new Request( $url, array(
	'headers' => array( 'range' => 'bytes=' . $downloaded . '-' ),
) );
$stream   = $client->fetch( $resume );
$response = $stream->await_response();

if ( 206 !== $response->status_code ) {
	echo "Server did not resume; discard partial file and retry from byte 0.\n";
	exit;
}

while ( ! $stream->reached_end_of_data() ) {
	$n = $stream->pull( 8192 );
	if ( 0 === $n ) {
		break;
	}
	file_put_contents( $dest, $stream->consume( $n ), FILE_APPEND );
}

echo "complete file: " . filesize( $dest ) . " bytes\n";
echo "saved: {$dest}\n";
```

## Stream-unzip a remote archive

<p>Mount the remote archive with <code>ZipFilesystem</code>, then copy it into any writable filesystem. <code>SeekableRequestReadStream</code> caches received bytes to a temporary file so <code>ZipFilesystem</code> can read the central directory and seek to entries without first writing the ZIP yourself.</p>

<!-- snippet:
filename: stream-unzip.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use WordPress\HttpClient\Client;
use WordPress\HttpClient\ByteStream\SeekableRequestReadStream;
use WordPress\HttpClient\Request;
use WordPress\Filesystem\LocalFilesystem;
use WordPress\Zip\ZipFilesystem;
use function WordPress\Filesystem\copy_between_filesystems;
use function WordPress\Filesystem\ls_recursive;

$url  = 'https://raw.githubusercontent.com/WordPress/php-toolkit/trunk/components/Zip/Tests/fixtures/childrens-literature.zip';
$root = sys_get_temp_dir() . '/remote-zip-' . uniqid();
mkdir( $root );

$client = new Client();
$reader = new SeekableRequestReadStream(
	new Request( $url ),
	array( 'client' => $client )
);

$response = $reader->await_response();
if ( ! $response->ok() ) {
	echo "HTTP " . $response->status_code . "\n";
	exit;
}

$zip   = ZipFilesystem::create( $reader );
$local = LocalFilesystem::create( $root );

copy_between_filesystems( array(
	'source_filesystem' => $zip,
	'source_path'       => '/',
	'target_filesystem' => $local,
	'target_path'       => '/',
) );

$tree  = ls_recursive( $local, '/' );
$files = 0;
array_walk_recursive( $tree, function ( $value, $key ) use ( &$files ) {
	if ( 'type' === $key && 'file' === $value ) {
		$files++;
	}
} );

echo "extracted {$files} files\n";
echo "root: {$root}\n";
```

## Parallel fan-out: fetch many URLs at once

<p>Enqueue a batch of requests and react to events as they fire. The client multiplexes them — total wall time is roughly the slowest request, not the sum.</p>

<!-- snippet:
filename: fan-out.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use WordPress\HttpClient\Client;
use WordPress\HttpClient\Request;

$urls = array(
	'https://wordpress.org/',
	'https://make.wordpress.org/',
	'https://developer.wordpress.org/',
);

$client = new Client();
$client->enqueue( array_map( function ( $url ) {
	return new Request( $url, array( 'method' => 'HEAD' ) );
}, $urls ) );

$results = array();
while ( $client->await_next_event() ) {
	$request = $client->get_request();
	if ( Client::EVENT_GOT_HEADERS === $client->get_event() ) {
		$results[ $request->url ] = $request->response->status_code;
	} elseif ( Client::EVENT_FAILED === $client->get_event() ) {
		$results[ $request->url ] = 'ERR ' . $request->error->message;
	}
}

foreach ( $results as $url => $status ) {
	printf( "%-40s %s\n", $url, $status );
}
```

## Stream a download to disk without OOM

<p>Process the body chunk-by-chunk via the event loop. Memory stays flat regardless of file size.</p>

<!-- snippet:
filename: stream-to-disk.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use WordPress\HttpClient\Client;
use WordPress\HttpClient\Request;

$dest   = sys_get_temp_dir() . '/wp-readme.html';
$client = new Client();
$client->enqueue( array( new Request( 'https://wordpress.org/' ) ) );

$bytes = 0;
@unlink( $dest );

while ( $client->await_next_event() ) {
	switch ( $client->get_event() ) {
		case Client::EVENT_BODY_CHUNK_AVAILABLE:
			$chunk  = $client->get_response_body_chunk();
			$bytes += strlen( $chunk );
			file_put_contents( $dest, $chunk, FILE_APPEND );
			break;
		case Client::EVENT_FINISHED:
			echo "Wrote {$bytes} bytes to {$dest}\n";
			break;
	}
}

echo "Peak memory: " . round( memory_get_peak_usage( true ) / 1024 / 1024, 2 ) . " MB\n";
```
