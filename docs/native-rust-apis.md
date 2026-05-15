# Native Rust API Extension Notes

This document captures the first-pass implementation shape for Rust-backed PHP
extensions for the HTML, XML, and URL-in-text APIs.

## Reference Architecture

The local reference checkout is:

```text
/home/claude/explore-sqlite-rust/packages/php-ext-wp-mysql-parser
```

That package builds a PHP extension with `ext-php-rs`:

```bash
PHP_CONFIG=/path/to/php-config \
LIBCLANG_PATH=/path/to/libclang/lib \
cargo build --release
```

The extension registers native classes such as `WP_MySQL_Native_Lexer` and
`WP_MySQL_Native_Parser`. The PHP package keeps public classes stable:

- PHP load code checks `class_exists( 'WP_MySQL_Native_Lexer', false )`.
- If native classes exist, public wrapper classes are loaded from a `native/`
  directory.
- If they do not exist, pure-PHP classes are loaded.
- The public parser class still extends the pure-PHP base class where caller
  `instanceof` contracts matter, but delegates work to a composed native object.
- Native-only tests are skipped when native classes are unavailable.
- Verification scripts fail with explicit stderr diagnostics when the extension
  is missing or not wired into the public API.

The same pattern should be used here: first add native classes and shared
conformance tests, then stack PHP default-to-native wrappers after correctness is
proven.

## Current Repository Surface

- `components/HTML` contains the global `WP_HTML_Tag_Processor` and
  `WP_HTML_Processor` APIs.
- `components/XML` contains `WordPress\XML\XMLProcessor` and W3C-oriented tests.
- `components/DataLiberation/URL` contains a WHATWG URL parser wrapper and
  `URLInTextProcessor` for plain-text URL detection. This native extension stack
  does not add an RFC URL parser; it adds a native URL-in-text candidate scanner
  that the public processor can validate through the existing WHATWG parser.
- The root autoloader is classmap-based. Do not convert this to PSR-4.

## Proposed Native Package Layout

Start the first native branch with a reviewable package that does not change the
default PHP behavior:

```text
extensions/native-apis/
  Cargo.toml
  README.md
  src/
    lib.rs
    html.rs
    xml.rs
  tests/
    verify-native-apis.php
```

Native class names should avoid taking over public names until the second stacked
branch:

- `WP_HTML_Native_Tag_Processor`
- `WP_HTML_Native_Processor`
- `WordPress\XML\NativeXMLProcessor`
- `WordPress\DataLiberation\URL\NativeURLInTextProcessor`
The current stack includes PHP wrappers that select native implementations when
the classes are already registered by the extension, while preserving PHP-only
fallback behavior.

## Native Default Controls

Public wrappers use native delegates only when the extension has already loaded
the matching native classes. Define `WP_NATIVE_APIS_DISABLE_DEFAULTS` as a
truthy constant before loading the components to force every public wrapper back
to the PHP implementation.

Each component also has a narrower control:

- HTML: `WP_NATIVE_APIS_ENABLE_HTML_DEFAULTS`
- XML: `WP_NATIVE_APIS_ENABLE_XML_DEFAULTS`
- URL-in-text: `WP_NATIVE_APIS_ENABLE_URL_DEFAULTS`
When one of those constants is defined, its boolean value decides whether that
component may use native defaults. If the constant is not defined, the matching
environment variable is checked instead; values `0`, `false`, `no`, and `off`
disable native defaults, while other values enable them. The global disable
constant takes precedence over component-level enables.

`WP_HTML_Processor::create_full_parser()` remains PHP-backed for now even when
HTML native defaults are enabled, because the native full-document parser is not
yet public-parity safe. `WP_HTML_Processor::create_fragment()` can use native
delegates for covered table, list, description-list, select/option/optgroup,
omitted-paragraph, and ruby tree-builder cases when enabled.

`URLInTextProcessor` can use the native URL-in-text scanner as its thick sieve
for ASCII input when enabled. The public class still validates every candidate
with the existing WHATWG parser and falls back to the PHP regular-expression
sieve for non-ASCII text or when the native scanner is unavailable.

## Shared Conformance Strategy

Conformance tests should be implementation-parameterized, not duplicated. Each
suite should expose a provider with at least:

- pure PHP class factory
- native class factory, skipped when the native class is unavailable

HTML tests should focus first on the public streaming surface:

- `next_token()`
- `next_tag()`
- `get_token_type()`
- `get_token_name()`
- `get_tag()`
- `get_namespace()` for the current first-slice HTML namespace
- `get_qualified_tag_name()` and `get_qualified_attribute_name()` for the
  current first-slice HTML namespace
