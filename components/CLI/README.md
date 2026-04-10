# CLI

A POSIX-style command-line argument parser for PHP. It handles long options (`--verbose`), short options (`-v`), bundled short options (`-abc`), inline values (`--port=8080`, `-p=8080`), and positional arguments -- all in a single static method call with no external dependencies.

## Installation

```bash
composer require wp-php-toolkit/cli
```

## Quick Start

```php
use WordPress\CLI\CLI;

$option_defs = array(
    'output' => array( 'o', true,  null,  'Output file path' ),
    'force'  => array( 'f', false, false, 'Overwrite existing files' ),
);

$argv = array( '--output', '/tmp/result.txt', '-f', 'input.json' );

list( $positionals, $options ) = CLI::parse_command_args_and_options( $argv, $option_defs );

// $positionals = array( 'input.json' )
// $options     = array( 'output' => '/tmp/result.txt', 'force' => true )
```

## Usage

### Defining Options

Each option is defined as an entry in an associative array. The key is the long option name, and the value is a four-element array:

```php
$option_defs = array(
    // 'long-name' => array( short, hasValue, default, description )
    'site-url'  => array( 'u',  true,  null,  'Public site URL' ),
    'site-path' => array( null, true,  null,  'Target directory (no short form)' ),
    'help'      => array( 'h',  false, false, 'Show help message' ),
    'verbose'   => array( 'v',  false, false, 'Enable verbose output' ),
);
```

| Element   | Type           | Meaning                                              |
|-----------|----------------|------------------------------------------------------|
| `short`   | `string\|null` | Single-character short alias, or `null` for none      |
| `hasValue`| `bool`         | `true` if the option takes a value, `false` for flags |
| `default` | `mixed`        | Default value when the option is not provided         |
| `description` | `string`   | Human-readable description (for help text)            |

### Long Options

Long options can be passed with `=` or as a separate argument:

```php
$option_defs = array(
    'port' => array( 'p', true, '3000', 'Server port' ),
);

// These are equivalent:
// --port=8080
// --port 8080

$argv = array( '--port=8080' );
list( $positionals, $options ) = CLI::parse_command_args_and_options( $argv, $option_defs );
// $options['port'] === '8080'
```

### Short Options

Short options work the same way as long options. Boolean flags can be bundled:

```php
$option_defs = array(
    'all'     => array( 'a', false, false, 'Process all items' ),
    'force'   => array( 'f', false, false, 'Force overwrite' ),
    'verbose' => array( 'v', false, false, 'Verbose output' ),
    'output'  => array( 'o', true,  null,  'Output path' ),
);

// Bundle boolean flags: -afv is the same as -a -f -v
$argv = array( '-afv' );
list( $positionals, $options ) = CLI::parse_command_args_and_options( $argv, $option_defs );
// $options['all']     === true
// $options['force']   === true
// $options['verbose'] === true

// A value-bearing short option can appear at the end of a bundle:
$argv = array( '-afo', '/tmp/out.txt' );
list( $positionals, $options ) = CLI::parse_command_args_and_options( $argv, $option_defs );
// $options['all']    === true
// $options['force']  === true
// $options['output'] === '/tmp/out.txt'
```

### Positional Arguments

Any argument that is not an option or an option value is collected as a positional argument:

```php
$option_defs = array(
    'help' => array( 'h', false, false, 'Show help' ),
);

$argv = array( 'blueprint.json', '-h', 'extra-arg' );
list( $positionals, $options ) = CLI::parse_command_args_and_options( $argv, $option_defs );
// $positionals = array( 'blueprint.json', 'extra-arg' )
// $options['help'] === true
```

### Error Handling

The parser throws `InvalidArgumentException` for unknown options or missing required values:

```php
use InvalidArgumentException;

$option_defs = array(
    'port' => array( 'p', true, null, 'Server port' ),
);

try {
    $argv = array( '--unknown' );
    CLI::parse_command_args_and_options( $argv, $option_defs );
} catch ( InvalidArgumentException $e ) {
    // "Unknown option --unknown"
}

try {
    $argv = array( '--port' ); // missing value
    CLI::parse_command_args_and_options( $argv, $option_defs );
} catch ( InvalidArgumentException $e ) {
    // "Option --port requires a value"
}
```

## API Reference

### `CLI` (class)

| Method | Description |
|--------|-------------|
| `CLI::parse_command_args_and_options( array $argv, array $option_defs ): array` | Parses CLI arguments and returns `array( $positionals, $options )`. |

**Parameters:**

- `$argv` -- Array of command-line arguments (typically `array_slice( $argv, 1 )` to skip the script name).
- `$option_defs` -- Associative array of option definitions. Each key is a long option name and each value is `array( $short, $has_value, $default, $description )`.

**Returns:** A two-element array: `array( $positionals, $options )` where `$positionals` is a list of non-option arguments and `$options` is an associative array of option values.

**Throws:** `InvalidArgumentException` for unknown options or missing values.

## Requirements

- PHP 7.2+
- No external dependencies
