# Blueprints

Declarative automation for provisioning and configuring WordPress sites. A Blueprint is a JSON file describing steps like installing plugins, writing files, importing content, running SQL/PHP, or unzipping assets. The Runner executes these steps locally or against an existing site.

## Problems Solved
- Repeatable site setups without ad‑hoc shell scripts
- Portable demo environments for plugins/themes
- Apply incremental changes to an existing site or create a new one

## Quick Start
```php
use WordPress\Blueprints\Runner;
use WordPress\Blueprints\RunnerConfiguration;

$config = (new RunnerConfiguration())
    ->set_blueprint(__DIR__ . '/blueprint.json')
    ->set_execution_mode(Runner::EXECUTION_MODE_CREATE_NEW_SITE)
    ->set_target_site_url('http://wp.test')
    ->set_database_engine('sqlite');

$runner = new Runner($config);
$runner->run();
```

Minimal `blueprint.json` example:
```json
{
  "$schema": "https://wordpress.org/blueprint/schema-v2.json",
  "steps": [
    { "step": "InstallPlugin", "slug": "hello-dolly" },
    { "step": "SetSiteOptions", "options": { "blogname": "My Site" } }
  ]
}
```

## Highlights
- Rich step library: install/activate plugins & themes, import WXR/media, write/move/copy files, unzip, run SQL/PHP, WP‑CLI integration, etc.
- Data references: local files, URLs, inline content; automatically fetched via `HttpClient` and handled via `Filesystem`/`Zip`.
- Validated schemas and friendly errors.

## Tips
- Use `EXECUTION_MODE_APPLY_TO_EXISTING_SITE` to target an existing WP install
- Pair with `components/CLI` to build custom command wrappers

