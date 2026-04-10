# XML

A pure PHP XML processor that parses and modifies XML documents without requiring the `libxml2` extension. It implements a subset of the XML 1.0 specification and operates as a streaming, forward-only scanner with namespace support, attribute manipulation, and bookmark-based seeking. Designed for environments where native XML extensions are unavailable, such as sandboxed WordPress installations.

## Installation

```
composer require wp-php-toolkit/xml
```

## Quick Start

```php
use WordPress\XML\XMLProcessor;

$xml = '<catalog><book price="29.99"><title>PHP Internals</title></book></catalog>';
$processor = XMLProcessor::create_from_string( $xml );

if ( $processor->next_tag( 'book' ) ) {
    $price = $processor->get_attribute( '', 'price' ); // "29.99"
    $processor->set_attribute( '', 'price', '24.99' );
}

echo $processor->get_updated_xml();
// <catalog><book price="24.99"><title>PHP Internals</title></book></catalog>
```

## Usage

### Navigating Tags

Use `next_tag()` to move the cursor forward through the document. It accepts a tag name string, a namespace-qualified array, or a query array.

```php
$xml = '<root><chapter><section id="intro">Hello</section></chapter></root>';
$processor = XMLProcessor::create_from_string( $xml );

// Find any tag
$processor->next_tag();
echo $processor->get_tag_local_name(); // "root"

// Find a specific tag by name
$processor->next_tag( 'section' );
echo $processor->get_attribute( '', 'id' ); // "intro"
```

### Working with Namespaces

Namespaces are first-class citizens. Methods like `get_attribute()` and `set_attribute()` take the full namespace URI as the first argument, not a prefix.

```php
$xml = '<root xmlns:wp="http://wordpress.org/export/1.2/">'
     . '<wp:post wp:status="draft">Content</wp:post>'
     . '</root>';

$processor = XMLProcessor::create_from_string( $xml );
$ns = 'http://wordpress.org/export/1.2/';

// Find a namespaced tag by passing array( namespace_uri, local_name )
if ( $processor->next_tag( array( $ns, 'post' ) ) ) {
    echo $processor->get_tag_local_name();  // "post"
    echo $processor->get_tag_namespace();   // "http://wordpress.org/export/1.2/"

    // Read and write namespaced attributes
    echo $processor->get_attribute( $ns, 'status' ); // "draft"
    $processor->set_attribute( $ns, 'status', 'published' );
}

echo $processor->get_updated_xml();
// <root xmlns:wp="http://wordpress.org/export/1.2/"><wp:post wp:status="published">Content</wp:post></root>
```

### Modifying Attributes

```php
$xml = '<config><setting name="timeout" value="30" deprecated="yes" /></config>';
$processor = XMLProcessor::create_from_string( $xml );

if ( $processor->next_tag( 'setting' ) ) {
    // Update an attribute
    $processor->set_attribute( '', 'value', '60' );

    // Remove an attribute
    $processor->remove_attribute( '', 'deprecated' );

    // Add a new attribute
    $processor->set_attribute( '', 'unit', 'seconds' );
}

echo $processor->get_updated_xml();
// <config><setting unit="seconds" name="timeout" value="60"  /></config>
```

### Token-Level Processing

Use `next_token()` to visit every lexical token in the document, including text nodes, comments, CDATA sections, and processing instructions.

```php
$xml = '<article><title>Hello World</title><body>Some text</body></article>';
$processor = XMLProcessor::create_from_string( $xml );

$text_content = '';
while ( $processor->next_token() ) {
    if ( '#text' === $processor->get_token_name() ) {
        $text_content .= $processor->get_modifiable_text();
    }
}

echo $text_content; // "Hello WorldSome text"
```

### Modifying Text Content

```php
$xml = '<greeting>Hello</greeting>';
$processor = XMLProcessor::create_from_string( $xml );

$processor->next_tag( 'greeting' );
$processor->next_token(); // Move to the text node
$processor->set_modifiable_text( 'Goodbye' );

echo $processor->get_updated_xml();
// <greeting>Goodbye</greeting>
```

