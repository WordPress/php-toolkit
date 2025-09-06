# CLI

Small helper to parse POSIX‑style CLI options and positionals, used by the Blueprints runner and other tools.

## Problems Solved
- Consistent handling of long (`--opt`), short (`-o`), `--opt=value`, bundled `-abc`, and required values
- Default values and help metadata in one definition array

## Usage
```php
use WordPress\CLI\CLI;

$defs = [
  'site-url'  => ['u', true,  null, 'Public site URL'],
  'site-path' => [null, true,  null, 'Target directory'],
  'help'      => ['h', false, false, 'Show help'],
];

[$args, $opts] = CLI::parse_command_args_and_options(
  ['--site-url=https://mysite.test', '--site-path', '/var/www', '-h', 'blueprint.json'],
  $defs
);

// $args  => ['blueprint.json']
// $opts  => ['site-url' => 'https://mysite.test', 'site-path' => '/var/www', 'help' => true]
```

Throws `InvalidArgumentException` on unknown options or missing values.

