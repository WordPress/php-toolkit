---
slug: dataliberation
title: DataLiberation
install: wp-php-toolkit/data-liberation

see_also:
  - ../learn/03-importing-content.html | Tutorial — Markdown to WXR | The chapter that walks through importing a folder of Markdown files into WordPress via the toolkit.
  - markdown | Markdown | Use Markdown as a source or destination format.
  - blockparser | BlockParser | Analyze serialized blocks inside post content.
  - httpclient | HttpClient | Download media and remote source data while importing.
---

Streaming WordPress import/export. WXR, SQL, block markup — without loading whole datasets into memory.

## Why this exists

<p>WordPress content should be portable, but real migrations cross several formats. A site export might arrive as WXR, a Markdown folder, or entities from another CMS. URLs can hide in block attributes, HTML, CSS, feeds, GUIDs, and post meta. Importers must also resume after a failed media download or upload.</p>

<p>The DataLiberation component streams WordPress-shaped data through readers, transformers, and writers. It models posts, terms, comments, attachments, and metadata as <code>ImportEntity</code> objects, then lets a pipeline rewrite each entity without loading the full export into memory.</p>

<p>The API reflects specific migration bugs: relative URLs in known block attributes, URLs inside inline CSS, self-closing block comments that must keep their shape, and origin-only URLs whose trailing slash style should not change during a rewrite.</p>

<p>Reach for it when the job combines formats: build WXR from another CMS, rewrite a staging export for production, frontload remote assets, or compose Markdown, XML, HTML, CSS, and URL rewriting into one pipeline.</p>

## Write a WXR file in five lines

<p>Stream a single post into a WXR document via <code>WXRWriter</code>. The writer holds no buffer beyond what is needed to close currently-open tags, so memory stays flat regardless of input size.</p>

<!-- snippet:
filename: wxr-quickstart.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

use WordPress\ByteStream\MemoryPipe;
use WordPress\DataLiberation\EntityWriter\WXRWriter;
use WordPress\DataLiberation\ImportEntity;

$pipe   = new MemoryPipe();
$writer = new WXRWriter( $pipe );
$writer->append_entity( new ImportEntity( 'post', array(
	'post_title' => 'Hello',
	'content'    => 'World.',
	'post_id'    => '1',
	'status'     => 'publish',
) ) );
$writer->finalize();
$writer->close_writing();
$pipe->close_writing();
$wxr = $pipe->consume_all();

echo "bytes: " . strlen( $wxr ) . "\n";
echo false !== strpos( $wxr, '<title>Hello</title>' ) ? "title exported\n" : "title missing\n";
echo false !== strpos( $wxr, '<wp:status>publish</wp:status>' ) ? "status exported\n" : "status missing\n";
```

<!-- expected-output -->
```
bytes: 475
title exported
status exported
```

## Build a WXR programmatically from any source

<p>The writer doesn't care where entities come from. Loop over rows from a CMS, a CSV, or a Notion API dump and emit posts plus their meta and comments.</p>

<!-- snippet:
filename: build-wxr.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

use WordPress\ByteStream\MemoryPipe;
use WordPress\DataLiberation\EntityWriter\WXRWriter;
use WordPress\DataLiberation\ImportEntity;

$rows = array(
	array( 'id' => 10, 'title' => 'About', 'body' => '<p>About us.</p>', 'tags' => array( 'company' ) ),
	array( 'id' => 11, 'title' => 'Blog',  'body' => '<p>Hello world.</p>', 'tags' => array( 'news', 'launch' ) ),
);

$pipe   = new MemoryPipe();
$writer = new WXRWriter( $pipe );

foreach ( $rows as $row ) {
	$writer->append_entity( new ImportEntity( 'post', array(
		'post_id'    => (string) $row['id'],
		'post_title' => $row['title'],
		'content'    => $row['body'],
		'status'     => 'publish',
		'post_type'  => 'post',
	) ) );
	foreach ( $row['tags'] as $i => $tag ) {
		$writer->append_entity( new ImportEntity( 'term', array(
			'term_id'  => (string) ( $row['id'] * 100 + $i ),
			'taxonomy' => 'post_tag',
			'slug'     => $tag,
			'parent'   => '0',
		) ) );
	}
}

$writer->finalize();
$writer->close_writing();
$pipe->close_writing();

$wxr = $pipe->consume_all();
echo "items: " . substr_count( $wxr, '<item>' ) . "\n";
echo "terms: " . substr_count( $wxr, '<wp:term>' ) . "\n";
echo false !== strpos( $wxr, '<title>Blog</title>' ) ? "Blog post exported\n" : "Blog post missing\n";
```

<!-- expected-output -->
```
items: 2
terms: 3
Blog post exported
```

## Read entities from a WXR file with constant memory

<p><code>WXREntityReader</code> emits one entity at a time. A 10 GB WXR uses the same memory as a 10 KB one.</p>

<!-- snippet:
filename: wxr-read.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

use WordPress\DataLiberation\EntityReader\WXREntityReader;