- `get_attribute()`
- `class_list()` and `has_class()` for decoded class access
- `get_attribute_names_with_prefix()` for prefixed attribute-name scans
- `remove_attribute()` for current-token attribute removal bookkeeping
- `is_virtual()` for current-token virtual status
- `get_last_error()` diagnostics
- `get_unsupported_exception()` diagnostics
- `expects_closer()` for current-token closer expectation
- `is_void()` static void-element checks
- `is_special()` static special-category checks
- `has_self_closing_flag()` syntax checks
- `paused_at_incomplete_token()` complete-input status for direct native tag
  processors
- `next_tag_summary_batch()` public row batches for direct native tag
  processors, including current-tag state after native batch advancement
- `next_tag_prefix_count_compact_batch()` current-tag state after public
  native-default count-only batch advancement
- `next_matching_tag_summary_batch()` public row batches for direct native tag
  processors
- `next_matching_tag_attribute_summary_batch()` public row batches for direct
  native tag processors
- `next_matching_tag_attributes_summary_batch()` public row batches for direct
  native tag processors
- `get_modifiable_text()` for text, comment, RAWTEXT, and RCDATA tokens
- public native-default `set_modifiable_text()` and
  `subdivide_text_appropriately()` delegation after native token advancement
- public native-default remaining-document aggregate scans leaving the public
  tag processor in the same complete state as PHP fallback scans
- `get_breadcrumbs()`
- `matches_breadcrumbs()` suffix and wildcard checks
- `set_bookmark()`, `seek()`, `has_bookmark()`, and `release_bookmark()` for
  direct native cursor state restore
- serialization behavior for `WP_HTML_Processor`
  when native defaults are loaded, including the PHP serializer fallback used
  by public `normalize()` / `serialize()` and the started-processor rejection
  after native token or token-summary advancement

XML tests should focus first on:

- `create_from_string()`
- `next_token()`
- `next_tag()`
- namespace-aware tag and attribute reads
- malformed document diagnostics via `get_last_error()`
- complete-input status, including rejected `append_bytes()` calls on direct
  native complete-string processors
- public native-default structural, metadata, payload, content, and import
  inventory summaries leaving the processor finished after remaining-document
  native scans
- public native-default token, tag, matching-tag, prefixed-attribute, and
  document-removal aggregate summaries leaving the processor finished after
  remaining-document native scans
- public native-default token, tag, matching-tag, and count batches leaving the
  processor finished after exhausted native batch scans
- `next_token_summary_batch()` public row batches for direct native processors
- `next_tag_summary_batch()` public row batches for direct native processors
- `next_matching_tag_summary_batch()` public row batches for direct native
  processors
- `set_bookmark()`, `seek()`, `has_bookmark()`, and `release_bookmark()` for
  direct native cursor state restore

The native XML first slice now resolves namespace declarations into
`get_tag_local_name()`, `get_tag_namespace()`, and
`get_tag_namespace_and_local_name()` results, and stores namespaced attributes
under the resolved `{namespace}local_name` key for conformance and verification
coverage.

Native-specific tests should cover wrapper identity, skipped native availability,
and diagnostics, following the sqlite parser extension tests.

URL-in-text tests should cover:

- direct native candidate scanning for HTTP, HTTPS, protocol-relative, and bare
  domain references
- trailing punctuation preservation during replacements
- malformed host and malformed port rejection/truncation before WHATWG
  validation
- public `URLInTextProcessor` behavior with and without the native extension
  loaded, proving the existing WHATWG parser remains the fine sieve

## Benchmarking

Use `bin/benchmark-native-apis.php` to capture PHP baseline CPU and memory
before native work starts. Re-run the same command with the extension loaded:

```bash
php bin/benchmark-native-apis.php --iterations=50
php -d extension=/path/to/libwp_native_apis.so \
	bin/benchmark-native-apis.php --iterations=50 --mode=both --require-native
```

The benchmark supports `--mode=php|native|both` and emits an `implementation`
field in each result row. Native rows fail softly with unavailable-class
diagnostics when the extension is not loaded, so CI or local workers can still
verify the harness without PHP development headers.

Pass `--disable-native-defaults` when the extension is loaded but the PHP row
should force public wrappers back to PHP fallback classes. The harness defines
`WP_NATIVE_APIS_DISABLE_DEFAULTS` before loading the repository bootstrap so the
global native-default kill switch applies consistently across HTML, XML, and
URL workloads.

Use `--require-native` in post-build benchmark jobs. It preserves the soft
missing-extension behavior for PHP-only development runs, but exits non-zero if
any selected native row is unavailable.

