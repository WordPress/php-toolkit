<?php

use PHPUnit\Framework\TestCase;

class ProxyFunctionsTest extends TestCase {

	private $original_server;

	/**
	 * @before
	 */
	public function snapshot_server_globals() {
		$this->original_server = $_SERVER;
	}

	/**
	 * @after
	 */
	public function restore_server_globals() {
		$_SERVER = $this->original_server;
	}

	/**
	 *
	 * @dataProvider provider_ips
	 */
	public function test_is_private_ip( $ip, $is_private ) {
		$this->assertEquals( $is_private, is_private_ip( $ip ), "IP $ip was not detected as " . ( $is_private ? 'private' : 'public' ) );
	}

	public static function provider_ips() {
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
	 * @dataProvider provider_rewrite_relative_redirect
	 */
	public function test_rewrite_relative_redirect( $request_url, $redirect_location, $proxy_absolute_url, $expected ) {
		$this->assertEquals( $expected, rewrite_relative_redirect( $request_url, $redirect_location, $proxy_absolute_url ) );
	}

	public static function provider_rewrite_relative_redirect() {
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
	 * @dataProvider provider_get_target_url
	 */
	public function test_get_target_url( $server_data, $expected_target_url ) {
		$this->assertEquals( $expected_target_url, get_target_url( $server_data ) );
	}

	public static function provider_get_target_url() {
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

	public function test_get_current_script_uri() {
		$this->assertEquals( 'http://localhost/cors-proxy/',
			get_current_script_uri( 'http://example.com', 'http://localhost/cors-proxy/http://example.com' ) );
	}

	public function test_url_validate_and_resolve() {
		$this->expectException( CorsProxyException::class );
		url_validate_and_resolve( 'ftp://example.com' );
	}

	public function test_url_validate_and_resolve_with_target_self() {
		$this->expectException( CorsProxyException::class );
		$_SERVER['HTTP_HOST'] = 'cors.playground.wordpress.net';
		url_validate_and_resolve(
			'http://cors.playground.wordpress.net/cors-proxy.php?http://cors.playground.wordpress.net'
		);
	}

	/**
	 * @dataProvider provider_target_ports
	 */
	public function test_resolves_the_effective_target_port( $url, $port ) {
		$lookups  = 0;
		$test     = $this;
		$resolved = url_validate_and_resolve(
			$url,
			function ( $host ) use ( &$lookups, $test ) {
				$test->assertSame( 'example.com', $host );
				++$lookups;
				return array( '8.8.8.8' );
			}
		);
		$this->assertSame( $port, $resolved['port'] );
		$this->assertSame( '8.8.8.8', $resolved['ip'] );
		$this->assertSame( 1, $lookups );
	}

	public static function provider_target_ports() {
		return array(
			'default HTTP port'                     => array( 'http://example.com/', 80 ),
			'default HTTPS port'                    => array( 'https://example.com/', 443 ),
			'custom HTTP port'                      => array( 'http://example.com:8080/', 8080 ),
			'custom HTTPS port'                     => array( 'https://example.com:8443/', 8443 ),
			'explicit port overrides HTTP default'  => array( 'http://example.com:443/', 443 ),
			'explicit port overrides HTTPS default' => array( 'https://example.com:80/', 80 ),
		);
	}

	public function test_rejects_port_zero_before_resolving() {
		// PHP 7.2's FILTER_VALIDATE_URL already rejects port zero as an
		// invalid URL. Later versions accept it, so the port check must.
		$this->expectException( CorsProxyException::class );
		$test = $this;
		url_validate_and_resolve(
			'http://example.com:0/',
			function () use ( $test ) {
				$test->fail( 'Invalid ports must not reach DNS resolution' );
			}
		);
	}

	/**
	 * @dataProvider provider_unsafe_resolution_results
	 */
	public function test_rejects_unsafe_resolution_results( $ips ) {
		$this->expectException( CorsProxyException::class );
		url_validate_and_resolve(
			'http://example.com:8080/',
			function () use ( $ips ) {
				return $ips;
			}
		);
	}

	public static function provider_unsafe_resolution_results() {
		return array(
			'lookup failure'  => array( false ),
			'empty answer'    => array( array() ),
			'invalid address' => array( array( 'not-an-ip' ) ),
			'IPv6 address'    => array( array( '2001:4860:4860::8888' ) ),
		);
	}

	/**
	 * @dataProvider provider_blocked_ipv4_destinations
	 */
	public function test_rejects_private_and_reserved_destinations( $ips ) {
		$this->expectException( CorsProxyException::class );
		$this->expectExceptionMessage( 'Private IPs are forbidden' );
		url_validate_and_resolve(
			'http://example.com:8080/',
			function () use ( $ips ) {
				return $ips;
			}
		);
	}

	public static function provider_blocked_ipv4_destinations() {
		$blocked_ranges = array(
			'private 10/8'             => array( '10.0.0.0', '10.255.255.255' ),
			'private 172.16/12'        => array( '172.16.0.0', '172.31.255.255' ),
			'private 192.168/16'       => array( '192.168.0.0', '192.168.255.255' ),
			'loopback 127/8'           => array( '127.0.0.0', '127.0.0.1', '127.255.255.255' ),
			'shared address space'     => array( '100.64.0.0', '100.127.255.255' ),
			'current network'          => array( '0.0.0.0', '0.255.255.255' ),
			'protocol assignments'     => array( '192.0.0.0', '192.0.0.255' ),
			'link-local and metadata'  => array( '169.254.0.0', '169.254.169.254', '169.254.255.255' ),
			'benchmarking'             => array( '198.18.0.0', '198.19.255.255' ),
			'documentation TEST-NET-1' => array( '192.0.2.0', '192.0.2.255' ),
			'documentation TEST-NET-2' => array( '198.51.100.0', '198.51.100.255' ),
			'documentation TEST-NET-3' => array( '203.0.113.0', '203.0.113.255' ),
			'deprecated relay'         => array( '192.88.99.0', '192.88.99.255' ),
			'multicast'                => array( '224.0.0.0', '239.255.255.255' ),
			'reserved and broadcast'   => array( '240.0.0.0', '255.255.255.255' ),
		);
		$cases          = array();
		// Reject each blocked address alone and after a public answer. Using
		// the first DNS answer must not hide a later private or reserved one.
		foreach ( $blocked_ranges as $name => $ips ) {
			foreach ( $ips as $ip ) {
				$cases[ "$name: $ip alone" ]                       = array( array( $ip ) );
				$cases[ "$name: public answer followed by $ip" ] = array( array( '8.8.8.8', $ip ) );
			}
		}
		return $cases;
	}

	/**
	 * @dataProvider provider_target_ports
	 */
	public function test_pins_the_effective_target_port( $url, $port ) {
		$resolved = url_validate_and_resolve(
			$url,
			function () {
				return array( '8.8.8.8' );
			}
		);
		$options  = get_curl_destination_options( $resolved );
		$this->assertSame( array( "example.com:$port:8.8.8.8" ), $options[ CURLOPT_RESOLVE ] );
		$this->assertSame( CURL_IPRESOLVE_V4, $options[ CURLOPT_IPRESOLVE ] );
		$this->assertSame( '', $options[ CURLOPT_PROXY ] );
	}

	public function test_filter_headers_strings() {
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

	public function test_filter_header_strings_with_additional_allowed_headers() {
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
