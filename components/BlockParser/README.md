# BlockParser

A standalone extraction of WordPress core's block parser. It takes a document containing WordPress block markup (`<!-- wp:name -->...<!-- /wp:name -->`) and returns a structured array of parsed blocks with their attributes, inner HTML, inner blocks, and content interleaving. This is the same parser that powers `parse_blocks()` in WordPress core, packaged as an independent library with no WordPress dependency.

## Installation

```
composer require wp-php-toolkit/blockparser
```

## Quick Start

```php
$document = <<<HTML
<!-- wp:heading {"level":2} -->
<h2>Welcome</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Hello from the block editor.</p>
<!-- /wp:paragraph -->
HTML;

$parser = new WP_Block_Parser();
$blocks = $parser->parse( $document );

foreach ( $blocks as $block ) {
    if ( 'core/heading' === $block['blockName'] ) {
        echo 'Found heading: ' . strip_tags( $block['innerHTML'] );
        // "Found heading: Welcome"
    }
}
```

## Usage

### Parsing a Document

Call `parse()` with any string containing block markup. It returns an array of block arrays, each with the following keys:

```php
$parser = new WP_Block_Parser();
$blocks = $parser->parse( $document );

// Each element in $blocks is an array:
// array(
//     'blockName'    => 'core/paragraph',   // Fully-qualified block name, or null for freeform HTML.
//     'attrs'        => array(),             // Attributes from the block comment delimiter.
//     'innerBlocks'  => array(),             // Nested blocks (same structure, recursive).
//     'innerHTML'    => '<p>Text</p>',       // The HTML inside the block, with inner blocks removed.
//     'innerContent' => array( '<p>Text</p>' ), // Interleaved HTML strings and null markers for inner block positions.
// )
```

### Block Types

The parser recognizes three kinds of block tokens:

**Standard blocks** have an opener and closer:

```php
$blocks = ( new WP_Block_Parser() )->parse(
    '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->'
);
// $blocks[0]['blockName'] === 'core/paragraph'
// $blocks[0]['innerHTML'] === '<p>Hello</p>'
```

**Self-closing (void) blocks** end with `/-→`:

```php
$blocks = ( new WP_Block_Parser() )->parse(
    '<!-- wp:spacer {"height":"50px"} /-->'
);
// $blocks[0]['blockName'] === 'core/spacer'
// $blocks[0]['attrs']     === array( 'height' => '50px' )
// $blocks[0]['innerHTML'] === ''
```

**Freeform HTML** is any content outside of block delimiters:

```php
$blocks = ( new WP_Block_Parser() )->parse(
    '<p>Just some HTML, no blocks here.</p>'
);
// $blocks[0]['blockName'] === null
// $blocks[0]['innerHTML'] === '<p>Just some HTML, no blocks here.</p>'
```

### Block Attributes

Attributes are encoded as JSON inside the block comment delimiter. The parser decodes them into a PHP associative array:

```php
$blocks = ( new WP_Block_Parser() )->parse(
    '<!-- wp:image {"id":123,"sizeSlug":"large","linkDestination":"none"} -->' .
    '<figure class="wp-block-image size-large"><img src="photo.jpg" class="wp-image-123"/></figure>' .
    '<!-- /wp:image -->'
);

$attrs = $blocks[0]['attrs'];
// array(
//     'id'              => 123,
//     'sizeSlug'        => 'large',
//     'linkDestination' => 'none',
// )
```

### Nested Blocks

Blocks can contain other blocks. Inner blocks appear in the `innerBlocks` array, and `innerContent` interleaves the HTML fragments with `null` markers showing where each inner block was located:

```php
$document = <<<HTML
<!-- wp:columns -->
<div class="wp-block-columns">
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:paragraph -->
<p>Left column</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:paragraph -->
<p>Right column</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
HTML;

$parser = new WP_Block_Parser();
$blocks = $parser->parse( $document );

$columns = $blocks[0];
// $columns['blockName']   === 'core/columns'
// count( $columns['innerBlocks'] ) === 2

$left_column = $columns['innerBlocks'][0];
// $left_column['blockName'] === 'core/column'
// $left_column['innerBlocks'][0]['blockName'] === 'core/paragraph'

// innerContent shows the interleaving of HTML and inner block positions:
// array(
//     '<div class="wp-block-columns">\n',  // HTML before first inner block
//     null,                                 // Position of first inner block (core/column)
//     '\n',                                 // HTML between inner blocks
//     null,                                 // Position of second inner block (core/column)
//     '\n</div>\n',                         // HTML after last inner block
// )
```

### Namespaced Blocks

The parser handles both core blocks (`wp:paragraph`) and namespaced third-party blocks (`wp:my-plugin/custom-block`). Block names without an explicit namespace are prefixed with `core/`:

```php
$blocks = ( new WP_Block_Parser() )->parse(
    '<!-- wp:my-plugin/testimonial {"author":"Jane"} -->' .
    '<blockquote>Great product!</blockquote>' .
    '<!-- /wp:my-plugin/testimonial -->'
);
// $blocks[0]['blockName'] === 'my-plugin/testimonial'
// $blocks[0]['attrs']     === array( 'author' => 'Jane' )
```

### Error Recovery

The parser is designed to never fail. When it encounters malformed markup such as missing closers or mismatched block names, it produces a best-effort parse rather than returning an error:

```php
// Missing closer -- the parser treats it as implicitly closed.
$blocks = ( new WP_Block_Parser() )->parse(
    '<!-- wp:paragraph --><p>No closer here'
);
// $blocks[0]['blockName'] === 'core/paragraph'
// $blocks[0]['innerHTML'] === '<p>No closer here'
```

## API Reference

### WP_Block_Parser

| Method | Description |
|--------|-------------|
| `parse( $document )` | Parse block markup and return an array of block structures |

### Block Structure (array keys)

| Key | Type | Description |
|-----|------|-------------|
| `blockName` | `string\|null` | Fully-qualified name (e.g. `core/paragraph`), or `null` for freeform HTML |
| `attrs` | `array` | Block attributes decoded from the JSON in the comment delimiter |
| `innerBlocks` | `array` | Nested blocks, same structure recursively |
| `innerHTML` | `string` | HTML content with inner blocks stripped out |
| `innerContent` | `array` | Interleaved HTML strings and `null` markers for inner block positions |

### WP_Block_Parser_Block

| Property | Type | Description |
|----------|------|-------------|
| `$blockName` | `string\|null` | Block name |
| `$attrs` | `array\|null` | Block attributes |
| `$innerBlocks` | `array` | Nested block instances |
| `$innerHTML` | `string` | Inner HTML content |
| `$innerContent` | `array` | Interleaved content with `null` placeholders |

## Attribution

This component is extracted from [WordPress core](https://github.com/WordPress/wordpress-develop). The `WP_Block_Parser`, `WP_Block_Parser_Block`, and `WP_Block_Parser_Frame` classes are maintained as part of the WordPress block editor infrastructure. Licensed under GPL v2.

## Requirements

- PHP 7.2+
- No external dependencies
