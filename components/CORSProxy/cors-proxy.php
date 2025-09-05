<?php

// Set error reporting
error_reporting( E_ALL );
ini_set( 'display_errors', 1 );

define( 'MAX_REQUEST_SIZE', 1 * 1024 * 1024 ); // 1MB
define( 'MAX_RESPONSE_SIZE', 100 * 1024 * 1024 ); // 100MB
require_once __DIR__ . '/cors-proxy-functions.php';

$config_file = __DIR__ . '/cors-proxy-config.php';
if ( file_exists( $config_file ) ) {
	require_once $config_file;
}

$server_host = $_SERVER['HTTP_HOST'] ?? '';
$origin      = $_SERVER['HTTP_ORIGIN'] ?? '';

if ( should_respond_with_cors_headers( $server_host, $origin ) ) {
	header( 'Access-Control-Allow-Origin: ' . $origin );
	header( 'Access-Control-Allow-Credentials: true' );
	header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
	header( 'Access-Control-Allow-Headers: Accept, Authorization, Content-Type, git-protocol, wp_blog, wp_install' );
}
if ( $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) {
	header( 'Allow: GET, POST, OPTIONS' );
	exit;
}

// Handle only GET and POST requests
if ( $_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
	http_response_code( 405 );
	echo 'Method Not Allowed';
	exit;
}

if ( $_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['CONTENT_LENGTH'] >= MAX_REQUEST_SIZE ) {
	http_response_code( 413 );
	echo 'Request Entity Too Large';
	exit;
}

// @TODO: Consider redirecting to the target URL if presented with a non-browser user agent.

if ( function_exists( 'playground_cors_proxy_maybe_rate_limit' ) ) {
	playground_cors_proxy_maybe_rate_limit();
} elseif (
	! getenv( 'PLAYGROUND_CORS_PROXY_DISABLE_RATE_LIMIT' ) && (
		! defined( 'PLAYGROUND_CORS_PROXY_DISABLE_RATE_LIMIT' ) ||
		! PLAYGROUND_CORS_PROXY_DISABLE_RATE_LIMIT
	)
) {
	http_response_code( 503 );
	echo 'Server needs to configure rate-limiting.';
	exit;
}

// Get the full target URL from the request path
$target_url = get_target_url( $_SERVER );
if ( ! $target_url ) {
	http_response_code( 400 );
	echo "Bad Request\n\nNo URL provided";
	exit;
}

try {
	$resolved = url_validate_and_resolve( $target_url );
} catch ( CorsProxyException $e ) {
	http_response_code( 400 );
	echo "Bad Request\n\n" . $e->getMessage();
	exit;
}

$host       = $resolved['host'];
$resolved_ip = $resolved['ip'];

define(
	'CURRENT_SCRIPT_URI',
	get_current_script_uri( $target_url, $_SERVER['REQUEST_URI'] )
);

$ch = curl_init( $target_url );

$is_chunked_response = false;
$http_code_sent      = false;

$relay_http_code_and_initial_headers_if_not_already_sent = function () use ( $ch, &$http_code_sent ) {
	if ( ! $http_code_sent ) {
		// Set the response code from the target server
		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		http_response_code( $http_code );

		// For now, let's clearly avoid the possibility of stale, cached responses.
		header( 'Cache-Control: no-cache' );

		$http_code_sent = true;
	}
};

function send_response_chunk( $data ) {
	if ( should_send_as_chunked_response() ) {
		// We need to manually chunk the response when running in the PHP
		// built-in server. It won't handle that for us.
		printf( "%s\r\n%s\r\n", dechex( strlen( $data ) ), $data );
	} else {
		// When running behing an Apache or Nginx or another webserver,
		// it will handle the chunking for us. Manually sending the chunk
		// header, \r\n separator, body, and \r\n trailer isn't just
		// unnecessary, but it would actually include those bytes in the
		// response body.
		echo $data;
	}
	@ob_flush();
	@flush();
}

/**
 * We need to manually chunk the response when running the PHP
 * dev server AND the transfer-encoding header is set to chunked.
 *
 * Apache, Nginx, etc. will handle the chunking for us.
 */
function should_send_as_chunked_response() {
	global $is_chunked_response;

	return $is_chunked_response && php_sapi_name() === 'cli-server';
}

// Pin the hostname resolution to an IP we've resolved earlier
curl_setopt(
	$ch,
	CURLOPT_RESOLVE,
	array(
		"$host:80:$resolved_ip",
		"$host:443:$resolved_ip",
	)
);