Pass `--component=url --name=url-in-text-processor` to benchmark URL-in-text
scans specifically. The PHP row measures the public `URLInTextProcessor`, while
the native row measures the direct native scanner.

Record the PHP version, command, wall time, CPU time, and peak memory in
`.autonomous-loop/memory.md` after each run.

## Performance API Shape

The native defaults preserve the existing public token and tag processors, but
token-by-token loops still execute PHP code for every public method call. The
performance branch therefore adds explicit fused and chunked APIs for common
high-throughput workflows where callers can accept summarized rows instead of
calling several accessors per token or tag.

HTML tag workflows now have compact chunked summaries for incremental callers
that only need tag names and closer state:

- `next_tag_summary_batch()` returns structured rows for the next chunk of
  tag metadata, including direct native public-row batches.
- `next_tag_compact_summary_batch()` returns the same rows as a compact string.
- `next_matching_tag_summary_batch()` returns structured rows for matching
  tags, including direct native public-row batches.
- `next_matching_tag_attribute_summary_batch()` returns structured rows for
  matching tags and one decoded attribute value, including direct native
  public-row batches.
- `next_matching_tag_attributes_summary_batch()` returns structured rows for
  matching tags and decoded multi-attribute maps, including direct native
  public-row batches.
- `summarize_tag_inventory()` consumes the remaining tag stream and returns
  tag, opener, closer, attribute, and unique tag-name counts for audit
  workflows that do not need one PHP call per tag.
- `summarize_heading_inventory()` consumes the remaining tag stream and returns
  heading counts by level for outline audits that do not need one PHP call per
  tag.
- `summarize_id_inventory()` consumes the remaining tag stream and returns
  ID-bearing tag counts, unique decoded ID counts, duplicate ID counts, and
  decoded ID value bytes for anchor/accessibility audits.
- `summarize_attribute_inventory()` consumes the remaining tag stream and
  returns attribute, unique attribute-name, and decoded attribute-value byte
  counts for audits that do not need one PHP call per tag or attribute.
- `summarize_data_attribute_inventory()` consumes the remaining tag stream and
  returns `data-*` tag, attribute, unique-name, and decoded value-byte counts
  for custom-data audits.
- `summarize_aria_attribute_inventory()` consumes the remaining tag stream and
  returns `aria-*` tag, attribute, unique-name, and decoded value-byte counts
  for accessibility audits.
- `summarize_class_inventory()` consumes the remaining tag stream and returns
  class-attribute, per-tag class-name, unique class-name, and decoded class
  value byte counts for class audits that do not need one PHP call per tag.
- `summarize_resource_inventory()` consumes the remaining tag stream and
  returns counts and decoded value bytes for common `href`/`src` resources on
  anchors, images, scripts, links, and sources.
- `summarize_image_inventory()` consumes the remaining tag stream and returns
  image, `src`, `alt`, empty-alt, dimension, and decoded value-byte counts for
  image audits.
- `summarize_form_inventory()` consumes the remaining tag stream and returns
  form/control counts, named-control counts, unique control-name counts, and
  decoded control-name byte totals.

HTML tag-prefix workflows build on that pattern with three shapes:

- `count_attribute_names_with_prefix()` counts matching attributes on the
  current tag.
- `summarize_attribute_names_with_prefix()` and
  `remove_attributes_with_prefix_from_document()` consume the remaining
  document in one call for fully fused read-only or sanitizer workflows.
- `next_tag_prefix_summary_batch()` and
  `next_tag_prefix_compact_summary_batch()` consume the next chunk of tags,
  preserving incremental processing while amortizing PHP/native crossings.
- `next_tag_prefix_count_compact_batch()` consumes the next chunk of tags and
  returns only aggregate tag and prefixed-attribute counts for callers that do
  not need per-tag rows. Native-backed public wrappers preserve the current tag
  after count-only batch advancement and map final short batches to the same
  complete public parser state as the PHP fallback loop.

HTML processor token workflows now also expose `next_token_summary_batch()` and
`next_token_compact_summary_batch()` for common read-only scans that need token
type, token name, closer state, depth, and breadcrumbs without several accessor
calls per token. Native-backed public wrappers map full remaining-document and
final short token batches to the same complete public parser state as the PHP
fallback loop.

XML token workflows follow the same pattern:

- Direct native XML processor instances expose `create_for_streaming()` and
  `get_reentrancy_cursor()` for the supported UTF-8 native-cursor factory path.
- `summarize_token_stream()` consumes the remaining token stream and returns
  aggregate token, tag, and attribute counts.
