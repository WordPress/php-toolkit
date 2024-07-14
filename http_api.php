<?php

use WordPress\AsyncHttp\Client;
use WordPress\AsyncHttp\Request;

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/WordPress/Streams/StreamWrapperInterface.php';
require_once __DIR__ . '/src/WordPress/Streams/VanillaStreamWrapper.php';
require_once __DIR__ . '/src/WordPress/Streams/StreamPeekerWrapper.php';
require_once __DIR__ . '/src/WordPress/AsyncHttp/InflateStreamWrapper.php';
require_once __DIR__ . '/src/WordPress/AsyncHttp/InflateStreamWrapperData.php';

$requests = [
	// new Request("https://playground.internal"),
	// (new Request("https://anglesharp.azurewebsites.net/Chunked"))->set_http_version('1.1'),
	// (new Request("https://anglesharp.azurewebsites.net/Chunked"))->set_http_version('1.0'),
	(new Request("http://127.0.0.1:3000/")) //->set_http_version('1.0'),
];
// list($streams, $headers, $errors) = streams_send_http_requests($requests);
// print_r($streams);
// print_r($errors);

// var_dump(streams_http_response_read_bytes($streams, 1024));
// Enqueuing another request here is instant and won't start the download yet.
$client = new Client();
$queue = $client->enqueue( $requests );
var_dump($client->read_bytes($requests[0], 10, Client::READ_POLL_ANY));
var_dump($client->read_bytes($requests[0], 1024, Client::READ_NON_BLOCKING));
var_dump($client->read_bytes($requests[0], 1024, Client::READ_POLL_ANY));
// var_dump($client->read_bytes($requests[0], 1024));

die();

// @TODO: handle wait_for_all_requested_bytes for more than content-length bytes
var_dump(stream_get_contents($requests[1]->get_response()->body_stream));
// var_dump($client->read_bytes($requests[1], 359, [
// 	'mode' => 'poll_once',
// ]));
// var_dump($client->read_bytes($requests[1], 359, [
// 	'mode' => 'poll_once',
// ]));
// var_dump($client->read_bytes($requests[1], 359, [
// 	'mode' => 'poll_once',
// ]));
// @TODO: poll_once should eventully mark the request as finished
var_dump("----");
var_dump($client->read_bytes($requests[2], 1024, [
	'mode' => 'return',
]));
var_dump($client->read_bytes($requests[2], 1024, [
	'mode' => 'poll_once',
]));
// var_dump($queue);
// var_dump($queue[0]);
// var_dump($client->read_bytes($requests[0], 1024, [
// 	'mode' => 'return',
// ]));
// var_dump(fread($queue[0]->get_body_stream(), 1));
// var_dump(fread($queue[0]->get_body_stream(), 1));
// var_dump(fread($queue[0]->get_body_stream(), 1));
die();
// var_dump($queue[0]);
var_dump($client->read_bytes($requests[0], 186, [
	'mode' => 'return',
]));
var_dump($client->read_bytes($requests[0], 186, [
	'mode' => 'return',
]));
// var_dump($queue[0]->get_status_code());
// var_dump($queue[0]->get_headers());

// var_dump(stream_get_contents($queue[0]->response->body_stream));
die();
$client = new Client();
$client->set_progress_callback( function ( Request $request, $downloaded, $total ) {
	echo "$request->url – Downloaded: $downloaded / $total\n";
} );

$requests = [
	new Request("https://anglesharp.azurewebsites.net/Chunked")
	// new Request( "https://downloads.wordpress.org/plugin/gutenberg.17.7.0.zip" ),
	// new Request( "https://downloads.wordpress.org/theme/pendant.zip" ),
];
$queue = $client->enqueue( $requests );
var_dump($queue[0]);
die();
// Enqueuing another request here is instant and won't start the download yet.
//$streams2 = $client->enqueue( [
//	new Request( "https://downloads.wordpress.org/plugin/hello-dolly.1.7.3.zip" ),
//] );

try {
	$client->read_bytes($requests[0], 4096);
	// var_dump(stream_get_contents($streams1[0]));
} catch (Exception $e) {
	echo $e->getMessage();
}
print_r($client);
print_r(stream_context_get_options($streams1[0]));
// Stream a single file, while streaming all the files
// file_put_contents( 'output-round1-0.zip', stream_get_contents( $streams1[0] ) );
//file_put_contents( 'output-round1-1.zip', stream_get_contents( $streams1[1] ) );
die();
// Initiate more HTTPS requests
$streams3 = $client->enqueue( [
	new Request( "https://downloads.wordpress.org/plugin/akismet.4.1.12.zip" ),
	new Request( "https://downloads.wordpress.org/plugin/hello-dolly.1.7.3.zip" ),
	new Request( "https://downloads.wordpress.org/plugin/hello-dolly.1.7.3.zip" ),
] );

// Download the rest of the files. Foreach() seems like downloading things
// sequentially, but we're actually streaming all the files in parallel.
$streams = array_merge( $streams2, $streams3 );
foreach ( $streams as $k => $stream ) {
	file_put_contents( 'output-round2-' . $k . '.zip', stream_get_contents( $stream ) );
}

echo "Done! :)";

// ----------------------------
//
// Previous explorations:

// Non-blocking parallel processing – the fastest method.
//while ( $results = sockets_http_response_read_bytes( $streams, 8096 ) ) {
//	foreach ( $results as $k => $chunk ) {
//		file_put_contents( 'output' . $k . '.zip', $chunk, FILE_APPEND );
//	}
//}

// Blocking sequential processing – the slowest method.
//foreach ( $streams as $k => $stream ) {
//	stream_set_blocking( $stream, 1 );
//	file_put_contents( 'output' . $k . '.zip', stream_get_contents( $stream ) );
//}
