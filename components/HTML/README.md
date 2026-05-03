# HTML

<!-- docs-site-banner -->
> 📚 **Runnable examples:** [https://wordpress.github.io/php-toolkit/reference/html.html](https://wordpress.github.io/php-toolkit/reference/html.html)
> Open the page to edit each snippet in your browser and run it in WordPress Playground.
<!-- /docs-site-banner -->

## Why this exists

Modifying HTML in PHP usually means one of two things: string manipulation (fragile, breaks on any attribute ordering or whitespace variation) or loading the DOM extension (which requires libxml2, triggers errors on valid HTML5 that doesn't conform to XML rules, and mangles the document in the process).

WordPress needed a third option: a parser that can safely scan and modify real-world HTML — including malformed markup — without any native extension, without loading the whole document into memory, and without altering content it wasn't asked to change. The result is `WP_HTML_Tag_Processor` and `WP_HTML_Processor`, both mirrored here from WordPress core for use outside WordPress.

The key design insight is that most HTML processing tasks don't need a full DOM tree. You want to find a tag and change one of its attributes. You want to add a class to every `<img>`. You don't need to understand the document structure for that — you just need to scan forward efficiently. `WP_HTML_Tag_Processor` handles that case. When you do need structure — "find the `<img>` inside a `<figure>` inside a `<div class='gallery'>`" — `WP_HTML_Processor` adds the HTML5 insertion algorithm on top.

## How it works

### WP_HTML_Tag_Processor — fast linear scanning

`WP_HTML_Tag_Processor` is a forward-only cursor over raw HTML bytes. You call `next_tag()` to advance to the next opening tag, optionally filtering by tag name or attribute. When the cursor is on a tag, you can read and modify its attributes. Calling `get_updated_html()` at the end returns the original HTML with your modifications applied.

The important thing it does *not* do: it doesn't build a tree, it doesn't understand nesting, it doesn't run the HTML5 parsing algorithm. It's a fast string scanner that knows what a tag looks like. This makes it useful for a huge class of real-world tasks that don't need structure awareness, and it makes it very fast.

### WP_HTML_Processor — structure-aware parsing

`WP_HTML_Processor` extends the tag processor with the HTML5 tree-construction algorithm. It understands that `<li>` implicitly closes a previous `<li>`, that `<p>` can't contain `<div>`, that `<table>` creates a distinct parsing context. This lets you query by breadcrumb — a sequence of ancestor tags — rather than just by tag name.

When it encounters markup it can't safely handle (certain edge cases in the HTML5 spec), it returns `null` rather than producing incorrect output. The design principle is "correct output or no output" — it refuses to silently corrupt a document.

## Usage

### Add a class to every image

```php
use WordPress\HTML\WP_HTML_Tag_Processor;

$html = new WP_HTML_Tag_Processor( $content );

while ( $html->next_tag( 'img' ) ) {
    $html->add_class( 'responsive' );
}

echo $html->get_updated_html();
```

### Find a tag with a specific attribute

```php
$html = new WP_HTML_Tag_Processor( $content );

// Find the first <a> with a rel="noopener" attribute.
if ( $html->next_tag( array( 'tag_name' => 'a', 'tag_closers' => 'skip' ) ) ) {
    while ( $html->get_attribute( 'rel' ) !== 'noopener' ) {
        if ( ! $html->next_tag( array( 'tag_name' => 'a' ) ) ) {
            break;
        }
    }
    $html->set_attribute( 'target', '_blank' );
}
```

### Read and modify attributes

```php
$html = new WP_HTML_Tag_Processor( '<img src="old.jpg" alt="A photo" class="hero">' );
$html->next_tag( 'img' );

echo $html->get_attribute( 'src' );   // "old.jpg"
echo $html->get_attribute( 'alt' );   // "A photo"

$html->set_attribute( 'src', 'new.jpg' );
$html->remove_attribute( 'class' );

echo $html->get_updated_html();
// <img src="new.jpg" alt="A photo">
```

### Query by structure with WP_HTML_Processor

When you need to find a tag based on where it sits in the document tree, use `WP_HTML_Processor`. Breadcrumbs work like a simplified CSS selector with only the child combinator:

```php
use WordPress\HTML\WP_HTML_Processor;

$html = WP_HTML_Processor::create_fragment( $content );

// Find every <img> that is a direct child of a <figure>.
while ( $html->next_tag( array( 'breadcrumbs' => array( 'figure', 'img' ) ) ) ) {
    $html->set_attribute( 'loading', 'lazy' );
}

echo $html->get_updated_html();
```

### Use bookmarks to return to a position

Sometimes you want to mark a position in the document and come back to it later:

```php
$html = new WP_HTML_Tag_Processor( $content );

while ( $html->next_tag( 'div' ) ) {
    if ( $html->get_attribute( 'id' ) === 'header' ) {
        $html->set_bookmark( 'header' );
    }
}

// ... do other work ...

$html->seek( 'header' );
$html->add_class( 'processed' );
```

### Decode HTML entities

`WP_HTML_Decoder` handles entity decoding, including numeric character references and all named HTML entities, without needing the DOM or `html_entity_decode()`:

```php
use WordPress\HTML\WP_HTML_Decoder;

echo WP_HTML_Decoder::decode( '&lt;em&gt;Hello&lt;/em&gt;' );
// <em>Hello</em>

echo WP_HTML_Decoder::decode( '&#128512;' );
// 😀
```

## Choosing between the two processors

Use `WP_HTML_Tag_Processor` when:
- You're modifying attributes on specific tags (add/remove classes, change `src`, set `data-*`).
- You don't care about document structure — just "find tags matching this name/attribute."
- You want maximum performance on large documents.

Use `WP_HTML_Processor` when:
- You need to find tags based on their ancestors ("the `<img>` inside a `<figure>`").
- You're working with content that relies on implicit tag behavior (e.g., HTML that omits `</p>` or `</li>` close tags).
- You need to understand the document tree, not just scan its surface.

## Important limitations

Neither processor supports:
- Modifying text content (only attributes and class names can be changed).
- Inserting or removing entire tags (you can modify existing ones only).
- JavaScript or CSS inside the document (those are treated as opaque text).

`WP_HTML_Processor` will abort with `null` on constructs it can't safely handle. Always check return values when using it on untrusted or complex HTML.
