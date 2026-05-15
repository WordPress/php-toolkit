---
slug: xml
title: XML
install: wp-php-toolkit/xml

see_also:
  - dataliberation | DataLiberation | Read and write WXR-sized WordPress exports as entities.
  - encoding | Encoding | Validate and scrub text before strict XML processing.
  - bytestream | ByteStream | Keep large XML reads incremental.
---

A streaming, namespace-aware XML processor in pure PHP. Read and modify huge feeds, WXR exports, ePub manifests, and Office Open XML parts without ever loading the document into memory and without depending on <code>libxml2</code>.

When the native API extension is loaded, <code>XMLProcessor</code> can use a
native delegate by default while preserving PHP fallback behavior. Define
<code>WP_NATIVE_APIS_DISABLE_DEFAULTS</code> or set
<code>WP_NATIVE_APIS_ENABLE_XML_DEFAULTS</code> to <code>0</code>,
<code>false</code>, <code>no</code>, or <code>off</code> to force the pure PHP
fallback.

## Why this exists

<p><code>SimpleXMLElement</code> and <code>DOMDocument</code> both need <code>libxml2</code> and both build a complete in-memory tree. <code>XMLProcessor</code> walks the document forward as a cursor, keeps modifications in a side buffer, and emits the full updated XML with <code>get_updated_xml()</code> only when you ask for it.</p>

<p>This design came from WordPress-scale documents such as WXR exports. A migration may only need to rewrite <code>wp:attachment_url</code> values or bump a feed attribute, so the processor optimizes for targeted cursor edits instead of a full validating XML stack.</p>

<p>Footgun: <strong>Namespace-aware methods use the namespace URI, not the prefix written in the tag.</strong> In WXR, <code>get_attribute( 'wp', 'status' )</code> looks for a namespace literally named <code>wp</code>; for the usual WXR declaration you want <code>get_attribute( 'http://wordpress.org/export/1.2/', 'status' )</code>.</p>

<p>Footgun: <strong>In streaming mode <code>next_tag()</code> can return false because input ran out, not because the document ended.</strong> Check <code>is_paused_at_incomplete_input()</code> before assuming you're done.</p>

## Bump every price in a catalog

<p>Find each <code>&lt;book&gt;</code>, read its price, write a new one, emit the updated document.</p>

<!-- snippet:
filename: bump-prices.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

use WordPress\XML\XMLProcessor;

$xml = <<<'XML'
<catalog>
<book sku="A1" price="29.99"><title>PHP Internals</title></book>
<book sku="A2" price="14.50"><title>WordPress at Scale</title></book>
</catalog>
XML;

$p = XMLProcessor::create_from_string( $xml );
while ( $p->next_tag( 'book' ) ) {
	$old = (float) $p->get_attribute( '', 'price' );
	$new = number_format( $old * 1.10, 2, '.', '' );
	$p->set_attribute( '', 'price', $new );
}

echo $p->get_updated_xml();
```

<!-- expected-output -->
```
<catalog>
<book sku="A1" price="32.99"><title>PHP Internals</title></book>
<book sku="A2" price="15.95"><title>WordPress at Scale</title></book>
</catalog>
```

## Read namespaced attributes from a WXR export

<p>WordPress's WXR commonly uses <code>wp:</code>, <code>dc:</code>, and <code>content:</code> prefixes bound to namespace names such as <code>http://wordpress.org/export/1.2/</code>. Pass that expanded namespace name, not the prefix; the processor handles whichever prefix the document actually uses.</p>

<!-- snippet:
filename: wxr-namespaces.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

use WordPress\XML\XMLProcessor;

$wxr = <<<'XML'
<?xml version="1.0"?>
<rss xmlns:wp="http://wordpress.org/export/1.2/" xmlns:dc="http://purl.org/dc/elements/1.1/">
<channel><item>
<title>Hello World</title>
<dc:creator>admin</dc:creator>
<wp:post_id>42</wp:post_id>
<wp:status>publish</wp:status>
</item></channel></rss>
XML;

$WP = 'http://wordpress.org/export/1.2/';
$DC = 'http://purl.org/dc/elements/1.1/';

$p = XMLProcessor::create_from_string( $wxr );
while ( $p->next_tag( 'item' ) ) {
	while ( $p->next_token() ) {
		if ( $p->is_tag_closer() && 'item' === $p->get_tag_local_name() ) break;
		if ( ! $p->is_tag_opener() ) continue;
		$ns = $p->get_tag_namespace();
		$local = $p->get_tag_local_name();
		$prefix = ( $WP === $ns ) ? 'wp/' : ( ( $DC === $ns ) ? 'dc/' : '' );
		echo "{$prefix}{$local}: ";
		while ( $p->next_token() && '#text' !== $p->get_token_name() ) {}
		echo trim( $p->get_modifiable_text() ) . "\n";
	}
}
```

