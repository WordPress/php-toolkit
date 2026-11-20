<?php

namespace WordPress\Git\Tests;

use PHPUnit\Framework\TestCase;
use WordPress\Filesystem\LocalFilesystem;
use WordPress\Git\GitRepository;

class GitCliEndToEndTest extends TestCase {

	private $temp_dir;
	private $git_home;
	private $remote_repository_path;
	private $working_copy_path;
	private $router_script_path;
	private $server_port;
	private $server_process;

	/**
	 * @before
	 */
	public function set_up_environment() {
		if ( ! function_exists( 'proc_open' ) ) {
			$this->markTestSkipped( 'proc_open() is required for the Git CLI end-to-end test.' );
		}
		if ( ! $this->command_is_available( 'git' ) ) {
			$this->markTestSkipped( 'The git command is required for the Git CLI end-to-end test.' );
		}

		$this->temp_dir               = sys_get_temp_dir() . '/php-toolkit-git-cli-' . uniqid();
		$this->git_home               = $this->temp_dir . '/home';
		$this->remote_repository_path = $this->temp_dir . '/remote-repository';
		$this->working_copy_path      = $this->temp_dir . '/working-copy';
		$this->router_script_path     = dirname( __FILE__ ) . '/fixtures/git-http-endpoint-router.php';

		mkdir( $this->temp_dir, 0777, true );
		mkdir( $this->git_home, 0777, true );

		$this->initialize_remote_repository();
		$this->start_http_server();
	}

	/**
	 * @after
	 */
	public function tear_down_environment() {
		if ( is_resource( $this->server_process ) ) {
			@proc_terminate( $this->server_process );
			@proc_close( $this->server_process );
		}

		$this->delete_directory( $this->temp_dir );
	}

	public function test_real_git_cli_can_clone_push_and_pull() {
		$remote_url = sprintf( 'http://127.0.0.1:%d/repo.git', $this->server_port );
		$this->assert_command_succeeds(
			sprintf(
				'git -c protocol.version=2 clone %s %s',
				escapeshellarg( $remote_url ),
				escapeshellarg( $this->working_copy_path )
			)
		);

		$this->assertSame(
			"Hello from the server\n",
			file_get_contents( $this->working_copy_path . '/README.md' )
		);

		$this->assert_command_succeeds( 'git config user.name "PHP Toolkit"', $this->working_copy_path );
		$this->assert_command_succeeds( 'git config user.email "php-toolkit@example.com"', $this->working_copy_path );

		file_put_contents( $this->working_copy_path . '/README.md', "Updated from clone\n" );
		$this->assert_command_succeeds( 'git add README.md', $this->working_copy_path );
		$this->assert_command_succeeds( 'git commit -m "Update README"', $this->working_copy_path );
		$this->assert_command_succeeds( 'git push origin trunk', $this->working_copy_path );

		$remote_repository = $this->open_remote_repository();
		$this->assertSame(
			"Updated from clone\n",
			$remote_repository->read_object_by_path( '/README.md' )->consume_all()
		);

		$remote_repository->set_branch_tip( 'HEAD', 'ref: refs/heads/trunk' );
		$remote_repository->commit(
			array(
				'updates' => array(
					'README.md' => "Updated on the server\n",
				),
			)
		);

		$this->assert_command_succeeds( 'git pull --ff-only origin trunk', $this->working_copy_path );
		$this->assertSame(
			"Updated on the server\n",
			file_get_contents( $this->working_copy_path . '/README.md' )
		);
	}

	private function initialize_remote_repository() {
		$repository = $this->open_remote_repository();
		$repository->set_config_value( 'user.name', 'PHP Toolkit' );
		$repository->set_config_value( 'user.email', 'php-toolkit@example.com' );
		$repository->set_branch_tip( 'HEAD', 'ref: refs/heads/trunk' );
		$repository->commit(
			array(
				'updates' => array(
					'README.md' => "Hello from the server\n",
				),
			)
		);
	}