$strictly_disallowed_headers = array(
	// Cookies represent a relationship between the proxy server
	// and the client, so it is inappropriate to forward them.
	'Cookie',
	// Drop the incoming Host header because it identifies the
	// proxy server, not the target server.
	'Host',
);
$headers_requiring_opt_in    = array(
	// Allow Authorization header to be forwarded only if the client
	// explicitly opts in to avoid undesirable situations such as:
	// - a browser auto-sending basic auth with every proxy request
	// - the proxy forwarding the basic auth values to all target servers
	'Authorization',
);
$curl_headers                 = kv_headers_to_curl_format(
	filter_headers_by_name(
		getallheaders(),
		$strictly_disallowed_headers,
		$headers_requiring_opt_in
	)
);
curl_setopt(
	$ch,
	CURLOPT_HTTPHEADER,
	array_merge(
		$curl_headers,
		array(
			"Host: $host",
			// @TODO: Consider relaying client IP with the following reasoning:
			// Let's not take full credit for the proxied request.
			// This is a CORS proxy, not an IP anonymizer.
			// NOTE: We cannot do this reliably based on X-Forwarded-For unless
			// we trust the reverse proxy, so it cannot be done unconditionally
			// in this script because we do not control where others deploy it.
		)
	)
);

// Set options to stream data
curl_setopt( $ch, CURLOPT_RETURNTRANSFER, false );
curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false );
curl_setopt(
	$ch,
	CURLOPT_HEADERFUNCTION,
	function (
		$curl,
		$header
	) use (
		$target_url,
		$relay_http_code_and_initial_headers_if_not_already_sent,
		&$is_chunked_response
	) {
		@$relay_http_code_and_initial_headers_if_not_already_sent();

		$len      = strlen( $header );
		$colon_pos = strpos( $header, ':' );

		if ( $colon_pos === false ) {
			return $len;
		}

		$name  = strtolower( substr( $header, 0, $colon_pos ) );
		$value = trim( substr( $header, $colon_pos + 1 ) );

		if ( $name === 'content-length' ) {
			$content_length = intval( $value );
			if ( $content_length >= MAX_RESPONSE_SIZE ) {
				http_response_code( 413 );
				send_response_chunk( 'Response Too Large' );
				exit;
			}

			return $len;
		}

		if ( $name === 'transfer-encoding' && stripos( $value, 'chunked' ) !== false ) {
			$is_chunked_response = true;
			header( $header, false );

			return $len;
		}

		if ( stripos( $header, 'Location:' ) === 0 ) {
			// Adjust the redirection URL to go back to the proxy script
			$location_url = trim( substr( $header, 9 ) );
			$new_location = rewrite_relative_redirect(
				$target_url,
				$location_url,
				CURRENT_SCRIPT_URI
			);
			header( 'Location: ' . $new_location, true );
		} elseif (
			// Safari fails with "Cannot connect to the server" if we let
			// the HTTP/2 line be relayed. This proxy doesn't support HTTP/2,
			// so let's not allow the HTTP line to explicitly pass through.
			// PHP already provides the HTTP version in the response code anyway.
			stripos( $header, 'HTTP/' ) !== 0 &&
			// The proxy server does not support relaying auth challenges.
			// Specifically, we want to avoid browsers prompting for basic auth
			// credentials which they will send to the proxy server for the
			// remainder of the session.
			stripos( $header, 'Set-Cookie:' ) !== 0 &&
			stripos( $header, 'Authorization:' ) !== 0 &&
			stripos( $header, 'WWW-Authenticate:' ) !== 0 &&
			stripos( $header, 'Cache-Control:' ) !== 0 &&
			// The browser won't accept multiple values for these headers.
			stripos( $header, 'Access-Control-Allow-Origin:' ) !== 0 &&
			stripos( $header, 'Access-Control-Allow-Credentials:' ) !== 0 &&
			stripos( $header, 'Access-Control-Allow-Methods:' ) !== 0 &&
			stripos( $header, 'Access-Control-Allow-Headers:' ) !== 0
		) {
			header( $header, false );
		}

		return $len;
	}
);

curl_setopt(
	$ch,
	CURLOPT_WRITEFUNCTION,
	function ( $curl, $data ) use ( &$is_chunked_response ) {
		send_response_chunk( $data, $is_chunked_response );

		return strlen( $data );
	}
);

// Handle request method and data
$request_method = $_SERVER['REQUEST_METHOD'];
curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, $request_method );

if ( $request_method !== 'GET' && $request_method !== 'HEAD' && $request_method !== 'OPTIONS' ) {
	$input = fopen( 'php://input', 'r' );
	curl_setopt( $ch, CURLOPT_UPLOAD, true );
	curl_setopt( $ch, CURLOPT_INFILE, $input );
	curl_setopt( $ch, CURLOPT_INFILESIZE, $_SERVER['CONTENT_LENGTH'] );
}

// Execute cURL session
if ( ! curl_exec( $ch ) ) {
	http_response_code( 502 );
	send_response_chunk( 'Bad Gateway – curl_exec error: ' . curl_error( $ch ) );
} else {
	@$relay_http_code_and_initial_headers_if_not_already_sent();
}
// Close cURL session
curl_close( $ch );

// Only send chunked transfer encoding footer if we're using chunked encoding.
// We need to manually send the footer when running in the PHP built-in server
// because, unlike apache or nginx, it won't handle that for us.
if ( should_send_as_chunked_response() ) {
	echo "0\r\n\r\n";
}
