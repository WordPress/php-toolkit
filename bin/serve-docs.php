<?php
/**
 * Local dev server for docs/. Adds CORS headers so the WordPress
 * Playground iframe can fetch docs/assets/php-toolkit.zip across
 * origins. GitHub Pages serves Access-Control-Allow-Origin: * by
 * default, so this script is only needed for local previews.
 *
 * Usage:
 *     php bin/serve-docs.php [port]    # default 8787
 *
 * Internally spawns `php -S` with this file as the router. When run
 * via `php -S` (i.e. as a router), it adds CORS headers and falls
 * through to the static file by returning false.
 */

declare(strict_types=1);

if ( PHP_SAPI === 'cli-server' ) {
	header( 'Access-Control-Allow-Origin: *' );
	header( 'Access-Control-Allow-Headers: *' );
	// Returning false tells the built-in server to serve the requested
	// file from the document root as-is.
	return false;
}

$port = (int) ( $argv[1] ?? 8787 );
$docs = realpath( __DIR__ . '/../docs' );
if ( ! $docs ) {
	fwrite( STDERR, "docs/ directory not found.\n" );
	exit( 1 );
}

// Reference pages are build artifacts; nudge the user if they are missing.
$missing = array();
if ( ! is_file( $docs . '/reference/html.html' ) ) {
	$missing[] = 'php bin/build-reference.php';
}
if ( ! is_file( $docs . '/assets/php-toolkit.zip' ) ) {
	$missing[] = 'bash bin/build-docs-bundle.sh';
}
if ( $missing ) {
	echo "Missing build artifacts. Run from the repo root first:\n";
	foreach ( $missing as $cmd ) {
		echo "  $cmd\n";
	}
	echo "\n";
}

echo "Serving $docs on http://localhost:$port/\n";
$cmd = sprintf(
	'%s -S 0.0.0.0:%d -t %s %s',
	escapeshellarg( PHP_BINARY ),
	$port,
	escapeshellarg( $docs ),
	escapeshellarg( __FILE__ )
);
passthru( $cmd );
