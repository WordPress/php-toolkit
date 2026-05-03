---
slug: html
title: HTML
install: wp-php-toolkit/html

credit_title: Ported from WordPress core
credit_body: |
  The HTML component is a port of WordPress core's <code>WP_HTML_Tag_Processor</code> and <code>WP_HTML_Processor</code>. Source: <a href="https://github.com/WordPress/wordpress-develop/tree/trunk/src/wp-includes/html-api">WordPress/wordpress-develop</a>. Bug fixes flow in both directions.

see_also: ../learn/01-rewriting-html.html | Tutorial — Rewriting HTML safely | The chapter that introduces the cursor model and the <code>clean_post_html()</code> function reused later in the importer.
see_also: blockparser | BlockParser | Parse block comments first, then rewrite the HTML inside each block.
see_also: markdown | Markdown | Convert Markdown to blocks before polishing generated HTML.
see_also: dataliberation | DataLiberation | Rewrite URLs and media references during import/export pipelines.
---

A pure-PHP HTML5 parser and tag rewriter mirroring WordPress core's HTML API. Treat HTML the way browsers do — without <code>libxml2</code>, <code>DOMDocument</code>, or regex hacks — and rewrite attributes in a single linear pass.

## Why this exists

<p>WordPress runs HTML fragments through filters every time a request renders: post content, block markup, comments, excerpts, widgets, feeds, imported documents. Those fragments can omit <code>&lt;html&gt;</code> and <code>&lt;body&gt;</code>, close tags implicitly, or mix browser-correct markup with author mistakes that <code>DOMDocument</code> and regular expressions do not model well.</p>

<p>The HTML component gives WordPress-style code the same parsing model WordPress core uses: a browser-compatible tokenizer and tree-aware processor that run in pure PHP. Choose it for exact-byte rewrites, imperfect fragments, and post-content filters where a full DOM would do too much work.</p>

<p>The component gives you two processors. <code>WP_HTML_Tag_Processor</code> is a forward-only cursor over tags and tokens — useful for attribute rewriting at scale. <code>WP_HTML_Processor</code> layers HTML5 tree construction on top so you can query by ancestry (breadcrumbs), serialize the parsed document, and trust that <code>&lt;p&gt;one&lt;p&gt;two</code> parses as two paragraphs the way a browser sees it.</p>

<p>Footgun: <strong>Mutations are buffered.</strong> Nothing changes in the source string until you call <code>get_updated_html()</code>. If you read <code>get_attribute()</code> after a <code>set_attribute()</code> on the same tag, you see the new value — but downstream tooling reading the original string sees stale HTML until you serialize.</p>

## Add loading="lazy" to every image

<p>The "hello world" of tag rewriting. One linear pass, no DOM, no reserialization cost beyond the bytes you actually changed.</p>

<p><strong>Try this:</strong> click <em>Run</em>, then change <code>'lazy'</code> to <code>'eager'</code> on the first image only by guarding it with <code>$tags-&gt;get_attribute( 'src' ) === 'hero.jpg'</code>. Run again and notice that <code>get_updated_html()</code> only rewrites the bytes for that one tag.</p>

<!-- snippet:
filename: lazy-load-images.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

$html = <<<'HTML'
<article>
	<img src="hero.jpg" alt="Hero">
	<p>Intro copy.</p>
	<img src="inline.jpg" alt="Inline">
</article>
HTML;

$tags = new WP_HTML_Tag_Processor( $html );
while ( $tags->next_tag( 'img' ) ) {
	// Don't clobber an explicit eager hint the author already set.
	if ( null === $tags->get_attribute( 'loading' ) ) {
		$tags->set_attribute( 'loading', 'lazy' );
	}
	$tags->set_attribute( 'decoding', 'async' );
}

echo $tags->get_updated_html();
```

<!-- expected-output -->
```
<article>
	<img decoding="async" loading="lazy" src="hero.jpg" alt="Hero">
	<p>Intro copy.</p>
	<img decoding="async" loading="lazy" src="inline.jpg" alt="Inline">
