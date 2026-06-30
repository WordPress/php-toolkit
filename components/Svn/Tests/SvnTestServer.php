<?php
declare(strict_types=1);

namespace WordPress\Svn\Tests;

/**
 * Spins up real Subversion servers for the integration tests:
 *
 *  - start_svnserve() runs the local `svnserve` binary against
 *    repositories created with `svnadmin`. Requires the subversion
 *    tools on the PATH.
 *  - start_dav() runs Apache + mod_dav_svn in a Docker container,
 *    built from Tests/http-server/. Requires a running Docker daemon.
 *
 * Both servers expose the same fixture layout:
 *
 *    repo  (anonymous read/write)        auth-repo / repo2 (alice:secret123)
 *      trunk/hello.txt                     ... same contents ...
 *      trunk/multi.txt   (svn:eol-style CRLF as of r2)
 *      trunk/sub/deep/nested.txt
 *      branches/
 */
class SvnTestServer {
	const DOCKER_IMAGE = 'php-toolkit-svn-test-server';
	const USERNAME     = 'alice';
	const PASSWORD     = 'secret123';

	/**
	 * @var string 'svnserve' or 'dav'
	 */
	private $kind;

	/**
	 * @var int
	 */
	private $port;

	/**
	 * @var string|null Temp directory holding svnserve repositories.
	 */
	private $directory;

	/**
	 * @var resource|null svnserve process handle.
	 */
	private $process;

	/**
	 * @var string|null Docker container id.
	 */
	private $container_id;

	public static function svn_binaries_available() {
		static $available = null;
		if ( null === $available ) {
			exec( 'svnadmin --version 2>/dev/null', $output, $exit_code );
			$available = 0 === $exit_code;
			if ( $available ) {
				exec( 'svnserve --version 2>/dev/null', $output, $exit_code );
				$available = 0 === $exit_code;
			}
			if ( $available ) {
				exec( 'svn --version 2>/dev/null', $output, $exit_code );
				$available = 0 === $exit_code;
			}
		}

		return $available;
	}

	public static function docker_available() {
		static $available = null;
		if ( null === $available ) {
			exec( 'docker ps 2>/dev/null', $output, $exit_code );
			$available = 0 === $exit_code;
		}

		return $available;
	}

	/**
	 * Starts svnserve with two fixture repositories.
	 *
	 * @return SvnTestServer
	 */
	public static function start_svnserve() {
		$server            = new SvnTestServer();
		$server->kind      = 'svnserve';
		$server->directory = sys_get_temp_dir() . '/php-toolkit-svn-test-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
		mkdir( $server->directory, 0777, true );

		self::create_fixture_repository( $server->directory . '/repo', false );
		self::create_fixture_repository( $server->directory . '/auth-repo', true );

		$server->port = self::find_free_port();
		$command      = sprintf(
			'svnserve -d -T --foreground -r %s --listen-host 127.0.0.1 --listen-port %d',
			escapeshellarg( $server->directory ),
			$server->port
		);
		$server->process = proc_open( $command, array(), $pipes );
		if ( ! is_resource( $server->process ) ) {
			throw new \RuntimeException( 'Could not start svnserve.' );
		}
		self::wait_for_port( $server->port );

		return $server;
	}

	/**
	 * Starts the Apache + mod_dav_svn Docker container.
	 *
	 * @return SvnTestServer
	 */
	public static function start_dav() {
		$server       = new SvnTestServer();
		$server->kind = 'dav';

		exec( 'docker image inspect ' . self::DOCKER_IMAGE . ' >/dev/null 2>&1', $output, $exit_code );
		if ( 0 !== $exit_code ) {
			exec(
				'docker build -t ' . self::DOCKER_IMAGE . ' ' . escapeshellarg( __DIR__ . '/http-server' ) . ' 2>&1',
				$build_output,
				$build_exit_code
			);
			if ( 0 !== $build_exit_code ) {
				throw new \RuntimeException( "Could not build the mod_dav_svn test image:\n" . implode( "\n", $build_output ) );
			}
		}

		$server->port = self::find_free_port();
		exec(
			sprintf( 'docker run -d --rm -p 127.0.0.1:%d:80 %s 2>&1', $server->port, self::DOCKER_IMAGE ),
			$run_output,
			$run_exit_code
		);
		if ( 0 !== $run_exit_code ) {
			throw new \RuntimeException( "Could not start the mod_dav_svn test container:\n" . implode( "\n", $run_output ) );
		}
		$server->container_id = trim( implode( "\n", $run_output ) );

		// Apache needs a moment to create the fixture repositories.
		$deadline = microtime( true ) + 60;
		while ( microtime( true ) < $deadline ) {
			$context  = stream_context_create( array( 'http' => array( 'timeout' => 2, 'ignore_errors' => true ) ) );
			$contents = @file_get_contents( "http://127.0.0.1:{$server->port}/repos/repo1/trunk/hello.txt", false, $context );
			if ( "hello world\n" === $contents ) {
				return $server;
			}
			usleep( 250000 );
		}
		$server->stop();
		throw new \RuntimeException( 'The mod_dav_svn test container did not become ready in time.' );
	}

	/**
	 * @param  string $repository  Repository name: 'repo' or 'auth-repo'.
	 * @return string The URL of the repository for this server.
	 */
	public function url( $repository = 'repo' ) {
		if ( 'svnserve' === $this->kind ) {
			return "svn://127.0.0.1:{$this->port}/{$repository}";
		}
		// The DAV container mounts the anonymous repository at
		// /repos/repo1 and the authenticated one at /auth-repos/repo2.
		return 'auth-repo' === $repository
			? "http://127.0.0.1:{$this->port}/auth-repos/repo2"
			: "http://127.0.0.1:{$this->port}/repos/repo1";
	}

