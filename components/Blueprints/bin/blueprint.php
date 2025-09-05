<?php
/**
 * blueprint.php – the main entry point to the WordPress Blueprint Runner CLI.
 *
 * @TODO: Add a verbose mode
 * @TODO: A large test suite.
 * @TODO: Client HTTP queue deadlock when we enqueued a lot of requests and need to fetch a small
 *        ad-hoc resource such as a JSON list of translations.
 * @TODO [_spec_]: How to handle the default WordPress theme? Should it be preserved for new sites?
 *        What if we want to remove it? And what should be the semantics for existing sites?
 *        -> how to handle conflicts in general? pre-existing themes conflicting with new themes?
 *           pre-existing plugins conflicting with new plugins? refuse to execute? tell the user what
 *           to do? As in change the Blueprint? What if I don't want to change it? maybe interact with the user
 *           and ask whether they want to bale or override the theme/plugin?
 * @TODO (low priority): Production-grade HTTP Cache support for remote files. Not the stopgap we have now.
 *                       We can ship Blueprints without http cache support, but do not ship the stopgap solution
 *                       in production.
 * @TODO (low priority): Range header-based HTTP stream for fast partial parsing of large remote zip files.
 *                       Needs to support servers lying about their Range support.
 * @TODO (low priority): Restrictions on supported step types, media files types, SQL queries types, etc.
 * @TODO (low priority): Fast unzipping of remote Zip Files by iterating over the entries
 *        instead of skipping over to the end central directory index entry.
 * @TODO (low priority) never require going through local paths. Make evalPHP explicitly support target filesystem paths so that
 *        we can be prepared for remote Blueprint execution.
 * ✅ @TODO: Get the tests to pass
 * ✅ @TODO: Support commands: "exec", "validate", "to-execution-plan" etc. See the Blueprints v2 spec for more commands ideas.
 * ✅ @TODO: Get explicit user consent before using paths from a local directory
 * ✅ @TODO: Support "wordPressVersion": "beta"
 * ✅ @TODO (low priority): Exception structure?
 * ✅ @TODO: Support --truncate-new-site-directory option for easy development – just re-run the same command to override a previous site.
 * ✅ @TODO: Prevent remote resources from using local bundle paths
 */

// Disable phpcs for now – there's a few classes declared in this file. Let's split
// them out into separate files eventually.

// phpcs:disable
require __DIR__ . '/../../../vendor/autoload.php';

use WordPress\CLI\CLI;
use WordPress\Blueprints\DataReference\AbsoluteLocalPath;
use WordPress\Blueprints\DataReference\DataReference;
use WordPress\Blueprints\DataReference\ExecutionContextPath;
use WordPress\Blueprints\Exception\BlueprintExecutionException;
use WordPress\Blueprints\Exception\PermissionsException;
use WordPress\Blueprints\Logger\CLILogger;
use WordPress\Blueprints\ProgressObserver;
use WordPress\Blueprints\Runner;
use WordPress\Blueprints\RunnerConfiguration;
use WordPress\Filesystem\LocalFilesystem;

