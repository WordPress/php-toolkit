---
slug: blockparser
title: BlockParser
install: wp-php-toolkit/blockparser

credit_title: WordPress core, packaged standalone
credit_body: |
  <code>WP_Block_Parser</code> is WordPress core's block parser, packaged here so importers and linters can read <a href="https://developer.wordpress.org/block-editor/reference-guides/block-api/">block markup</a> without booting WordPress. Source: <a href="https://github.com/WordPress/wordpress-develop/blob/trunk/src/wp-includes/class-wp-block-parser.php">WordPress/wordpress-develop</a>.

see_also:
  - html | HTML | Inspect or rewrite the HTML carried by parsed blocks.
  - markdown | Markdown | Move between author-friendly Markdown and serialized block markup.
  - dataliberation | DataLiberation | Audit and transform blocks while migrating content.
---

WordPress core's block parser, packaged as a standalone library. Turn block markup into a structured tree, lint posts for common authoring mistakes, and audit block usage — all without booting WordPress.

## Why this exists

<p>Block markup is not plain HTML. A post can contain HTML comments that identify blocks, JSON attributes inside those comments, freeform HTML between blocks, and nested blocks whose rendered HTML is interleaved with parent markup.</p>

<p>This component packages WordPress core's block parser so importers, linters, migration tools, and static analyzers can understand block content without loading WordPress. It deliberately mirrors core behavior — same array shape, same <code>null</code> blocks for freeform HTML, same core block names such as <code>core/paragraph</code> — so code written against this parser keeps working when run inside WordPress, and vice versa.</p>

<p>Reach for it when you need answers about the block tree: which blocks a post uses, which attributes they carry, where nested blocks appear, or whether content violates a rule your project cares about.</p>

## What you get back

<p><code>WP_Block_Parser::parse()</code> returns an array of blocks. Each block is an associative array with five keys: <code>blockName</code>, <code>attrs</code>, <code>innerBlocks</code>, <code>innerHTML</code>, and <code>innerContent</code>.</p>

<p><code>innerHTML</code> is the HTML inside the block <em>with inner blocks stripped out</em>. <code>innerContent</code> is the interleaved version: an array of HTML strings with <code>null</code> placeholders marking where each inner block belongs.</p>

<p>Most code starts by checking <code>blockName</code>, then reading <code>attrs</code> or <code>innerHTML</code>. When a post has container blocks such as Group, Columns, or Navigation, look inside <code>innerBlocks</code> too.</p>

<p>Footgun: <strong>Freeform HTML between blocks shows up as a block with <code>blockName === null</code>.</strong> Always skip that case before comparing names.</p>

## Parse a document

<p>The simplest possible use. Pass a string, get back a tree.</p>

<!-- snippet:
filename: parse.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

$document = "<!-- wp:heading {\"level\":2} -->\n<h2>Welcome</h2>\n<!-- /wp:heading -->\n\n"
	. "<!-- wp:paragraph -->\n<p>Hello from the block editor.</p>\n<!-- /wp:paragraph -->";

$blocks = ( new WP_Block_Parser() )->parse( $document );
foreach ( $blocks as $block ) {
	if ( null === $block['blockName'] ) {
		continue;
	}
	echo $block['blockName'] . ': ' . trim( strip_tags( $block['innerHTML'] ) ) . "\n";
}
```

<!-- expected-output -->
```
core/heading: Welcome
core/paragraph: Hello from the block editor.
```

## Count every block type in a post

<p>A common audit task: "How many Paragraph, Image, and Gallery blocks does this post use?" A small queue keeps the example readable while still visiting nested blocks.</p>

<!-- snippet:
filename: count-blocks.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

$document = "<!-- wp:group --><div class=\"wp-block-group\">"
	. "<!-- wp:heading --><h2>Title</h2><!-- /wp:heading -->"
	. "<!-- wp:paragraph --><p>One.</p><!-- /wp:paragraph -->"
	. "<!-- wp:paragraph --><p>Two.</p><!-- /wp:paragraph -->"
	. "<!-- wp:image {\"id\":1} --><figure><img src=\"a.jpg\"/></figure><!-- /wp:image -->"
	. "</div><!-- /wp:group -->";

$blocks = ( new WP_Block_Parser() )->parse( $document );

$counts = array();
$queue  = $blocks;

while ( ! empty( $queue ) ) {
	$block = array_shift( $queue );

	if ( null !== $block['blockName'] ) {
		$name             = $block['blockName'];
		$counts[ $name ] = isset( $counts[ $name ] ) ? $counts[ $name ] + 1 : 1;
	}

	foreach ( $block['innerBlocks'] as $inner_block ) {
		$queue[] = $inner_block;
	}
}

arsort( $counts );
foreach ( $counts as $name => $n ) {
	echo str_pad( (string) $n, 4, ' ', STR_PAD_LEFT ) . '  ' . $name . "\n";
}
```

<!-- expected-output -->
```
   2  core/paragraph
   1  core/group
   1  core/heading
   1  core/image
```

## Check whether a post uses a block

<p>Useful for templates, audits, and migrations: answer one yes/no question without caring where the block appears in the tree.</p>

<!-- snippet:
filename: has-block.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

$document = "<!-- wp:group --><div class=\"wp-block-group\">"
	. "<!-- wp:buttons --><div class=\"wp-block-buttons\">"
	. "<!-- wp:button --><div class=\"wp-block-button\"><a>Buy now</a></div><!-- /wp:button -->"
	. "</div><!-- /wp:buttons -->"
	. "</div><!-- /wp:group -->";

