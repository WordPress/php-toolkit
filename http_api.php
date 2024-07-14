<?php

use WordPress\AsyncHttp\Client;
use WordPress\AsyncHttp\ClientEvent;
use WordPress\AsyncHttp\Request;

require __DIR__ . '/vendor/autoload.php';

$requests = [
//	new Request( "https://w.org/latest-yo.zip" ),
//	new Request( "https://adamadam.blog" ),
	// new Request("https://anglesharp.azurewebsites.net/Chunked", [
	// 	'http_version' => '1.1'
	// ]),
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

// Way 1: Consume events, get all progress information in the correct order
while ( $event = $client->await_next_event() ) {
	echo( "Request " . $event->request->id . ": $event->name " );
	switch ( $event->name ) {
		case ClientEvent::EVENT_GOT_HEADERS:
			print_r( $event->request->response->get_headers() );
			break;

		case ClientEvent::EVENT_BODY_CHUNK_AVAILABLE:
			//	$client->read_bytes( $event->request, 1024 );
			echo " (".$event->request->response->received_bytes."/".$event->request->response->total_bytes.")\n";
			break;

		case ClientEvent::EVENT_FAILED:
			echo "– Failed request to {$event->request->url} – {$event->request->error}\n";
			break;

		case ClientEvent::EVENT_REDIRECT:
			echo "– Redirected from {$event->request->url} to {$event->request->redirected_to->url}\n";
			break;

		default:
			echo "\n";
			break;
	}
}


// Way 2: Consume specific requests
$request = $requests[0];
while ( true ) {
	$bytes = $client->read_bytes( $request, 1024, Client::READ_POLL_ALL );
	if ( $bytes === false ) {
		break;
	}
	if(!$bytes) {
		break;
//		var_dump($request->state);
//		die();
	}
	echo "* ✅ Got " . strlen( $bytes ) . " bytes on request {$request->id} \n";
}
echo 'aa';

foreach ( $client->get_failed_requests() as $failed_request ) {
	echo "* ❌ Failed request to " . $failed_request->url . " – " . $failed_request->error . "\n";
}


// $client->wait_for_headers($requests[3]);
// var_dump($requests[3]->get_response()->get_headers());
