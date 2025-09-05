<?php

namespace WordPress\Blueprints;

use Psr\Log\LoggerInterface;
use WordPress\Blueprints\Process;
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

class EvalResult {
	/**
	 * @var string
	 */
	public $output_file_content;
	/**
	 * @var Process
	 */
	public $process;

	public function __construct( string $output_file_content, Process $process ) {
		$this->output_file_content = $output_file_content;
		$this->process           = $process;
	}
}

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
		?string $execution_context_root=null
	) {
		$this->target_fs       = $target_fs;
		$this->configuration  = $configuration;
		$this->assets         = $assets;
		$this->client         = $client;
		$this->blueprint      = $blueprint;
		$this->temp_root       = $temp_root;
		$this->wp_cli_reference = $wp_cli_reference;
		$this->execution_context_root = $execution_context_root;
	}

	public function getExecutionContextRoot(): ?string {
		return $this->execution_context_root;
	}

	public function getHttpClient(): Client {
		return $this->client;
	}

	public function getBlueprint(): array {
		return $this->blueprint;
	}

	public function getConfiguration(): RunnerConfiguration {
		return $this->configuration;
	}

	public function getTargetFilesystem(): Filesystem {
		return $this->target_fs;
	}

	public function getTempRoot(): string {
		return $this->temp_root;
	}

	public function getDataReferenceResolver(): DataReferenceResolver {
		return $this->assets;
	}

	/**
	 * @return File|Directory
	 */
	public function resolve( DataReference $r ) {
		return $this->assets->resolve( $r );
	}

	public function saveToTemporaryFile( File $file ) {
		$temp_file     = $this->createTemporaryFile();
		$write_stream = FileWriteStream::from_path( $temp_file );
		pipe_stream( $file->getStream(), $write_stream );
		$write_stream->close_writing();

		return $temp_file;
	}

	public function getWpCliPath(): string {
		$wp_cli_path = wp_join_unix_paths( $this->getTempRoot(), 'wp-cli.phar' );
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

	public function getLogger(): LoggerInterface {
		return $this->configuration->getLogger();
	}

	public function withTemporaryDirectory( callable $callback ) {
		$tmp = $this->createTemporaryDirectory();
		try {
			return $callback( $tmp );
		} finally {
			LocalFilesystem::create( $tmp )->rmdir( '/', [ 'recursive' => true ] );
		}
	}

	public function createTemporaryDirectory(): string {
		do {
			$dirname = wp_join_unix_paths( $this->temp_root, uniqid( 'tmp_' ) );
		} while ( file_exists( $dirname ) );

		mkdir( $dirname, 0777, true );

		return $dirname;
	}

	public function withTemporaryFile( callable $callback, ?string $suffix = null ) {
		$temp_file = $this->createTemporaryFile( $suffix );
		try {
			return $callback( $temp_file );
		} finally {
			@unlink( $temp_file );
		}
	}

	public function createTemporaryFile( ?string $suffix = null ): string {
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
	 * * DOCROOT environment variable: The path to the WordPress root directory.
	 * * OUTPUT_FILE environment variable: The path to a file where the output of the code will be appended.
	 *
	 * @TODO: Useful error messages on process failure. Right now we get this mouthful error message:
	 *
	 * FAILED: The command "'php' '/var/folders/sb/cywb...
	 * Fatal error: Uncaught Symfony\Component\Process\Exception\ProcessFailedException: The command "'php' '/var/folders/sb/cywb762129g3f0jzq1_p2q5h0000gp/T/wp-blueprints-runtime-68290ca22b771/tmp_68290cac6bea8'" failed.
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
	 * @param  mixed[]|null  $env
	 * @param  float  $timeout
	 */
	public function evalPhpCodeInSubProcess(
		$code,
		$env = null,
		$input = null,
		$timeout = 60
	) {
		$process = $this->createPhpSubProcess( $code, $env, $input, $timeout );
		$process->mustRun();

		$output = $process->getOutputStream(Process::OUTPUT_FILE)->consume_all();
		return new EvalResult(
			$output,
			$process
		);
	}

	public function createPhpSubProcess(
		$code,
		$env = null,
		$input = null,
		$timeout = 60
	) {
		return $this->withTemporaryFile( function ( $script_path ) use ( $code, $env, $input, $timeout ) {
			file_put_contents( $script_path, $code );

			// @TODO: Cleaning up the temporary directory is not done here.
			$temp_dir = $this->createTemporaryDirectory();

			// Still put the script in a temporary file as the path may be refering
			// to a file inside the currently executed .phar archive.
			$actual_script_path = wp_join_unix_paths( $temp_dir, 'script.php' );
			$code = '<?php function append_output( $output ) { file_put_contents( getenv("OUTPUT_FILE"), $output, FILE_APPEND ); } $_SERVER["HTTP_HOST"] = "localhost"; ?>';
			$code .= file_get_contents( $script_path );
			file_put_contents( $actual_script_path, $code );

			$output_path = wp_join_unix_paths( $temp_dir, 'output.txt' );
			touch( $output_path );

			$php_binary = null;
			if ( getenv('PHP_BINARY') ) {
				$php_binary = getenv('PHP_BINARY');
			} elseif ( PHP_BINARY ) {
				$php_binary = PHP_BINARY;
			} else {
				$php_binary = 'php';
			}

			return $this->startShellCommand(
				array(
					$php_binary,
					$actual_script_path,
				),
				$this->configuration->getTargetSiteRoot(),
				array_merge(
					array(
						'DOCROOT'     => $this->configuration->getTargetSiteRoot(),
						'OUTPUT_FILE' => $output_path,
					),
					$env ?? array()
				),
				$input,
				$timeout,
				[
					'output_file_path' => $output_path,
				]
			);
		} );
	}

	/**
	 * @param  mixed[]  $command
	 * @param  string|null  $cwd
	 * @param  mixed[]|null  $env
	 * @param  float  $timeout
	 */
	public function startShellCommand(
		$command,
		$cwd = null,
		$env = null,
		$input = null,
		$timeout = 60,
		$options = []
	) {
		return new Process(
			$command,
			$cwd ?? $this->configuration->getTargetSiteRoot(),
			$env,
			$input,
			$timeout,
			$options
		);
	}
}
