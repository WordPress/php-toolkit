# WordPress Native APIs PHP Extension

This package is the first Rust-backed PHP extension surface for the toolkit's
native HTML, XML, and URL-in-text API work. It registers native classes that the public
PHP wrappers can use when the extension is loaded, while preserving PHP-only
fallback behavior when it is unavailable or explicitly disabled.

Public docs and releases:

- User-facing overview: <https://wordpress.github.io/php-toolkit/native-apis.html>
- Playground release index: <https://wordpress.github.io/php-toolkit/wp_native_apis-wasm-extension/>
- Latest Playground smoke test: <https://playground.wordpress.net/?php=8.4&php-extension=https%3A%2F%2Fwordpress.github.io%2Fphp-toolkit%2Fwp_native_apis-wasm-extension%2Flatest%2Fmanifest.json&blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2FWordPress%2Fphp-toolkit%2Ftrunk%2Fextensions%2Fnative-apis%2Fplayground%2Fblueprint.json>

## Native Classes

- `WP_HTML_Native_Tag_Processor`
- `WP_HTML_Native_Processor`
- `WordPress\XML\NativeXMLProcessor`
- `WordPress\DataLiberation\URL\NativeURLInTextProcessor`

## Quick Setup

Use this path when you want to build, load, and verify the extension on a
development machine.

You need:

- PHP CLI with matching development headers and `php-config`.
- Rust and Cargo.
- Clang and libclang for `bindgen`.
- Composer dependencies from the repository root.

On Ubuntu, the GitHub Actions job uses:

```bash
sudo apt-get update
sudo apt-get install -y clang libclang-dev
composer install --prefer-dist --no-progress --no-suggest
```

Build and verify from the repository root:

```bash
extensions/native-apis/build-extension.sh

php -d extension=extensions/native-apis/target/release/libwp_native_apis.so \
	extensions/native-apis/tests/verify-native-apis.php
```

If `php-config` is not on `PATH`, pass it explicitly:

```bash
PHP_CONFIG=/path/to/php-config \
LIBCLANG_PATH=/path/to/libclang/lib \
extensions/native-apis/build-extension.sh
```

Check the Rust parser kernels without PHP development headers:

```bash
cd extensions/native-apis
cargo test
```

Check that public PHP classes progressively resolve to native classes by loading
the extension before the repository bootstrap:

```bash
php -d extension=extensions/native-apis/target/release/libwp_native_apis.so <<'PHP'
<?php
require __DIR__ . '/bootstrap.php';

var_dump( is_subclass_of( 'WP_HTML_Tag_Processor', 'WP_HTML_Native_Tag_Processor' ) );
var_dump( is_subclass_of( 'WordPress\\XML\\XMLProcessor', 'WordPress\\XML\\NativeXMLProcessor' ) );
$p = new WordPress\DataLiberation\URL\URLInTextProcessor( 'Visit example.com/docs.', 'https://wordpress.org' );
var_dump( get_parent_class( $p ) === 'WordPress\\DataLiberation\\URL\\NativeURLInTextProcessorWrapper' );
PHP
```

Force the PHP fallback path for comparison by defining
`WP_NATIVE_APIS_DISABLE_DEFAULTS` before loading the bootstrap:

```bash
php -d extension=extensions/native-apis/target/release/libwp_native_apis.so <<'PHP'
<?php
define( 'WP_NATIVE_APIS_DISABLE_DEFAULTS', true );
require __DIR__ . '/bootstrap.php';

var_dump( is_subclass_of( 'WP_HTML_Tag_Processor', 'WP_HTML_Native_Tag_Processor' ) );
PHP
```

## Public Wrapper Defaults

When the extension is loaded before the PHP components, public wrappers may use
native delegates by default while preserving PHP fallback behavior.

Define `WP_NATIVE_APIS_DISABLE_DEFAULTS` as a truthy constant before loading the
components to disable all public native defaults.

HTML full-document parsing is intentionally still PHP-backed through
`WP_HTML_Processor::create_full_parser()` until native full-document semantics
match the public parser. Fragment processors, including covered table, list,
description-list, select/option/optgroup, omitted-paragraph, ruby, tag
processor, and simpler fragment inputs, can use native delegates when enabled.

`URLInTextProcessor` can use the native URL-in-text class as its ASCII
candidate scanner. The public PHP class still validates candidates with the
existing WHATWG parser and uses the PHP regular-expression scanner for non-ASCII
text or when native defaults are disabled.

## Build Details

The build requires Rust, PHP development headers, `php-config`, and libclang.
The helper script checks those prerequisites before invoking Cargo. Depending on
the environment, `LIBCLANG_PATH` may need to point at the directory containing
`libclang.so`.