$blocks = ( new WP_Block_Parser() )->parse( $document );

function post_has_block( $blocks, $name ) {
	$queue = $blocks;

	while ( ! empty( $queue ) ) {
		$block = array_shift( $queue );
		if ( $name === $block['blockName'] ) {
			return true;
		}

		foreach ( $block['innerBlocks'] as $inner_block ) {
			$queue[] = $inner_block;
		}
	}

	return false;
}

echo post_has_block( $blocks, 'core/button' ) ? "has button\n" : "missing button\n";
echo post_has_block( $blocks, 'core/gallery' ) ? "has gallery\n" : "missing gallery\n";
```

<!-- expected-output -->
```
has button
missing gallery
```

## Lint headings for hierarchy mistakes

<p>"Don't skip from H2 to H4" is a real accessibility rule. The helper below keeps headings in document order, including headings nested inside Group, Column, and Cover blocks.</p>

<!-- snippet:
filename: lint-headings.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

$document = "<!-- wp:heading -->\n<h2>Intro</h2>\n<!-- /wp:heading -->"
	. "<!-- wp:heading {\"level\":4} -->\n<h4>Subsection</h4>\n<!-- /wp:heading -->"
	. "<!-- wp:heading {\"level\":3} -->\n<h3>Body</h3>\n<!-- /wp:heading -->";

$blocks = ( new WP_Block_Parser() )->parse( $document );

function collect_headings( $blocks, &$headings ) {
	foreach ( $blocks as $block ) {
		if ( 'core/heading' === $block['blockName'] ) {
			$headings[] = array(
				'level' => isset( $block['attrs']['level'] ) ? (int) $block['attrs']['level'] : 2,
				'text'  => trim( strip_tags( $block['innerHTML'] ) ),
			);
		}

		collect_headings( $block['innerBlocks'], $headings );
	}
}

$headings = array();
collect_headings( $blocks, $headings );

$last = 1;
foreach ( $headings as $heading ) {
	$level = $heading['level'];
	$label = $heading['text'];

	if ( $level > $last + 1 ) {
		echo "WARN {$label}: jumped from H{$last} to H{$level}\n";
	} else {
		echo "ok   {$label}: H{$level}\n";
	}
	$last = $level;
}
```

<!-- expected-output -->
```
ok   Intro: H2
WARN Subsection: jumped from H2 to H4
ok   Body: H3
```

## Find all instances of a custom block

<p>When auditing an export for a block your plugin owns, collect every match and print the fields a human cares about.</p>

<!-- snippet:
filename: find-custom-block.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

$document = "<!-- wp:paragraph --><p>Reviews</p><!-- /wp:paragraph -->"
	. "<!-- wp:my-plugin/testimonial {\"author\":\"Jane\",\"rating\":5} -->"
	. "<blockquote>Loved it.</blockquote>"
	. "<!-- /wp:my-plugin/testimonial -->"
	. "<!-- wp:my-plugin/testimonial {\"author\":\"Joe\",\"rating\":4} -->"
	. "<blockquote>Pretty good.</blockquote>"
	. "<!-- /wp:my-plugin/testimonial -->";

$blocks = ( new WP_Block_Parser() )->parse( $document );

function find_blocks_by_name( $blocks, $name, &$matches ) {
	foreach ( $blocks as $block ) {
		if ( $name === $block['blockName'] ) {
			$matches[] = $block;
		}

		find_blocks_by_name( $block['innerBlocks'], $name, $matches );
	}
}

$testimonials = array();
find_blocks_by_name( $blocks, 'my-plugin/testimonial', $testimonials );

foreach ( $testimonials as $i => $b ) {
	echo ( $i + 1 ) . '. ' . $b['attrs']['author'] . ' (' . $b['attrs']['rating'] . '/5): '
		. trim( strip_tags( $b['innerHTML'] ) ) . "\n";
}
```

<!-- expected-output -->
```
1. Jane (5/5): Loved it.
2. Joe (4/5): Pretty good.
```

## Detect blocks with stale embed URLs

<p>A real-world content audit: find every <code>core/embed</code> whose URL points at a domain you have retired.</p>

<!-- snippet:
filename: audit-embeds.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

$document = <<<'HTML'
<!-- wp:embed {"url":"https://twitter.com/wordpress/status/1","providerNameSlug":"twitter"} /-->
<!-- wp:embed {"url":"https://youtube.com/watch?v=abc","providerNameSlug":"youtube"} /-->
<!-- wp:embed {"url":"https://vine.co/v/xyz","providerNameSlug":"vine"} /-->
HTML;

$retired = array( 'vine.co', 'plus.google.com' );

foreach ( ( new WP_Block_Parser() )->parse( $document ) as $b ) {
	if ( 'core/embed' !== $b['blockName'] ) {
		continue;
	}
	$url  = isset( $b['attrs']['url'] ) ? $b['attrs']['url'] : '';
	$host = parse_url( $url, PHP_URL_HOST );
	$bad  = $host && in_array( $host, $retired, true );
	echo ( $bad ? 'STALE  ' : 'ok     ' ) . $url . "\n";
}
```

<!-- expected-output -->
```
ok     https://twitter.com/wordpress/status/1
ok     https://youtube.com/watch?v=abc
STALE  https://vine.co/v/xyz
```
