<?php

use WordPress\AsyncHttp\Client;
use WordPress\AsyncHttp\ClientEvent;
use WordPress\AsyncHttp\Request;

require __DIR__ . '/vendor/autoload.php';

$requests = [
//	new Request( "https://wordpress.org/latest.zip" ),
	new Request( "https://raw.githubusercontent.com/wpaccessibility/a11y-theme-unit-test/master/a11y-theme-unit-test-data.xml" ),
	new Request( "https://adamadam.blog" ),
	new Request( "https://anglesharp.azurewebsites.net/Chunked", [
		'http_version' => '1.1',
	] ),
	new Request( "https://anglesharp.azurewebsites.net/Chunked", [
		'http_version' => '1.0',
	] ),
	new Request( "http://127.0.0.1:3000/", [
		'http_version' => '1.0',
		'headers'      => [
			'please-redirect' => 'yes',
		],
	] ),
];

// var_dump(streams_http_response_read_bytes($streams, 1024));
// Enqueuing another request here is instant and won't start the download yet.
$client = new Client( [
	'concurrency'   => 2,
	'max_redirects' => 2,
] );

$client->enqueue( $requests );
while ( $client->await_next_event( [ 'requests' => [ $requests[2] ] ] ) ) {
	echo "Request " . $client->get_request()->id . ": " . $client->get_event() . " \n";
	switch ( $client->get_event() ) {
		case Client::EVENT_BODY_CHUNK_AVAILABLE:
			echo $client->get_response_body_chunk() . "\n\n";
			break;
	}
}

foreach ( $client->get_failed_requests() as $failed_request ) {
	echo "* ❌ Failed request to " . $failed_request->url . " – " . $failed_request->error . "\n";
}