```bash
cd extensions/native-apis
PHP_CONFIG=/path/to/php-config \
LIBCLANG_PATH=/path/to/libclang/lib \
./build-extension.sh
```

The equivalent manual command is:

```bash
PHP_CONFIG=/path/to/php-config \
LIBCLANG_PATH=/path/to/libclang/lib \
cargo build --release --features php-extension
```

The shared object is written to:

```text
extensions/native-apis/target/release/libwp_native_apis.so
```

Load it for verification with:

```bash
php -d extension=extensions/native-apis/target/release/libwp_native_apis.so \
	extensions/native-apis/tests/verify-native-apis.php
```

When the extension is unavailable, the verification script exits with an
actionable diagnostic. Use `--allow-missing` only for environments that are
checking the repository without PHP development headers:

```bash
php extensions/native-apis/tests/verify-native-apis.php --allow-missing
```

The Rust parser kernels can be checked without PHP development headers:

```bash
cargo test
```

## Release Flow

The native extension has two release targets:

- host PHP builds, such as the Linux shared object produced by
  `build-extension.sh`;
- PHP.wasm builds for WordPress Playground.

Treat every release as experimental until the extension has packaging,
signing, and artifact retention policy in CI.

### Host PHP release checklist

1. Update the extension version in `extensions/native-apis/Cargo.toml`.
2. Confirm that the public PHP wrappers still gate on
   `supports_public_api()` and still fall back when the extension is absent.
3. Run the native verifier on the exact PHP version used to build the shared
   object:

   ```bash
   php -d extension=extensions/native-apis/target/release/libwp_native_apis.so \
     extensions/native-apis/tests/verify-native-apis.php
   ```

4. Run benchmarks with native classes required:

   ```bash
   php -d extension=extensions/native-apis/target/release/libwp_native_apis.so \
     bin/benchmark-native-apis.php \
     --iterations=100 \
     --mode=both \
     --disable-native-defaults \
     --require-native
   ```

5. Wait for the `Native APIs`, `PHP CodeSniffer`, docs snippet, and full
   PHPUnit matrix checks to pass on the release PR.
6. Attach the release artifact and the benchmark JSON to the GitHub release.
   Name host artifacts with the target platform and PHP version, for example:

   ```text
   wp-native-apis-0.1.0-php8.3-linux-x86_64.so
   wp-native-apis-0.1.0-benchmark.json
   ```

Host `.so` files are tied to the PHP ABI they were built against. Do not reuse
a PHP 8.3 build for PHP 8.4 or PHP 8.5.

### Playground release checklist

Browser Playground runs PHP as WebAssembly, so it cannot load the Linux shared
object from `target/release/`. Publish the PHP.wasm extension bundle produced
by `build-playground-extension.sh` before claiming Playground support:

```bash
extensions/native-apis/build-playground-extension.sh
```

The host PHP extension is Rust-backed through `ext-php-rs`. The Playground
bundle currently uses `native_apis_shim.c` instead, because Playground's
PHP.wasm runtime only exports the PHP C ABI symbols needed by regular C
extensions. The shim registers the native extension classes and verifies the
Playground loading path while the full Rust-backed implementation remains the
host PHP artifact.

The `Native APIs Playground Extension` workflow publishes the bundle after
changes land on `trunk`. It stores the release history on the repository's
`gh-pages` branch, copies that history into the generated docs site, and
deploys the complete site through GitHub Pages. Each run writes both a stable
`latest` URL and an immutable commit URL:

```text
https://wordpress.github.io/php-toolkit/wp_native_apis-wasm-extension/
https://wordpress.github.io/php-toolkit/wp_native_apis-wasm-extension/latest/manifest.json
https://wordpress.github.io/php-toolkit/wp_native_apis-wasm-extension/<commit-sha>/manifest.json
```

Use `latest` when manually testing the newest build. Use an immutable
`<commit-sha>` manifest when documenting a reproducible Playground URL, writing
tests, or comparing behavior across releases.

The release index page lists every published immutable bundle with its
publication date, manifest URL, checksum file, and source commit. The same
history is available to tooling as JSON:

```text
https://wordpress.github.io/php-toolkit/wp_native_apis-wasm-extension/index.html
https://wordpress.github.io/php-toolkit/wp_native_apis-wasm-extension/releases.json
```

If a bundle needs an additional archive outside GitHub Pages, publish it as a
GitHub prerelease using a `wp-native-apis-wasm-*` tag and attach the packaged
manifest, checksum file, and PHP.wasm side module.

The published directory has this shape in the GitHub Pages site:

