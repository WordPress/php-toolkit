<?php
/**
 * Router for the servers the CORS proxy end-to-end tests send requests to.
 *
 * It reports which server answered and what request reached it, so a test
 * can tell the upstream server from a forward proxy set in the environment.
 */

$body = json_encode(
	array(
		'served_by'   => getenv( 'CORS_PROXY_TEST_SERVER_ROLE' ),
		'request_uri' => $_SERVER['REQUEST_URI'],
		'host'        => isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : null,
	)
);

// A fixed length keeps the response from being chunked.
header( 'Content-Type: application/json' );
header( 'Content-Length: ' . strlen( $body ) );
echo $body;
