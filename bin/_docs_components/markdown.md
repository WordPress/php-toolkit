---
slug: markdown
title: Markdown
install: wp-php-toolkit/markdown

credit_title: Built on league/commonmark
credit_body: |
  Markdown parsing is delegated to <a href="https://commonmark.thephpleague.com/"><code>league/commonmark</code></a>; YAML frontmatter is handled by <a href="https://github.com/webuni/front-matter"><code>webuni/front-matter</code></a>. The toolkit's own work is the bridge between CommonMark's AST and <a href="https://developer.wordpress.org/block-editor/reference-guides/block-api/">WordPress block markup</a>, in both directions.

see_also: blockparser | BlockParser | Understand the block tree created from Markdown output.
see_also: html | HTML | Rewrite rendered HTML fragments without using DOMDocument.
see_also: dataliberation | DataLiberation | Turn Markdown folders into import/export streams.
---

Bidirectional converter between Markdown and WordPress block markup. Useful for moving content between Markdown files and WordPress while preserving the structures both formats can express.

## Why this exists

<p>Many publishing workflows start in Markdown: documentation sites, static-site generators, Git-backed editorial workflows, Obsidian vaults, and developer notes. WordPress stores editor content as block markup. Moving between those worlds by string replacement loses metadata and quickly breaks on lists, tables, code blocks, and frontmatter.</p>

<p>The Markdown component provides a structured bridge. <code>MarkdownConsumer</code> turns Markdown plus frontmatter into block markup and metadata; <code>MarkdownProducer</code> turns supported block markup back into Markdown. The conversion is meant for practical content workflows, not byte-identical round-tripping of every custom block attribute.</p>

## Markdown to blocks

<p>Feed Markdown into <code>MarkdownConsumer</code>, get block markup back. The result is a <code>BlocksWithMetadata</code> object that holds both the rendered blocks and any frontmatter parsed from the document.</p>

<!-- snippet:
filename: quickstart.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use WordPress\Markdown\MarkdownConsumer;

$result = ( new MarkdownConsumer( "# Hello\n\nWelcome to **WordPress**." ) )->consume();
echo $result->get_block_markup();
```

<!-- expected-output -->
```
<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading" id="hello">Hello</h1>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Welcome to <b>WordPress</b>.</p>
<!-- /wp:paragraph -->
```

## Round-trip: blocks back to Markdown

<p>Pair <code>MarkdownProducer</code> with <code>MarkdownConsumer</code> to convert in either direction. Round-tripping is lossy for block attributes that have no Markdown representation (custom classes, alignment), so do not expect byte-perfect equality.</p>

<!-- snippet:
filename: roundtrip.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use WordPress\Markdown\MarkdownConsumer;
use WordPress\Markdown\MarkdownProducer;

$md       = "## Round trip\n\n- one\n- two\n- three\n";
$blocks   = ( new MarkdownConsumer( $md ) )->consume();
$markdown = ( new MarkdownProducer( $blocks ) )->produce();

echo $markdown;
```

<!-- expected-output -->
```
## Round trip

- one
- two
- three
```

## Reading YAML frontmatter as post meta

<p>Frontmatter keys come back as arrays so a single key can hold multiple values. Use <code>get_meta_value()</code> when you only want the first scalar.</p>

<!-- snippet:
filename: frontmatter.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use WordPress\Markdown\MarkdownConsumer;

$md = <<<MD
---
post_title: "The Name of the Wind"
post_status: publish
tags: [fantasy, kingkiller]
---

Once upon a time...
MD;

$consumer = new MarkdownConsumer( $md );
$consumer->consume();

echo 'Title: '   . $consumer->get_meta_value( 'post_title' )  . "\n";
echo 'Status: '  . $consumer->get_meta_value( 'post_status' ) . "\n";
$metadata = $consumer->get_all_metadata();
echo 'Tags: ' . implode( ', ', $metadata['tags'][0] ) . "\n";
```

<!-- expected-output -->
```
Title: The Name of the Wind
Status: publish
Tags: fantasy, kingkiller
```

## Migrating an Obsidian or Hugo folder of Markdown

<p>Walk a directory of <code>.md</code> files (Obsidian vault, Hugo <code>content/</code>, Jekyll <code>_posts</code>) and emit one block-markup record per file.</p>

<!-- snippet:
filename: migrate-folder.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use WordPress\Markdown\MarkdownConsumer;

@mkdir( '/tmp/vault', 0777, true );
file_put_contents( '/tmp/vault/welcome.md', "---\ntitle: Welcome\n---\n\nHello world." );
file_put_contents( '/tmp/vault/roadmap.md', "# Roadmap\n\n1. Ship\n2. Iterate" );

foreach ( glob( '/tmp/vault/*.md' ) as $path ) {
	$consumer = new MarkdownConsumer( file_get_contents( $path ) );
	$consumer->consume();
	$title = $consumer->get_meta_value( 'title' );
	if ( ! $title ) $title = basename( $path, '.md' );
	echo "=== $title ($path) ===\n";
	echo substr( $consumer->get_block_markup(), 0, 120 ) . "...\n\n";
}
```

<!-- expected-output -->
```
=== roadmap (/tmp/<tempfile>/roadmap.md) ===
<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading" id="roadmap">Roadmap</h1>
<!-- /wp:heading -->

<!-- wp:lis...

=== Welcome (/tmp/<tempfile>/welcome.md) ===
<!-- wp:paragraph -->
<p>Hello world.</p>
<!-- /wp:paragraph -->

...
```

## Counting blocks produced by a Markdown document

<p>After conversion, the block markup is plain WordPress block markup, so <code>parse_blocks()</code> works on it directly. The standard way to introspect what the converter emitted before saving to the database.</p>

<!-- snippet:
filename: count-blocks.php
runnable: true
-->
````php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use WordPress\Markdown\MarkdownConsumer;

$md = <<<MD
# Title

A paragraph with **bold** and *italics*.

| Col A | Col B |
|-------|-------|
| 1     | 2     |

```php
echo 'hi';
```

> A quote.
MD;

$blocks = ( new MarkdownConsumer( $md ) )->consume()->get_block_markup();
$counts = array();
$queue  = parse_blocks( $blocks );

while ( $queue ) {
	$block = array_shift( $queue );
	if ( null !== $block['blockName'] ) {
		$name             = $block['blockName'];
		$counts[ $name ] = isset( $counts[ $name ] ) ? $counts[ $name ] + 1 : 1;
	}
	foreach ( $block['innerBlocks'] as $inner_block ) {
		$queue[] = $inner_block;
	}
}
foreach ( $counts as $name => $count ) {
	echo "{$name}: {$count}\n";
}
````

<!-- expected-output -->
```
core/heading: 1
core/paragraph: 2
core/table: 1
core/code: 1
core/quote: 1
```