```text
wp_native_apis-wasm-extension/
|-- index.html
|-- releases.json
|-- latest/
|   |-- manifest.json
|   |-- SHA256SUMS
|   `-- wp_native_apis-php8.4-jspi.so
`-- <commit-sha>/
    |-- manifest.json
    |-- SHA256SUMS
    `-- wp_native_apis-php8.4-jspi.so
```

When the workflow first sees older SHA-named directories in the release-history
branch without an existing `releases.json` entry, it imports them into the
release index and uses the `gh-pages` commit date for their publication date.
Subsequent releases preserve their recorded publication timestamp.

Use the Blueprint smoke test together with the Playground Query API
`php-extension` parameter to verify a published PHP.wasm extension bundle.
`php-extension` must be present in the initial Playground URL because PHP
extensions load before PHP starts; the Blueprint only writes and runs the smoke
test.

After publishing the PHP.wasm `manifest.json`, open the main Playground URL with
both the extension manifest and the Blueprint URL:

```text
https://playground.wordpress.net/?php=8.4&php-extension=<url-encoded-manifest-url>&blueprint-url=<url-encoded-blueprint-url>
```

For example, a release URL will look like:

```text
https://playground.wordpress.net/?php=8.4&php-extension=https%3A%2F%2Fwordpress.github.io%2Fphp-toolkit%2Fwp_native_apis-wasm-extension%2Flatest%2Fmanifest.json&blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2FWordPress%2Fphp-toolkit%2Ftrunk%2Fextensions%2Fnative-apis%2Fplayground%2Fblueprint.json
```

Expected output:

```text
wp_native_apis extension version: 0.1.0
WP_HTML_Native_Tag_Processor: ok
WP_HTML_Native_Processor: ok
WordPress\XML\NativeXMLProcessor: ok
WordPress\DataLiberation\URL\NativeURLInTextProcessor: ok
PASS: Native API extension classes are available.
```

The Blueprint lives at `extensions/native-apis/playground/blueprint.json`. It
writes a small `native-api-smoke.php` file into Playground and navigates to it.
The smoke page checks that the four native classes are registered, then runs one
small HTML tag, HTML processor, XML processor, and URL-in-text operation.

If the smoke page reports missing classes, the selected Playground runtime does
not include the `wp_native_apis` PHP.wasm extension. Check that the URL includes
`php-extension=<manifest-url>`, the bundle matches the selected PHP version, and
the extension was built for the JSPI PHP.wasm ABI instead of the host PHP ABI.
Custom PHP.wasm extensions require a JSPI-capable browser runtime; non-JSPI
runtimes cannot load these side modules.

## Benchmarking

The repository benchmark harness defaults to PHP userland rows so existing
baseline commands keep their shape:

```bash
php bin/benchmark-native-apis.php --iterations=50
```

Native rows normally fail softly with unavailable-class diagnostics so the
harness remains usable without PHP development headers. Add `--require-native`
to post-build benchmark commands when missing native classes should produce a
non-zero exit.

After building and loading the extension, compare PHP and native rows with the
same workloads:

```bash
php -d extension=extensions/native-apis/target/release/libwp_native_apis.so \
	bin/benchmark-native-apis.php --iterations=50 --mode=both --require-native
```

When the extension is loaded but a PHP row should force public wrappers back to
their PHP fallback classes, pass `--disable-native-defaults`. The harness
defines `WP_NATIVE_APIS_DISABLE_DEFAULTS` before loading the repository
bootstrap:

```bash
php -d extension=extensions/native-apis/target/release/libwp_native_apis.so \
	bin/benchmark-native-apis.php --iterations=50 --mode=php --disable-native-defaults
