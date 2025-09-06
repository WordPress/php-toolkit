# Polyfill

Polyfills for a subset of WordPress Core functions/classes to run components outside of a full WordPress runtime.

## Problems Solved
- Use `BlockParser`, `HTML`, `Markdown`, etc., in plain PHP scripts
- Basic i18n (`__`), escaping (`esc_*`), hooks (`add_filter`, `do_action`), and block helpers (`parse_blocks`, `serialize_blocks`)

## Usage
```php
// Load before using components that expect WP helpers
require __DIR__ . '/../Polyfill/wordpress.php';

// Now parse/serialize blocks without WP loaded
$blocks = parse_blocks('<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->');
$html   = serialize_blocks($blocks);
```

## Notes
- Only includes what’s needed by components in this toolkit
- Safe for CLI/tools; not a drop‑in replacement for full WordPress