<!-- expected-output -->
```
title: Hello World
dc/creator: admin
wp/post_id: 42
wp/status: publish
```

## Rewrite URLs across an entire WXR export

<p>Large WXR exports can hold many URLs in <code>&lt;link&gt;</code>, <code>&lt;guid&gt;</code>, and post content. Streaming the file lets you rewrite large exports without loading the whole XML document into memory.</p>

<!-- snippet:
filename: rewrite-wxr-urls.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

use WordPress\XML\XMLProcessor;

$wxr = <<<'XML'
<?xml version="1.0"?><rss xmlns:wp="http://wordpress.org/export/1.2/"><channel>
<wp:base_site_url>https://old.example.com</wp:base_site_url>
<item><link>https://old.example.com/2024/post-1</link>
<guid>https://old.example.com/?p=1</guid></item>
</channel></rss>
XML;

$from = 'https://old.example.com';
$to   = 'https://new.example.com';

$p = XMLProcessor::create_from_string( $wxr );
$rewritten = 0;

while ( $p->next_token() ) {
	if ( '#text' !== $p->get_token_name() ) continue;
	$text = $p->get_modifiable_text();
	if ( false === strpos( $text, $from ) ) continue;
	$p->set_modifiable_text( str_replace( $from, $to, $text ) );
	$rewritten++;
}

echo "rewrote {$rewritten} text nodes\n\n";
echo $p->get_updated_xml();
```

<!-- expected-output -->
```
rewrote 3 text nodes

<?xml version="1.0"?><rss xmlns:wp="http://wordpress.org/export/1.2/"><channel>
<wp:base_site_url>https://new.example.com</wp:base_site_url>
<item><link>https://new.example.com/2024/post-1</link>
<guid>https://new.example.com/?p=1</guid></item>
</channel></rss>
```

## Parse OPML to extract feed URLs

<p>OPML is the format Feedly and many readers use to import/export feed lists. Flat, attribute-heavy XML — exactly what a tag processor handles best.</p>

<!-- snippet:
filename: opml.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

use WordPress\XML\XMLProcessor;

$opml = <<<'XML'
<?xml version="1.0"?><opml version="2.0"><head><title>My Feeds</title></head>
<body>
<outline text="Tech"><outline text="Hacker News" type="rss" xmlUrl="https://news.ycombinator.com/rss"/>
<outline text="LWN" type="rss" xmlUrl="https://lwn.net/headlines/rss"/></outline>
<outline text="WordPress" type="rss" xmlUrl="https://wordpress.org/news/feed/"/>
</body></opml>
XML;

$p = XMLProcessor::create_from_string( $opml );
while ( $p->next_tag( 'outline' ) ) {
	$url = $p->get_attribute( '', 'xmlUrl' );
	if ( null === $url ) continue;
	echo $p->get_attribute( '', 'text' ) . "\t" . $url . "\n";
}
```

<!-- expected-output -->
```
Hacker News	https://news.ycombinator.com/rss
LWN	https://lwn.net/headlines/rss
WordPress	https://wordpress.org/news/feed/
```
