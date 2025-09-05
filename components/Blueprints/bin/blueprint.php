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

// Enable colours on Windows 10+ (safe‑no‑op elsewhere)
if ( PHP_OS_FAMILY === 'Windows' && function_exists( 'sapi_windows_vt100_support' ) ) {
	@sapi_windows_vt100_support( STDOUT, true );
}

interface ProgressReporter {
    /**
     * Report progress update
     * 
     * @param float $progress Progress percentage (0-100)
     * @param string $caption Progress caption/message
     */
    public function reportProgress(float $progress, string $caption): void;

    /**
     * Report an error
     * 
     * @param string $message Error message
     * @param \Throwable|null $exception Optional exception details
     */
    public function reportError(string $message, ?\Throwable $exception = null): void;

    /**
     * Report completion
     * 
     * @param string $message Completion message
     */
    public function reportCompletion(string $message): void;

    /**
     * Close/cleanup the reporter
     */
    public function close(): void;
}

class TerminalProgressReporter implements ProgressReporter {
    private $stdout;
    private $lastProgress = -1;
    private $lastCaption = '';
    private $progressBarWidth = 50;

    public function __construct() {
        $this->stdout = fopen('php://stdout', 'w');
    }

    public function reportProgress(float $progress, string $caption): void {
        // Don't repeat identical progress
        if ($this->lastProgress === $progress && $this->lastCaption === $caption) {
            return;
        }

        $this->lastProgress = $progress;
        $this->lastCaption = $caption;

        $percentage = min(100, max(0, $progress));
        $filled = (int)round($this->progressBarWidth * ($percentage / 100));
        $empty = $this->progressBarWidth - $filled;
        
        $bar = str_repeat('=', $filled);
        if ($empty > 0 && $filled < $this->progressBarWidth) {
            $bar .= '>';
            $bar .= str_repeat(' ', $empty - 1);
        } else {
            $bar .= str_repeat(' ', $empty);
        }

        $status = sprintf(
            "\r[%s] %3.1f%% - %s",
            $bar,
            $percentage,
            $caption
        );

        if ($this->isTty()) {
            // Clear line and write new progress
            fwrite($this->stdout, "\r\033[K" . $status);
        } else {
            // Non-TTY, just write new line
            fwrite($this->stdout, $status . "\n");
        }
        fflush($this->stdout);
    }

    public function reportError(string $message, ?\Throwable $exception = null): void {
        $this->clearCurrentLine();
        
        $errorMsg = "\033[1;31mError:\033[0m " . $message;
        if ($exception) {
            $errorMsg .= " (" . $exception->getMessage() . ")";
        }
        
        fwrite($this->stdout, $errorMsg . "\n");
        fflush($this->stdout);
    }

    public function reportCompletion(string $message): void {
        $this->clearCurrentLine();
        fwrite($this->stdout, "\033[1;32m" . $message . "\033[0m\n");
        fflush($this->stdout);
    }

    public function close(): void {
        if ($this->stdout) {
            fclose($this->stdout);
        }
    }

    private function clearCurrentLine(): void {
        if ($this->isTty()) {
            fwrite($this->stdout, "\r\033[K");
        }
    }

    private function isTty(): bool {
        return stream_isatty($this->stdout);
    }
}

class JsonProgressReporter implements ProgressReporter {
    private $outputFile;

    public function __construct() {
        $outputPath = getenv('OUTPUT_FILE') ?: 'php://stdout';
        $this->outputFile = fopen($outputPath, 'w');
    }

    public function reportProgress(float $progress, string $caption): void {
        $this->writeJsonMessage([
            'type' => 'progress',
            'progress' => round($progress, 2),
            'caption' => $caption
        ]);
    }

    public function reportError(string $message, ?\Throwable $exception = null): void {
        $errorData = [
            'type' => 'error',
            'message' => $message
        ];

        if ($exception) {
            $errorData['details'] = [
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString()
            ];
        }

        $this->writeJsonMessage($errorData);
    }

