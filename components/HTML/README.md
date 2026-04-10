# HTML

A full HTML5 parser and tag processor implemented in pure PHP, mirroring WordPress core's HTML API. It provides two levels of access: `WP_HTML_Tag_Processor` for fast, linear scanning and modification of HTML attributes, and `WP_HTML_Processor` for structure-aware parsing that understands nested elements, implicit tag closers, and the HTML5 insertion algorithm. No libxml2, no DOM extension, no external dependencies.

## Installation

```
composer require wp-php-toolkit/html
```

## Quick Start

Find and modify HTML tags:

```php
$html = '<div class="entry"><img src="photo.jpg"><p>Hello</p></div>';

$tags = new WP_HTML_Tag_Processor( $html );
if ( $tags->next_tag( 'img' ) ) {
    $tags->set_attribute( 'loading', 'lazy' );
    $tags->add_class( 'responsive' );
}

echo $tags->get_updated_html();
// <div class="entry"><img loading="lazy" class="responsive" src="photo.jpg"><p>Hello</p></div>
```

## Usage

### Tag Processor: Linear Scanning

`WP_HTML_Tag_Processor` scans through HTML linearly, finding tags by name, class, or other criteria. It does not parse the DOM tree -- it operates on a flat stream of tags, which makes it fast and predictable.

```php
$html = '<ul><li class="active">First</li><li>Second</li><li>Third</li></ul>';
$tags = new WP_HTML_Tag_Processor( $html );

// Find tags by name.
while ( $tags->next_tag( 'li' ) ) {
    $tags->set_attribute( 'role', 'listitem' );
}
echo $tags->get_updated_html();
// Every <li> now has role="listitem".
```

#### Querying with Arrays

Pass an array to `next_tag()` to match by tag name, class, or both:

```php
$tags = new WP_HTML_Tag_Processor( $html );

// Find by tag name.
$tags->next_tag( array( 'tag_name' => 'img' ) );

// Find by CSS class.
$tags->next_tag( array( 'class_name' => 'hero' ) );

// Find by both.
$tags->next_tag( array( 'tag_name' => 'div', 'class_name' => 'sidebar' ) );
```

#### Reading Attributes

```php
$html = '<a href="https://wordpress.org" title="WP" class="button primary">Visit</a>';
$tags = new WP_HTML_Tag_Processor( $html );

if ( $tags->next_tag( 'a' ) ) {
    $tags->get_tag();                   // 'A'
    $tags->get_attribute( 'href' );     // 'https://wordpress.org'
    $tags->get_attribute( 'title' );    // 'WP'
    $tags->get_attribute( 'missing' );  // null (attribute not present)
    $tags->has_class( 'button' );       // true
    $tags->has_class( 'danger' );       // false
}
```

#### Modifying Attributes and Classes

```php
$tags = new WP_HTML_Tag_Processor( '<div class="old" data-x="1">' );
$tags->next_tag();

$tags->set_attribute( 'id', 'main' );       // Add a new attribute.
$tags->set_attribute( 'data-x', '2' );      // Update an existing attribute.
$tags->remove_attribute( 'data-x' );        // Remove an attribute.
$tags->add_class( 'new' );                  // Add a CSS class.
$tags->remove_class( 'old' );               // Remove a CSS class.

echo $tags->get_updated_html();
// <div id="main" class=" new">
```

#### Custom Filtering

When the query syntax is not enough, loop through tags and inspect them directly:

```php
$tags = new WP_HTML_Tag_Processor( $html );
while ( $tags->next_tag() ) {
    if (
        ( 'DIV' === $tags->get_tag() || 'SPAN' === $tags->get_tag() ) &&
        'highlight' === $tags->get_attribute( 'data-style' )
    ) {
        $tags->add_class( 'theme-highlight' );
    }
}
```

#### Bookmarks

Bookmarks let you save a position and return to it later. This is the one exception to the forward-only scanning rule:

```php
$tags = new WP_HTML_Tag_Processor( '<div><span>text</span></div>' );
$tags->next_tag( 'div' );
$tags->set_bookmark( 'the-div' );

$tags->next_tag( 'span' );
$tags->set_attribute( 'class', 'inner' );

// Jump back to the bookmarked position.
$tags->seek( 'the-div' );
$tags->set_attribute( 'class', 'outer' );

$tags->release_bookmark( 'the-div' );
echo $tags->get_updated_html();
// <div class="outer"><span class="inner">text</span></div>
```

### HTML Processor: Structure-Aware Parsing

`WP_HTML_Processor` extends the tag processor with HTML5-compliant structural parsing. It understands nested elements, implied closers, and can query by element nesting (breadcrumbs).