	/**
	 * Creates a fresh, isolated fixture repository and returns its URL.
	 * Lets every test mutate its own repository without affecting the
	 * other tests.
	 *
	 * @param  bool $requires_auth  Whether the repository requires alice:secret123.
	 * @return string The URL of the new repository.
	 */
	public function create_repository( $requires_auth = false ) {
		$name = 'test-repo-' . bin2hex( random_bytes( 6 ) );
		if ( 'svnserve' === $this->kind ) {
			// Clone the already-built fixture repository instead of
			// rebuilding it with svn commands – this is much faster.
			$template = $this->directory . '/' . ( $requires_auth ? 'auth-repo' : 'repo' );
			self::run( 'cp -R ' . escapeshellarg( $template ) . ' ' . escapeshellarg( $this->directory . '/' . $name ) );

			return "svn://127.0.0.1:{$this->port}/{$name}";
		}

		$mode = $requires_auth ? 'auth' : 'anon';
		self::run(
			'docker exec ' . escapeshellarg( $this->container_id ) . " /create-repo.sh {$name} {$mode}"
		);

		return $requires_auth
			? "http://127.0.0.1:{$this->port}/auth-repos/{$name}"
			: "http://127.0.0.1:{$this->port}/repos/{$name}";
	}

	public function stop() {
		if ( null !== $this->process && is_resource( $this->process ) ) {
			proc_terminate( $this->process );
			proc_close( $this->process );
			$this->process = null;
		}
		if ( null !== $this->container_id ) {
			exec( 'docker stop -t 1 ' . escapeshellarg( $this->container_id ) . ' >/dev/null 2>&1' );
			$this->container_id = null;
		}
		if ( null !== $this->directory ) {
			self::remove_directory( $this->directory );
			$this->directory = null;
		}
	}

	/**
	 * Creates a repository with the standard fixture layout.
	 *
	 * @param string $path           Where to create the repository.
	 * @param bool   $requires_auth  Whether to require alice:secret123 for all access.
	 */
	private static function create_fixture_repository( $path, $requires_auth ) {
		self::run( 'svnadmin create ' . escapeshellarg( $path ) );

		if ( $requires_auth ) {
			file_put_contents(
				$path . '/conf/svnserve.conf',
				"[general]\nanon-access = none\nauth-access = write\npassword-db = passwd\nrealm = SvnTestRealm\n"
			);
			file_put_contents( $path . '/conf/passwd', "[users]\n" . self::USERNAME . ' = ' . self::PASSWORD . "\n" );
		} else {
			file_put_contents(
				$path . '/conf/svnserve.conf',
				"[general]\nanon-access = write\nauth-access = write\n"
			);
		}

		$work = $path . '-work';
		self::run( 'svn checkout -q ' . escapeshellarg( 'file://' . $path ) . ' ' . escapeshellarg( $work ) );
		mkdir( "$work/trunk/sub/deep", 0777, true );
		mkdir( "$work/branches" );
		file_put_contents( "$work/trunk/hello.txt", "hello world\n" );
		file_put_contents( "$work/trunk/multi.txt", "line1\nline2\nline3\n" );
		file_put_contents( "$work/trunk/sub/deep/nested.txt", "nested file\n" );
		self::run( 'svn add -q trunk branches', $work );
		self::run( 'svn commit -q -m "initial content" --username fixture', $work );
		self::run( 'svn propset -q svn:eol-style CRLF trunk/multi.txt', $work );
		self::run( 'svn commit -q -m "set eol-style" --username fixture', $work );
		self::remove_directory( $work );
	}

	/**
	 * Runs a shell command, throwing on failure.
	 *
	 * @param string      $command            The command to run.
	 * @param string|null $working_directory  Directory to run it in.
	 */
	public static function run( $command, $working_directory = null ) {
		$prefix = null !== $working_directory ? 'cd ' . escapeshellarg( $working_directory ) . ' && ' : '';
		exec( $prefix . $command . ' 2>&1', $output, $exit_code );
		if ( 0 !== $exit_code ) {
			throw new \RuntimeException( "Command failed ({$command}):\n" . implode( "\n", $output ) );
		}
	}

	private static function find_free_port() {
		$socket = stream_socket_server( 'tcp://127.0.0.1:0', $error_code, $error_message );
		if ( false === $socket ) {
			throw new \RuntimeException( "Could not find a free port: {$error_message}" );
		}
		$name = stream_socket_get_name( $socket, false );
		fclose( $socket );

		return (int) substr( $name, strrpos( $name, ':' ) + 1 );
	}

	private static function wait_for_port( $port ) {
		$deadline = microtime( true ) + 15;
		while ( microtime( true ) < $deadline ) {
			$socket = @stream_socket_client( "tcp://127.0.0.1:{$port}", $error_code, $error_message, 1 );
			if ( false !== $socket ) {
				fclose( $socket );

				return;
			}
			usleep( 100000 );
		}
		throw new \RuntimeException( "svnserve did not start listening on port {$port}." );
	}

	private static function remove_directory( $path ) {
		if ( ! is_dir( $path ) ) {
			return;
		}
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				rmdir( $item->getPathname() );
			} else {
				unlink( $item->getPathname() );
			}
		}
		rmdir( $path );
	}
}