    public function reportCompletion(string $message): void {
        $this->writeJsonMessage([
            'type' => 'completion',
            'message' => $message
        ]);
    }

    public function close(): void {
        if ($this->outputFile) {
            fclose($this->outputFile);
        }
    }

    private function writeJsonMessage(array $data): void {
        fwrite($this->outputFile, json_encode($data) . "\n");
        fflush($this->outputFile);
    }
}

function createProgressReporter(): ProgressReporter {
	$reporter = apply_filters('blueprint.progress_reporter', null);
	if ( $reporter ) {
		return $reporter;
	}

    // Use JSON mode if OUTPUT_FILE is set or if we're not in a TTY
    if (getenv('OUTPUT_FILE') || !stream_isatty(STDOUT)) {
        return new JsonProgressReporter();
    }
    
    return new TerminalProgressReporter();
}


$progressReporter = createProgressReporter();

// -----------------------------------------------------------------------------
//   Command and option definitions
// -----------------------------------------------------------------------------
$supportedPermissions = RunnerConfiguration::ALL_PERMISSIONS;

// Define common options that can be used by multiple commands
$commonOptions = [
	'help'    => [ 'h', false, false, 'Show help for this command' ],
	'version' => [ 'V', false, false, 'Show version' ],
];

// Define the available commands and their specific options
$commandConfigurations = [
	'exec' => [
		'description'     => 'Execute a WordPress Blueprint',
		'positionalArgs'  => [
			'blueprint' => 'Path / URL / DataReference to the blueprint (required)',
		],
		'options'         => array_merge( $commonOptions, [
			'site-url'                    => [ 'u', true, null, 'Public site URL (https://example.com)' ],
			'site-path'                   => [ null, true, null, 'Target directory with WordPress install context)' ],
			'execution-context'           => [ 'x', true, null, 'Source directory with Blueprint context files' ],
			'mode'                        => [ 'm', true, Runner::EXECUTION_MODE_CREATE_NEW_SITE, sprintf( 'Execution mode (%s|%s)', Runner::EXECUTION_MODE_CREATE_NEW_SITE, Runner::EXECUTION_MODE_APPLY_TO_EXISTING_SITE ) ],
			'db-engine'                   => [ 'd', true, 'mysql', 'Database engine (mysql|sqlite)' ],
			'db-host'                     => [ null, true, '127.0.0.1', 'MySQL host' ],
			'db-user'                     => [ null, true, 'root', 'MySQL user' ],
			'db-pass'                     => [ null, true, '', 'MySQL password' ],
			'db-name'                     => [ null, true, 'wordpress', 'MySQL database' ],
			'db-path'                     => [ 'p', true, 'wp.db', 'SQLite file path' ],
			'truncate-new-site-directory' => [ 't', false, false, 'Delete target directory if it exists before execution' ],
			/**
			 * @TODO: Reuse this error message removed from the Playground repo:
			 * 
			 *			if (!blueprintMayReadAdjacentFiles) {
			 *				throw new ReportableError(
			 *					`Error: Blueprint contained tried to read a local file at path "${path}" (via a resource of type "bundled"). ` +
			 *						`Playground restricts access to local resources by default as a security measure. \n\n` +
			 *						`You can allow this Blueprint to read files from the same parent directory by explicitly adding the ` +
			 *						`--blueprint-may-read-adjacent-files option to your command.`
			 *				);
			 *			}
			 */
			'allow'                       => [ null, true, null, 'Allowed permissions. One of: ' . implode( ', ', $supportedPermissions ) ],
		] ),
		'examples'        => [
			'php blueprint.php exec my-blueprint.json --site-url https://mysite.test --site-path /var/www/mysite.com',
			sprintf( 'php blueprint.php exec my-blueprint.json --execution-context /var/www --site-url https://mysite.test --mode %s --site-path ./site', Runner::EXECUTION_MODE_APPLY_TO_EXISTING_SITE ),
			'php blueprint.php exec my-blueprint.json --site-url https://mysite.test --site-path ./mysite --truncate-new-site-directory',
		],
		'aliases'         => [ 'run' ],
		'requiredOptions' => [ 'site-path', 'site-url', 'mode' ],
	],
	'help' => [
		'description'    => 'Show help for WordPress Blueprint Runner CLI',
		'positionalArgs' => [
			'command' => 'Command name to get help for (optional)',
		],
		'options'        => $commonOptions,
		'examples'       => [
			'php blueprint.php help',
			'php blueprint.php help exec',
		],
		'aliases'        => [],
	],
];

