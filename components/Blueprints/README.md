# Blueprints

Declarative WordPress site provisioning. Define a site's desired state as a JSON blueprint -- which plugins to install, which options to set, which content to import -- and let the runner execute it. Blueprints can create a new WordPress site from scratch or modify an existing one, making them useful for development environments, demo sites, automated testing, and reproducible WordPress setups.

## Installation

```
composer require wp-php-toolkit/blueprints
```

## Quick Start

Create a new WordPress site from a blueprint JSON file:

```php
use WordPress\Blueprints\Runner;
use WordPress\Blueprints\RunnerConfiguration;
use WordPress\Blueprints\DataReference\AbsoluteLocalPath;

$config = ( new RunnerConfiguration() )
    ->set_execution_mode( Runner::EXECUTION_MODE_CREATE_NEW_SITE )
    ->set_blueprint( new AbsoluteLocalPath( '/path/to/blueprint.json' ) )
    ->set_target_site_root( '/var/www/my-site' )
    ->set_target_site_url( 'http://localhost:8080' )
    ->set_database_engine( 'sqlite' );

$runner = new Runner( $config );
$runner->run();
```

Where `blueprint.json` looks like:

```json
{
    "version": 2,
    "steps": [
        {
            "step": "installPlugin",
            "pluginData": "https://downloads.wordpress.org/plugin/gutenberg.zip"
        },
        {
            "step": "setSiteOptions",
            "options": {
                "blogname": "My Test Site",
                "blogdescription": "Built with Blueprints"
            }
        }
    ]
}
```

## Usage

### Execution modes

Blueprints supports two execution modes:

- **`EXECUTION_MODE_CREATE_NEW_SITE`** -- Downloads WordPress, creates the database, and applies the blueprint steps. Use this for spinning up fresh sites.
- **`EXECUTION_MODE_APPLY_TO_EXISTING_SITE`** -- Applies the blueprint steps to an already-installed WordPress site. Use this for modifying live or staging sites.

```php
use WordPress\Blueprints\Runner;
use WordPress\Blueprints\RunnerConfiguration;
use WordPress\Blueprints\DataReference\AbsoluteLocalPath;

// Apply a blueprint to an existing site
$config = ( new RunnerConfiguration() )
    ->set_execution_mode( Runner::EXECUTION_MODE_APPLY_TO_EXISTING_SITE )
    ->set_blueprint( new AbsoluteLocalPath( '/path/to/blueprint.json' ) )
    ->set_target_site_root( '/var/www/existing-site' )
    ->set_target_site_url( 'http://localhost:8080' )
    ->set_database_engine( 'mysql' )
    ->set_database_credentials( array(
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'user'     => 'wp',
        'password' => 'secret',
        'dbname'   => 'wordpress',
    ) );

$runner = new Runner( $config );
$runner->run();
```

### Blueprint JSON structure

A blueprint is a JSON document with a `version` field and a `steps` array. Each step declares a single operation:

```json
{
    "version": 2,
    "steps": [
        {
            "step": "mkdir",
            "path": "wp-content/custom-dir"
        },
        {
            "step": "writeFiles",
            "files": {
                "wp-content/custom-dir/config.txt": {
                    "data": "inline",
                    "content": "key=value"
                }
            }
        },
        {
            "step": "installPlugin",
            "pluginData": "https://downloads.wordpress.org/plugin/akismet.zip"
        },
        {
            "step": "activatePlugin",
            "plugin": "akismet/akismet.php"
        },
        {
            "step": "installTheme",
            "themeData": "https://downloads.wordpress.org/theme/twentytwentyfour.zip"
        },
        {
            "step": "activateTheme",
            "theme": "twentytwentyfour"
        },
        {
            "step": "setSiteOptions",
            "options": {
                "blogname": "My Site",
                "permalink_structure": "/%postname%/"
            }
        },
        {
            "step": "runPHP",
            "code": "<?php echo 'Hello from Blueprint!';"
        },
        {
            "step": "runSql",
            "sql": "INSERT INTO wp_options (option_name, option_value) VALUES ('custom_opt', 'custom_val');"
        },
        {
            "step": "importContent",
            "content": "https://example.com/export.wxr"
        }
    ]
}
```

### Available steps

