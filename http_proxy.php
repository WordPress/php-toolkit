<?php
/**
 * HTTP Proxy implemented using AsyncHttp\Client
 *
 * This could be a replacement for the curl-based PHPProxy shipped
 * in https://github.com/WordPress/wordpress-playground/pull/1546.
 */

use WordPress\AsyncHttp\Client;
use WordPress\AsyncHttp\ClientEvent;
use WordPress\AsyncHttp\Request;

require __DIR__ . '/vendor/autoload.php';

function get_target_url($server_data=null) {
	if ($server_data === null) {
		$server_data = $_SERVER;
	}
	$requestUri = $server_data['REQUEST_URI'];
	$targetUrl = $requestUri;

	// Remove the current script name from the beginning of $targetUrl
	if (strpos($targetUrl, $server_data['SCRIPT_NAME']) === 0) {
		$targetUrl = substr($targetUrl, strlen($server_data['SCRIPT_NAME']));
	}

	// Remove the leading slash
	if ($targetUrl[0] === '/' || $targetUrl[0] === '?') {
		$targetUrl = substr($targetUrl, 1);
	}

	return $targetUrl;
}
$target_url = get_target_url();
$host = parse_url($target_url, PHP_URL_HOST);
$requests = [
	new Request(
		$target_url,
		[
			'method' => $_SERVER['REQUEST_METHOD'],
			'headers' => [
				...getallheaders(),
				'Accept-Encoding' => 'gzip, deflate',
				'Host' => $host,
			],
			'body_stream' => $_SERVER['REQUEST_METHOD'] === 'POST' ? fopen('php://input', 'r') : null,
		]
	),
];

$client = new Client();
$client->enqueue( $requests );

$headers_sent = false;
while ( $client->await_next_event() ) {
	$request = $client->get_request();
	switch ( $client->get_event() ) {
		case Client::EVENT_GOT_HEADERS:
			http_response_code($request->response->status_code);
			foreach ( $request->response->get_headers() as $name => $value ) {
				if(
					$name === 'transfer-encoding' ||
					$name === 'set-cookie' ||
					$name === 'content-encoding'
				) {
					continue;
				}
				header("$name: $value");
			}
			$headers_sent = true;
			break;
		case Client::EVENT_BODY_CHUNK_AVAILABLE:
			echo $client->get_response_body_chunk();
			break;
		case Client::EVENT_FAILED:
			if(!$headers_sent) {
				http_response_code(500);
				echo "Failed request to " . $request->url . " – " . $request->error;
			}
			break;
		case Client::EVENT_REDIRECT:
		case Client::EVENT_FINISHED:
			break;
	}
	echo "\n";
}