</article>
```

## Rewrite relative links to absolute URLs

<p>Use this before sending post content to an RSS feed, an email template, or a CDN-backed copy of a site. The processor rewrites only the changed bytes, so untouched markup stays byte-identical.</p>

<!-- snippet:
filename: absolute-links.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

$html = <<<'HTML'
<p>See <a href="/about">about</a>, <a href="https://example.com/x">x</a>, 
and <a href="contact.html">contact</a>.</p>
HTML;

$base = 'https://my-site.test/';

$tags = new WP_HTML_Tag_Processor( $html );
while ( $tags->next_tag( 'a' ) ) {
	$href = $tags->get_attribute( 'href' );
	if ( null === $href || '' === $href ) {
		continue;
	}
	if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $href ) || 0 === strpos( $href, '//' ) || 0 === strpos( $href, '#' ) ) {
		continue;
	}
	$tags->set_attribute( 'href', rtrim( $base, '/' ) . '/' . ltrim( $href, '/' ) );
}

echo $tags->get_updated_html();
```

<!-- expected-output -->
```
<p>See <a href="https://my-site.test/about">about</a>, <a href="https://example.com/x">x</a>, 
and <a href="https://my-site.test/contact.html">contact</a>.</p>
```

## Strip every script and inline event handler

<p>A common sanitization step: neutralize untrusted HTML before display. Blank a script's body with <code>set_modifiable_text()</code> and strip every <code>on*</code> attribute via <code>get_attribute_names_with_prefix()</code>.</p>

<!-- snippet:
filename: sanitize-html.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

$untrusted = <<<'HTML'
<p onclick="x()">hi</p>
<script>evil()</script>
<img src="x" onerror="boom()">
HTML;

$tags = new WP_HTML_Tag_Processor( $untrusted );
while ( $tags->next_tag() ) {
	if ( 'SCRIPT' === $tags->get_tag() && ! $tags->is_tag_closer() ) {
		$tags->set_modifiable_text( '' );
	}
	foreach ( $tags->get_attribute_names_with_prefix( 'on' ) as $attr ) {
		$tags->remove_attribute( $attr );
	}
}

echo $tags->get_updated_html();
```

<!-- expected-output -->
```
<p >hi</p>
<script></script>
<img src="x" >
```

## Stamp a CSP nonce on inline scripts and styles

<p>Content Security Policy in <code>nonce-</code> mode requires every inline <code>&lt;script&gt;</code> and <code>&lt;style&gt;</code> to carry a matching nonce attribute. Tag-by-tag is exactly the right granularity.</p>

<!-- snippet:
filename: csp-nonce.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

$nonce = bin2hex( random_bytes( 8 ) );

$html = <<<'HTML'
<head><style>body{font:16px sans-serif}</style></head>
<body><script>console.log("hi")</script><script src="vendor.js"></script></body>
HTML;

$tags = new WP_HTML_Tag_Processor( $html );
while ( $tags->next_tag() ) {
	$tag = $tags->get_tag();
	if ( ( 'SCRIPT' === $tag || 'STYLE' === $tag ) && ! $tags->is_tag_closer() ) {
		$tags->set_attribute( 'nonce', $nonce );
	}
}

echo "nonce: {$nonce}\n\n";
echo $tags->get_updated_html();
```

<!-- expected-output -->
```
nonce: <random>

<head><style nonce="<random>">body{font:16px sans-serif}</style></head>
<body><script nonce="<random>">console.log("hi")</script><script nonce="<random>" src="vendor.js"></script></body>
```

## Build a srcset from a single src

<p>Generate responsive image markup at render time without touching the editor data model. Read the existing <code>src</code>, derive a <code>srcset</code> with width descriptors, add a <code>sizes</code> hint.</p>

<!-- snippet:
filename: srcset-rewrite.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

$html = '<figure><img src="https://cdn.test/uploads/photo.jpg" alt="Sunset"></figure>';
$widths = array( 480, 768, 1200 );

