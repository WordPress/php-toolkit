# Markdown

Bidirectional converter between Markdown and WordPress block markup. Use `MarkdownConsumer` to parse Markdown (with optional YAML frontmatter) into WordPress blocks, and `MarkdownProducer` to serialize blocks back to Markdown. Designed for content synchronization workflows where round-trip fidelity and whitespace preservation matter, such as three-way merging of static Markdown files with a WordPress database.

## Installation

```
composer require wp-php-toolkit/markdown
```

## Quick Start

Convert a Markdown string into WordPress block markup:

```php
use WordPress\Markdown\MarkdownConsumer;

$markdown = "# Hello World\n\nThis is a paragraph with **bold** text.";

$consumer = new MarkdownConsumer( $markdown );
$result   = $consumer->consume();

$block_markup = $result->get_block_markup();
// <!-- wp:heading {"level":1} -->
// <h1 class="wp-block-heading" id="hello-world">Hello World</h1>
// <!-- /wp:heading -->
//
// <!-- wp:paragraph -->
// <p>This is a paragraph with <b>bold</b> text.</p>
// <!-- /wp:paragraph -->
```

## Usage

### Markdown to Blocks

Pass any Markdown string to `MarkdownConsumer` and call `consume()`. The returned `BlocksWithMetadata` object gives you both the block markup and any frontmatter metadata:

```php
use WordPress\Markdown\MarkdownConsumer;

$markdown = <<<MD
---
post_title: "WordPress 6.8 was released"
post_date: "2024-12-16"
post_author: "1"
---

## WordPress 6.8 was released

Last week, WordPress 6.8 was released. This release includes a new default theme.
MD;

$consumer = new MarkdownConsumer( $markdown );
$result   = $consumer->consume();

// Get YAML frontmatter as metadata.
// Each value is wrapped in an array to match the WP_Block_Markup_Converter interface.
$metadata = $result->get_all_metadata();
// array(
//     'post_title'  => array( 'WordPress 6.8 was released' ),
//     'post_date'   => array( '2024-12-16' ),
//     'post_author' => array( '1' ),
// )

$blocks = $result->get_block_markup();
```

### Supported Markdown Elements

The consumer handles paragraphs, headings (all levels), bold, italic, inline code, links, images, ordered and unordered lists (including nested lists), blockquotes, fenced and indented code blocks, tables, horizontal rules, and raw HTML blocks.

```php
use WordPress\Markdown\MarkdownConsumer;

// Lists convert to wp:list and wp:list-item blocks.
$consumer = new MarkdownConsumer( "- Item 1\n  - Item 1.1\n  - Item 1.2\n- Item 2" );
$result   = $consumer->consume();
$blocks   = $result->get_block_markup();
// <!-- wp:list {"ordered":false} -->
// <ul class="wp-block-list">
//   <!-- wp:list-item --><li>Item 1
//     <!-- wp:list {"ordered":false} -->
//     <ul class="wp-block-list">
//       <!-- wp:list-item --><li>Item 1.1</li><!-- /wp:list-item -->
//       <!-- wp:list-item --><li>Item 1.2</li><!-- /wp:list-item -->
//     </ul>
//     <!-- /wp:list -->
//   </li><!-- /wp:list-item -->
//   <!-- wp:list-item --><li>Item 2</li><!-- /wp:list-item -->
// </ul>
// <!-- /wp:list -->

// Tables convert to wp:table blocks with thead/tbody structure.
$table_md = "| Name | Role |\n|------|------|\n| Ada  | Dev  |";
$consumer = new MarkdownConsumer( $table_md );
$result   = $consumer->consume();
```

### Blocks to Markdown

Convert WordPress block markup back to Markdown using `MarkdownProducer`. Pass a `BlocksWithMetadata` instance containing the block markup and any metadata to include as YAML frontmatter:

```php
use WordPress\DataLiberation\DataFormatConsumer\BlocksWithMetadata;
use WordPress\Markdown\MarkdownProducer;

$blocks = '<!-- wp:paragraph --><p>A paragraph with a <a href="https://wordpress.org">link</a>.</p><!-- /wp:paragraph -->';

$metadata = array(
    'post_title' => 'My Post',
);

$producer = new MarkdownProducer( new BlocksWithMetadata( $blocks, $metadata ) );
$markdown = $producer->produce();
// ---
// post_title: "My Post"
// ---
//
// A paragraph with a [link](https://wordpress.org).
```

The producer converts headings to `#` syntax, lists to `-` or `1.` syntax, images to `![alt](url)` syntax, bold/italic to `**`/`*`, inline code to backticks, code blocks to fenced blocks, tables to pipe tables, and blockquotes to `>` prefixed lines. Blocks that cannot be represented in Markdown are serialized as fenced code blocks with the `block` language tag, preserving them for round-trip conversion.

## API Reference

### MarkdownConsumer

| Method | Description |
|--------|-------------|
| `__construct( $markdown )` | Create a consumer from a Markdown string |
| `consume()` | Parse and return a `BlocksWithMetadata` instance |
| `get_all_metadata()` | Get frontmatter as `array( 'key' => array( value ) )` |
| `get_meta_value( $key )` | Get a single metadata value by key |
| `get_block_markup()` | Get the resulting block markup string |

### MarkdownProducer

| Method | Description |
|--------|-------------|
| `__construct( BlocksWithMetadata $blocks_with_meta )` | Create a producer from blocks and metadata |
| `produce()` | Convert to Markdown string with optional YAML frontmatter |

### BlocksWithMetadata

| Method | Description |
|--------|-------------|
| `get_block_markup()` | Get the block markup string |
| `get_all_metadata()` | Get all metadata as an associative array |

## Requirements

- PHP 7.2+
- No external dependencies beyond other `wp-php-toolkit` components
