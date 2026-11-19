<?php
/**
 * Router that runs the real cors-proxy.php against the local test servers.
 *
 * Only the destination checks change:
 *
 * - is_private_ip() also accepts 127.0.0.1, where the test servers listen.
 * - When the test starts PHP 8+ with -d disable_functions=gethostbynamel,
 *   this file replaces the proxy's validation lookup with a fixed answer.
 *   cURL keeps its own resolver, so proxy-target.test, which has no DNS
 *   entry, can only be reached if the proxy pins the validated address.
 */

function is_private_ip( $ip ) {
	return '127.0.0.1' === $ip ? false : IpUtils::isPrivateIp( $ip );
}

if ( ! function_exists( 'gethostbynamel' ) ) {
	function gethostbynamel( $host ) {
		if ( 'proxy-target.test' === $host ) {
			return array( '127.0.0.1' );
		}
		return false;
	}
}

require __DIR__ . '/../../cors-proxy.php';
