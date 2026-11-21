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
	 * @var int A server sending responses PHP's built-in server can't send.
	 */
	private static $raw_upstream_port;

	/**
	 * @var int The proxy with the warning-proxy-router.php fixture.
	 */
	private static $warning_proxy_port;

	/**
	 * @var string The file that receives the warning proxy's error log.
	 */
	private static $warning_proxy_log;

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

		self::$raw_upstream_port = self::find_free_port();
		self::start_process(
			escapeshellarg( PHP_BINARY ) . ' ' .
				escapeshellarg( $fixtures . '/raw-upstream-server.php' ) . ' ' .
				self::$raw_upstream_port,
			$fixtures,
			getenv(),
			self::$raw_upstream_port
		);

		self::$warning_proxy_log  = tempnam( sys_get_temp_dir(), 'cors-proxy-log' );
		self::$warning_proxy_port = self::start_php_server(
			array( $fixtures . '/warning-proxy-router.php' ),
			array(),
			array(),
			self::$warning_proxy_log
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
		if ( self::$warning_proxy_log && file_exists( self::$warning_proxy_log ) ) {
			unlink( self::$warning_proxy_log );
		}
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

	public function testDoesNotDisplayErrorsForAChunkedRequestBody() {
		$log_size = filesize( self::$warning_proxy_log );
		clearstatcache();

		// A chunked request body comes without a Content-Length. The
		// upstream answers without reading the request body.
		$response = self::request_through_proxy(
			self::$warning_proxy_port,
			'http://127.0.0.1:' . self::$raw_upstream_port . '/ok',
			'POST',
			array( 'Content-Type: text/plain', 'Transfer-Encoding: chunked' ),
			"5\r\nhello\r\n0\r\n\r\n"
		);

		$this->assertSame( 200, $response['status'], $response['body'] );
		$this->assertSame( 'ok', $response['body'] );

		$log = (string) file_get_contents( self::$warning_proxy_log, false, null, $log_size );
		$this->assertStringNotContainsString( 'CONTENT_LENGTH', $log );
	}

	public function testLogsErrorsInsteadOfDisplayingThem() {
		$log_size = filesize( self::$warning_proxy_log );
		clearstatcache();

		$response = self::request_through_proxy(
			self::$warning_proxy_port,
			'http://127.0.0.1:' . self::$raw_upstream_port . '/ok'
		);

		$this->assertSame( 200, $response['status'], $response['body'] );
		$this->assertSame( 'ok', $response['body'] );

		$log = (string) file_get_contents( self::$warning_proxy_log, false, null, $log_size );
		$this->assertStringContainsString( 'cors-proxy-test-warning', $log );
	}

	public function testRelaysOnlyTheFinalResponseAfterAnInterimResponse() {
		$response = self::request_through_proxy(
			self::$loopback_proxy_port,
			'http://127.0.0.1:' . self::$raw_upstream_port . '/continue'
		);

		$this->assertSame( 200, $response['status'], $response['body'] );
		$this->assertSame( 'hello', $response['body'] );
		$this->assertNotContains( 'x-interim-header', $response['header_names'] );
	}

	public function testRejectsSwitchingProtocols() {
		$response = self::request_through_proxy(
			self::$loopback_proxy_port,
			'http://127.0.0.1:' . self::$raw_upstream_port . '/switching-protocols'
		);

		$this->assertSame( 502, $response['status'], $response['body'] );
		$this->assertStringNotContainsString( 'rawdata', $response['body'] );
		$this->assertNotContains( 'x-target-header', $response['header_names'] );
		$this->assertNotContains( 'upgrade', $response['header_names'] );
	}

	/**
	 * @dataProvider providerErrorsAfterTargetHeaders
	 */
	public function testErrorResponsesDoNotCarryTargetHeaders( $path, $status, $message ) {
		$response = self::request_through_proxy(
			self::$loopback_proxy_port,
			'http://127.0.0.1:' . self::$raw_upstream_port . $path
		);

		$this->assertSame( $status, $response['status'], $response['body'] );
		$this->assertStringStartsWith( $message, $response['body'] );
		$this->assertNotContains( 'x-target-header', $response['header_names'] );
		$this->assertNotContains( 'transfer-encoding', $response['header_names'] );
		$this->assertContains( 'cache-control', $response['header_names'] );
	}

	public static function providerErrorsAfterTargetHeaders() {
		return array(
			'response too large'           => array( '/too-large', 413, 'Response Too Large' ),
			'connection closed'            => array( '/no-body', 502, 'Bad Gateway' ),
			'chunked, connection closed'   => array( '/chunked-no-body', 502, 'Bad Gateway' ),
		);
	}

	/**
	 * Sends a request for the target URL through a proxy server.
	 *
	 * @param int    $proxy_port The proxy server's port.
	 * @param string $target_url The URL to request through the proxy.
	 * @param string $method     The request method.
	 * @param array  $headers    Extra request header lines.
	 * @param string $body       The raw request body, sent as given.
	 *
	 * @return array The status code, lowercase response header names, and body.
	 */
	private static function request_through_proxy( $proxy_port, $target_url, $method = 'GET', $headers = array(), $body = '' ) {
		$socket = stream_socket_client( 'tcp://127.0.0.1:' . $proxy_port, $error_code, $error_message, 5 );
		if ( false === $socket ) {
			throw new RuntimeException( "Could not connect to the proxy: {$error_message}" );
		}
		stream_set_timeout( $socket, 20 );
		$request = "{$method} /cors-proxy.php?{$target_url} HTTP/1.1\r\n" .
			"Host: 127.0.0.1:{$proxy_port}\r\n";
		foreach ( $headers as $header ) {
			$request .= $header . "\r\n";
		}
		fwrite( $socket, $request . "Connection: close\r\n\r\n" . $body );
		$raw = stream_get_contents( $socket );
		fclose( $socket );

		$parts = explode( "\r\n\r\n", $raw, 2 );
		if ( ! preg_match( '#^HTTP/\d\.\d (\d{3})#', $parts[0], $matches ) ) {
			throw new RuntimeException( "Unexpected response from the proxy:\n{$raw}" );
		}

		$header_names = array();
		foreach ( array_slice( explode( "\r\n", $parts[0] ), 1 ) as $line ) {
			$header_names[] = strtolower( trim( strstr( $line, ':', true ) ) );
		}

		return array(
			'status'       => (int) $matches[1],
			'header_names' => $header_names,
			'body'         => isset( $parts[1] ) ? $parts[1] : '',
		);
	}

	/**
	 * Starts PHP's built-in server on a free port.
	 *
	 * @param array $arguments    PHP options followed by the router script.
	 * @param array $environment  Variables to set for the server.
	 * @param array $remove       Variables to remove, matched case-insensitively.
	 * @param string|null $log_file A file that receives the server's error output.
	 *
	 * @return int The port.
	 */
	private static function start_php_server( $arguments, $environment, $remove = array(), $log_file = null ) {
		$port     = self::find_free_port();
		$router   = array_pop( $arguments );
		$command  = escapeshellarg( PHP_BINARY );
		foreach ( $arguments as $argument ) {
			$command .= ' ' . escapeshellarg( $argument );
		}
		$command .= ' -S ' . escapeshellarg( '127.0.0.1:' . $port ) . ' ' . escapeshellarg( $router );

		$env = getenv();
		foreach ( array_keys( $env ) as $name ) {
			if ( in_array( strtolower( $name ), $remove, true ) ) {
				unset( $env[ $name ] );
			}
		}
		$env = array_merge( $env, $environment );

		return self::start_process( $command, dirname( $router ), $env, $port, $log_file );
	}

	/**
	 * Starts a server process and waits until it listens on the port.
	 *
	 * @param string      $command  The command to run.
	 * @param string      $cwd      The working directory.
	 * @param array       $env      The environment.
	 * @param int         $port     The port the server listens on.
	 * @param string|null $log_file A file that receives the server's error output.
	 *
	 * @return int The port.
	 */
	private static function start_process( $command, $cwd, $env, $port, $log_file = null ) {
		$is_windows = '\\' === DIRECTORY_SEPARATOR;
		if ( ! $is_windows ) {
			// Replace the shell, so stopping the process stops the server.
			$command = 'exec ' . $command;
		}

		$null_device = $is_windows ? 'NUL' : '/dev/null';
		$process     = proc_open(
			$command,
			array(
				0 => array( 'file', $null_device, 'r' ),
				1 => array( 'file', $null_device, 'w' ),
				2 => null === $log_file ? array( 'file', $null_device, 'w' ) : array( 'file', $log_file, 'a' ),
			),
			$pipes,
			$cwd,
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
