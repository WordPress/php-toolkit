# BlockParser

A fast, dependency‑free parser for WordPress block markup (HTML with `<!-- wp:... -->` comments). It produces parsed block structures compatible with WordPress Core’s block parser.

## Problems Solved
- Parses block comment syntax into structured arrays you can inspect or transform
- Recovers from many malformed inputs without throwing
- Works without WordPress loaded (pair with `components/Polyfill` for helpers)

## Quick Start
```php
// Parse a string with block comments into block arrays
$parser = new \WP_Block_Parser();
$blocks = $parser->parse("Pre\n<!-- wp:paragraph -->Hello<!-- /wp:paragraph -->\n");

// Re‑serialize if needed (when using Polyfill’s serialize helpers):
$serialized = serialize_blocks($blocks);
```

## Typical Uses
- Inspect or migrate content stored as Gutenberg blocks
- Transform block attributes or inner HTML programmatically
- Validate that markup contains expected blocks

## Notes
- Input/Output mirrors WordPress Core behavior as much as possible
- For convenience functions `parse_blocks()`, `serialize_blocks()`, add `components/Polyfill` in non‑WP environments

