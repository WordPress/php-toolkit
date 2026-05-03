## PHP Toolkit

<!-- docs-site-banner -->
> 📚 **Live, runnable docs:** **<https://wordpress.github.io/php-toolkit/>**
> Every component has a reference page with snippets that execute in WordPress Playground — edit code in your browser, click *Run*, see real output. There's also a short [learning path](https://wordpress.github.io/php-toolkit/learn/).
<!-- /docs-site-banner -->

Standalone, dependency-free PHP libraries for use in WordPress plugins and standalone PHP projects.

### Components

| Component | What it does | Live docs |
| --- | --- | --- |
| [HTML](components/HTML/README.md) | Stream-modify real-world HTML without `libxml2` (mirrors `WP_HTML_Tag_Processor` / `WP_HTML_Processor`). | [reference/html](https://wordpress.github.io/php-toolkit/reference/html.html) |
| [XML](components/XML/README.md) | Stream-parse XML files on any PHP installation — no `libxml2` required. | [reference/xml](https://wordpress.github.io/php-toolkit/reference/xml.html) |
| [Zip](components/Zip/README.md) | Stream-read and stream-write ZIP archives with no `libzip` dependency. | [reference/zip](https://wordpress.github.io/php-toolkit/reference/zip.html) |
| [Markdown](components/Markdown/README.md) | Convert between Markdown and WordPress block markup. | [reference/markdown](https://wordpress.github.io/php-toolkit/reference/markdown.html) |
| [BlockParser](components/BlockParser/README.md) | Parse and serialize WordPress block markup. | [reference/blockparser](https://wordpress.github.io/php-toolkit/reference/blockparser.html) |
| [HttpClient](components/HttpClient/README.md) | Streaming, non-blocking, concurrent HTTP client — no `curl` extension. | [reference/httpclient](https://wordpress.github.io/php-toolkit/reference/httpclient.html) |
| [HttpServer](components/HttpServer/README.md) | Minimal pure-PHP HTTP server primitives. | [reference/httpserver](https://wordpress.github.io/php-toolkit/reference/httpserver.html) |
| [CORSProxy](components/CORSProxy/README.md) | A small CORS proxy for arbitrary HTTP traffic. | [reference/corsproxy](https://wordpress.github.io/php-toolkit/reference/corsproxy.html) |
| [Git](components/Git/README.md) | Pure-PHP Git client and server. | [reference/git](https://wordpress.github.io/php-toolkit/reference/git.html) |
| [Filesystem](components/Filesystem/README.md) | Single API across local files, ZIP, Git, in-memory, etc. | [reference/filesystem](https://wordpress.github.io/php-toolkit/reference/filesystem.html) |
| [ByteStream](components/ByteStream/README.md) | Composable byte streaming utilities — readers, writers, filters. | [reference/bytestream](https://wordpress.github.io/php-toolkit/reference/bytestream.html) |
| [DataLiberation](components/DataLiberation/README.md) | Streaming data importers for WordPress (WXR, zipped Markdown, remote git, URL rewriting…). | [reference/dataliberation](https://wordpress.github.io/php-toolkit/reference/dataliberation.html) |
| [Encoding](components/Encoding/README.md) | Encoding-detection and conversion helpers. | [reference/encoding](https://wordpress.github.io/php-toolkit/reference/encoding.html) |
| [Merge](components/Merge/README.md) | Three-way text and structural merge primitives. | [reference/merge](https://wordpress.github.io/php-toolkit/reference/merge.html) |
| [Polyfill](components/Polyfill/README.md) | PHP 8.0 string functions and minimal WordPress stubs (hooks, escaping, `WP_Error`). | [reference/polyfill](https://wordpress.github.io/php-toolkit/reference/polyfill.html) |
| [CLI](components/CLI/README.md) | Helpers for building friendly PHP command-line tools. | [reference/cli](https://wordpress.github.io/php-toolkit/reference/cli.html) |
| [Blueprints](components/Blueprints/README.md) | Reproducible WordPress environment setup (the runtime behind `blueprints.phar`). | [reference/blueprints](https://wordpress.github.io/php-toolkit/reference/blueprints.html) |
| [ToolkitCodingStandards](components/ToolkitCodingStandards/README.md) | Shared PHPCS ruleset used across the toolkit. | [reference/coding-standards](https://wordpress.github.io/php-toolkit/reference/coding-standards.html) |

> **Looking for code samples?** Prefer the [live docs](https://wordpress.github.io/php-toolkit/) — every snippet there is executable in-browser and is verified against `trunk` in CI on every PR.

### Using the Blueprints v2 runner

The Blueprints v2 runner is an all-php CLI tool that runs Blueprints v1 and v2. To use it, download [blueprints.phar from the latest release](https://github.com/WordPress/php-toolkit/releases) and run it:

```sh
php blueprints.phar
```

From there, follow the help message for required arguments and options.

If you want to use Blueprints as a library, you absolutely can. It is designed to be reusable,
compatible with web and CLI environments on PHP 7.2+. There's not much technical documentation
at this point but you can refer to the [blueprints.php file](https://github.com/WordPress/php-toolkit/blob/219dc4e846af270a5009e523244d0ec23baaa32a/components/Blueprints/bin/blueprint.php#L226) to see
how the runner is implemented.

### Using the components

Each component is independently published on Packagist under [`wp-php-toolkit/*`](https://packagist.org/packages/wp-php-toolkit/). Install only the pieces you need:

```bash
composer require wp-php-toolkit/http-client
composer require wp-php-toolkit/data-liberation
composer require wp-php-toolkit/git
# ...
```

Each Packagist page links back to its component's reference page so you can browse runnable examples without leaving the package listing.

#### PHAR distribution

For convenience, a standalone Blueprints runner and other tools from this repository are shipped as phar files available in the [GitHub releases](https://github.com/WordPress/php-toolkit/releases).

### Design goals

-   Build re-entrant data tools that can start, stop, resume, tolerate errors, accept alternative media files, posts etc. from the user.
-   WordPress-first – Everything is built in PHP using WordPress coding standards. The divergences are strategic and minimal, such as the use of namespaces.
-   Compatibility – Support for major WordPress versions, PHP version (7.2+), and Playground runtime (web, CLI, browser extension, desktop app, CI etc.).
-   Dependency-free – No PHP extensions are required and only minimal Composer dependencies are allowed when absolutely necessary.
-   Simple – The architectural role model is [WP_HTML_Processor](https://developer.wordpress.org/reference/classes/wp_html_processor/) – a **single class** that can parse nearly all HTML. There's no "Node", "Element", "Attribute" classes etc. Let's aim for the same here. Some OOP patterns are used when useful, but we're explicitly avoiding ideas like AbstractSingletonFactoryProxy.
-   Extensibility – Playground should be able to benefit from, say, WASM markdown parser even if core WordPress cannot.
-   Reusability – Each library should be framework-agnostic and usable outside of WordPress.

### Development

#### Dev Container

A [Dev Container](https://containers.dev/) spec is included in `.devcontainer/`.
It provides PHP 8.1 with all the required extensions, Composer, and editor
tooling pre-configured. Works with VS Code ("Reopen in Container"), GitHub
Codespaces, or `devcontainer up --workspace-folder .` from the CLI.

#### Docker sandbox

For running tests and lints in an isolated container without a full dev
environment, use the Docker Compose sandbox:

```sh
# Build once
docker compose build

# Run all tests
docker compose run --rm sandbox vendor/bin/phpunit -c phpunit.xml

# Run tests for one component
docker compose run --rm sandbox vendor/bin/phpunit components/Zip/Tests/

# Run a PHP script
docker compose run --rm sandbox php my-script.php

# Lint
docker compose run --rm sandbox vendor/bin/phpcs -d memory_limit=1G .
```

The sandbox has no network access, a read-only root filesystem, and all Linux
capabilities dropped — the only writable areas are the project mount and `/tmp`.

#### Without Docker

The test suite works directly on the host too — no database, no web server,
no external services needed. You just need PHP 7.2+ with `json` and `mbstring`.

#### Testing

```sh
composer test
```

#### Linting

```sh
composer lint
```

To fix the linting errors, run:

```sh
composer lint-fix
```

#### Building the docs site

The docs site under `docs/` is generated from `bin/_docs_components/<slug>.md`. To rebuild and preview locally:

```sh
bash bin/build-docs-bundle.sh    # bundles toolkit + regenerates HTML
python3 bin/serve-docs.py        # opens http://localhost:8787
```

Snippets in the markdown sources run in CI on every PR (see `.github/workflows/snippet-tests.yml`) and in WordPress Playground from the live site.

### Windows compatibility

Windows compatibility is achieved on a few different fronts:

#### Newlines

This repository comes with a `.gitattributes` file to ensure that the unit test
files and fixtures are normalized to `\n` on checkout. It's important, because
Windows uses `\r\n` for newlines in text files. Unix-based systems use `\n`.
Without the `.gitattributes`, git on Windows would replace all the `\n` with `\r\n` 
on checkout.

The strings produced by the library uses `\n` for newlines where it can make
that choice. For example, the `WXRWriter` class will separate XML tags with
`\n` newlines to make sure the generated XML is consistent across platforms.

#### Paths

The `Filesystem` components makes a point of using Unix-style forward slashes
as directory separators, even on Windows.

As a library consumer, ensure all the local paths you pass to the library are
using Unix-style forward slashes as directory separators. A simple str_replace
will do the trick:

```php
if (DIRECTORY_SEPARATOR === '\\') {
	$path = str_replace('\\', '/', $path);
}
```

The reason for using Unix-style forward slashes is care for data integrity.
Windows understands both forward slashes and backslashes, so the replacement
operation is safe there. On Unix, however, a backslash can be used as a part
of a filename so it cannot be safely translated.

Importantly, do not just run this str_replace() on every possible path.
`C:\my-dir\my-file.txt` is both, a valid Windows absolute path and a valid Unix
filename and a relative path. Furthermore, `Filesystem` supports more filesystems
than just local disk.

Anytime you're handling paths, consider:

-   Which filesystem is this path related to? Local? Remote? Git?
-   Which OS are you on? Windows? Unix?

If the answers are "local" and "Windows", you may need to apply the `str_replace()`
slash normalization. Otherwise, just keep the path as it is.

The takeaway from this section is: **paths are difficult**.

For a fun read on the topic, check out this article: [Windows File Paths](https://www.fileside.app/blog/2023-03-17_windows-file-paths/).