// Get the command name from arguments, accounting for aliases
function resolveCommand( $commandArg, array $commandConfigurations ): ?string {
	// Direct command match
	if ( isset( $commandConfigurations[ $commandArg ] ) ) {
		return $commandArg;
	}

	// Check for aliases
	foreach ( $commandConfigurations as $cmdName => $config ) {
		if ( isset( $config['aliases'] ) && in_array( $commandArg, $config['aliases'] ) ) {
			return $cmdName;
		}
	}

	return null;
}

// -----------------------------------------------------------------------------
//   Command handlers
// -----------------------------------------------------------------------------
function handleExecCommand( array $positionalArgs, array $options, array $commandConfig, ProgressReporter $progressReporter ): void {
	// Check if help is requested for this command
	if ( $options['help'] ) {
		showCommandHelpMessage( 'exec', $commandConfig );
		exit( 0 );
	}
	
	// Validate required options
	foreach ( $commandConfig['requiredOptions'] as $requiredOption ) {
		if ( empty( $options[ $requiredOption ] ) ) {
			$progressReporter->reportError("The --$requiredOption option is required for the exec command.");
			exit( 1 );
		}
	}

	// Validate required positional arguments
	if ( empty( $positionalArgs ) ) {
		$progressReporter->reportError("A Blueprint reference must be specified as a positional argument.");
		exit( 1 );
	}

	try {
		// Convert CLI arguments to RunnerConfiguration
		$config = cliArgsToRunnerConfiguration( $positionalArgs, $options );
		$config->setProgressObserver( new ProgressObserver( function ( $progress, $caption ) use ( $progressReporter ) {
			$progressReporter->reportProgress( $progress, $caption );
		} ) );
		$runner = new Runner( $config );

		// Execute the Blueprint
		if ( $config->getExecutionMode() === Runner::EXECUTION_MODE_CREATE_NEW_SITE ) {
			$progressReporter->reportProgress(0, 'Creating a new site');
		} else {
			$progressReporter->reportProgress(0, 'Updating an existing site');
		}
		$progressReporter->reportProgress(0, sprintf("  Site URL:  %s", $config->getTargetSiteUrl()));
		$progressReporter->reportProgress(0, sprintf("  Site path: %s", $config->getTargetSiteRoot()));
		$progressReporter->reportProgress(0, sprintf("  Blueprint: %s", $config->getBlueprint()->get_human_readable_name()));
		
		$runner->run();
		
		$progressReporter->reportCompletion("Blueprint successfully executed.");
	} catch ( PermissionsException $ex ) {
		$permission = $ex->getPermission();
		$flag       = RunnerConfiguration::getPermissionCliFlag( $permission );

		$progressReporter->reportError(sprintf("Permission Error: %s", $ex->getMessage()), $ex);
		$progressReporter->reportError(sprintf("Tip: Run with --allow=%s to grant this permission.", $flag));
		exit( 1 );
	}
}

