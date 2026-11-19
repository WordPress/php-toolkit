<?php

use PHPUnit\Framework\TestCase;

/**
 * Sends real HTTP requests through cors-proxy.php running in PHP's built-in
 * server, with cURL making the outgoing connections.
 */
class ProxyEndToEndTest extends TestCase {

	/**
	 * @var resource[] Running server processes.
	 */
	private static $processes = array();

	/**
	 * @var int The upstream server the proxy should reach.
	 */
	private static $upstream_port;

	/**
	 * @var int The unmodified proxy.
	 */
	private static $proxy_port;

	/**
	 * @var int The proxy with the loopback-proxy-router.php fixture and
	 *          proxy settings in its environment.
	 */
	private static $loopback_proxy_port;

	/**
	 * @var int|null The proxy with the loopback-proxy-router.php fixture
	 *               and a fixed validation lookup, when supported.
	 */
	private static $fixed_lookup_proxy_port;

	/**
	 * @beforeClass
	 */
	public static function start_servers() {
		if ( ! extension_loaded( 'curl' ) ) {
			self::markTestSkipped( 'The CORS proxy requires the curl extension.' );
		}
		if ( ! function_exists( 'proc_open' ) ) {
			self::markTestSkipped( 'Starting the test servers requires proc_open().' );
		}

		$fixtures = __DIR__ . '/fixtures';

		self::$upstream_port = self::start_php_server(
			array( $fixtures . '/echo-server.php' ),
			array( 'CORS_PROXY_TEST_SERVER_ROLE' => 'upstream' )
		);

		// A forward proxy set in the environment. cURL would send requests
		// here, and resolve the target hostname itself, unless the proxy
		// disables it.
		$environment_proxy_port = self::start_php_server(
			array( $fixtures . '/echo-server.php' ),
			array( 'CORS_PROXY_TEST_SERVER_ROLE' => 'environment proxy' )
		);
		$environment_proxy      = 'http://127.0.0.1:' . $environment_proxy_port;

		self::$proxy_port = self::start_php_server(
			array( dirname( __DIR__ ) . '/cors-proxy.php' ),
			array( 'PLAYGROUND_CORS_PROXY_DISABLE_RATE_LIMIT' => '1' )
		);

		self::$loopback_proxy_port = self::start_php_server(
			array( $fixtures . '/loopback-proxy-router.php' ),
			array(
				'PLAYGROUND_CORS_PROXY_DISABLE_RATE_LIMIT' => '1',
				'http_proxy'                               => $environment_proxy,
				'HTTP_PROXY'                               => $environment_proxy,
				'https_proxy'                              => $environment_proxy,
				'HTTPS_PROXY'                              => $environment_proxy,
				'all_proxy'                                => $environment_proxy,
				'ALL_PROXY'                                => $environment_proxy,
			),
			array( 'no_proxy' )
		);

		if ( null === self::fixed_lookup_unsupported_reason() ) {
			self::$fixed_lookup_proxy_port = self::start_php_server(
				array( '-d', 'disable_functions=gethostbynamel', $fixtures . '/loopback-proxy-router.php' ),
				array( 'PLAYGROUND_CORS_PROXY_DISABLE_RATE_LIMIT' => '1' )
			);
		}
	}

	/**
	 * The fixture replaces gethostbynamel() by starting PHP with it disabled
	 * and declaring its own. Returns why that can't work here, or null.
	 *
	 * @return string|null
	 */
	private static function fixed_lookup_unsupported_reason() {
		if ( PHP_VERSION_ID < 80000 ) {
			return 'Before PHP 8.0, a disabled function cannot be declared again.';
		}
		if ( '\\' === DIRECTORY_SEPARATOR && PHP_VERSION_ID >= 80400 ) {
			// Seen on GitHub's Windows runners with PHP 8.4 and 8.5.
			return 'On Windows with PHP 8.4+, disable_functions breaks other built-in functions: filter_var() runs as filter_input_array().';
		}
		return null;
	}

	/**
	 * @afterClass
	 */
	public static function stop_servers() {
		foreach ( self::$processes as $process ) {
			proc_terminate( $process );
			proc_close( $process );
		}
		self::$processes = array();
	}

	/**
	 * @dataProvider providerBlockedDestinations
	 */
	public function testRejectsPrivateAndReservedDestinations( $host ) {
		$response = self::request_through_proxy(
			self::$proxy_port,
			'http://' . $host . ':' . self::$upstream_port . '/'
		);

		$this->assertSame( 400, $response['status'], $response['body'] );
		$this->assertStringNotContainsString( '"served_by"', $response['body'] );
	}

	public static function providerBlockedDestinations() {
		return array(
			'localhost'      => array( 'localhost' ),
			'loopback'       => array( '127.0.0.1' ),
			'private 10/8'   => array( '10.0.0.1' ),
			'private 172/12' => array( '172.16.0.1' ),
			'private 192/16' => array( '192.168.1.1' ),
			'link-local'     => array( '169.254.169.254' ),
			'shared'         => array( '100.64.0.1' ),
			'documentation'  => array( '192.0.2.1' ),
			'multicast'      => array( '224.0.0.1' ),
			'reserved'       => array( '240.0.0.1' ),
		);
	}

