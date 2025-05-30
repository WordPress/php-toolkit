<?php

use PHPUnit\Framework\TestCase;

class ProxyFunctionsTests extends TestCase {


	/**
	 *
	 * @dataProvider providerIps
	 */
	public function testIsPrivateIp( $ip, $is_private ) {
		$this->assertEquals( $is_private, is_private_ip( $ip ), "IP $ip was not detected as " . ( $is_private ? 'private' : 'public' ) );
	}

	public static function providerIps() {
		return array(
			array( '127.0.0.1', true ),      // Loopback address
			array( '192.168.1.1', true ),    // Private network
			array( '10.0.0.1', true ),       // Private network
			array( '172.16.0.1', true ),     // Private network
			array( '172.31.255.255', true ), // Private network end
			array( '8.8.8.8', false ),       // Public IP address (Google DNS)
			array( '54.239.28.85', false ),  // Public IP address
			array( '192.88.99.1', true ),
			array( '::1', true ),           // Loopback IPv6
			array( 'fd00::', true ),        // Unique Local Address IPv6
			array( 'fe80::', true ),        // Link-local IPv6 address
			array( '2001:db8::', true ),
			array( '64:ff9b::0:0', true ),
			array( '2001:4860:4860::8888', false ), // Google Public IPv6 DNS
			array( '204.79.197.200', false ), // Public IP address (Microsoft)
		);
	}

	/**
	 *
	 * @dataProvider providerRewriteRelativeRedirect
	 */
	public function testRewriteRelativeRedirect( $request_url, $redirect_location, $proxy_absolute_url, $expected ) {
		$this->assertEquals( $expected, rewrite_relative_redirect( $request_url, $redirect_location, $proxy_absolute_url ) );
	}

	public static function providerRewriteRelativeRedirect() {
		return array(
			'Relative redirect to a trailing slash path'                       => array(
				'https://w.org/hosting',
				'/hosting/',
				'https://cors.playground.wordpress.net/proxy.php',
				'https://cors.playground.wordpress.net/proxy.php?https://w.org/hosting/',
			),
			'Relative redirect when the proxy URL has a trailing slash itself' => array(
				'https://w.org/hosting',
				'/hosting/',
				'https://cors.playground.wordpress.net/proxy.php/',
				'https://cors.playground.wordpress.net/proxy.php/https://w.org/hosting/',
			),
			'Relative redirect with query params involved'                     => array(
				'https://w.org/hosting',
				'/hosting/?utm_source=wporg',
				'https://cors.playground.wordpress.net/proxy.php',
				'https://cors.playground.wordpress.net/proxy.php?https://w.org/hosting/?utm_source=wporg',
			),
			'Absolute redirect with query params involved'                     => array(
				'https://w.org/hosting',
				'https://w.net/hosting/?utm_source=wporg',
				'https://cors.playground.wordpress.net/proxy.php',
				'https://cors.playground.wordpress.net/proxy.php?https://w.net/hosting/?utm_source=wporg',
			),
		);
	}

	/**
	 *
	 * @dataProvider providerGetTargetUrl
	 */
	public function testGetTargetUrl( $server_data, $expected_target_url ) {
		$this->assertEquals( $expected_target_url, get_target_url( $server_data ) );
	}

	public static function providerGetTargetUrl() {
		return array(
			'Request with server-provided PATH_INFO'                        => array(
				array(
					'PATH_INFO' => '/http://example.com',
				),
				'http://example.com',
			),
			'Request with server-provided single-slash PATH_INFO'           => array(
				array(
					'PATH_INFO' => '/',
				),
				false,
			),
			'Request with server-provided empty PATH_INFO'                  => array(
				array(
					'PATH_INFO' => '',
				),
				false,
			),
			'Request with server-provided PATH_INFO and QUERY_STRING'       => array(
				array(
					'PATH_INFO'    => '/http://example.com/from-path-info',
					'QUERY_STRING' => 'http://example.com/from-query-string',
				),
				'http://example.com/from-path-info',
			),
			'Request with server-provided slash PATH_INFO and QUERY_STRING' => array(
				array(
					'PATH_INFO'    => '/',
					'QUERY_STRING' => 'http://example.com/from-query-string',
				),
				'http://example.com/from-query-string',
			),
			'Request with just query params'                                => array(
				array(
					'QUERY_STRING' => 'http://example.com/from-query-string',
				),
				'http://example.com/from-query-string',
			),
			'Request with neither PATH_INFO nor QUERY_STRING'               => array(
				array(),
				false,
			),
		);
	}

	public function testGetCurrentScriptUri() {
		$this->assertEquals( 'http://localhost/cors-proxy/',
			get_current_script_uri( 'http://example.com', 'http://localhost/cors-proxy/http://example.com' ) );
	}

	public function testUrlValidateAndResolve() {
		$this->expectException( CorsProxyException::class );
		url_validate_and_resolve( 'ftp://example.com' );
	}

	public function testUrlValidateAndResolveWithTargetSelf() {
		$this->expectException( CorsProxyException::class );
		$_SERVER['HTTP_HOST'] = 'cors.playground.wordpress.net';
		url_validate_and_resolve(
			'http://cors.playground.wordpress.net/cors-proxy.php?http://cors.playground.wordpress.net'
		);
	}

	public function testFilterHeadersStrings() {
		$original_headers = array(
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json',
			'Cookie'       => 'test=1',
			'Host'         => 'example.com',
		);

		$strictly_disallowed_headers = array(
			'Cookie',
			'Host',
		);

		$headers_requiring_opt_in = array(
			'Authorization',
		);

		$this->assertEquals(
			array(
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
			),
			filter_headers_by_name(
				$original_headers,
				$strictly_disallowed_headers,
				$headers_requiring_opt_in
			)
		);
	}

	public function testFilterHeaderStringsWithAdditionalAllowedHeaders() {
		$original_headers = array(
			'Accept'                               => 'application/json',
			'Content-Type'                         => 'application/json',
			'Cookie'                               => 'test=1',
			'Host'                                 => 'example.com',
			'Authorization'                        => 'Bearer 1234567890',
			'X-Authorization'                      => 'Bearer 1234567890',
			'X-Cors-Proxy-Allowed-Request-Headers' => 'Authorization',
		);

		$strictly_disallowed_headers = array(
			'Cookie',
			'Host',
		);

		$headers_requiring_opt_in = array(
			'Authorization',
		);

		$this->assertEquals(
			array(
				'Accept'                               => 'application/json',
				'Content-Type'                         => 'application/json',
				'Authorization'                        => 'Bearer 1234567890',
				'X-Authorization'                      => 'Bearer 1234567890',
				'X-Cors-Proxy-Allowed-Request-Headers' => 'Authorization',
			),
			filter_headers_by_name(
				$original_headers,
				$strictly_disallowed_headers,
				$headers_requiring_opt_in
			)
		);
	}
}
