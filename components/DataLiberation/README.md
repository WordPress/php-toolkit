# DataLiberation

<!-- docs-site-banner -->
> 📚 **Runnable examples:** [https://wordpress.github.io/php-toolkit/reference/dataliberation.html](https://wordpress.github.io/php-toolkit/reference/dataliberation.html)
> Open the page to edit each snippet in your browser and run it in WordPress Playground.
<!-- /docs-site-banner -->

Streaming data import and export for WordPress. Reads and writes WordPress content in multiple formats -- WXR (WordPress eXtended RSS), SQL dumps, block markup, and more -- without loading everything into memory. Designed for migrating content between WordPress sites, converting between formats, and processing large exports that would otherwise exhaust PHP's memory limits.

## Installation

```
composer require wp-php-toolkit/data-liberation
```

## Quick Start

Export a WordPress post to WXR format:

```php
use WordPress\ByteStream\MemoryPipe;
use WordPress\DataLiberation\EntityWriter\WXRWriter;
use WordPress\DataLiberation\ImportEntity;

$output = new MemoryPipe();
$writer = new WXRWriter( $output );

$post = new ImportEntity( 'post', array(
    'post_title' => 'Hello World',
    'post_date'  => '2024-01-15',
    'guid'       => 'https://example.com/?p=1',
    'content'    => '<p>Welcome to my site.</p>',
    'excerpt'    => 'A short summary.',
    'post_id'    => '1',
    'post_name'  => 'hello-world',
    'status'     => 'publish',
    'post_type'  => 'post',
) );

$writer->append_entity( $post );
$writer->finalize();
$writer->close_writing();
$output->close_writing();

echo $output->consume_all();
// Outputs a complete WXR XML document with the post.
```

## Usage

### Writing WXR exports

`WXRWriter` generates WordPress eXtended RSS (WXR) XML files. You feed it entities one at a time -- posts, metadata, terms, and comments -- and it produces valid WXR output. Entities must be appended in logical order: metadata, terms, and comments belong to the most recently appended post.

```php
use WordPress\ByteStream\MemoryPipe;
use WordPress\DataLiberation\EntityWriter\WXRWriter;
use WordPress\DataLiberation\ImportEntity;

$output = new MemoryPipe();
$writer = new WXRWriter( $output );

// Write a post
$writer->append_entity( new ImportEntity( 'post', array(
    'post_title'     => 'My Article',
    'post_date'      => '2024-03-01',
    'guid'           => 'https://example.com/?p=42',
    'content'        => '<!-- wp:paragraph --><p>Article body.</p><!-- /wp:paragraph -->',
    'post_id'        => '42',
    'post_name'      => 'my-article',
    'status'         => 'publish',
    'post_type'      => 'post',
    'comment_status' => 'open',
) ) );

// Attach metadata to that post
$writer->append_entity( new ImportEntity( 'post_meta', array(
    'meta_key'   => '_thumbnail_id',
    'meta_value' => '99',
) ) );

// Attach a term
$writer->append_entity( new ImportEntity( 'term', array(
    'term_id'  => '5',
    'taxonomy' => 'category',
    'slug'     => 'tutorials',
    'parent'   => '0',
) ) );

// Attach a comment
$writer->append_entity( new ImportEntity( 'comment', array(
    'comment_id'      => '1',
    'comment_author'  => 'Jane',
    'comment_content' => 'Great post!',
    'comment_date'    => '2024-03-02',
    'comment_approved' => '1',
) ) );

$writer->finalize();
$writer->close_writing();
$output->close_writing();
```

The writer supports pausing and resuming via a reentrancy cursor. This lets you split large exports across multiple PHP requests:

```php
// Save state after writing some entities
$cursor = $writer->get_reentrancy_cursor();
$writer->close_writing();

// Later, resume from where you left off
$writer = new WXRWriter( $output, $cursor );
$writer->append_entity( $next_post );
```

### Writing SQL dumps

`MySQLDumpWriter` produces SQL INSERT statements from entity data:

```php
use WordPress\ByteStream\MemoryPipe;
use WordPress\DataLiberation\EntityWriter\MySQLDumpWriter;
use WordPress\DataLiberation\ImportEntity;

$output = new MemoryPipe();
$writer = new MySQLDumpWriter( $output );

$writer->append_entity( new ImportEntity( 'database_row', array(
    'table'  => 'wp_posts',
    'record' => array(
        'ID'           => 1,
        'post_title'   => 'First Post',
        'post_content' => 'Hello World',
    ),
) ) );

$writer->close_writing();
echo $output->consume_all();
// INSERT INTO wp_posts (ID, post_title, post_content) VALUES (1, 'First Post', 'Hello World');
```

String values are automatically escaped. NULL values are written as SQL NULL.

### Reading WXR files

`WXREntityReader` streams through WXR files and emits entities as it encounters them. It never loads the full document into memory, so it can handle exports of any size:

```php
use WordPress\DataLiberation\EntityReader\WXREntityReader;

$reader = WXREntityReader::create();
$reader->append_bytes( file_get_contents( 'export.xml' ) );
$reader->input_finished();

while ( $reader->next_entity() ) {
    $entity = $reader->get_entity();
    switch ( $entity->get_type() ) {
        case 'site_option':
            $data = $entity->get_data();
            // $data['option_name'], $data['option_value']
            break;

        case 'post':
            $data = $entity->get_data();
            // $data['post_title'], $data['post_content'], etc.
            break;

        case 'comment':
            $data = $entity->get_data();
            // $data['comment_author'], $data['comment_content'], etc.
            break;
    }
}
```

For streaming large files without reading them entirely into memory:

```php
$reader = WXREntityReader::create();
$handle = fopen( 'large-export.xml', 'r' );

while ( ! feof( $handle ) ) {
    $reader->append_bytes( fread( $handle, 65536 ) );

    while ( $reader->next_entity() ) {
        $entity = $reader->get_entity();
        // Process entity...
    }
}
fclose( $handle );
```

### Processing block markup

`BlockMarkupProcessor` parses WordPress block comments (like `<!-- wp:paragraph -->`) and lets you inspect and modify block names, attributes, and content:

```php
use WordPress\DataLiberation\BlockMarkup\BlockMarkupProcessor;

$markup = '<!-- wp:image {"url": "/photo.jpg", "class": "wide"} -->'
    . '<img src="/photo.jpg" />'
    . '<!-- /wp:image -->';

$p = new BlockMarkupProcessor( $markup );

while ( $p->next_token() ) {
    if ( '#block-comment' === $p->get_token_type() ) {
        echo $p->get_block_name();       // "wp:image"
        $attrs = $p->get_block_attributes(); // ["url" => "/photo.jpg", "class" => "wide"]
        echo $p->is_block_closer() ? 'closer' : 'opener';
    }
}
```

Iterate over individual block attributes and modify them:

```php
$p = new BlockMarkupProcessor(
    '<!-- wp:image {"class": "wp-bold", "url": "old.png"} -->'
);
$p->next_token();

while ( $p->next_block_attribute() ) {
    $key   = $p->get_block_attribute_key();   // "class", then "url"
    $value = $p->get_block_attribute_value();  // "wp-bold", then "old.png"

    if ( 'url' === $key ) {
        $p->set_block_attribute_value( 'new.png' );
    }
}

echo $p->get_updated_html();
// <!-- wp:image {"class":"wp-bold","url":"new.png"} -->
```

### Rewriting URLs in block markup

`BlockMarkupUrlProcessor` finds and rewrites URLs across all parts of block markup -- HTML attributes, block comment attributes, text nodes, and inline CSS:

```php
use WordPress\DataLiberation\BlockMarkup\BlockMarkupUrlProcessor;

$markup = '<a href="https://old-site.com/about">About</a>'
    . '<!-- wp:image {"url": "https://old-site.com/photo.jpg"} -->';

$p = new BlockMarkupUrlProcessor( $markup, 'https://old-site.com' );

while ( $p->next_url() ) {
    $raw = $p->get_raw_url();           // "https://old-site.com/about", etc.
    $parsed = $p->get_parsed_url();     // URL object with host, path, etc.

    // Rewrite to a new domain
    $new_url = str_replace( 'old-site.com', 'new-site.com', $raw );
    $p->set_raw_url( $new_url );
}

echo $p->get_updated_html();
```

### CSS tokenization

`CSSProcessor` tokenizes CSS according to the CSS Syntax Level 3 specification. It processes stylesheets one token at a time without building a full AST:

```php
use WordPress\DataLiberation\CSS\CSSProcessor;

$css = 'body { background: url("image.png"); color: red; }';
$processor = CSSProcessor::create( $css );

while ( $processor->next_token() ) {
    echo $processor->get_token_type() . ': ' . $processor->get_normalized_token() . "\n";
}
```

## API Reference

### Entity types (ImportEntity)

| Type | Constants | Key data fields |
|------|-----------|----------------|
| `post` | `ImportEntity::TYPE_POST` | `post_title`, `post_content`, `post_date`, `guid`, `post_name`, `status`, `post_type`, `post_id` |
| `post_meta` | `ImportEntity::TYPE_POST_META` | `meta_key`, `meta_value` |
| `comment` | `ImportEntity::TYPE_COMMENT` | `comment_id`, `comment_author`, `comment_content`, `comment_date`, `comment_approved` |
| `term` | `ImportEntity::TYPE_TERM` | `term_id`, `taxonomy`, `slug`, `parent` |
| `site_option` | `ImportEntity::TYPE_SITE_OPTION` | `option_name`, `option_value` |
| `database_row` | -- | `table`, `record` (associative array of column => value) |

### Writers (EntityWriter interface)

| Class | Purpose |
|-------|---------|
| `WXRWriter` | Writes WXR XML exports. Constructor takes a `ByteWriteStream`. |
| `MySQLDumpWriter` | Writes SQL INSERT statements. Constructor takes a `ByteWriteStream`. |

Shared methods: `append_entity( ImportEntity )`, `close_writing()`, `get_reentrancy_cursor()`.

### Readers (EntityReader interface)

| Class | Purpose |
|-------|---------|
| `WXREntityReader` | Streams WXR XML files. Use `WXREntityReader::create()`. |
| `HTMLEntityReader` | Converts an HTML file into WordPress entities. |
| `EPubEntityReader` | Reads EPUB documents as WordPress entities. |
| `DatabaseRowsEntityReader` | Reads database query results as entities. |
| `FilesystemEntityReader` | Reads a directory tree as entities. |

Shared methods: `next_entity()`, `get_entity()`, `is_finished()`, `get_reentrancy_cursor()`.

### Block markup processors

| Class | Purpose |
|-------|---------|
| `BlockMarkupProcessor` | Parses block comments. Key methods: `next_token()`, `get_block_name()`, `get_block_attributes()`, `is_self_closing_block()`, `is_block_closer()`, `next_block_attribute()`, `set_block_attribute_value()`. |
| `BlockMarkupUrlProcessor` | Finds and rewrites URLs in block markup. Key methods: `next_url()`, `get_raw_url()`, `get_parsed_url()`, `set_raw_url()`. |

### CSS processors

| Class | Purpose |
|-------|---------|
| `CSSProcessor` | CSS Syntax Level 3 tokenizer. Key methods: `next_token()`, `get_token_type()`, `get_normalized_token()`. |
| `CSSURLProcessor` | Finds and rewrites URLs inside CSS. |

## Requirements

- PHP 7.2+
- No external dependencies