```php
$html = '<figure><img src="photo.jpg"><figcaption>A <em>lovely</em> day</figcaption></figure>';

$processor = WP_HTML_Processor::create_fragment( $html );

// Find an IMG that is a direct child of FIGURE.
if ( $processor->next_tag( array( 'breadcrumbs' => array( 'FIGURE', 'IMG' ) ) ) ) {
    $processor->set_attribute( 'loading', 'lazy' );
}
```

#### Breadcrumbs

Breadcrumbs represent the stack of open elements from the root down to the current tag. They work like a CSS child combinator (`FIGURE > IMG`):

```php
$html = '<div><p>One</p><p>Two <em>Three</em></p></div>';
$processor = WP_HTML_Processor::create_fragment( $html );

while ( $processor->next_tag() ) {
    $crumbs = $processor->get_breadcrumbs();
    // First match:  array( 'HTML', 'BODY', 'DIV' )
    // Second match: array( 'HTML', 'BODY', 'DIV', 'P' )
    // ... and so on for each tag encountered.
}
```

#### Token-Level Access

Both processors support token-level iteration via `next_token()`, which visits every token in the document including text nodes, comments, and tags:

```php
$processor = WP_HTML_Processor::create_fragment( '<p>Hello <b>world</b></p>' );

while ( $processor->next_token() ) {
    $type = $processor->get_token_type();
    // '#tag'  for HTML tags (openers and closers)
    // '#text' for text content
    // Other types for comments, doctypes, etc.

    if ( '#text' === $type ) {
        echo $processor->get_modifiable_text();
        // "Hello ", then "world"
    }
}
```

#### Serialization

The processor can serialize its parsed document back to a well-formed HTML string:

```php
$messy = '<p>one<p>two';  // Missing closer -- valid HTML5, parsed as two paragraphs.
$processor = WP_HTML_Processor::create_fragment( $messy );
echo $processor->serialize();
// <html><head></head><body><p>one</p><p>two</p></body></html>
```

### HTML Decoder

`WP_HTML_Decoder` decodes HTML character references in text nodes and attribute values, handling named entities, numeric references, and edge cases from the HTML5 spec:

```php
$decoded = WP_HTML_Decoder::decode_text_node( 'AT&amp;T &mdash; 100&percnt;' );
// 'AT&T — 100%'

$decoded = WP_HTML_Decoder::decode_attribute( 'path?a=1&amp;b=2' );
// 'path?a=1&b=2'

// Check if an encoded attribute value starts with a given string.
$starts = WP_HTML_Decoder::attribute_starts_with( 'http&colon;//example.com', 'http:', 'ascii-case-insensitive' );
// true
```

## API Reference

### WP_HTML_Tag_Processor

| Method | Description |
|--------|-------------|
| `__construct( $html )` | Create a processor for the given HTML string |
| `next_tag( $query = null )` | Advance to the next matching tag; returns `bool` |
| `next_token()` | Advance to the next token (tag, text, comment); returns `bool` |
| `get_tag()` | Get the uppercase tag name of the current tag |
| `get_token_type()` | Get the token type (`#tag`, `#text`, `#comment`, etc.) |
| `get_attribute( $name )` | Get an attribute value, `null` if missing, `true` for boolean attributes |
| `set_attribute( $name, $value )` | Set or update an attribute |
| `remove_attribute( $name )` | Remove an attribute |
| `add_class( $class_name )` | Add a CSS class |
| `remove_class( $class_name )` | Remove a CSS class |
| `has_class( $wanted_class )` | Check if a CSS class is present |
| `get_updated_html()` | Get the modified HTML string |
| `get_modifiable_text()` | Get the text content of the current text node |
| `set_bookmark( $name )` | Save the current position |
| `seek( $bookmark_name )` | Return to a bookmarked position |
| `release_bookmark( $name )` | Free a bookmark |

### WP_HTML_Processor

| Method | Description |
|--------|-------------|
| `create_fragment( $html )` | Create a processor for an HTML fragment (static factory) |
| `next_tag( $query = null )` | Find the next tag, supports `'breadcrumbs'` queries |
| `next_token()` | Advance to the next token with structural awareness |
| `get_breadcrumbs()` | Get the stack of open elements as an array of tag names |
| `serialize()` | Serialize the parsed document to well-formed HTML |

Inherits all attribute and class methods from `WP_HTML_Tag_Processor`.

### WP_HTML_Decoder

| Method | Description |
|--------|-------------|
| `decode_text_node( $text )` | Decode character references in an HTML text node |
| `decode_attribute( $text )` | Decode character references in an attribute value |
| `attribute_starts_with( $haystack, $search, $case )` | Check if an encoded attribute starts with a plain string |

## Attribution

This component is extracted from [WordPress core's HTML API](https://developer.wordpress.org/reference/classes/wp_html_processor/). The `WP_HTML_Tag_Processor` and `WP_HTML_Processor` were created by the WordPress core team to provide a safe, spec-compliant way to modify HTML without regular expressions. Licensed under GPL v2.

## Requirements

- PHP 7.2+
- No external dependencies
