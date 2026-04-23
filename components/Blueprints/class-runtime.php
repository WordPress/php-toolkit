<?php

namespace WordPress\Blueprints;

use VendorPrefix\Psr\Log\LoggerInterface;
use WordPress\Blueprints\DataReference\DataReference;
use WordPress\Blueprints\DataReference\DataReferenceResolver;
use WordPress\Blueprints\DataReference\Directory;
use WordPress\Blueprints\DataReference\File;
use WordPress\Blueprints\Exception\BlueprintExecutionException;
use WordPress\ByteStream\WriteStream\FileWriteStream;
use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\LocalFilesystem;
use WordPress\HttpClient\Client;

use function WordPress\Filesystem\pipe_stream;
use function WordPress\Filesystem\wp_join_unix_paths;

class Runtime {
	/**
	 * @var Filesystem
	 */
	private $target_fs;
	/**
	 * @var RunnerConfiguration
	 */
	private $configuration;
	/**
	 * @var DataReferenceResolver
	 */
	private $assets;
	/**
	 * @var Client
	 */
	private $client;
	/**
	 * @var mixed[]
	 */
	private $blueprint;
	/**
	 * @var string
	 */
	private $temp_root;
	/**
	 * @var DataReference
	 */
	private $wp_cli_reference;
	/**
	 * @var string
	 */
	private $execution_context_root;

	public function __construct(
		Filesystem $target_fs,
		RunnerConfiguration $configuration,
		DataReferenceResolver $assets,
		Client $client,
		array $blueprint,
		string $temp_root,
		DataReference $wp_cli_reference,
		?string $execution_context_root = null
	) {
		$this->target_fs              = $target_fs;
		$this->configuration          = $configuration;
		$this->assets                 = $assets;
		$this->client                 = $client;
		$this->blueprint              = $blueprint;
		$this->temp_root              = $temp_root;
		$this->wp_cli_reference       = $wp_cli_reference;
		$this->execution_context_root = $execution_context_root;
	}

	public function get_execution_context_root(): ?string {
		return $this->execution_context_root;
	}

	public function get_http_client(): Client {
		return $this->client;
	}

	public function get_blueprint(): array {
		return $this->blueprint;
	}

	public function get_configuration(): RunnerConfiguration {
		return $this->configuration;
	}

	public function get_target_filesystem(): Filesystem {
		return $this->target_fs;
	}

	public function get_temp_root(): string {
		return $this->temp_root;
	}

	public function get_data_reference_resolver(): DataReferenceResolver {
		return $this->assets;
	}

	/**
	 * @return File|Directory
	 */
	public function resolve( DataReference $r ) {
		return $this->assets->resolve( $r );
	}

	public function save_to_temporary_file( File $file ) {
		$temp_file    = $this->create_temporary_file();
		$write_stream = FileWriteStream::from_path( $temp_file );
		pipe_stream( $file->getStream(), $write_stream );
		$write_stream->close_writing();

		return $temp_file;
	}

	public function get_wp_cli_path(): string {
		$wp_cli_path = wp_join_unix_paths( $this->get_temp_root(), 'wp-cli.phar' );
		if ( ! file_exists( $wp_cli_path ) ) {
			$resolved = $this->resolve( $this->wp_cli_reference );
			if ( ! $resolved instanceof File ) {
				throw new BlueprintExecutionException( 'Error downloading WP-CLI' );
			}
			$write_stream = FileWriteStream::from_path( $wp_cli_path );
			pipe_stream( $resolved->getStream(), $write_stream );
			$write_stream->close_writing();
			chmod( $wp_cli_path, 0755 );
		}

		return $wp_cli_path;
	}

	public function get_logger(): LoggerInterface {
		return $this->configuration->get_logger();
	}

	public function with_temporary_directory( callable $callback ) {
		$tmp = $this->create_temporary_directory();
		try {
			return $callback( $tmp );
		} finally {
			LocalFilesystem::create( $tmp )->rmdir( '/', array( 'recursive' => true ) );
		}
	}

	public function create_temporary_directory(): string {
		do {
			$dirname = wp_join_unix_paths( $this->temp_root, uniqid( 'tmp_' ) );
		} while ( file_exists( $dirname ) );

		mkdir( $dirname, 0777, true );

		return $dirname;
	}

