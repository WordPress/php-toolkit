<?php

namespace WordPress\Blueprints\Steps;

use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\HttpServer\IncomingRequest;
use WordPress\HttpServer\Response\TcpResponseWriteStream;
use WordPress\HttpServer\TcpServer;

use function WordPress\Filesystem\pipe_stream;
use function WordPress\Filesystem\wp_join_unix_paths;

// Initialize runtime for the given document root
require_once __DIR__ . '/../../../../vendor/autoload.php';

error_reporting( E_ALL & ~E_NOTICE );


$document_root = __DIR__;

// Parse CLI arguments for host and port
$host = '127.0.0.1';
$port = 8000;
if ( isset( $argv ) && is_array( $argv ) ) {
	if ( isset( $argv[1] ) ) {
		$host = $argv[1];
	}
	if ( isset( $argv[2] ) ) {
		$port = (int) $argv[2];
	}
}

$server = new TcpServer( $host, $port );
$server->set_handler( function ( IncomingRequest $request, TcpResponseWriteStream $response ) use ( $document_root ) {
	$pathname = $request->get_parsed_url()->pathname;

	$file_path = wp_join_unix_paths( $document_root, $pathname );
	if ( ! file_exists( $file_path ) || ! is_file( $file_path ) ) {
		$response->send_http_code( 404 );
		$response->send_header( 'Content-Type', 'text/plain' );
		$response->append_bytes( "Path $pathname not found" );

		return;
	}

	$response->send_http_code( 200 );
	$response->send_header( 'Content-Type', 'application/octet-stream' );

	$parsed_url = $request->get_parsed_url();
	if ( $parsed_url->searchParams->get( 'chunked' ) === 'yes' ) {
		$response->use_chunked_encoding();
	} else {
		$response->send_header( 'Content-Length', filesize( $file_path ) );
	}
	$file_stream = FileReadStream::from_path( $file_path );
	pipe_stream( $file_stream, $response );
} );

$server->serve( function ( $host, $port ) {
	echo "Server started on http://{$host}:{$port}\n";
} );