| Step | Description |
|------|-------------|
| `mkdir` | Create a directory (supports recursive creation). |
| `writeFiles` | Write files with inline or referenced content. |
| `cp` | Copy files or directories. |
| `mv` | Move or rename files and directories. |
| `rm` | Delete a file. |
| `rmDir` | Delete a directory. |
| `unzip` | Extract a zip archive to a target path. |
| `installPlugin` | Download and install a plugin from a URL or WordPress.org. |
| `activatePlugin` | Activate an installed plugin. |
| `installTheme` | Download and install a theme from a URL or WordPress.org. |
| `activateTheme` | Activate an installed theme. |
| `setSiteOptions` | Set WordPress options (calls `update_option` for each). |
| `defineConstants` | Add `define()` statements to `wp-config.php`. |
| `enableMultisite` | Enable WordPress Multisite. |
| `runPHP` | Execute a PHP script in a subprocess with WordPress loaded. |
| `runSql` | Execute raw SQL queries against the site database. |
| `importContent` | Import WXR content into the site. |
| `importMedia` | Import media files. |
| `wpCLI` | Run a WP-CLI command. |
| `setSiteLanguage` | Set the site language and download language packs. |

### Data references

Blueprint steps that accept file data use a data reference system. References can point to different sources:

```json
{
    "step": "writeFiles",
    "files": {
        "output.txt": {
            "data": "inline",
            "content": "Direct content here"
        }
    }
}
```

```json
{
    "step": "installPlugin",
    "pluginData": "https://downloads.wordpress.org/plugin/gutenberg.zip"
}
```

```json
{
    "step": "installPlugin",
    "pluginData": "./local-plugin.zip"
}
```

### CLI usage

Blueprints ships a CLI tool (packaged as `blueprints.phar`) for command-line execution:

```bash
php blueprints.phar exec --blueprint=blueprint.json --target=/var/www/my-site --url=http://localhost:8080
```

### Tracking progress

Attach a progress observer to receive updates during execution:

```php
use WordPress\Blueprints\ProgressObserver;
use WordPress\Blueprints\Runner;
use WordPress\Blueprints\RunnerConfiguration;

$observer = new ProgressObserver();
$observer->on(
    'progress',
    function ( $event ) {
        echo sprintf(
            "[%d%%] %s\n",
            $event->progress,
            $event->caption
        );
    }
);

$config = ( new RunnerConfiguration() )
    ->set_progress_observer( $observer );
    // ... other configuration ...
```

### Blueprint validation

Validate a blueprint against the JSON schema before executing it:

```php
use WordPress\Blueprints\Validator\HumanFriendlySchemaValidator;

$schema = array(
    'type' => 'object',
    'properties' => array(
        'version' => array( 'type' => 'integer' ),
        'steps'   => array( 'type' => 'array' ),
    ),
    'required' => array( 'version' ),
);

$validator = new HumanFriendlySchemaValidator( $schema );
$error = $validator->validate( json_decode( $blueprint_json ) );

if ( null !== $error ) {
    echo 'Validation failed: ' . $error->get_message();
}
```

## API Reference

### Core classes

| Class | Purpose |
|-------|---------|
| `Runner` | Executes a blueprint. Constructor takes a `RunnerConfiguration`. Call `run()` to execute. |
| `RunnerConfiguration` | Fluent configuration builder. Key methods: `set_blueprint()`, `set_execution_mode()`, `set_target_site_root()`, `set_target_site_url()`, `set_database_engine()`, `set_database_credentials()`, `set_progress_observer()`. |
| `Runtime` | Execution context available to steps. Provides `get_target_filesystem()`, `eval_php_code_in_subprocess()`. |

### Execution mode constants

| Constant | Value |
|----------|-------|
| `Runner::EXECUTION_MODE_CREATE_NEW_SITE` | `'create-new-site'` |
| `Runner::EXECUTION_MODE_APPLY_TO_EXISTING_SITE` | `'apply-to-existing-site'` |

### Data reference classes

| Class | Purpose |
|-------|---------|
| `DataReference` | Factory class. Use `DataReference::create( $value )` to auto-detect the source type. |
| `InlineFile` | Embed file content directly. Constructor takes `array( 'filename' => '...', 'content' => '...' )`. |
| `AbsoluteLocalPath` | Reference a file by its absolute path on disk. |
| `ExecutionContextPath` | Reference a file relative to the blueprint's directory. |
| `URLReference` | Reference a file by URL (downloaded at execution time). |
| `WordPressOrgPlugin` | Reference a plugin on wordpress.org by slug. |
| `WordPressOrgTheme` | Reference a theme on wordpress.org by slug. |

### Validation

| Class | Purpose |
|-------|---------|
| `HumanFriendlySchemaValidator` | Validates data against a JSON Schema. Returns `null` on success or a `ValidationError` on failure. |

## Requirements

- PHP 7.2+
- No external dependencies
