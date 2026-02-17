# AGENTS.md – PHP Toolkit

## What this is

A monorepo of standalone PHP libraries for WordPress and general PHP projects.
Each component lives in `components/<Name>/` and is independently publishable
to Packagist under `wp-php-toolkit/<name>`. Some components are also bundled
into WordPress plugins under `plugins/`.

Upstream: https://github.com/WordPress/php-toolkit
Branch: `trunk` (not `main`)

## Commands

```bash
# Install dependencies (required before anything else)
composer install

# Run all tests – two suites: fast component tests first, slow Blueprints tests second
composer test

# Run tests for a single component
vendor/bin/phpunit components/Zip/Tests/

# Run a single test class
vendor/bin/phpunit components/Zip/Tests/ZipEncoderTest.php

# Lint
composer lint

# Auto-fix lint errors
composer lint-fix

# Regenerate root composer.json after changing a component's composer.json
php bin/regenerate_composer.json.php
```

CRITICAL: After any code change, run lint AND the relevant component tests at
minimum. If your change touches shared code (Encoding, Polyfill, ByteStream,
Filesystem), run the full test suite.

## Component structure

Every component follows the same layout:

```
components/<Name>/
  class-<name>.php          # WordPress naming: class-lowercase-with-dashes.php
  functions.php              # Optional standalone functions
  composer.json              # Per-component, with inter-component dependencies
  Tests/
    <Name>Test.php           # PHPUnit test classes (no WordPress naming here)
    fixtures/                # Test data
  vendor-patched/            # Vendored dependencies (rare, checked in)
```

Namespace: `WordPress\<Name>` (e.g., `WordPress\Zip\ZipEncoder`).
Autoloading: classmap-based, NOT PSR-4 (despite using namespaces).

## PHP requirements – CRITICAL

This project MUST run on PHP 7.2 through 8.3. This means:

- No typed properties (PHP 7.4+)
- No union types (PHP 8.0+)
- No named arguments (PHP 8.0+)
- No enums (PHP 8.1+)
- No `readonly` (PHP 8.1+)
- No `match` expressions (PHP 8.0+)
- No arrow functions `fn()` (PHP 7.4+)
- No null coalescing assignment `??=` (PHP 7.4+)
- Use `array()` or `[]` — both are fine, but existing code mostly uses `array()`
  for multi-line and `[]` for inline

If you aren't sure whether a feature is available in PHP 7.2, don't use it.
The CI matrix tests PHP 7.2, 7.3, 7.4, 8.0, 8.1, 8.2, and 8.3 on Linux,
macOS, and Windows.

## Zero external dependencies

This is a hard constraint. Components MUST NOT require PHP extensions beyond
`json` and `mbstring`. No `libxml2`, no `curl`, no `libzip`, no `sqlite3`
(sqlite3 is a dev-only dependency for tests). No Composer packages beyond
other `wp-php-toolkit/*` components, except rare vendored exceptions already
checked into `vendor-patched/`.

If you need functionality provided by a PHP extension, implement it in pure PHP.
That's the whole point of this project.

## Coding conventions

WordPress coding standards with strategic divergences:

- **Namespaces**: all components use `namespace WordPress\<Component>`
- **File naming**: `class-lowercase-with-dashes.php` (WordPress convention)
- **Spacing**: tabs for indentation, spaces inside parentheses per WordPress
  style: `function_name( $arg1, $arg2 )`
- **Variables**: `$snake_case` (NOT `$camelCase`)
- **Methods and functions**: `snake_case()` (NOT `camelCase()`)
- **Classes**: `PascalCase`
- **Constants**: `UPPER_SNAKE_CASE`
- **Test classes**: `PascalCaseTest extends TestCase` (PHPUnit convention, not
  WordPress naming)

The linter enforces most of this. When in doubt, match the surrounding code.

## Architecture – the single-class philosophy

The architectural role model is `WP_HTML_Processor` — a single class that does
one thing well. Avoid deep class hierarchies, abstract factories, or patterns
like AbstractSingletonFactoryProxy. When a component needs multiple classes,
that's fine, but keep the API surface small and the class count low.

Components are designed to be re-entrant: they can start, stop, and resume
processing. Many operate as streaming processors where you feed data in and
get results incrementally.