```

### Fused and Chunked Workloads

Native-backed public wrappers are fastest when one extension call can cover a
caller-shaped workflow. The benchmark harness includes rows for these paths:

- HTML tag-prefix sanitizing through document-level removal/update.
- HTML tag-name scans through compact chunked batches.
- HTML matching tag-name scans through compact chunked batches.
- HTML matching tag-name plus attribute extraction through compact chunked batches.
- HTML matching tag-name plus multi-attribute extraction through compact chunked batches.
- HTML matching tag-name plus multi-attribute aggregate summaries for link audits.
- HTML tag inventory summaries for tag, closer, attribute, and unique-name audits.
- HTML heading inventory summaries for outline and heading-level audits.
- HTML ID inventory summaries for unique and duplicate ID audits.
- HTML attribute inventory summaries for attribute-name and decoded-value audits.
- HTML data-attribute inventory summaries for `data-*` usage audits.
- HTML ARIA attribute inventory summaries for `aria-*` accessibility audits.
- HTML class inventory summaries for class-attribute, class-name, and unique-class audits.
- HTML resource inventory summaries for common `href` and `src` link/media audits.
- HTML image inventory summaries for image source, alt-text, and dimension audits.
- HTML form inventory summaries for form/control name audits.
- HTML tag-prefix count batches for incremental callers that only need aggregate counts.
- HTML tag-prefix summary scans through compact chunked batches.
- HTML processor token scans through compact chunked batches.
- XML token stream summaries and compact token-summary batches.
- XML streaming factory and reentrancy cursor support for direct native
  processor instances.
- XML document, attribute, ID, content, and import inventory summaries through
  direct source scans.
- XML tag scans through compact chunked batches.
- XML tag count scans through compact chunked count batches.
- XML namespace/local-name tag scans through compact matching-tag batches.
- XML namespace/local-name tag counts through compact matching-tag count batches.
- XML namespace/local-name tag summaries through direct source scans.
- XML tag, prefix, and sanitizer summaries through direct source scans.
- URL-in-text scans through a direct native plain-text URL candidate processor,
  with public `URLInTextProcessor` rows preserving WHATWG validation.
The compact batch APIs return strings with `\x1f` field separators and `\x1e`
record separators. They are intended for callers that need incremental
processing but can aggregate without building one PHP array per tag or token.
Array-returning batch wrappers remain available for clearer application code.

Current benchmark claims should be scoped to these fused and chunked workflow
rows. Generic public `next_tag()` / `next_token()` loops remain compatibility
paths and still pay per-item PHP/native crossing overhead; use the aggregate
benchmark rows to distinguish those loops from target-clearing caller-shaped
APIs.

## Current Scope

The Rust implementation currently provides parser kernels and native class
stubs for the first conformance slice:

- HTML tag iteration, tag-name reads, first-slice namespace and qualified-name
  reads, virtual-token status, last-error and unsupported-exception
  diagnostics, syntax-level self-closing flag reads, closer-expectation status,
  attribute reads/removals, decoded class-list and class-membership reads,
  normalization/serialization through the PHP fragment serializer, public native-default token/tag, summary-batch, count-batch, short-batch exhaustion, and aggregate completion-state alignment, text subdivision, modifiable text mutation, DOCTYPE info access, full-parser factory, processor stepping, namespace switching, attribute setting, class additions/removals, prefixed attribute-name scans, public tag-summary,
  tag-prefix compact summary,
  matching-tag summary, matching-tag attribute summary, and matching-tag
  multi-attribute summary batches, complete-input pause status, bookmark
  lifecycle methods, and static
  void/special-category checks, including first-slice character reference
  decoding for numeric and common named references.
- HTML token iteration via the native processor stub, including text, comment,
  RAWTEXT, and RCDATA token text, qualified-name reads, decoded class reads,
  complete-input pause status, prefixed attribute-name count reads and
  aggregate summaries, tag-prefix summary batches, compact tag-prefix summary
  batch aliases, compact tag-prefix count batches, tag-inventory aggregate
  summaries, current-token and document-level prefixed attribute removal,
  full-parser factory, namespace switching, attribute setting, class additions/removals, PHP serializer-backed normalization/serialization for public compatibility including started-processor rejection after native token advancement, updated HTML serialization and string-cast serialization, breadcrumbs, breadcrumb matching, public
  token-summary with native-default full/final-short batch completion-state
  alignment, structured tag-summary, and compact
  tag-summary/matching-tag summary/matching-tag attribute summary/matching-tag
  multi-attribute summary batches, structured matching-tag summary,
  matching-tag attribute summary, and matching-tag multi-attribute summary
  batches, matching-tag attribute aggregate summaries, and bookmark lifecycle
  methods.
- XML tag iteration, token names, token types, local-name/namespace reads,
  resolved namespaced attribute reads, text/comment tokens, breadcrumbs,
  bookmark lifecycle methods, complete-input status and append rejection,
  structural/metadata/payload/content/import inventory completion-state alignment,
  token/tag/matching-tag/prefixed-attribute aggregate completion-state alignment,
  public token-summary, tag-summary, matching-tag summary, and count batch
  completion-state alignment, current depth, and malformed document diagnostics.
- URL-in-text candidate scanning for HTTP, HTTPS, protocol-relative, and bare
  domain references, including trailing punctuation trimming, malformed port
  truncation, replacement serialization, and ASCII-only public wrapper defaults
  that keep the existing WHATWG parser as the fine sieve.

The next milestone should keep extending shared PHPUnit conformance providers
against the loaded extension and continue targeting caller-shaped fused
workloads where native code can keep hot scans out of PHP userland.
