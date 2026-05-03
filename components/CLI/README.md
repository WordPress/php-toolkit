---
slug: cli
title: CLI
install: wp-php-toolkit/cli

see_also:
  - filesystem | Filesystem | Keep command behavior testable with in-memory storage.
  - blueprints | Blueprints | Build repeatable site setup commands around parsed options.
  - httpserver | HttpServer | Add a local web UI to a CLI workflow.
---

POSIX-style argument parser. Long options, short bundles, inline values, positional args — one static call.

## Why this exists

<p>Real CLI tools in PHP usually mean either pulling in <code>symfony/console</code> (and the transitive dependencies that come with it) or hand-rolling argv parsing that breaks the first time someone writes <code>-vvv</code> or <code>--port=8080</code>. The toolkit's <code>CLI</code> class is one static method, no dependencies, and handles the POSIX shapes you actually see.</p>

## Parse a single flag

<p>The smallest useful invocation: one boolean flag, one positional. Each option is a four-tuple of <code>[ short, has_value, default, description ]</code>.</p>

<!-- snippet:
filename: parse-flag.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use WordPress\CLI\CLI;

$option_defs = array(
	'verbose' => array( 'v', false, false, 'Enable verbose output' ),
);

list( $positionals, $options ) = CLI::parse_command_args_and_options(
	array( '-v', 'input.txt' ),
	$option_defs
);

echo "verbose: " . ( $options['verbose'] ? 'yes' : 'no' ) . "\n";
echo "input:   " . $positionals[0] . "\n";
```

<!-- expected-output -->
```
verbose: yes
input:   input.txt
```

## Mix values, flags, and bundles

<p>The parser accepts <code>--port 8080</code>, <code>--port=8080</code>, <code>-p 8080</code>, and <code>-p=8080</code>. It also expands bundled boolean shorts such as <code>-afv</code>.</p>

<!-- snippet:
filename: mix-shapes.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use WordPress\CLI\CLI;

$option_defs = array(
	'all'     => array( 'a', false, false, 'Process everything' ),
	'force'   => array( 'f', false, false, 'Overwrite existing files' ),
	'verbose' => array( 'v', false, false, 'Verbose output' ),
	'output'  => array( 'o', true,  null,  'Output path' ),
	'port'    => array( 'p', true,  '3000', 'Server port' ),
);

$argv = array( '-afv', '--port=8080', '-o', '/tmp/result.txt', 'input.json' );
list( $positionals, $options ) = CLI::parse_command_args_and_options( $argv, $option_defs );

echo "input:   " . $positionals[0] . "\n";
echo "flags:   " . implode( ', ', array_keys( array_filter( array(
	'all'     => $options['all'],
	'force'   => $options['force'],
	'verbose' => $options['verbose'],
) ) ) ) . "\n";
echo "output:  " . $options['output'] . "\n";
echo "port:    " . $options['port'] . "\n";
```

<!-- expected-output -->
```
input:   input.json
flags:   all, force, verbose
output:  /tmp/<tempfile>.txt
port:    8080
```

## Validate required options

<p>The parser fills in defaults but never enforces "required". Check for <code>null</code> after parsing — full control over the error message.</p>

<!-- snippet:
filename: require-options.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use WordPress\CLI\CLI;

$option_defs = array(
	'site-url'  => array( 'u', true, null, 'Public site URL (required)' ),
	'site-path' => array( null, true, null, 'Target directory (required)' ),
);

$argv = array( '--site-url', 'https://mysite.test' );

try {
	list( , $options ) = CLI::parse_command_args_and_options( $argv, $option_defs );
	foreach ( array( 'site-url', 'site-path' ) as $name ) {
		if ( null === $options[ $name ] ) {
			throw new RuntimeException( "Missing required option --{$name}" );
		}
	}
	echo "All good.\n";
} catch ( Exception $e ) {
	echo "error: " . $e->getMessage() . "\n";
}
```

<!-- expected-output -->
```
error: Missing required option --site-path
```

## Generate --help from definitions

<p>Because each option carries its own description, you can render help text by walking the same definitions you parse with. No second source of truth.</p>

<!-- snippet:
filename: help-text.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use WordPress\CLI\CLI;

$option_defs = array(
	'output'  => array( 'o', true,  null,  'Write result to FILE' ),
	'force'   => array( 'f', false, false, 'Overwrite existing files' ),
	'verbose' => array( 'v', false, false, 'Verbose output' ),
	'help'    => array( 'h', false, false, 'Show this help and exit' ),
);

function render_help( array $defs ) {
	echo "Usage: mytool [options] <input>\n\nOptions:\n";
	foreach ( $defs as $long => $def ) {
		list( $short, $has_value, $default, $desc ) = $def;
		$flag = ( $short ? "-{$short}, " : '    ' ) . "--{$long}";
		if ( $has_value ) $flag .= '=VALUE';
		echo sprintf( "  %-28s %s\n", $flag, $desc );
	}
}

list( , $options ) = CLI::parse_command_args_and_options( array( '-h' ), $option_defs );
if ( $options['help'] ) render_help( $option_defs );
```

<!-- expected-output -->
```
Usage: mytool [options] <input>

Options:
  -o, --output=VALUE           Write result to FILE
  -f, --force                  Overwrite existing files
  -v, --verbose                Verbose output
  -h, --help                   Show this help and exit
```

## Git-style subcommands

<p>To build a tool with subcommands like <code>mytool deploy</code>, peel the first positional off <code>argv</code>, dispatch, and parse the rest with a per-command option set.</p>

<!-- snippet:
filename: subcommands.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use WordPress\CLI\CLI;

$commands = array(
	'deploy' => array(
		'env'     => array( 'e', true, 'staging', 'Target environment' ),
		'dry-run' => array( 'n', false, false, 'Preview without applying' ),
	),
	'rollback' => array(
		'to' => array( 't', true, null, 'Revision to roll back to' ),
	),
);

function run( array $argv, array $commands ) {
	if ( empty( $argv ) ) {
		echo "Usage: mytool <command> [options]\nCommands: " . implode( ', ', array_keys( $commands ) ) . "\n";
		return;
	}
	$command = array_shift( $argv );
	if ( ! isset( $commands[ $command ] ) ) {
		echo "Unknown command: {$command}\n";
		return;
	}
	list( $positionals, $options ) = CLI::parse_command_args_and_options( $argv, $commands[ $command ] );
	echo "command={$command}\n";
	echo "options: " . json_encode( $options ) . "\n";
	echo "positionals: " . json_encode( $positionals ) . "\n";
}

run( array( 'deploy', '--env=production', '-n', 'web-01', 'web-02' ), $commands );
echo "---\n";
run( array( 'rollback', '-t', 'abc123' ), $commands );
```

<!-- expected-output -->
```
command=deploy
options: {"env":"production","dry-run":true}
positionals: ["web-01","web-02"]
---
command=rollback
options: {"to":"abc123"}
positionals: []
```