// Enable colours on Windows 10+ (safe‑no‑op elsewhere).
if ( 'Windows' === PHP_OS_FAMILY && function_exists( 'sapi_windows_vt100_support' ) ) {
	@sapi_windows_vt100_support( STDOUT, true );
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
interface ProgressReporter {
	/**
	 * Report progress update
	 *
	 * @param float  $progress Progress percentage (0-100)
	 * @param string $caption Progress caption/message
	 */
	public function reportProgress( float $progress, string $caption ): void;

	/**
	 * Report an error
	 *
	 * @param string          $message Error message
	 * @param \Throwable|null $exception Optional exception details
	 */
	public function reportError( string $message, ?\Throwable $exception = null ): void;

	/**
	 * Report completion
	 *
	 * @param string $message Completion message
	 */
	public function reportCompletion( string $message ): void;

	/**
	 * Close/cleanup the reporter
	 */
	public function close(): void;
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
class TerminalProgressReporter implements ProgressReporter {
	private $stdout;
	private $last_progress      = -1;
	private $last_caption       = '';
	private $progress_bar_width = 50;

	public function __construct() {
		$this->stdout = fopen( 'php://stdout', 'w' );
	}

	public function reportProgress( float $progress, string $caption ): void {
		// Don't repeat identical progress.
		if ( $this->last_progress === $progress && $this->last_caption === $caption ) {
			return;
		}

		$this->last_progress = $progress;
		$this->last_caption  = $caption;

		$percentage = min( 100, max( 0, $progress ) );
		$filled     = (int) round( $this->progress_bar_width * ( $percentage / 100 ) );
		$empty      = $this->progress_bar_width - $filled;

		$bar = str_repeat( '=', $filled );
		if ( $empty > 0 && $filled < $this->progress_bar_width ) {
			$bar .= '>';
			$bar .= str_repeat( ' ', $empty - 1 );
		} else {
			$bar .= str_repeat( ' ', $empty );
		}

		$status = sprintf(
			"\r[%s] %3.1f%% - %s",
			$bar,
			$percentage,
			$caption
		);

		if ( $this->isTty() ) {
			// Clear line and write new progress.
			fwrite( $this->stdout, "\r\033[K" . $status );
		} else {
			// Non-TTY, just write new line.
			fwrite( $this->stdout, $status . "\n" );
		}
		fflush( $this->stdout );
	}

	public function reportError( string $message, ?\Throwable $exception = null ): void {
		$this->clearCurrentLine();

		$error_msg = "\033[1;31mError:\033[0m " . $message;
		if ( $exception ) {
			$error_msg .= ' (' . $exception->getMessage() . ')';
		}

		fwrite( $this->stdout, $error_msg . "\n" );
		fflush( $this->stdout );
	}

	public function reportCompletion( string $message ): void {
		$this->clearCurrentLine();
		fwrite( $this->stdout, "\033[1;32m" . $message . "\033[0m\n" );
		fflush( $this->stdout );
	}

	public function close(): void {
		if ( $this->stdout ) {
			fclose( $this->stdout );
		}
	}

	private function clearCurrentLine(): void {
		if ( $this->isTty() ) {
			fwrite( $this->stdout, "\r\033[K" );
		}
	}

	private function isTty(): bool {
		return stream_isatty( $this->stdout );
	}
}

// phpcs:disable WordPress.NamingConventions.ValidClassName
class JsonProgressReporter implements ProgressReporter {
	private $output_file;

	public function __construct() {
		$output_path       = getenv( 'OUTPUT_FILE' ) ? getenv( 'OUTPUT_FILE' ) : 'php://stdout';
		$this->output_file = fopen( $output_path, 'w' );
	}

	public function reportProgress( float $progress, string $caption ): void {
		$this->writeJsonMessage(
			array(
				'type' => 'progress',
				'progress' => round( $progress, 2 ),
				'caption' => $caption,
			)
		);
	}

	public function reportError( string $message, ?\Throwable $exception = null ): void {
		$error_data = array(
			'type' => 'error',
			'message' => $message,
		);

		if ( $exception ) {
			$error_data['details'] = array(
				'exception' => get_class( $exception ),
				'message' => $exception->getMessage(),
				'file' => $exception->getFile(),
				'line' => $exception->getLine(),
				'trace' => $exception->getTraceAsString(),
			);
		}

		$this->writeJsonMessage( $error_data );
	}

	public function reportCompletion( string $message ): void {
		$this->writeJsonMessage(
			array(
				'type' => 'completion',
				'message' => $message,
			)
		);
	}

	public function close(): void {
		if ( $this->output_file ) {
			fclose( $this->output_file );
		}
	}

	private function writeJsonMessage( array $data ): void {
		fwrite( $this->output_file, json_encode( $data ) . "\n" );
		fflush( $this->output_file );
	}
}

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed
function createProgressReporter(): ProgressReporter {
	$reporter = apply_filters( 'blueprint.progress_reporter', null );
	if ( $reporter ) {
		return $reporter;
	}

	// Use JSON mode if OUTPUT_FILE is set or if we're not in a TTY.
	if ( getenv( 'OUTPUT_FILE' ) || ! stream_isatty( STDOUT ) ) {
		return new JsonProgressReporter();
	}

	return new TerminalProgressReporter();
}


$progress_reporter = createProgressReporter();

// -----------------------------------------------------------------------------.
// Command and option definitions.
// -----------------------------------------------------------------------------.
$supported_permissions = RunnerConfiguration::ALL_PERMISSIONS;

// Define common options that can be used by multiple commands.
$common_options = array(
	'help'    => array( 'h', false, false, 'Show help for this command' ),
	'version' => array( 'V', false, false, 'Show version' ),
);

// Define the available commands and their specific options.
$command_configurations = array(
	'exec' => array(
		'description'     => 'Execute a WordPress Blueprint',
		'positionalArgs'  => array(
			'blueprint' => 'Path / URL / DataReference to the blueprint (required)',
		),
		'options'         => array_merge(
			$common_options,
			array(
				'site-url'                    => array( 'u', true, null, 'Public site URL (https://example.com)' ),
				'site-path'                   => array( null, true, null, 'Target directory with WordPress install context)' ),
				'execution-context'           => array( 'x', true, null, 'Source directory with Blueprint context files' ),
				'mode'                        => array( 'm', true, Runner::EXECUTION_MODE_CREATE_NEW_SITE, sprintf( 'Execution mode (%s|%s)', Runner::EXECUTION_MODE_CREATE_NEW_SITE, Runner::EXECUTION_MODE_APPLY_TO_EXISTING_SITE ) ),
				'db-engine'                   => array( 'd', true, 'mysql', 'Database engine (mysql|sqlite)' ),
				'db-host'                     => array( null, true, '127.0.0.1', 'MySQL host' ),
				'db-user'                     => array( null, true, 'root', 'MySQL user' ),
				'db-pass'                     => array( null, true, '', 'MySQL password' ),
				'db-name'                     => array( null, true, 'wordpress', 'MySQL database' ),
				'db-path'                     => array( 'p', true, 'wp.db', 'SQLite file path' ),
				'truncate-new-site-directory' => array( 't', false, false, 'Delete target directory if it exists before execution' ),
				/**
				 * @TODO: Reuse this error message removed from the Playground repo:
				 *
				 *          if (!blueprintMayReadAdjacentFiles) {
				 *              throw new ReportableError(
				 *                  `Error: Blueprint contained tried to read a local file at path "${path}" (via a resource of type "bundled"). ` +
				 *                      `Playground restricts access to local resources by default as a security measure. \n\n` +
				 *                      `You can allow this Blueprint to read files from the same parent directory by explicitly adding the ` +
				 *                      `--blueprint-may-read-adjacent-files option to your command.`
				 *              );
				 *          }
				 */
				'allow'                       => array( null, true, null, 'Allowed permissions. One of: ' . implode( ', ', $supported_permissions ) ),
			)
		),
		'examples'        => array(
			'php blueprint.php exec my-blueprint.json --site-url https://mysite.test --site-path /var/www/mysite.com',
			sprintf( 'php blueprint.php exec my-blueprint.json --execution-context /var/www --site-url https://mysite.test --mode %s --site-path ./site', Runner::EXECUTION_MODE_APPLY_TO_EXISTING_SITE ),
			'php blueprint.php exec my-blueprint.json --site-url https://mysite.test --site-path ./mysite --truncate-new-site-directory',
		),
		'aliases'         => array( 'run' ),
		'requiredOptions' => array( 'site-path', 'site-url', 'mode' ),
	),
	'help' => array(
		'description'    => 'Show help for WordPress Blueprint Runner CLI',
		'positionalArgs' => array(
			'command' => 'Command name to get help for (optional)',
		),
		'options'        => $common_options,
		'examples'       => array(
			'php blueprint.php help',
			'php blueprint.php help exec',
		),
		'aliases'        => array(),
	),
);

// Get the command name from arguments, accounting for aliases.
function resolveCommand( $command_arg, array $command_configurations ): ?string {
	// Direct command match.
	if ( isset( $command_configurations[ $command_arg ] ) ) {
		return $command_arg;
	}

	// Check for aliases.
	foreach ( $command_configurations as $cmd_name => $config ) {
		if ( isset( $config['aliases'] ) && in_array( $command_arg, $config['aliases'] ) ) {
			return $cmd_name;
		}
	}

	return null;
}

// -----------------------------------------------------------------------------.
// Command handlers.
// -----------------------------------------------------------------------------.
function handleExecCommand( array $positional_args, array $options, array $command_config, ProgressReporter $progress_reporter ): void {
	// Check if help is requested for this command.
	if ( $options['help'] ) {
		showCommandHelpMessage( 'exec', $command_config );
		exit( 0 );
	}

	// Validate required options.
	foreach ( $command_config['requiredOptions'] as $required_option ) {
		if ( empty( $options[ $required_option ] ) ) {
			$progress_reporter->reportError( "The --$required_option option is required for the exec command." );
			exit( 1 );
		}
	}

	// Validate required positional arguments.
	if ( empty( $positional_args ) ) {
		$progress_reporter->reportError( 'A Blueprint reference must be specified as a positional argument.' );
		exit( 1 );
	}

	try {
		// Convert CLI arguments to RunnerConfiguration.
		$config = cliArgsToRunnerConfiguration( $positional_args, $options );
		$config->setProgressObserver(
			new ProgressObserver(
				function ( $progress, $caption ) use ( $progress_reporter ) {
					$progress_reporter->reportProgress( $progress, $caption );
				}
			)
		);
		$runner = new Runner( $config );

		// Execute the Blueprint.
		if ( Runner::EXECUTION_MODE_CREATE_NEW_SITE === $config->getExecutionMode() ) {
			$progress_reporter->reportProgress( 0, 'Creating a new site' );
		} else {
			$progress_reporter->reportProgress( 0, 'Updating an existing site' );
		}
		$progress_reporter->reportProgress( 0, sprintf( '  Site URL:  %s', $config->getTargetSiteUrl() ) );
		$progress_reporter->reportProgress( 0, sprintf( '  Site path: %s', $config->getTargetSiteRoot() ) );
		$progress_reporter->reportProgress( 0, sprintf( '  Blueprint: %s', $config->getBlueprint()->get_human_readable_name() ) );

		$runner->run();

		$progress_reporter->reportCompletion( 'Blueprint successfully executed.' );
	} catch ( PermissionsException $ex ) {
		$permission = $ex->getPermission();
		$flag       = RunnerConfiguration::getPermissionCliFlag( $permission );

		$progress_reporter->reportError( sprintf( 'Permission Error: %s', $ex->getMessage() ), $ex );
		$progress_reporter->reportError( sprintf( 'Tip: Run with --allow=%s to grant this permission.', $flag ) );
		exit( 1 );
	}
}

function handleHelpCommand( array $positional_args, array $options, array $command_configurations, ProgressReporter $progress_reporter ): void {
	if ( ! empty( $positional_args ) ) {
		$requested_command = $positional_args[0];
		$resolved_command  = resolveCommand( $requested_command, $command_configurations );

		if ( null !== $resolved_command ) {
			showCommandHelpMessage( $resolved_command, $command_configurations[ $resolved_command ] );
		} else {
			$progress_reporter->reportError( "Unknown command '$requested_command'." );
			showGeneralHelpMessage( $command_configurations );
		}
	} else {
		showGeneralHelpMessage( $command_configurations );
	}
}

function cliArgsToRunnerConfiguration( array $positional_args, array $options ): RunnerConfiguration {
	global $supported_permissions;

	$config = new RunnerConfiguration();

	// The first positional is the blueprint reference.
	try {
		$blueprint_reference = $positional_args[0];
		$config->setBlueprint(
			DataReference::create(
				$blueprint_reference,
				array(
					AbsoluteLocalPath::class,
					ExecutionContextPath::class,
				)
			)
		);
	} catch ( InvalidArgumentException $e ) {
		throw new InvalidArgumentException( sprintf( 'Invalid Blueprint reference: %s. Hint: paths must start with ./ or /. URLs must start with http:// or https://.', $positional_args[0] ) );
	}

	if ( ! empty( $options['mode'] ) ) {
		$mode = $options['mode'];
		if ( Runner::EXECUTION_MODE_CREATE_NEW_SITE === $mode ) {
			$config->setExecutionMode( Runner::EXECUTION_MODE_CREATE_NEW_SITE );
		} elseif ( Runner::EXECUTION_MODE_APPLY_TO_EXISTING_SITE === $mode ) {
			$config->setExecutionMode( Runner::EXECUTION_MODE_APPLY_TO_EXISTING_SITE );
			if ( ! empty( $options['wp'] ) ) {
				throw new InvalidArgumentException( sprintf( 'The --wp option cannot be used with --mode=%s. The WordPress version is whatever the existing site has.', Runner::EXECUTION_MODE_APPLY_TO_EXISTING_SITE ) );
			}
		} else {
			throw new InvalidArgumentException( sprintf( "Invalid execution mode: '{$mode}'. Supported modes are: %s", implode( ', ', Runner::EXECUTION_MODES ) ) );
		}
	}

	$target_site_root = $options['site-path'];
	if ( $options['truncate-new-site-directory'] ) {
		if ( Runner::EXECUTION_MODE_CREATE_NEW_SITE !== $options['mode'] ) {
			throw new InvalidArgumentException( sprintf( '--truncate-new-site-directory can only be used with --mode=%s', Runner::EXECUTION_MODE_CREATE_NEW_SITE ) );
		}
		$absolute_target_site_root = realpath( $target_site_root );
		if ( false === $absolute_target_site_root ) {
			mkdir( $target_site_root, 0755, true );
		} elseif ( is_dir( $absolute_target_site_root ) ) {
			$fs = LocalFilesystem::create( $absolute_target_site_root );
			// Delete all the files and directories in the target site root, but preserve the.
			// target directory itself. Why? In Playground CLI, `/wordpress` is likely to be a.
			// mount removing a mount root throws an Exception.
			foreach ( $fs->ls( '/' ) as $file ) {
				if ( $fs->is_dir( $file ) ) {
					$fs->rmdir( $file, array( 'recursive' => true ) );
				} else {
					$fs->rm( $file );
				}
			}
			if ( ! $fs->is_dir( '/' ) ) {
				$fs->mkdir( '/', array( 'chmod' => 0755 ) );
			}
		}
	}

	$absolute_target_site_root = realpath( $target_site_root );
	if ( false === $absolute_target_site_root || ! is_dir( $absolute_target_site_root ) ) {
		throw new InvalidArgumentException( "The --site-path path does not exist: {$target_site_root}" );
	}
	$config->setTargetSiteRoot( $absolute_target_site_root );
	$config->setTargetSiteUrl( $options['site-url'] );

	// Set database engine.
	if ( ! empty( $options['db-engine'] ) ) {
		$config->setDatabaseEngine( $options['db-engine'] );
	}

	// Set database credentials.
	$db_engine = $options['db-engine'] ?? 'mysql';
	$db_creds  = array();
	if ( 'mysql' === $db_engine ) {
		$db_creds = array(
			'host'         => $options['db-host'] ?? '127.0.0.1',
			'username'     => $options['db-user'] ?? 'root',
			'password'     => $options['db-pass'] ?? '',
			'databaseName' => $options['db-name'] ?? 'wordpress',
		);
	} elseif ( 'sqlite' === $db_engine ) {
		$db_creds = array(
			'path' => $options['db-path'] ?? 'wp.db',
		);
	}
	$config->setDatabaseCredentials( $db_creds );

	// Set allow options.
	if ( ! empty( $options['allow'] ) ) {
		$allow = explode( ',', $options['allow'] );
		foreach ( $allow as $permission ) {
			switch ( $permission ) {
				case 'read-local-fs':
					$config->setAllowLocalFilesystemAccess( true );
					break;
				default:
					throw new InvalidArgumentException(
						"Unknown --allow permission: $permission. Allowed permissions: " . implode(
							', ',
							$supported_permissions
						)
					);
			}
		}
	}

	$config->setLogger(
		new CLILogger( 'php://stdout', CLILogger::VERBOSITY_INFO )
	);

	return $config;
}

// -----------------------------------------------------------------------------.
// Help & version.
// -----------------------------------------------------------------------------.
function showGeneralHelpMessage( array $command_configurations ): void {
	$script = basename( $_SERVER['argv'][0] );
	echo "\033[1mWordPress Blueprint Runner\033[0m\n\n";
	echo "\033[1mUsage:\033[0m\n";
	echo "  php $script \033[33m<command>\033[0m [options] [arguments]\n\n";
	echo "\033[1mAvailable commands:\033[0m\n";

	$command_list = array();
	foreach ( $command_configurations as $cmd => $config ) {
		$aliases        = isset( $config['aliases'] ) && ! empty( $config['aliases'] )
			? ' (aliases: ' . implode( ', ', $config['aliases'] ) . ')'
			: '';
		$command_list[] = array(
			'name' => $cmd . $aliases,
			'desc' => $config['description'],
		);
	}

	// Find the longest command name for proper formatting.
	$max_name_length = 0;
	foreach ( $command_list as $cmd ) {
		$max_name_length = max( $max_name_length, strlen( $cmd['name'] ) );
	}

	// Output command list with descriptions.
	foreach ( $command_list as $cmd ) {
		printf( '  %-' . ( $max_name_length + 2 ) . "s %s\n", $cmd['name'], $cmd['desc'] );
	}

	echo "\nFor detailed help on a specific command, use:\n";
	echo "  php $script help \033[33m<command>\033[0m\n";
	echo "  php $script \033[33m<command>\033[0m --help\n";
}

function showCommandHelpMessage( string $command, array $command_config ): void {
	$script = basename( $_SERVER['argv'][0] );

	echo "\033[1m" . $command_config['description'] . "\033[0m\n\n";

	// Display command syntax.
	echo "\033[1mUsage:\033[0m\n";
	echo "  php $script $command";

	// Add positional args to usage if any.
	if ( ! empty( $command_config['positionalArgs'] ) ) {
		foreach ( $command_config['positionalArgs'] as $name => $desc ) {
			echo " \033[33m<$name>\033[0m";
		}
	}
	echo " [options]\n\n";

	// Display positional arguments.
	if ( ! empty( $command_config['positionalArgs'] ) ) {
		echo "\033[1mArguments:\033[0m\n";
		$max_arg_name_length = max( array_map( 'strlen', array_keys( $command_config['positionalArgs'] ) ) );
		foreach ( $command_config['positionalArgs'] as $name => $desc ) {
			printf( '  %-' . ( $max_arg_name_length + 2 ) . "s %s\n", $name, $desc );
		}
		echo "\n";
	}

	// Display options.
	if ( ! empty( $command_config['options'] ) ) {
		echo "\033[1mOptions:\033[0m\n";
		foreach ( $command_config['options'] as $long => [$short, $has_val, $def, $desc] ) {
			$flags = '  ' . ( $short ? "-$short, " : '    ' ) . "--$long";
			if ( $has_val ) {
				$flags .= ' <value>';
			}
			$default_text = is_null( $def ) ? '' : ' (default ' . var_export( $def, true ) . ')';

			// Mark required options.
			if ( isset( $command_config['requiredOptions'] ) && in_array( $long, $command_config['requiredOptions'] ) ) {
				$default_text = ' (required)';
			}

			printf( "%-34s %s\n", $flags, $desc . $default_text );
		}
	}

	// Display examples.
	if ( ! empty( $command_config['examples'] ) ) {
		echo "\n\033[1mExamples:\033[0m\n";
		foreach ( $command_config['examples'] as $example ) {
			echo "  $example\n";
		}
	}
	echo "\n";
}


// -----------------------------------------------------------------------------.
// Main entry.
// -----------------------------------------------------------------------------.
try {
	global $command_configurations;

	// Process global arguments first (version, etc.).
	if ( isset( $_SERVER['argv'][1] ) && '--version' === $_SERVER['argv'][1] ) {
		echo "WordPress Blueprint Runner CLI v0.0.1-alpha\n";
		exit( 0 );
	}

	// Get the command from arguments.
	$command_arg = $_SERVER['argv'][1] ?? 'help';
	$command     = resolveCommand( $command_arg, $command_configurations );

	if ( null === $command ) {
		$progress_reporter->reportError( "Unknown command '$command_arg'." );
		showGeneralHelpMessage( $command_configurations );
		exit( 1 );
	}

	// Parse command arguments and options.
	$command_argv                  = array_slice( $_SERVER['argv'], 2 ); // Skip "php script.php command".
	[ $positional_args, $options ] = CLI::parseCommandArgsAndOptions( $command_argv, $command_configurations[ $command ]['options'] );

	// Dispatch to appropriate command handler.
	switch ( $command ) {
		case 'exec':
			handleExecCommand( $positional_args, $options, $command_configurations[ $command ], $progress_reporter );
			break;
		case 'help':
			handleHelpCommand( $positional_args, $options, $command_configurations, $progress_reporter );
			break;
		default:
			$progress_reporter->reportError( "Command handler not implemented for '$command'." );
			exit( 1 );
	}
} catch ( BlueprintExecutionException $ex ) {
	if ( ! $ex->schema_error ) {
		$progress_reporter->reportError( $ex->getMessage() );
		while ( $ex->getPrevious() ) {
			$ex = $ex->getPrevious();
			$progress_reporter->reportError( 'Caused by: ' . $ex->getMessage() );
		}
		exit( 1 );
	}

	$progress_reporter->reportError( $ex->getMessage() . ' See the validation errors below:' );
	$last_pretty_path = '';
	$current_error    = $ex->schema_error;
	while ( $current_error ) {
		$pretty_path = $current_error->getPrettyPath();
		if ( $pretty_path !== $last_pretty_path ) {
			$progress_reporter->reportError( $pretty_path . ':' );
		}
		$progress_reporter->reportError( $current_error->message );
		$current_error    = $current_error->getMostProbableCause();
		$last_pretty_path = $pretty_path;
	}
	exit( 1 );
} catch ( Exception $ex ) {
	$progress_reporter->reportError( $ex->getMessage(), $ex );
	exit( 1 );
}