	private function open_remote_repository() {
		return new GitRepository(
			LocalFilesystem::create( $this->remote_repository_path ),
			array(
				'default_branch' => 'trunk',
			)
		);
	}

	private function start_http_server() {
		$this->server_port = $this->find_available_port();

		$command = sprintf(
			'%s -S 127.0.0.1:%d %s',
			escapeshellarg( PHP_BINARY ),
			$this->server_port,
			escapeshellarg( $this->router_script_path )
		);

		$descriptor_spec = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'file', $this->temp_dir . '/server.stdout.log', 'a' ),
			2 => array( 'file', $this->temp_dir . '/server.stderr.log', 'a' ),
		);

		$this->server_process = proc_open(
			$command,
			$descriptor_spec,
			$pipes,
			dirname( dirname( dirname( dirname( __FILE__ ) ) ) ),
			array(
				'PHP_TOOLKIT_GIT_E2E_REPOSITORY_PATH' => $this->remote_repository_path,
			)
		);

		if ( ! is_resource( $this->server_process ) ) {
			$this->fail( 'Failed to start the PHP built-in server for the Git CLI end-to-end test.' );
		}

		fclose( $pipes[0] );

		$started = false;
		for ( $attempt = 0; $attempt < 50; $attempt++ ) {
			$socket = @fsockopen( '127.0.0.1', $this->server_port );
			if ( false !== $socket ) {
				fclose( $socket );
				$started = true;
				break;
			}
			usleep( 100000 );
		}

		if ( ! $started ) {
			$this->fail(
				"Failed to start the PHP built-in server.\n" .
				$this->get_server_logs()
			);
		}
	}

	private function find_available_port() {
		$server = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
		if ( false === $server ) {
			$this->fail( sprintf( 'Failed to find an available port: %s', $errstr ) );
		}

		$server_name = stream_socket_get_name( $server, false );
		fclose( $server );

		return intval( substr( strrchr( $server_name, ':' ), 1 ) );
	}

	private function command_is_available( $command ) {
		$result = $this->run_command( sprintf( 'command -v %s', escapeshellarg( $command ) ) );

		return 0 === $result['exit_code'];
	}

	private function assert_command_succeeds( $command, $cwd = null ) {
		$result = $this->run_command( $command, $cwd );

		$this->assertSame(
			0,
			$result['exit_code'],
			sprintf(
				"Command failed: %s\nstdout:\n%s\nstderr:\n%s\nserver logs:\n%s",
				$command,
				$result['stdout'],
				$result['stderr'],
				$this->get_server_logs()
			)
		);

		return $result;
	}

	private function run_command( $command, $cwd = null ) {
		$descriptor_spec = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$process = proc_open(
			$command,
			$descriptor_spec,
			$pipes,
			$cwd,
			array(
				'GIT_CONFIG_NOSYSTEM' => '1',
				'GIT_TERMINAL_PROMPT' => '0',
				'HOME'               => $this->git_home,
			)
		);

		if ( ! is_resource( $process ) ) {
			$this->fail( sprintf( 'Failed to start command: %s', $command ) );
		}

		fclose( $pipes[0] );
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		return array(
			'exit_code' => proc_close( $process ),
			'stdout'    => $stdout,
			'stderr'    => $stderr,
		);
	}

	private function get_server_logs() {
		$stdout_log = $this->temp_dir . '/server.stdout.log';
		$stderr_log = $this->temp_dir . '/server.stderr.log';

		return "stdout:\n" .
			( is_file( $stdout_log ) ? file_get_contents( $stdout_log ) : '' ) .
			"\nstderr:\n" .
			( is_file( $stderr_log ) ? file_get_contents( $stderr_log ) : '' );
	}

	private function delete_directory( $path ) {
		if ( ! $path || ! file_exists( $path ) ) {
			return;
		}

		if ( is_file( $path ) || is_link( $path ) ) {
			@unlink( $path );
			return;
		}

		$entries = scandir( $path );
		if ( false === $entries ) {
			return;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$this->delete_directory( $path . '/' . $entry );
		}

		@rmdir( $path );
	}
}
