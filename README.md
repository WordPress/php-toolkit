# WordPress Blueprints

Blueprints are JSON files used to create WordPress sites with specific settings, themes, plugins, content, and anything
else that can be configured in WordPress. Here's what a Blueprint looks like:

```json
{
  "plugins": [
    "akismet",
    "gutenberg"
  ],
  "themes": [
    "twentynineteen"
  ],
  "settings": {
    "blogname": "My Blog",
    "blogdescription": "Just another WordPress site",
    "permalink_structure": "/%postname%/"
  },
  "constants": {
    "WP_DEBUG": true,
    "WP_DEBUG_LOG": true
  },
  "steps": [
    {
      "step": "runPHP",
      "content": "<?php require 'wp-load.php'; update_user_meta(1, 'test', 'value');"
    }
  ]
}
```

See the
original [Getting started with Blueprints v1](https://wordpress.github.io/wordpress-playground/blueprints-api/index)
page for more information.

## Let's build Version 2 of Blueprints together!

Blueprints were initially built in TypeScript
for [WordPress Playground](https://github.com/WordPress/wordpress-playground/),
However, they [quickly proved useful for
WordPress in general](https://github.com/WordPress/wordpress-playground/issues/1025).

This repository explores
a [PHP-based version 2 of Blueprints](https://github.com/WordPress/wordpress-playground/issues/1025) that can be
used in any environment, be it a browser, Node.js, wp-cli, or a native PHP application. The plan is to create a robust,
useful tool that will eventually be merged into WordPress core.

Your feedback is not just welcome, but essential to the success of this project. Please:

* Share your thoughts and ideas in the [Blueprints v2 Specification](https://github.com/WordPress/blueprints/issues/6)
  issue – or any other issue that interests you
* Start new discussions
* Propose changes through comments and pull requests

Your input and code contributions will help shape the future of Blueprints in WordPress.
in discussions.

# Technical bits

## Setting Up the Project Locally

To set up the WordPress/blueprints project locally, here's a few useful commands:

Install [composer](https://getcomposer.org/). Once you have it, install the required dependencies via

```shell
composer install
```

### Run tests with

```shell
composer test
```

### Run Blueprints in a variety of ways

#### using the PHP Blueprint builder:

```shell
 php examples/blueprint_compiling.php
```

#### using a string containing a Blueprint (in JSON):

```shell
 php examples/json_string_compiling.php
```

### Regenerate models files from JSON schema with

```shell
composer global require jane-php/json-schema
php src/WordPress/Blueprints/bin/autogenerate_models.php
```

## Building to .phar

The Blueprints library is distributed as a .phar library. To build the .phar file, install box:

```shell
composer global require humbug/box
```

And then run:

```shell
rm composer.lock
rm -rf vendor
COMPOSER=composer-web.json composer install --no-dev
rm -rf vendor/pimple/pimple/ext/
rm -rf vendor/symfony/*/*.md
rm -rf vendor/symfony/*/composer.json
rm -rf vendor/symfony/*/*.dist
rm -rf vendor/*/*/LICENSE
box compile
```

Note that in box.json, the `"check-requirements"` option is set to `false`. Somehow, keeping it as `true` results in a
.phar file
that breaks HTTP requests in Playground. @TODO: Investigate why this is the case.

To try the built .phar file, run:

```shell
rm -rf new-wp/* && USE_PHAR=1 php blueprint_compiling.php
```

## Coding Standards

This project uses
the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/) and
PHPCS to enforce them. To check the code for compliance, run:

```shell
composer run-script phpcs
```

To automatically fix the code to comply with the standards, run:

```shell
composer run-script phpcbf
composer run-script phpcs-fix
```

## PHP 7.0 Compatibility

This project is compatible with PHP >= 7.0.

Part of the process is automated with [rector](https://github.com/rectorphp/rector),
which transpiles the features added in PHP 7.2 and later to PHP 7.1. Unfortunately,
that's as far as Rector goes.

From there, manual transformations are required to bring the compatibility further down to PHP 7.0.

### Automated part

To transpile the code to PHP 7.1, run:

```bash
# Install rector:
composer require rector/rector --ignore-platform-req=php

# Transpile:
php vendor/bin/rector process src
```

Unfortunately, Rector does not support downgrading to PHP 7.0 yet, so we need to do the
last stretch manually.

### Manual part

Rector will downgrade PHP code to PHP 7.1 but not further. We need PHP 7.0 compat
so here's a few additional regexps to run. Regexps are not, of course, reliable in
the general case, but they seem to do the trick here.

List of manual replacements

* `: \?[a-zA-Z_0-9]+` -> (empty string) to remove the unsupported return type
  from `function(): ?SchemaResolver {}` -> `function() {}`.
* `: iterable` to fix `Fatal error: Generators may only declare a return type of Generator, Iterator or Traversable`.
* `\?[a-zA-Z_0-9]+ \$` -> `$` to remove the unsupported nullable type from function signatures,
  e.g. `function(?Schema $schema){}` -> `function($schema){}`.
* `(protected|public|private) const` -> `const` as const visibility is not supported in PHP 7.0.
* `: void` -> `` as `void` return type is unsupported in PHP 7.0.

@TODO:

* `[$ns, $name] = $this->parseName($name);` -> `list($ns, $name) = $this->parseName($name);`
* `foreach ($data as [$cp, $chars]) {` -> `foreach ($data as list($cp, $chars)) {`
* Find or write Rector rules for downgrading to PHP 7.0

## License

WordPress Blueprints are open-source software licensed under the GPL.
