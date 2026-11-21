<?php
/**
 * Minimal HTTP server for responses PHP's built-in server can't send.
 *
 * The request path picks the response:
 *
 * - /ok: a 200 response, sent without reading any request body.
 * - /continue: an interim 100 Continue response, then the final 200.
 * - /switching-protocols: 101 Switching Protocols, then another protocol's bytes.
 * - /too-large: headers announcing a body above the proxy's size limit.
 * - /no-body: headers announcing a body, then the connection closes.
 * - /chunked-no-body: chunked response headers, then the connection closes.
 *
 * Run: php raw-upstream-server.php <port>
 */

$responses = array(
	'/ok'                  =>
		"HTTP/1.1 200 OK\r\n" .
		"Content-Type: text/plain\r\n" .
		"Content-Length: 2\r\n" .
		"Connection: close\r\n" .
		"\r\n" .
		'ok',
	'/continue'            =>
		"HTTP/1.1 100 Continue\r\n" .
		"X-Interim-Header: continue\r\n" .
		"\r\n" .
		"HTTP/1.1 200 OK\r\n" .
		"Content-Type: text/plain\r\n" .
		"Content-Length: 5\r\n" .
		"Connection: close\r\n" .
		"\r\n" .
		'hello',
	'/switching-protocols' =>
		"HTTP/1.1 101 Switching Protocols\r\n" .
		"Upgrade: example\r\n" .
		"Connection: Upgrade\r\n" .
		"X-Target-Header: switching-protocols\r\n" .
		"\r\n" .
		'rawdata',
	'/too-large'           =>
		"HTTP/1.1 200 OK\r\n" .
		"X-Target-Header: too-large\r\n" .
		"Content-Length: 104857600\r\n" .
		"Connection: close\r\n" .
		"\r\n",
	'/no-body'             =>
		"HTTP/1.1 200 OK\r\n" .
		"X-Target-Header: no-body\r\n" .
		"Content-Length: 10\r\n" .
		"Connection: close\r\n" .
		"\r\n",
	'/chunked-no-body'     =>
		"HTTP/1.1 200 OK\r\n" .
		"X-Target-Header: chunked-no-body\r\n" .
		"Transfer-Encoding: chunked\r\n" .
		"Connection: close\r\n" .
		"\r\n",
);

$server = stream_socket_server( 'tcp://127.0.0.1:' . (int) $argv[1], $error_code, $error_message );
if ( false === $server ) {
	fwrite( STDERR, $error_message . "\n" );
	exit( 1 );
}

while ( true ) {
	$connection = @stream_socket_accept( $server, 3600 );
	if ( false === $connection ) {
		continue;
	}
	// The request line picks the response. The headers don't matter.
	$request_line = fgets( $connection );
	if ( false === $request_line ) {
		// A connection that only checks whether the server is listening.
		fclose( $connection );
		continue;
	}
	do {
		$line = fgets( $connection );
	} while ( false !== $line && '' !== rtrim( $line ) );

	$parts = explode( ' ', (string) $request_line );
	$path  = isset( $parts[1] ) ? $parts[1] : '';
	fwrite(
		$connection,
		isset( $responses[ $path ] ) ? $responses[ $path ] : "HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\nConnection: close\r\n\r\n"
	);
	fclose( $connection );
}