$wxr = <<<XML
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/" xmlns:content="http://purl.org/rss/1.0/modules/content/">
<channel>
<title>Demo</title>
<item><title>First</title><wp:post_id>1</wp:post_id><wp:post_type>post</wp:post_type><content:encoded>Body 1</content:encoded></item>
<item><title>Second</title><wp:post_id>2</wp:post_id><wp:post_type>post</wp:post_type><content:encoded>Body 2</content:encoded></item>
</channel>
</rss>
XML;

$reader = WXREntityReader::create();
$reader->append_bytes( $wxr );
$reader->input_finished();

while ( $reader->next_entity() ) {
	$entity = $reader->get_entity();
	echo $entity->get_type() . ': ' . json_encode( $entity->get_data() ) . "\n";
}
```

<!-- expected-output -->
```
site_option: {"option_name":"blogname","option_value":"Demo"}
post: {"post_title":"First","post_id":"1","post_type":"post","post_content":"Body 1"}
post: {"post_title":"Second","post_id":"2","post_type":"post","post_content":"Body 2"}
```

## Streaming transform: rewrite URLs while copying WXR

<p>Wire reader to writer to rewrite a WXR file on the fly. This pattern is how you migrate a staging export to production: swap <code>staging.example.com</code> for <code>example.com</code> without ever loading the file into memory.</p>

<!-- snippet:
filename: rewrite-urls.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

use WordPress\ByteStream\MemoryPipe;
use WordPress\DataLiberation\EntityReader\WXREntityReader;
use WordPress\DataLiberation\EntityWriter\WXRWriter;
use WordPress\DataLiberation\ImportEntity;

$source_xml = <<<XML
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/" xmlns:content="http://purl.org/rss/1.0/modules/content/">
<channel>
<item><title>Hello</title><wp:post_id>1</wp:post_id><wp:post_type>post</wp:post_type>
<content:encoded>Visit https://staging.example.com/about for more.</content:encoded></item>
</channel>
</rss>
XML;

$reader = WXREntityReader::create();
$reader->append_bytes( $source_xml );
$reader->input_finished();

$out_pipe = new MemoryPipe();
$writer   = new WXRWriter( $out_pipe );

while ( $reader->next_entity() ) {
	$entity = $reader->get_entity();
	$data   = $entity->get_data();
	foreach ( array( 'post_content', 'content', 'description' ) as $field ) {
		if ( isset( $data[ $field ] ) ) {
			$data[ $field ] = str_replace( 'staging.example.com', 'example.com', $data[ $field ] );
		}
	}
	if ( 'post' === $entity->get_type() ) {
		$data['content'] = isset( $data['post_content'] ) ? $data['post_content'] : ( isset( $data['content'] ) ? $data['content'] : '' );
	}
	$writer->append_entity( new ImportEntity( $entity->get_type(), $data ) );
}

$writer->finalize();
$writer->close_writing();
$out_pipe->close_writing();

$wxr = $out_pipe->consume_all();
echo false !== strpos( $wxr, 'https://example.com/about' ) ? "new URL present\n" : "new URL missing\n";
echo false === strpos( $wxr, 'staging.example.com' ) ? "old URL removed\n" : "old URL still present\n";
```

<!-- expected-output -->
```
new URL present
old URL removed
```

## Render Markdown into a WXR import in one pipeline

<p>Compose <code>MarkdownConsumer</code> with <code>WXRWriter</code> to publish a folder of Markdown directly as a WordPress import file.</p>

<!-- snippet:
filename: md-to-wxr.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

use WordPress\ByteStream\MemoryPipe;
use WordPress\DataLiberation\EntityWriter\WXRWriter;
use WordPress\DataLiberation\ImportEntity;
use WordPress\Markdown\MarkdownConsumer;

@mkdir( '/tmp/md-src', 0777, true );
file_put_contents( '/tmp/md-src/hello.md',  "---\ntitle: Hello\n---\n\n# Hello\n\nFirst post." );
file_put_contents( '/tmp/md-src/second.md', "---\ntitle: Second\n---\n\nMore text **here**." );

$pipe   = new MemoryPipe();
$writer = new WXRWriter( $pipe );

$id = 1;
foreach ( glob( '/tmp/md-src/*.md' ) as $path ) {
	$consumer = new MarkdownConsumer( file_get_contents( $path ) );
	$consumer->consume();
	$writer->append_entity( new ImportEntity( 'post', array(
		'post_id'    => (string) $id++,
		'post_title' => $consumer->get_meta_value( 'title' ) ?: basename( $path, '.md' ),
		'content'    => $consumer->get_block_markup(),
		'status'     => 'publish',
		'post_type'  => 'post',
		'post_name'  => basename( $path, '.md' ),
	) ) );
}

$writer->finalize();
$writer->close_writing();
$pipe->close_writing();

$wxr = $pipe->consume_all();
echo "posts: " . substr_count( $wxr, '<item>' ) . "\n";
echo false !== strpos( $wxr, '&lt;!-- wp:heading' ) ? "block markup exported\n" : "block markup missing\n";
echo false !== strpos( $wxr, '<title>Second</title>' ) ? "frontmatter title exported\n" : "frontmatter title missing\n";
```

<!-- expected-output -->
```
posts: 2
block markup exported
frontmatter title exported
```
