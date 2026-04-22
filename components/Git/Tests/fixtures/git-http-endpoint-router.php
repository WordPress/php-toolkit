<?php

require_once dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/vendor/autoload.php';

use WordPress\Git\GitEndpoint;
use WordPress\Git\GitRepository;
use WordPress\Filesystem\LocalFilesystem;
use WordPress\HttpServer\Response\BufferingResponseWriter;

$repository_path = getenv( 'PHP_TOOLKIT_GIT_E2E_REPOSITORY_PATH' );
if ( ! $repository_path ) {
	http_response_code( 500 );
	echo 'Missing PHP_TOOLKIT_GIT_E2E_REPOSITORY_PATH.';
	return;
}

$request_path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$query_string = $_SERVER['QUERY_STRING'] ?? '';
$prefix       = '/repo.git';

if ( 0 !== strncmp( $request_path, $prefix, strlen( $prefix ) ) ) {
	http_response_code( 404 );
	echo 'Unknown Git endpoint path.';
	return;
}

$git_path = substr( $request_path, strlen( $prefix ) );
if ( '' === $git_path ) {
	$git_path = '/';
}
if ( '' !== $query_string ) {
	$git_path .= '?' . $query_string;
}

$repository = new GitRepository(
	LocalFilesystem::create( $repository_path ),
	array(
		'default_branch' => 'trunk',
	)
);
$endpoint   = new GitEndpoint( $repository );
$response   = new BufferingResponseWriter();

try {
	$endpoint->handle_request(
		$git_path,
		file_get_contents( 'php://input' ),
		$response
	);
} catch ( Exception $exception ) {
	http_response_code( 500 );
	header( 'Content-Type: text/plain' );
	echo $exception->getMessage();
}