function handleHelpCommand( array $positionalArgs, array $options, array $commandConfigurations, ProgressReporter $progressReporter ): void {
	if ( ! empty( $positionalArgs ) ) {
		$requestedCommand = $positionalArgs[0];
		$resolvedCommand = resolveCommand( $requestedCommand, $commandConfigurations );
		
		if ( $resolvedCommand !== null ) {
			showCommandHelpMessage( $resolvedCommand, $commandConfigurations[ $resolvedCommand ] );
		} else {
			$progressReporter->reportError("Unknown command '$requestedCommand'.");
			showGeneralHelpMessage( $commandConfigurations );
		}
	} else {
		showGeneralHelpMessage( $commandConfigurations );
	}
}

function cliArgsToRunnerConfiguration( array $positionalArgs, array $options ): RunnerConfiguration {
	global $supportedPermissions;

	$config = new RunnerConfiguration();

	// The first positional is the blueprint reference
	try {
		$blueprint_reference = $positionalArgs[0];
		$config->setBlueprint( DataReference::create( $blueprint_reference, [
			AbsoluteLocalPath::class,
			ExecutionContextPath::class,
		] ) );
	} catch ( InvalidArgumentException $e ) {
		throw new InvalidArgumentException( sprintf( "Invalid Blueprint reference: %s. Hint: paths must start with ./ or /. URLs must start with http:// or https://.", $positionalArgs[0] ) );
	}

	if ( ! empty( $options['mode'] ) ) {
		$mode = $options['mode'];
		if ( $mode === Runner::EXECUTION_MODE_CREATE_NEW_SITE ) {
			$config->setExecutionMode( Runner::EXECUTION_MODE_CREATE_NEW_SITE );
		} elseif ( $mode === Runner::EXECUTION_MODE_APPLY_TO_EXISTING_SITE ) {
			$config->setExecutionMode( Runner::EXECUTION_MODE_APPLY_TO_EXISTING_SITE );
			if(!empty($options['wp'])) {
				throw new InvalidArgumentException( sprintf( "The --wp option cannot be used with --mode=%s. The WordPress version is whatever the existing site has.", Runner::EXECUTION_MODE_APPLY_TO_EXISTING_SITE ) );
			}
		} else {
			throw new InvalidArgumentException( sprintf( "Invalid execution mode: '{$mode}'. Supported modes are: %s", implode( ', ', Runner::EXECUTION_MODES ) ) );
		}
	}

	$targetSiteRoot         = $options['site-path'];
	if ( $options['truncate-new-site-directory'] ) {
		if ( $options['mode'] !== Runner::EXECUTION_MODE_CREATE_NEW_SITE ) {
			throw new InvalidArgumentException( sprintf( "--truncate-new-site-directory can only be used with --mode=%s", Runner::EXECUTION_MODE_CREATE_NEW_SITE ) );
		}
		$absoluteTargetSiteRoot = realpath( $targetSiteRoot );
		if ( false === $absoluteTargetSiteRoot) {
			mkdir( $targetSiteRoot, 0755, true );
		} else if( is_dir( $absoluteTargetSiteRoot ) ) {
			$fs = LocalFilesystem::create( $absoluteTargetSiteRoot );
			// Delete all the files and directories in the target site root, but preserve the
			// target directory itself. Why? In Playground CLI, `/wordpress` is likely to be a
			// mount removing a mount root throws an Exception.
			foreach ( $fs->ls('/') as $file ) {
				if( $fs->is_dir( $file ) ) {
					$fs->rmdir( $file, [ 'recursive' => true ] );
				} else {
					$fs->rm( $file );
				}
			}
			if ( ! $fs->is_dir( '/' ) ) {
				$fs->mkdir( '/', [ 'chmod' => 0755 ] );
			}
		} 
	}

	$absoluteTargetSiteRoot = realpath( $targetSiteRoot );
	if ( false === $absoluteTargetSiteRoot || ! is_dir( $absoluteTargetSiteRoot ) ) {
		throw new InvalidArgumentException( "The --site-path path does not exist: {$targetSiteRoot}" );
	}
	$config->setTargetSiteRoot( $absoluteTargetSiteRoot );
	$config->setTargetSiteUrl( $options['site-url'] );

	// Set database engine
	if ( ! empty( $options['db-engine'] ) ) {
		$config->setDatabaseEngine( $options['db-engine'] );
	}

	// Set database credentials
	$dbEngine = $options['db-engine'] ?? 'mysql';
	$dbCreds  = [];
	if ( $dbEngine === 'mysql' ) {
		$dbCreds = [
			'host'         => $options['db-host'] ?? '127.0.0.1',
			'username'     => $options['db-user'] ?? 'root',
			'password'     => $options['db-pass'] ?? '',
			'databaseName' => $options['db-name'] ?? 'wordpress',
		];
	} elseif ( $dbEngine === 'sqlite' ) {
		$dbCreds = [
			'path' => $options['db-path'] ?? 'wp.db',
		];
	}
	$config->setDatabaseCredentials( $dbCreds );

	// Set allow options
	if ( ! empty( $options['allow'] ) ) {
		$allow = explode( ',', $options['allow'] );
		foreach ( $allow as $permission ) {
			switch ( $permission ) {
				case 'read-local-fs':
					$config->setAllowLocalFilesystemAccess( true );
					break;
				default:
					throw new InvalidArgumentException( "Unknown --allow permission: $permission. Allowed permissions: " . implode( ', ',
							$supportedPermissions ) );
			}
		}
	}

	$config->setLogger(
		new CLILogger( 'php://stdout', CLILogger::VERBOSITY_INFO )
	);

	return $config;
}

