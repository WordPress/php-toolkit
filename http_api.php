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
	new Request("https://playground.internal"),
	(new Request("https://anglesharp.azurewebsites.net/Chunked"))->set_http_version('1.1'),
	(new Request("https://anglesharp.azurewebsites.net/Chunked"))->set_http_version('1.0'),
	(new Request("http://127.0.0.1:3000/"))->set_http_version('1.0'),
];

// var_dump(streams_http_response_read_bytes($streams, 1024));
// Enqueuing another request here is instant and won't start the download yet.
$client = new Client();
$client->set_progress_callback( function ( Request $request, $downloaded, $total ) {
	// echo "$request->url – Downloaded: $downloaded / $total\n";
} );

$client->enqueue( $requests );


while(true) {
	$request = $client->next_response_chunk();
	if(false === $request) {
		break;
	}
	echo "GOT DATA CHUNK ON REQUEST $request->id:\n";
	echo $request->get_response()->consume_buffer(1024);
	echo "----------------\n\n";
}

// $client->wait_for_headers($requests[3]);
// var_dump($requests[3]->get_response()->get_headers());
