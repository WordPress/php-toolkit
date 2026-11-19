<?php

// Report every error to the log, but don't display it. A displayed error
// would become part of the response body. Without a php.ini, PHP doesn't
// log errors, so turn logging on explicitly.
error_reporting( E_ALL );
ini_set( 'display_errors', 0 );
ini_set( 'log_errors', 1 ); // phpcs:ignore WordPress.PHP.IniSet.log_errors_Disallowed -- The proxy runs outside WordPress.

define( 'MAX_REQUEST_SIZE', 1 * 1024 * 1024 ); // 1MB.
define( 'MAX_RESPONSE_SIZE', 100 * 1024 * 1024 ); // 100MB.
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
if ( 'OPTIONS' === $_SERVER['REQUEST_METHOD'] ) {
	header( 'Allow: GET, POST, OPTIONS' );
	exit;
}

// Handle only GET and POST requests.
if ( 'GET' !== $_SERVER['REQUEST_METHOD'] && 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
	http_response_code( 405 );
	echo 'Method Not Allowed';
	exit;
}

// A request with a chunked body has no Content-Length.
$request_content_length = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
if ( 'GET' !== $_SERVER['REQUEST_METHOD'] && $request_content_length >= MAX_REQUEST_SIZE ) {
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

// Get the full target URL from the request path.
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

define(
	'CURRENT_SCRIPT_URI',
	get_current_script_uri( $target_url, $_SERVER['REQUEST_URI'] )
);

$ch = curl_init( $target_url );

$is_chunked_response = false;
// Whether the header callback is inside an interim 1xx response's headers.
$in_interim_response = false;

/**
 * Replaces the target's response with an error response from the proxy.
 *
 * Call only before any output has started, while headers_sent() is false.
 * The target's status and headers may be queued by then. The error response
 * keeps only the headers queued before the target was contacted, which
 * includes any set by cors-proxy-config.php or an auto_prepend_file.
 *
 * Every target header goes, not only the ones describing its body. An error
 * from the proxy must not carry headers from the response it replaces.
 */
function replace_response_with_error( $status_code, $message ) {
	global $is_chunked_response, $proxy_headers;

	header_remove();
	foreach ( $proxy_headers as $header ) {
		header( $header, false );
	}
	// The target's Transfer-Encoding header is gone, so don't chunk the
	// error message.
	$is_chunked_response = false;
	http_response_code( $status_code );
	send_response_chunk( $message );
}

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

	return $is_chunked_response && 'cli-server' === php_sapi_name();
}

// Pin the connection to the IP we've validated, on the URL's effective port.
try {
	set_curl_destination( $ch, $resolved );
} catch ( CorsProxyException $e ) {
	http_response_code( 500 );
	echo "Internal Server Error\n\n" . $e->getMessage();
	exit;
}

$strictly_disallowed_headers = array(
	// Cookies represent a relationship between the proxy server.
	// and the client, so it is inappropriate to forward them.
	'Cookie',
	// Drop the incoming Host header because it identifies the.
	// proxy server, not the target server.
	'Host',
);
$headers_requiring_opt_in    = array(
	// Allow Authorization header to be forwarded only if the client.
	// explicitly opts in to avoid undesirable situations such as:
	// - a browser auto-sending basic auth with every proxy request.
	// - the proxy forwarding the basic auth values to all target servers.
	'Authorization',
);
$curl_headers                = kv_headers_to_curl_format(
	filter_headers_by_name(
		getallheaders(),
		$strictly_disallowed_headers,
		$headers_requiring_opt_in
	)
);
// cURL generates the Host header from the target URL, including a custom
// port, so the incoming Host header is dropped above and not replaced here.
// @TODO: Consider relaying client IP with the following reasoning:
// Let's not take full credit for the proxied request.
// This is a CORS proxy, not an IP anonymizer.
// NOTE: We cannot do this reliably based on X-Forwarded-For unless.
// we trust the reverse proxy, so it cannot be done unconditionally.
// in this script because we do not control where others deploy it.
curl_setopt( $ch, CURLOPT_HTTPHEADER, $curl_headers );