// -----------------------------------------------------------------------------
//   Help & version
// -----------------------------------------------------------------------------
function showGeneralHelpMessage( array $commandConfigurations ): void {
	$script = basename( $_SERVER['argv'][0] );
	echo "\033[1mWordPress Blueprint Runner\033[0m\n\n";
	echo "\033[1mUsage:\033[0m\n";
	echo "  php $script \033[33m<command>\033[0m [options] [arguments]\n\n";
	echo "\033[1mAvailable commands:\033[0m\n";
	
	$commandList = [];
	foreach ( $commandConfigurations as $cmd => $config ) {
		$aliases = isset( $config['aliases'] ) && !empty( $config['aliases'] ) 
			? ' (aliases: ' . implode( ', ', $config['aliases'] ) . ')' 
			: '';
		$commandList[] = [
			'name' => $cmd . $aliases,
			'desc' => $config['description']
		];
	}
	
	// Find the longest command name for proper formatting
	$maxNameLength = 0;
	foreach ( $commandList as $cmd ) {
		$maxNameLength = max( $maxNameLength, strlen( $cmd['name'] ) );
	}
	
	// Output command list with descriptions
	foreach ( $commandList as $cmd ) {
		printf( "  %-" . ($maxNameLength + 2) . "s %s\n", $cmd['name'], $cmd['desc'] );
	}
	
	echo "\nFor detailed help on a specific command, use:\n";
	echo "  php $script help \033[33m<command>\033[0m\n";
	echo "  php $script \033[33m<command>\033[0m --help\n";
}

