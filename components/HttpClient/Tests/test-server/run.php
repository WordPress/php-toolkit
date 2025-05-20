<?php

use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\HttpServer\IncomingRequest;
use WordPress\HttpServer\Response\TcpResponseWriteStream;
use WordPress\HttpServer\TcpServer;

require_once __DIR__ . '/../../../../vendor/autoload.php';

error_reporting( E_ALL );

$document_root = realpath( __DIR__ . '/../fixtures' );
$host          = $argv[1] ?? '127.0.0.1';
$port          = isset( $argv[2] ) ? (int) $argv[2] : 8950;
$scenario      = $argv[3] ?? 'default';

$server = new TcpServer( $host, $port );
$server->set_handler( function ( IncomingRequest $request, TcpResponseWriteStream $response ) use ( $document_root, $scenario ) {
	$path  = $request->get_parsed_url()->pathname;
	$query = $request->get_parsed_url()->searchParams;

	switch ( $scenario ) {
		case 'echo-method':
			$response->send_http_code( 200 );
			$response->send_header( 'Content-Type', 'text/plain' );
			$response->append_bytes( $request->method );
			break;
		case 'status':
			$status = (int) basename( $path );
			$response->send_http_code( $status );
			$response->send_header( 'Content-Type', 'text/plain' );
			switch ( $status ) {
				case 200:
					$body = 'OK';
					break;
				case 204:
					$body = '';
					break;
				case 301:
				case 302:
					$body = 'Redirect';
					break;
				case 400:
					$body = 'Bad Request';
					break;
				case 404:
					$body = 'Not Found';
					break;
				case 500:
					$body = 'Internal Server Error';
					break;
				default:
					$body = 'Status';
					break;
			}
			$response->append_bytes( $body );
			break;
		case 'encoding':
			$encoding = basename( $path );
			if ( $encoding === 'chunked' ) {
				$response->use_chunked_encoding();
				$response->send_header( 'Content-Type', 'text/plain' );
				$response->append_bytes( 'chunked' );
			} elseif ( $encoding === 'gzip' ) {
				$response->send_header( 'Content-Encoding', 'gzip' );
				$response->send_header( 'Content-Type', 'text/plain' );
				$gz = gzencode( 'gzipped' );
				$response->append_bytes( $gz );
			} else {
				$response->send_header( 'Content-Type', 'text/plain' );
				$response->append_bytes( 'plain' );
			}
			break;
		case 'redirect':
			$type = basename( $path );
			if ( $type === 'absolute' ) {
				$response->send_http_code( 302 );
				$response->send_header( 'Location', '/redirected' );
				$response->append_bytes( 'Redirect' );
			} elseif ( $type === 'relative' ) {
				$response->send_http_code( 302 );
				$response->send_header( 'Location', '/redirected' );
				$response->append_bytes( 'Redirect' );
			} elseif ( $type === 'loop' ) {
				$response->send_http_code( 302 );
				$response->send_header( 'Location', '/redirect/loop' );
				$response->append_bytes( 'Redirect' );
			} elseif ( $path === '/redirected' ) {
				$response->send_http_code( 200 );
				$response->append_bytes( 'redirected!' );
			} else {
				$response->send_http_code( 404 );
				$response->append_bytes( 'Not Found' );
			}
			break;
		case 'error':
			$err = basename( $path );
			if ( $err === 'broken-connection' ) {
				$response->dangerously_mark_headers_as_sent();
				$response->append_bytes( "HTTP/1.1 200 OK\r\n" );
				$response->append_bytes( "Content-Type: text/pla" );
				// Simulate broken connection by not closing properly
				exit( 1 );
			} elseif ( $err === 'invalid-response' ) {
				// Send malformed HTTP
				$response->dangerously_mark_headers_as_sent();
				$response->append_bytes( "INVALID\r\n\r\n" );
			} elseif ( $err === 'timeout' ) {
				sleep( 10 ); // Simulate timeout
				$response->send_http_code( 200 );
				$response->append_bytes( 'timeout' );
			} else {
				$response->send_http_code( 500 );
				$response->append_bytes( 'Unknown error' );
			}
			break;
		case 'headers':
			$header = basename( $path );
			if ( $header === 'X-Test-Header' ) {
				$response->send_header( 'X-Test-Header', 'test-value' );
				$response->append_bytes( 'X-Test-Header: test-value' );
			} elseif ( $header === 'X-Long-Header' ) {
				$response->send_header( 'X-Long-Header', str_repeat( 'a', 1000 ) );
				$response->append_bytes( 'X-Long-Header: ' . str_repeat( 'a', 1000 ) );
			} elseif ( $header === 'X-Multi-Header' ) {
				$response->send_header( 'X-Multi-Header', 'value1,value2' );
				$response->append_bytes( 'X-Multi-Header: value1,value2' );
			} else {
				$response->send_header( 'X-Unknown', 'unknown' );
				$response->append_bytes( 'unknown header' );
			}
			break;
		case 'body':
			$type = basename( $path );
			if ( $type === 'empty' ) {
				$response->send_http_code( 200 );
				$response->append_bytes( '' );
			} elseif ( $type === 'small' ) {
				$response->send_http_code( 200 );
				$response->append_bytes( 'small' );
			} elseif ( $type === 'large' ) {
				$response->send_http_code( 200 );
				$response->append_bytes( str_repeat( 'x', 10000 ) );
			} elseif ( $type === 'binary' ) {
				$response->send_http_code( 200 );
				$response->send_header( 'Content-Type', 'application/octet-stream' );
				$response->append_bytes( random_bytes( 256 ) );
			} else {
				$response->send_http_code( 404 );
				$response->append_bytes( 'Not Found' );
			}
			break;
		case 'stream':
			$type = basename( $path );
			if ( $type === 'slow' ) {
				$response->use_chunked_encoding();
				for ( $i = 0; $i < 5; $i ++ ) {
					$response->append_bytes( "s" );
					usleep( 200000 ); // 200ms
				}
			} elseif ( $type === 'fast' ) {
				$response->use_chunked_encoding();
				for ( $i = 0; $i < 10; $i ++ ) {
					$response->append_bytes( "f" );
				}
			} else {
				$response->send_http_code( 404 );
				$response->append_bytes( 'Not Found' );
			}
			break;
		default:
			// Serve static files or a default response
			$file = $document_root . $path;
			if ( file_exists( $file ) && is_file( $file ) ) {
				$response->send_http_code( 200 );
				$response->send_header( 'Content-Type', 'text/plain' );
				$response->send_header( 'Content-Length', (string) filesize( $file ) );
				$stream = FileReadStream::from_path( $file );
				while ( !$stream->reached_end_of_data() ) {
					$n = $stream->pull( 4096 );
					$response->append_bytes( $stream->consume( $n ) );
				}
				$stream->close_reading();
				$response->close_writing();
			} else {
				$response->send_http_code( 404 );
				$response->send_header( 'Content-Type', 'text/plain' );
				$response->append_bytes( 'Not Found' );
			}
			break;
	}
} );

$server->serve( function ( $host, $port ) {
	echo "Server started on http://{$host}:{$port}\n";
} );