## Common pitfalls

- **Do not add `declare(strict_types=1)`** to component source files. The test
  files and config files use it, but the library code deliberately doesn't
  because WordPress core doesn't.
- **Do not add type declarations** on parameters or return types that would
  break PHP 7.2 compatibility. `?Type` nullable syntax is fine (PHP 7.1+),
  but `Type|null` union syntax is not (PHP 8.0+).
- **Do not restructure autoloading to PSR-4.** The classmap approach is
  intentional — it matches WordPress core conventions and avoids directory
  depth requirements.
- **Do not add Composer dependencies.** If you think you need a package,
  implement the functionality yourself or discuss it first.
- **Do not use `\n` in expected test output on disk.** The `.gitattributes`
  file normalizes fixtures to LF, but constructing expected output in test
  code should use `"\n"`, not `PHP_EOL`.
- **Do not edit files in `vendor-patched/`.** These are manually vendored and
  patched third-party code. Changes there require careful consideration.
- **Root `composer.json` is generated.** Don't edit it directly for
  dependencies or autoloading — edit the component's `composer.json` and run
  `php bin/regenerate_composer.json.php`.
- **Paths MUST use forward slashes** even on Windows. The Filesystem component
  deliberately uses Unix-style separators everywhere. See README.md for details.
- **Keep tests fast.** The Blueprints test suite is intentionally separated
  because it's slow. Component tests should be quick and self-contained.
- **Do not modify the `plugins/` directory** unless specifically asked. Plugins
  are built from components and have their own build step.

## Test conventions

- Tests live in `components/<Name>/Tests/`
- Test classes extend `PHPUnit\Framework\TestCase`
- Use `@before` and `@after` annotations for setup/teardown (not `setUp`/
  `tearDown` methods, following the existing pattern)
- Test fixtures go in `components/<Name>/Tests/fixtures/`
- Tests MUST pass on PHP 7.2 — use `yoast/phpunit-polyfills` for compatibility
  between PHPUnit versions

## Verification

To verify changes work end-to-end (not just unit tests), use WordPress
Playground. The `/wordpress-playground` skill starts a local WordPress instance
where you can install plugins from this repo and test behavior from a user
perspective. This is especially useful for the `plugins/` directory.

## Sandbox

A Docker-based sandbox isolates agent-built code from the host. The container
runs with no network access, a read-only root filesystem, and all Linux
capabilities dropped — code can only write to the mounted project directory
and `/tmp`.

```bash
# Build the sandbox image (first time only, or after Dockerfile changes)
docker compose build

# Run the full test suite inside the sandbox
docker compose run --rm sandbox

# Run tests for a single component
docker compose run --rm sandbox vendor/bin/phpunit components/Zip/Tests/

# Run the linter
docker compose run --rm sandbox vendor/bin/phpcs -d memory_limit=1G .

# Auto-fix lint errors
docker compose run --rm sandbox vendor/bin/phpcbf -d memory_limit=1G .

# Open a shell for interactive debugging
docker compose run --rm sandbox bash
```

The container uses PHP 8.1 (matching the lint CI). Source files are bind-mounted
so edits on the host are immediately visible inside the container.

Without Docker, the test suite still works directly on the host — it's fully
self-contained (no database, no web server, no external services). Tests create
temp files in `sys_get_temp_dir()` and clean up after themselves.

For WordPress-level integration testing, use the WordPress Playground skill
which spins up an isolated WordPress instance in-memory.

### Dev Containers

The repo includes a [Dev Containers](https://containers.dev/) spec in
`.devcontainer/devcontainer.json`. This provides a consistent, pre-configured
development environment that works in VS Code, GitHub Codespaces, and any
editor that supports the Dev Containers standard.

To use it:

- **VS Code**: Install the Dev Containers extension, then "Reopen in Container"
- **GitHub Codespaces**: Click "Code > Codespaces > New codespace" on GitHub
- **CLI**: `devcontainer up --workspace-folder .`

The container is built from the same `Dockerfile` used by the sandbox. Composer
dependencies are installed automatically on creation. PHP IntelliSense and
PHPCS linting are pre-configured.
