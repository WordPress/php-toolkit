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
			'mode'                        => [ 'm', true, 'create-new-site', 'Execution mode (create|apply)' ],
			'db-engine'                   => [ 'd', true, 'mysql', 'Database engine (mysql|sqlite)' ],
			'db-host'                     => [ null, true, '127.0.0.1', 'MySQL host' ],
			'db-user'                     => [ null, true, 'root', 'MySQL user' ],
			'db-pass'                     => [ null, true, '', 'MySQL password' ],
			'db-name'                     => [ null, true, 'wordpress', 'MySQL database' ],
			'db-path'                     => [ 'p', true, 'wp.db', 'SQLite file path' ],
			'truncate-new-site-directory' => [ 't', false, false, 'Delete target directory if it exists before execution' ],
			'allow'                       => [ null, true, null, 'Allowed permissions. One of: ' . implode( ', ', $supportedPermissions ) ],
		] ),
		'examples'        => [
			'php blueprint.php exec my-blueprint.json --site-url https://mysite.test --site-path /var/www/mysite.com',
			'php blueprint.php exec my-blueprint.json --execution-context /var/www --site-url https://mysite.test --mode apply --site-path ./site',
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
function handleExecCommand( array $positionalArgs, array $options, array $commandConfig ): void {
	// Check if help is requested for this command
	if ( $options['help'] ) {
		showCommandHelpMessage( 'exec', $commandConfig );
		exit( 0 );
	}
	
	// Validate required options
	foreach ( $commandConfig['requiredOptions'] as $requiredOption ) {
		if ( empty( $options[ $requiredOption ] ) ) {
			echo "\033[31mError:\033[0m The --$requiredOption option is required for the exec command." . PHP_EOL;
			exit( 1 );
		}
	}

	// Validate required positional arguments
	if ( empty( $positionalArgs ) ) {
		echo "\033[31mError:\033[0m A Blueprint reference must be specified as a positional argument." . PHP_EOL;
		exit( 1 );
	}

	try {
		// Convert CLI arguments to RunnerConfiguration
		$config = cliArgsToRunnerConfiguration( $positionalArgs, $options );
		$config->setProgressObserver( new ProgressObserver( function ( $progress, $caption ) {
			reportProgress( $progress, $caption );
		} ) );
		$runner = new Runner( $config );

		// Execute the Blueprint
		if ( $config->getExecutionMode() === 'create-new-site' ) {
			echo "\033[1;32mCreating a new site\033[0m\n";
		} else {
			echo "\033[1;32mUpdating an existing site\033[0m\n";
		}
		echo sprintf( "  Site URL:  %s\n", $config->getTargetSiteUrl() );
		echo sprintf( "  Site path: %s\n", $config->getTargetSiteRoot() );
		echo sprintf( "  Blueprint: %s\n", $config->getBlueprint()->get_human_readable_name() );
		echo PHP_EOL;
		
		$runner->run();
		
		echo PHP_EOL;
		echo sprintf( "\033[32m✔ Blueprint successfully executed.\033[0m\n" );
	} catch ( PermissionsException $ex ) {
		echo PHP_EOL . PHP_EOL;
		$permission = $ex->getPermission();
		$flag       = RunnerConfiguration::getPermissionCliFlag( $permission );

		echo sprintf( "\033[31mPermission Error:\033[0m %s\n", $ex->getMessage() );
		echo sprintf( "\033[33mTip:\033[0m Run with \033[1m--allow=%s\033[0m to grant this permission.\n", $flag );
		exit( 1 );
	}
}

function handleHelpCommand( array $positionalArgs, array $options, array $commandConfigurations ): void {
	if ( ! empty( $positionalArgs ) ) {
		$requestedCommand = $positionalArgs[0];
		$resolvedCommand = resolveCommand( $requestedCommand, $commandConfigurations );
		
		if ( $resolvedCommand !== null ) {
			showCommandHelpMessage( $resolvedCommand, $commandConfigurations[ $resolvedCommand ] );
		} else {
			echo "\033[31mError:\033[0m Unknown command '$requestedCommand'.\n\n";
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
		] ) );
	} catch ( InvalidArgumentException $e ) {
		throw new InvalidArgumentException( "Invalid Blueprint reference: " . $positionalArgs[0] );
	}

	if ( ! empty( $options['mode'] ) ) {
		// Accept 'create-new-site' or 'apply-to-existing-site' as CLI values, map to internal values
		$mode = $options['mode'];
		if ( $mode === 'create-new-site' ) {
			$config->setExecutionMode( 'create-new-site' );
		} elseif ( $mode === 'apply-to-existing-site' ) {
			$config->setExecutionMode( 'apply-to-existing-site' );
		} else {
			throw new InvalidArgumentException( "Invalid execution mode: {$mode}. Supported modes are: create-new-site, apply-to-existing-site" );
		}
	}

	$targetSiteRoot         = $options['site-path'];
	if ( $options['truncate-new-site-directory'] ) {
		if ( $options['mode'] !== 'create-new-site' ) {
			throw new InvalidArgumentException( "--truncate-new-site-directory can only be used with --mode=create-new-site" );
		}
		$absoluteTargetSiteRoot = realpath( $targetSiteRoot );
		if ( false === $absoluteTargetSiteRoot) {
			mkdir( $targetSiteRoot, 0755, true );
		} else if( is_dir( $absoluteTargetSiteRoot ) ) {
			$fs = LocalFilesystem::create( $absoluteTargetSiteRoot );
			$fs->rmdir( '/', [ 'recursive' => true ] );
			$fs->mkdir( '/', [ 'chmod' => 0755 ] );
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

function reportProgress( $progress, $caption ) {
	static $lastLength = 0;
	static $columns = null;
	$output        = sprintf( "[%3d%%] %s", $progress, $caption );
	$currentLength = strlen( $output );

	// Get terminal width if possible
	if ( null === $columns ) {
		if ( function_exists( 'exec' ) && false !== exec( 'tput cols 2>/dev/null', $out ) ) {
			$columns = (int) $out[0];
		} elseif ( function_exists( 'shell_exec' ) && ( $shellColumns = shell_exec( 'tput cols 2>/dev/null' ) ) ) {
			$columns = (int) $shellColumns;
		}
		if ( null === $columns ) {
			$columns = 80;
		}
	}

	// Truncate if longer than terminal width
	if ( $currentLength > $columns - 1 ) {
		$output        = substr( $output, 0, $columns - 4 ) . '...';
		$currentLength = $columns - 1;
	}

	fprintf( STDERR, "\r%s%s", $output, $currentLength < $lastLength ? str_repeat( ' ', $lastLength - $currentLength ) : '' );
	$lastLength = $currentLength;
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
		echo "\033[31mError:\033[0m Unknown command '$commandArg'.\n\n";
		showGeneralHelpMessage( $commandConfigurations );
		exit( 1 );
	}
	
	// Parse command arguments and options
	$commandArgv = array_slice( $_SERVER['argv'], 2 ); // Skip "php script.php command"
	[ $positionalArgs, $options ] = CLI::parseCommandArgsAndOptions( $commandArgv, $commandConfigurations[ $command ]['options'] );
	
	// Dispatch to appropriate command handler
	switch ( $command ) {
		case 'exec':
			handleExecCommand( $positionalArgs, $options, $commandConfigurations[ $command ] );
			break;
		case 'help':
			handleHelpCommand( $positionalArgs, $options, $commandConfigurations );
			break;
		default:
			echo "\033[31mError:\033[0m Command handler not implemented for '$command'.\n";
			exit( 1 );
	}
} catch ( BlueprintExecutionException $ex ) {
	echo PHP_EOL;
	if ( ! $ex->schemaError ) {
		echo sprintf( "\033[31mError:\033[0m %s\n", $ex->getMessage() );
		while ( $ex->getPrevious() ) {
			$ex = $ex->getPrevious();
			echo sprintf( "\033[31mCaused by:\033[0m %s\n", $ex->getMessage() );
		}
		exit( 1 );
	}

	echo sprintf( "\033[31mError:\033[0m %s See the validation errors below:\n", $ex->getMessage() );
	$lastPrettyPath = '';
	$currentError   = $ex->schemaError;
	while ( $currentError ) {
		$prettyPath = $currentError->getPrettyPath();
		if ( $prettyPath !== $lastPrettyPath ) {
			echo sprintf( "\033[31m%s\033[0m: \n", $prettyPath );
		}
		echo $currentError->message . PHP_EOL;
		$currentError   = $currentError->getMostProbableCause();
		$lastPrettyPath = $prettyPath;
	}
	exit( 1 );
} catch ( Exception $ex ) {
	echo sprintf( "\033[31mError:\033[0m %s\n", $ex->getMessage() );
	echo "Try 'help' for usage.\n";
	exit( 1 );
}