function showCommandHelpMessage( string $command, array $commandConfig ): void {
	$script = basename( $_SERVER['argv'][0] );
	
	echo "\033[1m" . $commandConfig['description'] . "\033[0m\n\n";
	
	// Display command syntax
	echo "\033[1mUsage:\033[0m\n";
	echo "  php $script $command";
	
	// Add positional args to usage if any
	if ( !empty( $commandConfig['positionalArgs'] ) ) {
		foreach ( $commandConfig['positionalArgs'] as $name => $desc ) {
			echo " \033[33m<$name>\033[0m";
		}
	}
	echo " [options]\n\n";
	
	// Display positional arguments
	if ( !empty( $commandConfig['positionalArgs'] ) ) {
		echo "\033[1mArguments:\033[0m\n";
		$maxArgNameLength = max( array_map( 'strlen', array_keys( $commandConfig['positionalArgs'] ) ) );
		foreach ( $commandConfig['positionalArgs'] as $name => $desc ) {
			printf( "  %-" . ($maxArgNameLength + 2) . "s %s\n", $name, $desc );
		}
		echo "\n";
	}
	
	// Display options
	if ( !empty( $commandConfig['options'] ) ) {
		echo "\033[1mOptions:\033[0m\n";
		foreach ( $commandConfig['options'] as $long => [$short, $hasVal, $def, $desc] ) {
			$flags = '  ' . ( $short ? "-$short, " : '    ' ) . "--$long";
			if ( $hasVal ) {
				$flags .= " <value>";
			}
			$defaultText = is_null( $def ) ? '' : ' (default ' . var_export( $def, true ) . ')';
			
			// Mark required options
			if ( isset( $commandConfig['requiredOptions'] ) && in_array( $long, $commandConfig['requiredOptions'] ) ) {
				$defaultText = ' (required)';
			}
			
			printf( "%-34s %s\n", $flags, $desc . $defaultText );
		}
	}
	
	// Display examples
	if ( !empty( $commandConfig['examples'] ) ) {
		echo "\n\033[1mExamples:\033[0m\n";
		foreach ( $commandConfig['examples'] as $example ) {
			echo "  $example\n";
		}
	}
	echo "\n";
}


// -----------------------------------------------------------------------------
//   Main entry
// -----------------------------------------------------------------------------
try {
	global $commandConfigurations;
	
	// Process global arguments first (version, etc.)
	if ( isset( $_SERVER['argv'][1] ) && $_SERVER['argv'][1] === '--version' ) {
		echo "WordPress Blueprint Runner CLI v0.0.1-alpha\n";
		exit( 0 );
	}
	
	// Get the command from arguments
	$commandArg = $_SERVER['argv'][1] ?? 'help';
	$command = resolveCommand( $commandArg, $commandConfigurations );
	
	if ( $command === null ) {
		$progressReporter->reportError("Unknown command '$commandArg'.");
		showGeneralHelpMessage( $commandConfigurations );
		exit( 1 );
	}
	
	// Parse command arguments and options
	$commandArgv = array_slice( $_SERVER['argv'], 2 ); // Skip "php script.php command"
	[ $positionalArgs, $options ] = CLI::parseCommandArgsAndOptions( $commandArgv, $commandConfigurations[ $command ]['options'] );
	
	// Dispatch to appropriate command handler
	switch ( $command ) {
		case 'exec':
			handleExecCommand( $positionalArgs, $options, $commandConfigurations[ $command ], $progressReporter );
			break;
		case 'help':
			handleHelpCommand( $positionalArgs, $options, $commandConfigurations, $progressReporter );
			break;
		default:
			$progressReporter->reportError("Command handler not implemented for '$command'.");
			exit( 1 );
	}
} catch ( BlueprintExecutionException $ex ) {
	if ( ! $ex->schemaError ) {
		$progressReporter->reportError($ex->getMessage());
		while ( $ex->getPrevious() ) {
			$ex = $ex->getPrevious();
			$progressReporter->reportError("Caused by: " . $ex->getMessage());
		}
		exit( 1 );
	}

	$progressReporter->reportError($ex->getMessage() . ' See the validation errors below:');
	$lastPrettyPath = '';
	$currentError   = $ex->schemaError;
	while ( $currentError ) {
		$prettyPath = $currentError->getPrettyPath();
		if ( $prettyPath !== $lastPrettyPath ) {
			$progressReporter->reportError($prettyPath . ":");
		}
		$progressReporter->reportError($currentError->message);
		$currentError   = $currentError->getMostProbableCause();
		$lastPrettyPath = $prettyPath;
	}
	exit( 1 );
} catch ( Exception $ex ) {
	$progressReporter->reportError($ex->getMessage(), $ex);
	exit( 1 );
}
