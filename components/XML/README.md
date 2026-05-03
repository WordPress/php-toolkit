# XML

<!-- docs-site-banner -->
> 📚 **Runnable examples:** [https://wordpress.github.io/php-toolkit/reference/xml.html](https://wordpress.github.io/php-toolkit/reference/xml.html)
> Open the page to edit each snippet in your browser and run it in WordPress Playground.
<!-- /docs-site-banner -->

## Why this exists

PHP ships with excellent XML support — `SimpleXML`, `DOMDocument`, `XMLReader` — but all of them rely on `libxml2`, a native C library. In most PHP environments that's fine. In WordPress Playground, which runs PHP compiled to WebAssembly in the browser, native extensions aren't available. You get the PHP standard library and nothing else.

WordPress Playground needs to parse and modify XML to handle OPML files, RSS feeds, WordPress export files (WXR), and configuration formats. This component provides a pure PHP XML processor — no extensions, no external libraries — that covers the practical subset of XML 1.0 that real-world documents use.

The design mirrors `WP_HTML_Tag_Processor` from the HTML component: a streaming, forward-only cursor you advance tag by tag, reading and modifying attributes as you go. If you already know the HTML processor, you'll be immediately comfortable here.

## How it works

`XMLProcessor` is a forward-only scanner over an XML document string. Under the hood it implements a hand-written lexer that recognizes the token types XML defines: opening tags, closing tags, self-closing tags, text content, CDATA sections, processing instructions, and comments. It doesn't build a DOM tree. It doesn't allocate node objects. It simply advances a cursor through the bytes and lets you inspect and modify the token it's currently pointing at.

**Namespaces** work the same way they do in XML 1.0: a namespace declaration like `xmlns:wp="http://wordpress.org/export/1.2/"` maps a prefix to an expanded namespace name. Many namespace names look like URLs, but they are identifiers, not URLs the processor fetches. When querying for tags, you provide the expanded namespace name and the local name (not the prefix), making queries stable across documents that use different prefix conventions.

**Bookmarks** let you mark positions in the document and seek back to them. This is useful for multi-pass processing: scan forward to collect information, then seek back to the marked positions to make changes.

**Streaming mode** accepts input in chunks. The scanner can tell you when it needs more data, so you can process large documents without loading them entirely into memory.

## Supported subset

The processor handles:
- Well-formed UTF-8 encoded XML documents
- Namespace declarations and namespace-qualified tag queries
- Processing instructions and comments (scannable but not modifiable)
- CDATA sections (treated as opaque text)
- All attribute operations: read, set, remove

It explicitly does not support:
- DTDs, DOCTYPE declarations, ATTLIST, ENTITY, or conditional sections
- XML schemas or validation
- Encoding declarations other than UTF-8

For the XML that WordPress-ecosystem tools actually produce and consume, these constraints are rarely a limitation.

## Usage

### Scan tags and read attributes

```php
use WordPress\XML\XMLProcessor;

$xml = XMLProcessor::create_from_string( $document );

while ( $xml->next_tag() ) {
    echo $xml->get_tag() . "\n";                    // local tag name
    echo $xml->get_attribute( 'id' ) . "\n";        // attribute value or null
}
```

### Query by tag name

```php
$xml = XMLProcessor::create_from_string( $document );

while ( $xml->next_tag( 'item' ) ) {
    // Only visits <item> opening tags.
    $title = '';
    if ( $xml->next_tag( 'title' ) ) {
        $xml->next_token();
        $title = $xml->get_modifiable_text();
    }
    echo $title . "\n";
}
```

### Query by namespace

When working with namespaced XML, pass a `[namespace_name, local_name]` tuple to `next_tag()`:

```php
// WordPress export files use the "wp" namespace.
$ns  = 'http://wordpress.org/export/1.2/';
$xml = XMLProcessor::create_from_string( $wxr_document );

while ( $xml->next_tag( array( $ns, 'post_id' ) ) ) {
    $xml->next_token();
    echo $xml->get_modifiable_text() . "\n";  // the post ID value
}
```

### Modify attributes

```php
$xml = XMLProcessor::create_from_string( '<items><item id="1" status="draft"/></items>' );

while ( $xml->next_tag( 'item' ) ) {
    $xml->set_attribute( 'status', 'published' );
    $xml->remove_attribute( 'id' );
}

echo $xml->get_updated_xml();
// <items><item status="published"/></items>
```

### Use bookmarks for multi-pass processing

```php
$xml = XMLProcessor::create_from_string( $document );
$ids = array();

// First pass: collect all IDs.
while ( $xml->next_tag( 'item' ) ) {
    $xml->set_bookmark( 'item_' . $xml->get_attribute( 'id' ) );
    $ids[] = $xml->get_attribute( 'id' );
}

// Second pass: update specific items.
foreach ( $ids as $id ) {
    $xml->seek( 'item_' . $id );
    $xml->set_attribute( 'processed', 'true' );
}
```

### Process a document in streaming chunks

For large documents, feed data in pieces:

```php
$xml = XMLProcessor::create_for_streaming();

while ( $chunk = fread( $handle, 65536 ) ) {
    $xml->append_bytes( $chunk );

    while ( $xml->next_tag() ) {
        // Process tokens as they arrive.
    }
}
```

## Relationship to the HTML component

`XMLProcessor` and `WP_HTML_Tag_Processor` share the same API philosophy: forward-only cursor, `next_tag()` to advance, attribute getters and setters, bookmarks for seeking, `get_updated_*()` to retrieve the modified document. The main differences are:

- XML is strict and well-formed; HTML is lenient about malformed markup.
- XML has namespaces as a first-class concept; HTML's namespace handling is implicit.
- XML has no equivalent to HTML's implicit tag closing rules.

If you're already comfortable with one, the other will feel immediately familiar.
