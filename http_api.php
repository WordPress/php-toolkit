<?php

use WordPress\AsyncHttp\Client;
use WordPress\AsyncHttp\Request;

require __DIR__ . '/vendor/autoload.php';

$requests = [
	new Request("https://playground.internal"),
	new Request("https://anglesharp.azurewebsites.net/Chunked", [
		'http_version' => '1.1'
	]),
	new Request("https://anglesharp.azurewebsites.net/Chunked", [
		'http_version' => '1.0'
	]),
	new Request("http://127.0.0.1:3000/", [
		'http_version' => '1.0',
		'headers' => [
			'please-redirect' => 'yes'
		]
	]),
];

// var_dump(streams_http_response_read_bytes($streams, 1024));
// Enqueuing another request here is instant and won't start the download yet.
$client = new Client([
	'concurrency' => 1,
	'on_progress' => function ( Request $request, $downloaded, $total ) {
		// echo "$request->url – Downloaded: $downloaded / $total\n";
	},
	'max_redirects' => 0
]);

$client->enqueue( $requests );

while($request = $client->await_incoming_bytes()) {
	$bytes = $client->read_bytes($request, 1024);
	echo "* ✅ Got " . strlen($bytes) . " bytes on request $request->id \n";
}

foreach($client->get_failed_requests() as $failed_request) {
	echo "* ❌ Failed request to " . $failed_request->url . " – " . $failed_request->error . "\n";
}


// $client->wait_for_headers($requests[3]);
// var_dump($requests[3]->get_response()->get_headers());