- `summarize_document_inventory()` consumes the remaining token stream and
  returns structural counts such as open/closing tags, text/comment/CDATA
  tokens, maximum depth, and empty elements.
- `summarize_attribute_inventory()` consumes the remaining token stream and
  returns opening-tag attribute counts, namespaced attribute counts, tags with
  attributes, and maximum attributes per tag.
- `summarize_id_inventory()` consumes the remaining token stream and returns
  no-namespace `id` attribute counts, unique ID counts, duplicate ID counts,
  and decoded ID value bytes for importer/dedupe audits.
- `summarize_content_inventory()` consumes the remaining token stream and
  returns combined attribute-value and payload-byte counts for importer/audit
  workflows that need both metadata and text-like content in one pass.
- `summarize_import_inventory()` consumes the remaining token stream and
  returns combined structural, attribute, and payload counts for importer/audit
  workflows that need one document-shape and content-size pass.
- `next_token_summary_batch()` returns structured rows for the next chunk of
  token metadata.
- `next_token_compact_summary_batch()` returns the same rows as a compact
  string using `\x1f` field separators and `\x1e` record separators.

XML tag workflows also expose `next_tag_summary_batch()` and
`next_tag_compact_summary_batch()` for incremental read-only scans that only
need opening tag metadata and one cached attribute. The compact rows include
the number of tokens consumed in the current batch so native-backed public
wrappers can replay accurately if a later call falls back to PHP.

Count-only XML tag workflows can use `next_tag_count_batch()` for structured
counts or `next_tag_count_compact_batch()` for a compact `token_count`,
`tag_count`, and `attribute_count` row. This caller shape avoids per-tag
metadata rows when the caller only needs aggregate progress through an
incremental scan.

Namespace/local-name XML tag scans can use `next_matching_tag_summary_batch()`,
`next_matching_tag_compact_summary_batch()`, `next_matching_tag_count_batch()`,
`next_matching_tag_count_compact_batch()`, or `summarize_matching_tag_stream()`.
These match the common `next_tag( array( $namespace, $local_name ) )` loop shape
while allowing native implementations to skip unrelated tags and amortize or
avoid the crossing cost.

HTML tag-name scans that also read one attribute can use
`next_matching_tag_attribute_summary_batch()` or
`next_matching_tag_attribute_compact_summary_batch()`. This matches common link,
image, and script scans where the caller filters by tag name and immediately
extracts `href`, `src`, or a similar single attribute.

HTML tag-name scans that need several attributes can use
`next_matching_tag_attributes_summary_batch()` or
`next_matching_tag_attributes_compact_summary_batch()`. This matches link-audit
workflows that filter to `A` tags and extract fields such as `href`, `title`,
and `rel` in one native scan instead of one crossing per attribute.

HTML link-audit workflows that only need aggregate counts can use
`summarize_matching_tag_attributes()`. This consumes the remaining matching tags
in one call and returns tag, present-attribute, and decoded attribute byte
counts without PHP parsing one compact row per link.

The structured array batch methods are the readability path. The compact string
methods are the performance path for callers that can aggregate directly from
the separator-delimited rows.

Current benchmark interpretation: native-backed public wrappers clear the 5x
CPU target with the same peak memory for caller-shaped HTML/XML fused or chunked
workflows. This includes HTML sanitizer, processor, token batch, compact tag,
matching-tag, matching-attribute, prefix, and inventory rows; and XML fused
token/tag/prefix summaries, compact token/tag/count batches, matching-tag
batches, sanitizer rows, and document, attribute, ID, namespace, payload,
structural, content, and import inventory summaries.

Direct native HTML processor instances also expose qualified-name accessors,
complete-input pause status, prefixed-attribute count and aggregate summary
access, compact tag-prefix count batches, inventory aggregate summaries,
current-token and document-level prefixed-attribute removal,
normalization/serialization through the PHP fragment serializer, text
subdivision, modifiable text mutation, DOCTYPE info access, full-parser
factory, processor stepping, namespace switching, attribute setting, class
additions/removals, updated HTML serialization and string-cast serialization,
structured and compact token/tag/matching-tag summary rows, and matching-tag
attribute aggregate summaries for parity with the public processor API.

The unqualified performance target remains open for transparent generic public
cursor loops. Focused generic HTML cursor snapshots improved substantially, but
the reviewable performance claim is still scoped to caller-shaped rows. Generic
public `next_tag()` and `next_token()` loops that call several accessors per
item, especially the XML `next_token()` cursor row, remain compatibility paths
below 5x because they still pay repeated PHP method dispatch plus native row
hydration or accessor export overhead.
