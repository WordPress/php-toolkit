# WordPress Native APIs PHP Extension

This package is the Rust-backed PHP extension surface for native parser and
scanner classes in the toolkit. It registers direct native classes while leaving
public PHP components unchanged unless a later integration layer chooses to use
those classes.

## Native Classes

- `WP_HTML_Native_Tag_Processor`
- `WP_HTML_Native_Processor`
- `WordPress\XML\NativeXMLProcessor`
- `WordPress\DataLiberation\URL\NativeURLInTextProcessor`

## Build

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

## Current Scope

The Rust implementation currently provides parser kernels and direct native
classes for the first conformance slice:

- HTML tag iteration, token iteration, tag-name reads, namespace and
  qualified-name reads, attribute reads and mutations, class-list reads,
  bookmark lifecycle methods, text/comment/RAWTEXT/RCDATA handling,
  serialization, compact tag/token batches, aggregate inventory summaries, and
  selected tree-builder breadcrumbs.
- XML token and tag iteration, local-name and namespace reads, resolved
  namespaced attribute reads, text/comment/CDATA handling, bookmark lifecycle
  methods, complete-input and streaming support, serialization, compact
  tag/token batches, aggregate inventory summaries, and malformed document
  diagnostics.
- URL-in-text candidate scanning for HTTP, HTTPS, protocol-relative, and bare
  domain references, including trailing punctuation trimming, malformed port
  truncation, replacement serialization, and direct byte offsets.
