<?php
/**
 * Router for an upstream server that sends response headers the proxy must
 * not relay, alongside an ordinary X-Custom header.
 *
 * The names use different letter cases on purpose.
 */

$body = 'body';

header( 'Content-Type: text/plain' );
header( 'Content-Length: ' . strlen( $body ) );
header( 'X-Custom: relayed' );
header( 'X-Accel-Redirect: /internal/file' );
header( 'x-accel-buffering: no' );
header( 'X-Sendfile: /tmp/file' );
header( 'X-SENDFILE2: /tmp/file 0-' );
header( 'X-Sendfile-Temporary: /tmp/file' );
header( 'X-Lighttpd-Send-File: /tmp/file' );
header( 'X-LiteSpeed-Location: /internal/file' );
header( 'Status: 299 Server Control' );
header( 'CDN-Cache-Control: max-age=3600' );
header( 'Cloudflare-CDN-Cache-Control: max-age=3600' );
header( 'surrogate-control: max-age=3600' );
header( 'Edge-Control: cache-maxage=3600' );
header( 'Strict-Transport-Security: max-age=31536000; includeSubDomains' );
header( 'Upgrade-Insecure-Requests: 1' );
echo $body;
