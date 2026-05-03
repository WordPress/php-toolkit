---
slug: blueprints
title: Blueprints
install: wp-php-toolkit/blueprints

see_also: filesystem | Filesystem | Prepare files and fixtures before applying site setup steps.
see_also: httpclient | HttpClient | Download packages or source data as part of provisioning workflows.
see_also: cli | CLI | Wrap repeatable blueprint operations in a small command.
---

Declarative WordPress site provisioning. Write a JSON description of plugins, options, and content; let the runner execute it.

## Why this exists

<p>A WordPress environment is more than a database dump. It can require a specific core version, plugins, themes, site options, uploaded files, content, and setup steps. Rebuilding that by hand makes demos, tests, bug reports, workshops, and CI fixtures drift over time.</p>

<p>The Blueprints component treats site setup as data. A blueprint JSON document describes the desired steps, and the runner applies them to either a new WordPress install or an existing one. The validator exists because user-authored JSON needs clear, path-specific errors rather than generic schema failures.</p>

<p><code>RunnerConfiguration</code> separates the web root from the WordPress core directory, since real hosts often put them in different places. Both paths are explicit on the runner, never inferred.</p>

<p>Blueprints can <em>create</em> a new WordPress install (download core, set up the database, apply steps) or <em>apply to an existing</em> site. Creating a fresh install needs filesystem access this in-browser runtime doesn't have, so the runnable snippets focus on <code>APPLY_TO_EXISTING_SITE</code>.</p>

## Configure a runner for an existing site

<p><code>RunnerConfiguration</code> is a fluent builder. The minimum: target site root, target site URL, execution mode.</p>

<!-- snippet:
filename: configure.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use WordPress\Blueprints\Runner;
use WordPress\Blueprints\RunnerConfiguration;

$config = ( new RunnerConfiguration() )
	->set_execution_mode( Runner::EXECUTION_MODE_APPLY_TO_EXISTING_SITE )
	->set_target_site_root( '/wordpress' )
	->set_target_site_url( 'http://playground.test/' );

echo "mode: " . $config->get_execution_mode() . "\n";
echo "root: " . $config->get_target_site_root() . "\n";
echo "url:  " . $config->get_target_site_url() . "\n";
```

<!-- expected-output -->
```
mode: apply-to-existing-site
root: /wordpress
url:  http://playground.test/
```

## Generate blueprint JSON from PHP

<p>CI jobs and tests stay clearer when PHP builds the blueprint from data instead of hand-writing JSON. Keep the structure plain: <code>version</code>, then a list of step arrays.</p>

<!-- snippet:
filename: build-json.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

$site_name = 'Demo Site';
$plugins   = array( 'gutenberg', 'classic-editor' );

$blueprint = array(
	'version' => 2,
	'steps'   => array(
		array(
			'step'    => 'setSiteOptions',
			'options' => array(
				'blogname'              => $site_name,
				'permalink_structure'   => '/%postname%/',
				'show_on_front'         => 'page',
			),
		),
	),
);

foreach ( $plugins as $slug ) {
	$blueprint['steps'][] = array(
		'step'       => 'installPlugin',
		'pluginData' => "https://downloads.wordpress.org/plugin/{$slug}.zip",
	);
	$blueprint['steps'][] = array(
		'step'   => 'activatePlugin',
		'plugin' => "{$slug}/{$slug}.php",
	);
}

echo json_encode( $blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
```

<!-- expected-output -->
```
{
    "version": 2,
    "steps": [
        {
            "step": "setSiteOptions",
            "options": {
                "blogname": "Demo Site",
                "permalink_structure": "/%postname%/",
                "show_on_front": "page"
            }
        },
        {
            "step": "installPlugin",
            "pluginData": "https://downloads.wordpress.org/plugin/gutenberg.zip"
        },
        {
            "step": "activatePlugin",
            "plugin": "gutenberg/gutenberg.php"
        },
        {
            "step": "installPlugin",
            "pluginData": "https://downloads.wordpress.org/plugin/classic-editor.zip"
        },
        {
            "step": "activatePlugin",
            "plugin": "classic-editor/classic-editor.php"
        }
    ]
}
```

## Validate before running

<p>The schema validator returns a human-readable <code>ValidationError</code> instead of a generic "does not match schema" failure. Use it before handing user-authored JSON to a runner.</p>

<!-- snippet:
filename: validate.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use WordPress\Blueprints\Validator\HumanFriendlySchemaValidator;

$schema = array(
	'type'       => 'object',
	'required'   => array( 'version', 'steps' ),
	'properties' => array(
		'version' => array( 'type' => 'integer' ),
		'steps'   => array(
			'type'  => 'array',
			'items' => array(
				'type'       => 'object',
				'required'   => array( 'step' ),
				'properties' => array(
					'step' => array( 'type' => 'string' ),
				),
			),
		),
	),
);

$blueprint = array(
	'version' => 2,
	'steps'   => array(
		array( 'pluginData' => 'https://downloads.wordpress.org/plugin/gutenberg.zip' ),
	),
);

$error = ( new HumanFriendlySchemaValidator( $schema ) )->validate( $blueprint );
if ( null === $error ) {
	echo "valid\n";
} else {
	echo $error->get_pretty_path() . ": " . $error->message . "\n";
}
```

<!-- expected-output -->
```
Blueprint root["steps"][0]: Missing required field: step.
```

## The Blueprint JSON shape

<p>A blueprint is a JSON document with a <code>version</code> field and a <code>steps</code> array. Each step has a <code>"step"</code> discriminator and step-specific fields. This is the same shape used by <a href="https://playground.wordpress.net/">WordPress Playground</a>.</p>

<pre><code>{
  "version": 2,
  "steps": [
    { "step": "setSiteOptions",
      "options": {
        "blogname": "Demo Site",
        "permalink_structure": "/%postname%/"
      } },
    { "step": "installPlugin",
      "pluginData": "https://downloads.wordpress.org/plugin/gutenberg.zip" },
    { "step": "activatePlugin",
      "plugin": "gutenberg/gutenberg.php" }
  ]
}</code></pre>