	public function with_temporary_file( callable $callback, ?string $suffix = null ) {
		$temp_file = $this->create_temporary_file( $suffix );
		try {
			return $callback( $temp_file );
		} finally {
			@unlink( $temp_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	public function create_temporary_file( ?string $suffix = null ): string {
		do {
			$filename = wp_join_unix_paths( $this->temp_root, uniqid( $suffix ?? 'tmp_' ) );
		} while ( file_exists( $filename ) );

		touch( $filename );

		return $filename;
	}

	/**
	 * Runs the given PHP code in a sub-process. The code has access to:
	 *
	 * * append_output( $output ): A function that appends a given string to the output file. Useful for
	 *                             separating the returned structured data from PHP warnings and echos.
	 * * DOCROOT environment variable: The path to the web root directory (document root).
	 * * WP_CORE_DIR environment variable: The path to the WordPress core directory (where wp-load.php lives).
	 *                                      On standard installs this equals DOCROOT. Some hosts place
	 *                                      the core in a subdirectory separate from the web root.
	 * * OUTPUT_FILE environment variable: The path to a file where the output of the code will be appended.
	 *
	 * @TODO: Useful error messages on process failure. Right now we get this mouthful error message:
	 *
	 * FAILED: The command "'php' '/var/folders/sb/cywb...
	 * Fatal error: Uncaught VendorPrefix\Symfony\Component\Process\Exception\ProcessFailedException: The command "'php' '/var/folders/sb/cywb762129g3f0jzq1_p2q5h0000gp/T/wp-blueprints-runtime-68290ca22b771/tmp_68290cac6bea8'" failed.
	 *
	 * Exit Code: 255(Unknown error)
	 *
	 * Working directory: /code/plugins/wordpress-components/untracked/newsite
	 *
	 * Output:
	 * =================
	 *
	 * Fatal error: Uncaught Error: Call to a member function info() on null in /code/plugins/wordpress-components/untracked/newsite/wp-content/plugins/WordPress-Importer-master/class-wxr-importer.php on line 1561
	 *
	 * It could be simpler, e.g.:
	 *
	 * The command "php /var/folders/..." failed with exit code 255.
	 *
	 * Stdout:
	 *
	 * Stderr:
	 *
	 * @param  string       $code    The PHP code to execute.
	 * @param  mixed[]|null $env     Optional environment variables to set.
	 * @param  string|null  $input   Optional input to pass to the process.
	 * @param  float        $timeout Timeout in seconds.
	 */
	public function eval_php_code_in_subprocess(
		$code,
		$env = null,
		$input = null,
		$timeout = 60
	) {
		$process = $this->create_php_sub_process( $code, $env, $input, $timeout );
		$process->mustRun();

		$output = $process->getOutputStream( Process::OUTPUT_FILE )->consume_all();
		return new EvalResult(
			$output,
			$process
		);
	}

	public function create_php_sub_process(
		$code,
		$env = null,
		$input = null,
		$timeout = 60
	) {
		return $this->with_temporary_file(
			function ( $script_path ) use ( $code, $env, $input, $timeout ) {
				file_put_contents( $script_path, $code );

				// @TODO: Cleaning up the temporary directory is not done here.
				$temp_dir = $this->create_temporary_directory();

				// Still put the script in a temporary file as the path may be refering
				// to a file inside the currently executed .phar archive.
				$actual_script_path = wp_join_unix_paths( $temp_dir, 'script.php' );
				$code               = '<?php function append_output( $output ) { file_put_contents( getenv("OUTPUT_FILE"), $output, FILE_APPEND ); } $_SERVER["HTTP_HOST"] = "localhost"; ?>';
				$code              .= file_get_contents( $script_path );
				file_put_contents( $actual_script_path, $code );

				$output_path = wp_join_unix_paths( $temp_dir, 'output.txt' );
				touch( $output_path );

				$php_binary = null;
				if ( getenv( 'PHP_BINARY' ) ) {
						$php_binary = getenv( 'PHP_BINARY' );
				} elseif ( PHP_BINARY ) {
					$php_binary = PHP_BINARY;
				} else {
					$php_binary = 'php';
				}

				// Inherit the parent process's environment so that
				// hosting-injected variables (e.g. DB_HOST, DB_NAME on
				// WP Cloud) remain available to WordPress in the subprocess.
				return $this->start_shell_command(
					array(
						$php_binary,
						$actual_script_path,
					),
					$this->configuration->get_target_site_root(),
					array_merge(
						getenv(),
						array(
							'DOCROOT'     => $this->configuration->get_target_site_root(),
							'WP_CORE_DIR' => $this->configuration->get_wordpress_core_dir(),
							'OUTPUT_FILE' => $output_path,
						),
						$env ?? array()
					),
					$input,
					$timeout,
					array(
						'output_file_path' => $output_path,
					)
				);
			}
		);
	}

	/**
	 * @param  mixed[]      $command
	 * @param  string|null  $cwd
	 * @param  mixed[]|null $env
	 * @param  string|null  $input
	 * @param  float        $timeout
	 * @param  mixed[]      $options
	 */
	public function start_shell_command(
		$command,
		$cwd = null,
		$env = null,
		$input = null,
		$timeout = 60,
		$options = array()
	) {
		return new Process(
			$command,
			$cwd ?? $this->configuration->get_target_site_root(),
			$env,
			$input,
			$timeout,
			$options
		);
	}
}