### Self-Closing Elements

```php
$xml = '<root><img src="photo.jpg" /><br /><p>Text</p></root>';
$processor = XMLProcessor::create_from_string( $xml );

while ( $processor->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
    if ( $processor->is_empty_element() ) {
        echo $processor->get_tag_local_name() . ' is self-closing' . "\n";
    }
}
// img is self-closing
// br is self-closing
```

### Bookmarks

Bookmarks let you save a position in the document and return to it later. This is useful when you need to inspect downstream content before deciding how to modify an earlier tag.

```php
$xml = '<list><item>A</item><item>B</item><item>C</item></list>';
$processor = XMLProcessor::create_from_string( $xml );

$processor->next_tag( 'list' );
$processor->set_bookmark( 'list-start' );

// Count items
$count = 0;
while ( $processor->next_tag( 'item' ) ) {
    $count++;
}

// Go back and annotate the list with the count
$processor->seek( 'list-start' );
$processor->set_attribute( '', 'data-count', (string) $count );

echo $processor->get_updated_xml();
// <list data-count="3"><item>A</item><item>B</item><item>C</item></list>
```

### Streaming XML Processing

For large documents, use `create_for_streaming()` to feed XML in chunks and process it incrementally.

```php
$processor = XMLProcessor::create_for_streaming();

// Feed chunks of XML data
$processor->append_bytes( '<root><item id=' );
$processor->append_bytes( '"1">First</item>' );
$processor->append_bytes( '<item id="2">Second</item></root>' );
$processor->input_finished();

// Process all tags
while ( $processor->next_tag( 'item' ) ) {
    echo $processor->get_attribute( '', 'id' ) . "\n";
}
// 1
// 2
```

## API Reference

### XMLProcessor

| Method | Description |
|--------|-------------|
| `create_from_string( $xml )` | Create a processor for a complete XML string |
| `create_for_streaming( $xml )` | Create a processor that accepts incremental input |
| `next_tag( $query )` | Advance to the next matching tag. Returns `true` if found |
| `next_token()` | Advance to the next lexical token of any kind |
| `get_tag_local_name()` | Get the local name of the current tag |
| `get_tag_namespace()` | Get the namespace URI of the current tag |
| `is_tag_opener()` | Whether the current tag is an opening tag |
| `is_tag_closer()` | Whether the current tag is a closing tag |
| `is_empty_element()` | Whether the current tag is self-closing |
| `get_attribute( $ns, $name )` | Get the decoded value of an attribute |
| `set_attribute( $ns, $name, $value )` | Set or add an attribute on the current tag |
| `remove_attribute( $ns, $name )` | Remove an attribute from the current tag |
| `get_modifiable_text()` | Get decoded text content of the current text/CDATA/comment node |
| `set_modifiable_text( $value )` | Replace text content of the current node |
| `get_token_name()` | Get the name of the current token (tag name, `#text`, `#comment`, etc.) |
| `set_bookmark( $name )` | Save the current position with a name |
| `seek( $name )` | Return to a previously saved bookmark |
| `release_bookmark( $name )` | Free a bookmark |
| `get_updated_xml()` | Get the full XML document with all modifications applied |
| `append_bytes( $chunk )` | Feed more XML bytes (streaming mode) |
| `input_finished()` | Signal that all XML bytes have been provided |
| `is_paused_at_incomplete_input()` | Whether the parser stopped due to incomplete input |

## Attribution

The `XMLProcessor` follows the same architecture and API patterns as [WordPress core's HTML API](https://developer.wordpress.org/reference/classes/wp_html_processor/), extending the streaming tag-processor approach from HTML to XML. Licensed under GPL v2.

## Requirements

- PHP 7.2+
- No external PHP extensions required (no libxml2)