	public function testConnectsToTheValidatedAddressOnACustomPort() {
		$unsupported_reason = self::fixed_lookup_unsupported_reason();
		if ( null !== $unsupported_reason ) {
			$this->markTestSkipped( $unsupported_reason );
		}

		// Only the proxy's validation lookup knows proxy-target.test.
		$response = self::request_through_proxy(
			self::$fixed_lookup_proxy_port,
			'http://proxy-target.test:' . self::$upstream_port . '/custom-port'
		);

		$this->assertSame( 200, $response['status'], $response['body'] );
		$received = json_decode( $response['body'], true );
		$this->assertIsArray( $received, "The upstream response was not relayed unchanged:\n" . $response['body'] );
		$this->assertSame( 'upstream', $received['served_by'] );
		$this->assertSame( '/custom-port', $received['request_uri'] );
		$this->assertSame( 'proxy-target.test:' . self::$upstream_port, $received['host'] );
	}

	public function testIgnoresProxySettingsFromTheEnvironment() {
		$response = self::request_through_proxy(
			self::$loopback_proxy_port,
			'http://localhost:' . self::$upstream_port . '/direct'
		);

		$this->assertSame( 200, $response['status'], $response['body'] );
		$received = json_decode( $response['body'], true );
		$this->assertIsArray( $received, "The upstream response was not relayed unchanged:\n" . $response['body'] );
		$this->assertSame( 'upstream', $received['served_by'] );
		$this->assertSame( '/direct', $received['request_uri'] );
		$this->assertSame( 'localhost:' . self::$upstream_port, $received['host'] );
	}

	/**
	 * Sends a GET request for the target URL through a proxy server.
	 *
	 * @return array The status code and body.
	 */
	private static function request_through_proxy( $proxy_port, $target_url ) {
		$socket = stream_socket_client( 'tcp://127.0.0.1:' . $proxy_port, $error_code, $error_message, 5 );
		if ( false === $socket ) {
			throw new RuntimeException( "Could not connect to the proxy: {$error_message}" );
		}
		stream_set_timeout( $socket, 20 );
		fwrite(
			$socket,
			"GET /cors-proxy.php?{$target_url} HTTP/1.1\r\n" .
			"Host: 127.0.0.1:{$proxy_port}\r\n" .
			"Connection: close\r\n\r\n"
		);
		$raw = stream_get_contents( $socket );
		fclose( $socket );

		$parts = explode( "\r\n\r\n", $raw, 2 );
		if ( ! preg_match( '#^HTTP/\d\.\d (\d{3})#', $parts[0], $matches ) ) {
			throw new RuntimeException( "Unexpected response from the proxy:\n{$raw}" );
		}

		return array(
			'status' => (int) $matches[1],
			'body'   => isset( $parts[1] ) ? $parts[1] : '',
		);
	}

	/**
	 * Starts PHP's built-in server on a free port.
	 *
	 * @param array $arguments    PHP options followed by the router script.
	 * @param array $environment  Variables to set for the server.
	 * @param array $remove       Variables to remove, matched case-insensitively.
	 *
	 * @return int The port.
	 */
	private static function start_php_server( $arguments, $environment, $remove = array() ) {
		$port     = self::find_free_port();
		$router   = array_pop( $arguments );
		$command  = escapeshellarg( PHP_BINARY );
		foreach ( $arguments as $argument ) {
			$command .= ' ' . escapeshellarg( $argument );
		}
		$command .= ' -S ' . escapeshellarg( '127.0.0.1:' . $port ) . ' ' . escapeshellarg( $router );

		$is_windows = '\\' === DIRECTORY_SEPARATOR;
		if ( ! $is_windows ) {
			// Replace the shell, so stopping the process stops the server.
			$command = 'exec ' . $command;
		}

		$env = getenv();
		foreach ( array_keys( $env ) as $name ) {
			if ( in_array( strtolower( $name ), $remove, true ) ) {
				unset( $env[ $name ] );
			}
		}
		$env = array_merge( $env, $environment );

		$null_device = $is_windows ? 'NUL' : '/dev/null';
		$process     = proc_open(
			$command,
			array(
				0 => array( 'file', $null_device, 'r' ),
				1 => array( 'file', $null_device, 'w' ),
				2 => array( 'file', $null_device, 'w' ),
			),
			$pipes,
			dirname( $router ),
			$env,
			array( 'bypass_shell' => true )
		);
		if ( ! is_resource( $process ) ) {
			throw new RuntimeException( "Could not start: {$command}" );
		}
		self::$processes[] = $process;

		$deadline = microtime( true ) + 10;
		while ( microtime( true ) < $deadline ) {
			$socket = @stream_socket_client( 'tcp://127.0.0.1:' . $port, $error_code, $error_message, 1 );
			if ( false !== $socket ) {
				fclose( $socket );
				return $port;
			}
			usleep( 50000 );
		}
		throw new RuntimeException( "The server did not start listening: {$command}" );
	}

	private static function find_free_port() {
		$socket = stream_socket_server( 'tcp://127.0.0.1:0', $error_code, $error_message );
		if ( false === $socket ) {
			throw new RuntimeException( "Could not find a free port: {$error_message}" );
		}
		$name = stream_socket_get_name( $socket, false );
		fclose( $socket );

		return (int) substr( $name, strrpos( $name, ':' ) + 1 );
	}
}
