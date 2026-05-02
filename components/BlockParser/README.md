# BlockParser

## Why this exists

WordPress stores post content as annotated HTML. Instead of inventing a separate file format, it embeds block boundaries directly inside HTML comments:

```html
<!-- wp:paragraph -->
<p>Hello, world.</p>
<!-- /wp:paragraph -->

<!-- wp:image {"align":"center","sizeSlug":"large"} -->
<figure class="wp-block-image aligncenter"><img src="photo.jpg" /></figure>
<!-- /wp:image -->
```

Every WordPress editor, REST API response, and block renderer needs to turn that serialized markup into a structured tree. WordPress core ships `WP_Block_Parser` to do exactly that — but it's buried inside WordPress itself, tied to the full WordPress load. This component extracts it so you can parse block markup anywhere: CLI tools, build scripts, data-migration pipelines, standalone PHP apps — without booting WordPress.

## How it works

The parser is a single-pass, stack-based scanner. It moves forward through the document looking for HTML comments that follow the block annotation pattern. When it finds an opening comment like `<!-- wp:image {"align":"center"} -->`, it:

1. Decodes the JSON attributes from the comment body.
2. Pushes a frame onto a stack, recording the block name, attributes, and the byte offset where the block started.
3. Keeps scanning, collecting the raw HTML between the opening and closing comments as `innerHTML`.
4. If it encounters another `<!-- wp:... -->` before the closing comment, it recurses — pushing a new frame for the inner block.
5. When it finds a closing comment (`<!-- /wp:image -->`), it pops the frame, attaches any collected inner blocks, and appends the completed block to its parent.

Freeform content between blocks — plain HTML with no block annotations — becomes a "classic block" with `blockName` set to `null`.

The `innerContent` array is the most subtle part of the output. It interleaves child block positions with raw HTML chunks, letting renderers reconstruct the exact original layout. This is how the columns block describes which raw HTML wraps each inner column.

## Usage

### Parse a post's block content

```php
use WordPress\BlockParser\WP_Block_Parser;

$parser = new WP_Block_Parser();
$blocks = $parser->parse( $post_content );

foreach ( $blocks as $block ) {
    echo $block['blockName'];   // e.g. "core/paragraph"
    echo $block['innerHTML'];   // the raw HTML inside the block
    // $block['attrs']          — decoded JSON attributes
    // $block['innerBlocks']    — nested blocks (same structure, recursive)
    // $block['innerContent']   — interleaved HTML chunks + child-block slots
}
```

### Inspect block attributes

Attributes are encoded as JSON in the opening comment and decoded automatically:

```php
$markup = '<!-- wp:image {"sizeSlug":"large","linkDestination":"none"} -->'
        . '<figure>...</figure>'
        . '<!-- /wp:image -->';

$blocks = $parser->parse( $markup );
echo $blocks[0]['attrs']['sizeSlug'];  // "large"
```

### Walk a nested block tree

Blocks can contain other blocks. The `innerBlocks` key holds them recursively:

```php
function walk( array $blocks, int $depth = 0 ): void {
    foreach ( $blocks as $block ) {
        if ( $block['blockName'] === null ) {
            continue; // skip freeform HTML between blocks
        }
        echo str_repeat( '  ', $depth ) . $block['blockName'] . "\n";
        walk( $block['innerBlocks'], $depth + 1 );
    }
}

walk( $parser->parse( $post_content ) );
// core/columns
//   core/column
//     core/paragraph
//   core/column
//     core/image
```

### Reconstruct output using innerContent

The `innerContent` array lets you rebuild the original markup while swapping in rendered child blocks:

```php
function render_block( array $block ): string {
    $output      = '';
    $child_index = 0;

    foreach ( $block['innerContent'] as $chunk ) {
        if ( is_string( $chunk ) ) {
            $output .= $chunk;
        } else {
            // null = "insert rendered child block here"
            $output .= render_block( $block['innerBlocks'][ $child_index++ ] );
        }
    }

    return $output;
}
```

### Find all blocks of a specific type

```php
function find_blocks( array $blocks, string $name ): array {
    $found = array();
    foreach ( $blocks as $block ) {
        if ( $block['blockName'] === $name ) {
            $found[] = $block;
        }
        $found = array_merge( $found, find_blocks( $block['innerBlocks'], $name ) );
    }
    return $found;
}

$images = find_blocks( $parser->parse( $post_content ), 'core/image' );
```

## Block structure reference

Each parsed block is an associative array:

| Key | Type | Description |
|-----|------|-------------|
| `blockName` | `string\|null` | Namespaced block name, e.g. `"core/paragraph"`. `null` for classic/freeform content between blocks. |
| `attrs` | `array` | Decoded JSON attributes from the opening comment. Empty array if none. |
| `innerBlocks` | `array` | Recursively parsed child blocks in order of appearance. |
| `innerHTML` | `string` | The full raw HTML between the opening and closing comments, including inner block markup verbatim. |
| `innerContent` | `array` | Interleaved array: strings are raw HTML chunks, `null` values mark positions where a child block from `innerBlocks` should be inserted. |