$tags = new WP_HTML_Tag_Processor( $html );
while ( $tags->next_tag( 'img' ) ) {
	$src = $tags->get_attribute( 'src' );
	if ( null === $src || $tags->get_attribute( 'srcset' ) !== null ) {
		continue;
	}
	$variants = array();
	foreach ( $widths as $w ) {
		$variants[] = $src . '?w=' . $w . ' ' . $w . 'w';
	}
	$tags->set_attribute( 'srcset', implode( ', ', $variants ) );
	$tags->set_attribute( 'sizes', '(max-width: 768px) 100vw, 768px' );
}

echo $tags->get_updated_html();
```

<!-- expected-output -->
```
<figure><img sizes="(max-width: 768px) 100vw, 768px" srcset="https://cdn.test/uploads/photo.jpg?w=480 480w, https://cdn.test/uploads/photo.jpg?w=768 768w, https://cdn.test/uploads/photo.jpg?w=1200 1200w" src="https://cdn.test/uploads/photo.jpg" alt="Sunset"></figure>
```

## Decode HTML entities the way the spec demands

<p>The HTML5 entity table has roughly 2,200 named references and a long list of edge cases. <code>WP_HTML_Decoder</code> implements the algorithm — don't roll your own.</p>

<!-- snippet:
filename: decode-entities.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

echo "attribute: " . WP_HTML_Decoder::decode_attribute( 'path?a=1&amp;b=2&amp;copy' ) . "\n";
echo "text:      " . WP_HTML_Decoder::decode_text_node( 'AT&amp;T &mdash; 100&percnt; &#x1F600;' ) . "\n";

// Safe URL prefix check that respects encoded colons (a classic XSS vector).
$is_javascript = WP_HTML_Decoder::attribute_starts_with(
	'java&#x09;script:alert(1)',
	'javascript:',
	'ascii-case-insensitive'
);
var_dump( $is_javascript );
```

<!-- expected-output -->
```
attribute: path?a=1&b=2&copy
text:      AT&T — 100% 😀
bool(false)
```

## Find images by ancestry with breadcrumbs

<p>The full <code>WP_HTML_Processor</code> understands HTML5 tree construction, so you can ask "find every <code>&lt;img&gt;</code> directly inside a <code>&lt;figure&gt;</code>" without writing your own DOM walker.</p>

<!-- snippet:
filename: breadcrumbs.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

$html = <<<'HTML'
<article>
<figure><img src="hero.jpg" alt="Hero"><figcaption>Hero shot</figcaption></figure>
<p>Body copy <img src="emoji.png" alt=""> mid-paragraph.</p>
<figure><img src="diagram.png" alt="Diagram"></figure>
</article>
HTML;

$p = WP_HTML_Processor::create_fragment( $html );
$figure_images = 0;
while ( $p->next_tag( array( 'breadcrumbs' => array( 'FIGURE', 'IMG' ) ) ) ) {
	$p->add_class( 'figure-image' );
	$figure_images++;
}

echo "found {$figure_images} figure images\n";
echo $p->get_updated_html();
```

<!-- expected-output -->
```
found 2 figure images
<article>
<figure><img class="figure-image" src="hero.jpg" alt="Hero"><figcaption>Hero shot</figcaption></figure>
<p>Body copy <img src="emoji.png" alt=""> mid-paragraph.</p>
<figure><img class="figure-image" src="diagram.png" alt="Diagram"></figure>
</article>
```

## Outline a document by walking tokens with depth

<p>The full processor exposes <code>get_current_depth()</code> and <code>get_breadcrumbs()</code>. Combine with <code>next_token()</code> to print a structural outline.</p>

<!-- snippet:
filename: outline.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

$html = <<<'HTML'
<section><h1>Title</h1>
<section><h2>Chapter 1</h2><p>Body</p></section>
<section><h2>Chapter 2</h2><p>More body</p></section>
</section>
HTML;

$p = WP_HTML_Processor::create_fragment( $html );
while ( $p->next_token() ) {
	if ( '#tag' !== $p->get_token_type() || $p->is_tag_closer() ) {
		continue;
	}
	$tag = $p->get_tag();
	if ( ! preg_match( '/^H[1-6]$/', $tag ) ) {
		continue;
	}
	$indent = str_repeat( '  ', max( 0, $p->get_current_depth() - 2 ) );
	$text = '';
	while ( $p->next_token() ) {
		if ( '#text' === $p->get_token_type() ) {
			$text .= $p->get_modifiable_text();
			continue;
		}
		if ( '#tag' === $p->get_token_type() && $tag === $p->get_tag() && $p->is_tag_closer() ) {
			break;
		}
	}
	echo "{$indent}{$tag}  {$text}\n";
}
```