// Set options to stream data.
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
		&$in_interim_response,
		&$is_chunked_response
	) {
		$len = strlen( $header );

		// cURL passes every response's status line and headers to this
		// callback, including interim 1xx responses before the final one.
		// A status line starts a new header block.
		if ( 0 === stripos( $header, 'HTTP/' ) ) {
			$status_code = preg_match( '#^HTTP/\S+\s+(\d{3})#', $header, $matches )
				? (int) $matches[1]
				: curl_getinfo( $curl, CURLINFO_HTTP_CODE );
			if ( 101 === $status_code ) {
				// The target switched to another protocol. A single HTTP
				// response can't carry it, and cURL would deliver the other
				// protocol's bytes as the response body.
				replace_response_with_error( 502, 'Bad Gateway – the target switched protocols' );
				exit;
			}
			// Skip other interim responses, such as 100 Continue or
			// 103 Early Hints. Only the final response is relayed.
			$in_interim_response = $status_code < 200;
			if ( ! $in_interim_response ) {
				http_response_code( $status_code );
			}

			return $len;
		}
		if ( $in_interim_response ) {
			return $len;
		}

		$colon_pos = strpos( $header, ':' );

		if ( false === $colon_pos ) {
			return $len;
		}

		$name  = strtolower( substr( $header, 0, $colon_pos ) );
		$value = trim( substr( $header, $colon_pos + 1 ) );

		if ( 'content-length' === $name ) {
			$content_length = intval( $value );
			if ( $content_length >= MAX_RESPONSE_SIZE ) {
				replace_response_with_error( 413, 'Response Too Large' );
				exit;
			}

			return $len;
		}

		if ( 'transfer-encoding' === $name && false !== stripos( $value, 'chunked' ) ) {
			$is_chunked_response = true;
			header( $header, false );

			return $len;
		}

		if ( 0 === stripos( $header, 'Location:' ) ) {
			// Adjust the redirection URL to go back to the proxy script.
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
			0 !== stripos( $header, 'HTTP/' ) &&
			// The proxy server does not support relaying auth challenges.
			// Specifically, we want to avoid browsers prompting for basic auth
			// credentials which they will send to the proxy server for the
			// remainder of the session.
			0 !== stripos( $header, 'Set-Cookie:' ) &&
			0 !== stripos( $header, 'Authorization:' ) &&
			0 !== stripos( $header, 'WWW-Authenticate:' ) &&
			0 !== stripos( $header, 'Cache-Control:' ) &&
			// The browser won't accept multiple values for these headers.
			0 !== stripos( $header, 'Access-Control-Allow-Origin:' ) &&
			0 !== stripos( $header, 'Access-Control-Allow-Credentials:' ) &&
			0 !== stripos( $header, 'Access-Control-Allow-Methods:' ) &&
			0 !== stripos( $header, 'Access-Control-Allow-Headers:' )
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

// Handle request method and data.
$request_method = $_SERVER['REQUEST_METHOD'];
curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, $request_method );

if ( 'GET' !== $request_method && 'HEAD' !== $request_method && 'OPTIONS' !== $request_method ) {
	$input = fopen( 'php://input', 'r' );
	curl_setopt( $ch, CURLOPT_UPLOAD, true );
	curl_setopt( $ch, CURLOPT_INFILE, $input );
	curl_setopt( $ch, CURLOPT_INFILESIZE, $request_content_length );
}

// Avoid stale, cached responses. This is set before the snapshot below, so
// the proxy's own error responses keep it too.
header( 'Cache-Control: no-cache' );

// Everything queued so far belongs to the proxy's own response. The proxy
// restores these headers when it replaces the target's response with an error.
$proxy_headers = headers_list();

// Execute cURL session. Check curl_errno() rather than the return value:
// before PHP 7.4, curl_exec() returns true when a transfer ends early.
curl_exec( $ch );
if ( 0 !== curl_errno( $ch ) ) {
	$error_message = 'Bad Gateway – curl_exec error: ' . curl_error( $ch );
	if ( headers_sent() ) {
		// Part of the target's response was already sent, so its status and
		// headers can't be replaced anymore.
		send_response_chunk( $error_message );
	} else {
		replace_response_with_error( 502, $error_message );
	}
}
// curl_close() does nothing since PHP 8.0 and is deprecated since PHP 8.5.
if ( PHP_VERSION_ID < 80000 ) {
	curl_close( $ch ); // phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated -- Only runs before PHP 8.0.
}

// Only send chunked transfer encoding footer if we're using chunked encoding.
// We need to manually send the footer when running in the PHP built-in server.
// because, unlike apache or nginx, it won't handle that for us.
if ( should_send_as_chunked_response() ) {
	echo "0\r\n\r\n";
}