<!-- expected-output -->
```
    H1  Title
      H2  Chapter 1
      H2  Chapter 2
```

## Bookmarks: annotate a parent based on its children

<p>Bookmarks are the one escape from forward-only scanning. Save a position, scan ahead, decide what to do, then <code>seek()</code> back and rewrite the earlier tag.</p>

<!-- snippet:
filename: bookmarks.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

$html = <<<'HTML'
<ul>
<li><input type="checkbox" checked> Buy milk</li>
<li><input type="checkbox"> Walk the dog</li>
<li><input type="checkbox" checked> Read book</li>
</ul>
HTML;

$tags = new WP_HTML_Tag_Processor( $html );
$tags->next_tag( 'ul' );
$tags->set_bookmark( 'list' );

$total = 0;
$done = 0;
while ( $tags->next_tag( 'input' ) ) {
	$total++;
	if ( null !== $tags->get_attribute( 'checked' ) ) {
		$done++;
	}
}

$tags->seek( 'list' );
$tags->set_attribute( 'data-progress', $done . '/' . $total );
$tags->release_bookmark( 'list' );

echo $tags->get_updated_html();
```

<!-- expected-output -->
```
<ul data-progress="2/3">
<li><input type="checkbox" checked> Buy milk</li>
<li><input type="checkbox"> Walk the dog</li>
<li><input type="checkbox" checked> Read book</li>
</ul>
```

## When to use which

<table class="api-table">
<tr><th>Use</th><th>For</th></tr>
<tr><td><code>WP_HTML_Tag_Processor</code></td><td>Attribute rewriting, sanitization, finding tags by name. Forward-only walks. Anything where speed and byte-honesty matter more than context.</td></tr>
<tr><td><code>WP_HTML_Processor::create_fragment()</code></td><td>Queries by ancestry (<code>breadcrumbs</code>), heading outline extraction, anything that needs to know "is this tag inside that one."</td></tr>
<tr><td><code>WP_HTML_Decoder::decode_text_node()</code></td><td>Turning entity-encoded text (<code>AT&amp;amp;T</code>) back into raw text correctly. Implements the HTML5 entity algorithm — don't roll your own.</td></tr>
<tr><td><code>WP_HTML_Decoder::attribute_starts_with()</code></td><td>Safe URL-prefix checks that respect encoded characters (<code>java&amp;#x09;script:</code>). The classic <code>strpos</code> approach misses these.</td></tr>
</table>

<p>Footgun: <strong>Tag closers are visited too.</strong> <code>next_tag()</code> stops on both opening and closing tags. For most attribute-rewriting code, gate with <code>! $tags-&gt;is_tag_closer()</code> so you don't try to set attributes on a <code>&lt;/script&gt;</code>.</p>

<p>Footgun: <strong>Tag-name matches are uppercase.</strong> <code>get_tag()</code> always returns the tag name in uppercase (<code>'IMG'</code>, not <code>'img'</code>). Compare accordingly. The filter argument to <code>next_tag()</code> is case-insensitive in either direction.</p>

<p>Footgun: <strong>Don't confuse <code>WP_HTML_Tag_Processor</code> with the full processor.</strong> The cursor is forward-only and ancestry-blind. If you call <code>get_breadcrumbs()</code> on it, you'll get a thin shape that doesn't reflect HTML5 tree construction — implicit <code>&lt;tbody&gt;</code> insertion, automatic <code>&lt;p&gt;</code> closing, and the rest live only in <code>WP_HTML_Processor</code>.</p>
