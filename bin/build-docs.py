#!/usr/bin/env python3
"""
Generates docs/<component>/index.html for every component listed below from
a single template, plus the docs/index.html landing page.

Each component entry is a tuple of:
    (slug, title, lede, install_pkg, sections)

`sections` is a list of (heading, body_html, snippet_or_none) — body_html may
contain HTML; snippet is a (filename, php_code) tuple or None.

The PHP snippets here are derived from each component's own README. They run
inside WordPress Playground via `<php-snippet blueprint="toolkit-setup">`,
which loads docs/assets/php-toolkit.zip and the toolkit's vendor/autoload.php.
"""

import os
import re
import sys
from html import escape as h
from textwrap import dedent

DOCS = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'docs')

PAGE_HEAD = '''<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{title} — PHP Toolkit</title>
<meta name="description" content="{description}">
<link rel="stylesheet" href="../assets/style.css">
<script type="module" src="https://playground.wordpress.net/php-code-snippet.js"></script>
<script id="toolkit-setup" type="application/json"></script>
<script src="../assets/page.js" defer></script>
</head>
<body>
<header class="site">
\t<a class="brand" href="../">PHP Toolkit</a>
\t<nav>
\t\t<a href="../">Components</a>
\t\t<a href="https://github.com/WordPress/php-toolkit">GitHub</a>
\t</nav>
</header>
'''

PAGE_FOOT = '''<footer class="site">
\t<a href="https://github.com/WordPress/php-toolkit">WordPress/php-toolkit</a> · runnable docs powered by <a href="https://wordpress.github.io/wordpress-playground/">WordPress Playground</a>
</footer>
</body>
</html>
'''


def snippet_block(name, code):
    # <script type="application/x-php"> content is parsed as raw text —
    # entities are not decoded — so the PHP must be inserted verbatim. The
    # only sequence that ends raw-text mode is "</script", which we guard
    # against in the unlikely case PHP code mentions it.
    safe = code.rstrip().replace('</script', '<\\/script')
    return (
        f'<php-snippet blueprint="toolkit-setup" name="{h(name)}">\n'
        f'<script type="application/x-php">\n{safe}\n</script>\n'
        f'</php-snippet>\n'
    )


def render_component(slug, title, lede, install, sections):
    # Component sidebar: all sibling pages, with the current one highlighted.
    nav_items = []
    for s, t, _, _, _ in COMPONENTS:
        cls = ' class="current"' if s == slug else ''
        nav_items.append(f'\t\t\t<li{cls}><a href="../{s}/">{h(t)}</a></li>')
    components_nav = (
        '\t<aside class="sidebar" aria-label="Component navigation">\n'
        '\t\t<button class="sidebar-toggle" type="button" aria-expanded="false">'
        f'On this page ▾</button>\n'
        '\t\t<nav class="toc" aria-label="Table of contents"></nav>\n'
        '\t\t<details class="components-nav" open>\n'
        '\t\t\t<summary>All components</summary>\n'
        '\t\t\t<ol>\n'
        + '\n'.join(nav_items) + '\n'
        '\t\t\t</ol>\n'
        '\t\t</details>\n'
        '\t</aside>\n'
    )

    out = [PAGE_HEAD.format(title=h(title), description=h(lede))]
    out.append('<div class="layout">\n')
    out.append(components_nav)
    out.append('\t<article class="content">\n')
    out.append(f'\t\t<h1>{h(title)}</h1>\n')
    out.append(f'\t\t<p class="lede">{lede}</p>\n')
    if install:
        out.append(f'\t\t<code class="install">composer require {h(install)}</code>\n')
    out.append(
        '\t\t<p class="runtime-note"><strong>Runnable docs.</strong> Click <em>Run</em> on any '
        'snippet to execute it on PHP 8.3 in your browser via WordPress Playground. '
        'Click into the code to edit it before running. The toolkit ships as a '
        'self-contained bundle with the page; nothing extra is downloaded.</p>\n'
    )
    for heading, body_html, snippet in sections:
        anchor = re.sub(r'[^\w\s-]', '', heading.lower()).strip().replace(' ', '-')
        out.append(f'\t\t<h2 id="{anchor}">{h(heading)}</h2>\n')
        if body_html:
            out.append(f'\t\t{body_html}\n')
        if snippet:
            name, code = snippet
            out.append(snippet_block(name, code))
    out.append(
        '\t\t<p style="margin-top:3rem;color:var(--muted);font-size:0.9rem">'
        f'Full API reference: <a href="https://github.com/WordPress/php-toolkit/blob/trunk/components/{h(title.replace(" ", ""))}/README.md">{h(title)} README</a>.</p>\n'
    )
    out.append('\t</article>\n</div>\n')
    out.append(PAGE_FOOT)
    return ''.join(out)


# ---------------------------------------------------------------------------
# Component catalog
# ---------------------------------------------------------------------------

LOAD = "require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';\n\n"


def php(snippet):
    return '<?php\n' + LOAD + snippet


# Each entry: (slug, title, lede, composer_pkg, sections)
COMPONENTS = []

# ---------- HTML ----------
COMPONENTS.append(('html', 'HTML',
    'A full HTML5 parser and tag processor in pure PHP, mirroring WordPress core\'s HTML API. No libxml2, no DOM extension, no external dependencies.',
    'wp-php-toolkit/html',
    [
        ('Why this exists',
            '<p>Working with HTML in PHP usually means choosing between <code>libxml2</code> (heavyweight, parses HTML loosely), regex (broken on the first edge case), or <code>DOMDocument</code> (full document mode only). The toolkit\'s HTML component gives you the same API WordPress core uses, runs anywhere, and treats HTML5 the way browsers do.</p>'
            '<p>You get two layers: <code>WP_HTML_Tag_Processor</code> for fast linear scanning and attribute rewriting, and <code>WP_HTML_Processor</code> for full HTML5 tree-construction semantics including implicit closers, foster parenting, and the active formatting elements algorithm.</p>',
            None),
        ('Lazy-load every image',
            '<p>The most common need: rewrite tag attributes without reserializing the document. The tag processor handles each <code>&lt;img&gt;</code> in a single linear pass.</p>',
            ('lazy-load-images.php', php('''$html = '<article>
\t<img src="hero.jpg" alt="Hero">
\t<img src="inline.jpg" alt="Inline">
</article>';

$tags = new WP_HTML_Tag_Processor( $html );
while ( $tags->next_tag( 'img' ) ) {
\t$tags->set_attribute( 'loading', 'lazy' );
\t$tags->add_class( 'responsive' );
}

echo $tags->get_updated_html();'''))),
        ('Query by tag and class',
            '<p>Pass an array to <code>next_tag()</code> to find tags matching a tag name, a CSS class, or both. The processor never builds a DOM; it just advances a cursor.</p>',
            ('query-tags.php', php('''$html = '<ul><li class="todo">Buy milk</li>'
\t. '<li class="todo done">Walk dog</li>'
\t. '<li class="todo">Read book</li></ul>';

$tags = new WP_HTML_Tag_Processor( $html );
while ( $tags->next_tag( array( 'tag_name' => 'li', 'class_name' => 'done' ) ) ) {
\t$tags->set_attribute( 'aria-checked', 'true' );
}
echo $tags->get_updated_html();'''))),
        ('Walk the structure',
            '<p><code>WP_HTML_Processor</code> understands HTML5 tree construction. You can ask it for the current depth, breadcrumbs, and whether a tag is implicitly closed.</p>',
            ('walk-tree.php', php('''$html = '<section><p>First<p>Second<ul><li>A<li>B</ul></section>';

$p = WP_HTML_Processor::create_fragment( $html );
while ( $p->next_token() ) {
\tif ( $p->get_token_type() !== '#tag' ) continue;
\t$prefix = str_repeat( '  ', $p->get_current_depth() );
\t$close  = $p->is_tag_closer() ? '/' : '';
\techo "{$prefix}<{$close}{$p->get_tag()}>\\n";
}'''))),
        ('Decode HTML entities',
            '<p>Need to read attribute values or text content as their decoded form? <code>WP_HTML_Decoder::decode_attribute()</code> handles entity references the way the spec demands — including the long tail of HTML5 named entities and the special rules for unterminated references in attributes.</p>',
            ('decode-entities.php', php('''$html = '<a href="?q=hello&amp;world&amp;copy">Hello&nbsp;&amp;&nbsp;World &copy; 2026</a>';

$tags = new WP_HTML_Tag_Processor( $html );
$tags->next_tag( 'a' );
echo "href=" . $tags->get_attribute( 'href' ) . "\\n";

while ( $tags->next_token() ) {
\tif ( $tags->get_token_type() === '#text' ) {
\t\techo "text=" . $tags->get_modifiable_text() . "\\n";
\t}
}'''))),
        ('Insert and remove subtrees',
            '<p>The full processor lets you replace, insert, or remove entire subtrees by addressing a bookmark. Useful for scrubbing posts before display.</p>',
            ('strip-scripts.php', php('''$html = <<<HTML
<article>
\t<p>Body copy.</p>
\t<script>alert("xss")</script>
\t<p>More copy.</p>
</article>
HTML;

$tags = new WP_HTML_Tag_Processor( $html );
while ( $tags->next_tag( 'script' ) ) {
\t// Drop the script element entirely, including its content.
\t$tags->remove_node();
}
echo $tags->get_updated_html();'''))),
    ]))

# ---------- Zip ----------
COMPONENTS.append(('zip', 'Zip',
    'Read and write ZIP archives in pure PHP. No <code>libzip</code>, no <code>ZipArchive</code>. Streams entries incrementally so it works on multi-gigabyte archives without exhausting memory.',
    'wp-php-toolkit/zip',
    [
        ('Why this exists',
            '<p>PHP\'s built-in ZIP support requires the <code>libzip</code>-backed <code>ZipArchive</code> extension, which isn\'t available everywhere — sandboxed shared hosts, WebAssembly runtimes, alpine images without the extension. The toolkit\'s Zip component reads and writes Stored and Deflate-compressed archives entirely in PHP and exposes a streaming API so you never have to load an archive into memory.</p>',
            None),
        ('Create an archive',
            '<p>Encoder writes one entry at a time. The sink is any <code>WriteStream</code> — here a temp file. For huge archives, a <code>FileWriteStream</code> on the final destination keeps memory flat.</p>',
            ('create-zip.php', php('''use WordPress\\ByteStream\\MemoryPipe;
use WordPress\\ByteStream\\WriteStream\\FileWriteStream;
use WordPress\\Zip\\FileEntry;
use WordPress\\Zip\\ZipDecoder;
use WordPress\\Zip\\ZipEncoder;

$path = tempnam( sys_get_temp_dir(), 'demo' ) . '.zip';
$out  = FileWriteStream::from_path( $path, 'truncate' );
$enc  = new ZipEncoder( $out );

foreach ( array(
\t'readme.txt'      => 'Hello from the toolkit.',
\t'data/hello.json' => json_encode( array( 'ok' => true ) ),
) as $name => $body ) {
\t$enc->append_file( new FileEntry( array(
\t\t'path'               => $name,
\t\t'compression_method' => ZipDecoder::COMPRESSION_DEFLATE,
\t\t'body_reader'        => new MemoryPipe( $body ),
\t) ) );
}
$enc->close();
$out->close_writing();

$bytes = file_get_contents( $path );
printf( "Wrote %d bytes, %d entries.\\n", strlen( $bytes ), substr_count( $bytes, "PK\\x01\\x02" ) );'''))),
        ('Read entries through a filesystem',
            '<p><code>ZipFilesystem</code> implements the toolkit\'s <code>Filesystem</code> interface, so you can <code>ls()</code>, <code>is_file()</code>, and <code>get_contents()</code> as if the archive were a directory tree.</p>',
            ('read-zip.php', php('''use WordPress\\ByteStream\\MemoryPipe;
use WordPress\\ByteStream\\ReadStream\\FileReadStream;
use WordPress\\ByteStream\\WriteStream\\FileWriteStream;
use WordPress\\Zip\\FileEntry;
use WordPress\\Zip\\ZipDecoder;
use WordPress\\Zip\\ZipEncoder;
use WordPress\\Zip\\ZipFilesystem;

// Build an archive on the fly so the example is self-contained.
$path = tempnam( sys_get_temp_dir(), 'demo' ) . '.zip';
$out  = FileWriteStream::from_path( $path, 'truncate' );
$enc  = new ZipEncoder( $out );
foreach ( array(
\t'mimetype'        => 'application/epub+zip',
\t'EPUB/package.opf' => '<package/>',
) as $name => $body ) {
\t$enc->append_file( new FileEntry( array(
\t\t'path'               => $name,
\t\t'compression_method' => ZipDecoder::COMPRESSION_NONE,
\t\t'body_reader'        => new MemoryPipe( $body ),
\t) ) );
}
$enc->close();
$out->close_writing();

$zip = ZipFilesystem::create( FileReadStream::from_path( $path ) );
foreach ( $zip->ls() as $entry ) {
\techo $zip->is_dir( $entry )
\t\t? "[dir] {$entry}\\n"
\t\t: "{$entry}: " . $zip->get_contents( $entry ) . "\\n";
}'''))),
        ('Stream a large file out of an archive',
            '<p>For multi-megabyte entries inside an archive, use <code>open_read_stream()</code> instead of loading the whole file. The decoder inflates as you pull.</p>',
            ('stream-large-entry.php', php('''use WordPress\\ByteStream\\MemoryPipe;
use WordPress\\ByteStream\\ReadStream\\FileReadStream;
use WordPress\\ByteStream\\WriteStream\\FileWriteStream;
use WordPress\\Zip\\FileEntry;
use WordPress\\Zip\\ZipDecoder;
use WordPress\\Zip\\ZipEncoder;
use WordPress\\Zip\\ZipFilesystem;

$path = tempnam( sys_get_temp_dir(), 'demo' ) . '.zip';
$out  = FileWriteStream::from_path( $path, 'truncate' );
$enc  = new ZipEncoder( $out );
$enc->append_file( new FileEntry( array(
\t'path'               => 'big.csv',
\t'compression_method' => ZipDecoder::COMPRESSION_DEFLATE,
\t'body_reader'        => new MemoryPipe( str_repeat( "id,value\\n1,foo\\n2,bar\\n", 200 ) ),
) ) );
$enc->close();
$out->close_writing();

$zip    = ZipFilesystem::create( FileReadStream::from_path( $path ) );
$stream = $zip->open_read_stream( 'big.csv' );
$lines  = 0;
while ( ! $stream->reached_end_of_data() ) {
\t$n = $stream->pull( 4096 );
\tif ( $n <= 0 ) break;
\t$lines += substr_count( $stream->consume( $n ), "\\n" );
}
echo "Streamed {$lines} lines.\\n";'''))),
    ]))

# ---------- ByteStream ----------
COMPONENTS.append(('bytestream', 'ByteStream',
    'Composable streaming primitives for reading, writing, and transforming byte data. Pull-based, peek-friendly, and zero-copy where it matters.',
    'wp-php-toolkit/bytestream',
    [
        ('The model',
            '<p>Every stream has the same shape: <code>pull($n)</code> asks the source for up to <code>$n</code> bytes (returning how many landed in the buffer), <code>peek($n)</code> looks without advancing, <code>consume($n)</code> reads and advances. Pull/consume separation lets parsers backtrack without ever copying out of the source buffer.</p>',
            None),
        ('Read a file in chunks',
            '<p>The classic streaming-read loop: pull until you get bytes, consume them, repeat. Memory usage is bounded by the buffer size.</p>',
            ('read-file.php', php('''use WordPress\\ByteStream\\ReadStream\\FileReadStream;

// Make a sample file inside the snippet so it is self-contained.
$path = tempnam( sys_get_temp_dir(), 'sample' );
file_put_contents( $path, str_repeat( "line of text\\n", 50 ) );

$reader = FileReadStream::from_path( $path );
$total  = 0;
while ( ! $reader->reached_end_of_data() ) {
\t$n = $reader->pull( 64 );
\tif ( $n <= 0 ) break;
\t$total += strlen( $reader->consume( $n ) );
}
$reader->close_reading();
echo "Read {$total} bytes.\\n";'''))),
        ('Memory pipes',
            '<p><code>MemoryPipe</code> is a bidirectional buffer — useful for tests, for wrapping a string in the stream interface, and for piping output of one component into another in-process.</p>',
            ('memory-pipe.php', php('''use WordPress\\ByteStream\\MemoryPipe;

$pipe = new MemoryPipe();
$pipe->append_bytes( "first line\\n" );
$pipe->append_bytes( "second line\\n" );
$pipe->close_writing();

while ( ! $pipe->reached_end_of_data() ) {
\t$n = $pipe->pull( 1024 );
\tif ( $n <= 0 ) break;
\techo "chunk: " . $pipe->consume( $n );
}'''))),
        ('Transform on the fly',
            '<p>Wrap any read stream with a transformer to compute checksums, count bytes, or compress as data flows through. The wrapped stream still satisfies <code>ByteReadStream</code>, so it composes.</p>',
            ('count-lines.php', php('''use WordPress\\ByteStream\\MemoryPipe;
use WordPress\\ByteStream\\ReadStream\\TransformedReadStream;

$source = new MemoryPipe( "alpha\\nbeta\\ngamma\\ndelta\\n" );
$source->close_writing();

$line_count = 0;
$counter = new TransformedReadStream(
\t$source,
\tfunction ( $bytes ) use ( &$line_count ) {
\t\t$line_count += substr_count( $bytes, "\\n" );
\t\treturn $bytes;
\t}
);

while ( ! $counter->reached_end_of_data() ) {
\t$n = $counter->pull( 1024 );
\tif ( $n <= 0 ) break;
\t$counter->consume( $n );
}
echo "{$line_count} lines.\\n";'''))),
    ]))

# ---------- Filesystem ----------
COMPONENTS.append(('filesystem', 'Filesystem',
    'A unified filesystem abstraction across local disk, in-memory trees, SQLite, and ZIP archives. Forward-slash paths everywhere, even on Windows.',
    'wp-php-toolkit/filesystem',
    [
        ('Pick a backend',
            '<p>Every backend implements the same <code>Filesystem</code> interface. Tests use <code>InMemoryFilesystem</code>, production uses <code>LocalFilesystem</code>, and code that reads ZIPs uses <code>ZipFilesystem</code> from the Zip component — same calls everywhere.</p>',
            None),
        ('In-memory tree',
            '<p>The fastest backend. Stores everything in PHP arrays; ideal for tests and ephemeral processing.</p>',
            ('in-memory.php', php('''use WordPress\\Filesystem\\InMemoryFilesystem;

$fs = InMemoryFilesystem::create();
$fs->mkdir( '/src/components', array( 'recursive' => true ) );
$fs->put_contents( '/src/components/button.php', '<?php // button' );
$fs->put_contents( '/src/components/form.php', '<?php // form' );

print_r( $fs->ls( '/src/components' ) );'''))),
        ('Local disk',
            '<p><code>LocalFilesystem::create($root)</code> chroots all paths to <code>$root</code>. The forward-slash convention is enforced even on Windows.</p>',
            ('local.php', php('''use WordPress\\Filesystem\\LocalFilesystem;

$root = sys_get_temp_dir() . '/toolkit-demo';
$fs = LocalFilesystem::create( $root );

$fs->put_contents( '/hello.txt', "Hi!\\n" );
echo $fs->get_contents( '/hello.txt' );
echo "exists? " . ( $fs->exists( '/hello.txt' ) ? 'yes' : 'no' ) . "\\n";'''))),
        ('SQLite-backed',
            '<p>Everything lives in a single SQLite file. Convenient for portable scratch storage that survives a process boundary.</p>',
            ('sqlite.php', php('''use WordPress\\Filesystem\\SQLiteFilesystem;

$fs = SQLiteFilesystem::create( ':memory:' );
$fs->put_contents( '/notes.md', "# Hello\\n\\nFrom SQLite." );
echo $fs->get_contents( '/notes.md' );'''))),
        ('Walk a tree',
            '<p>The interface includes a recursive walker so you can iterate every file regardless of backend.</p>',
            ('walk.php', php('''use WordPress\\Filesystem\\InMemoryFilesystem;

$fs = InMemoryFilesystem::create();
foreach ( array(
\t'/a.txt'           => 'A',
\t'/dir/b.txt'       => 'B',
\t'/dir/sub/c.txt'   => 'C',
) as $path => $body ) {
\t$fs->mkdir( dirname( $path ), array( 'recursive' => true ) );
\t$fs->put_contents( $path, $body );
}

$walker = function ( $dir ) use ( $fs, &$walker ) {
\tforeach ( $fs->ls( $dir ) as $name ) {
\t\t$full = rtrim( $dir, '/' ) . '/' . $name;
\t\tif ( $fs->is_dir( $full ) ) $walker( $full );
\t\telse echo "{$full}\\n";
\t}
};
$walker( '/' );'''))),
    ]))

# ---------- BlockParser ----------
COMPONENTS.append(('blockparser', 'BlockParser',
    'WordPress core\'s block parser as a standalone library. Same parser, no WP dependency.',
    'wp-php-toolkit/blockparser',
    [
        ('Parse block markup',
            '<p>Pass the parser any HTML containing block delimiters and get back a structured array — <code>blockName</code>, <code>attrs</code>, <code>innerBlocks</code>, <code>innerHTML</code>, <code>innerContent</code>.</p>',
            ('parse-blocks.php', php('''$document = "<!-- wp:heading {\\"level\\":2} -->\\n<h2>Welcome</h2>\\n<!-- /wp:heading -->\\n\\n<!-- wp:paragraph -->\\n<p>Hello from the block editor.</p>\\n<!-- /wp:paragraph -->";

$blocks = ( new WP_Block_Parser() )->parse( $document );
foreach ( $blocks as $block ) {
\tif ( $block['blockName'] ) {
\t\techo "{$block['blockName']}: " . trim( strip_tags( $block['innerHTML'] ) ) . "\\n";
\t}
}'''))),
        ('Self-closing blocks',
            '<p>Void blocks like <code>core/spacer</code> end with <code>/--&gt;</code>. They have no inner HTML, just attributes.</p>',
            ('void-blocks.php', php('''$blocks = ( new WP_Block_Parser() )->parse(
\t'<!-- wp:spacer {"height":"50px"} /-->'
);
print_r( $blocks[0] );'''))),
        ('Walk nested blocks',
            '<p>Inner blocks recurse with the same shape, so a depth-first walk is a small recursive function.</p>',
            ('walk-blocks.php', php('''$document = "<!-- wp:group -->\\n<div class=\\"wp-block-group\\">\\n<!-- wp:heading -->\\n<h2>Title</h2>\\n<!-- /wp:heading -->\\n<!-- wp:paragraph -->\\n<p>Body.</p>\\n<!-- /wp:paragraph -->\\n</div>\\n<!-- /wp:group -->";

$blocks = ( new WP_Block_Parser() )->parse( $document );
$walk = function ( array $blocks, int $depth = 0 ) use ( &$walk ) {
\tforeach ( $blocks as $block ) {
\t\tif ( ! $block['blockName'] ) continue;
\t\techo str_repeat( '  ', $depth ) . $block['blockName'] . "\\n";
\t\tif ( ! empty( $block['innerBlocks'] ) ) $walk( $block['innerBlocks'], $depth + 1 );
\t}
};
$walk( $blocks );'''))),
    ]))

# ---------- Markdown ----------
COMPONENTS.append(('markdown', 'Markdown',
    'Bidirectional converter between Markdown and WordPress block markup. Round-trips faithfully so you can keep Markdown files and a WP database in sync.',
    'wp-php-toolkit/markdown',
    [
        ('Markdown to blocks',
            '<p>Pass a Markdown string to <code>MarkdownConsumer</code> and call <code>consume()</code>. The result exposes block markup and any YAML frontmatter as metadata.</p>',
            ('md-to-blocks.php', php('''use WordPress\\Markdown\\MarkdownConsumer;

$markdown = "# Hello World\\n\\nThis is a paragraph with **bold** text.";

$consumer = new MarkdownConsumer( $markdown );
$result   = $consumer->consume();
echo $result->get_block_markup();'''))),
        ('Blocks back to Markdown',
            '<p><code>MarkdownProducer</code> walks block markup and emits matching Markdown. Round-tripping a document should produce equivalent output (modulo whitespace normalization).</p>',
            ('blocks-to-md.php', php('''use WordPress\\Markdown\\MarkdownConsumer;
use WordPress\\Markdown\\MarkdownProducer;

$source  = "## Setup\\n\\n1. Install\\n2. Configure\\n3. Profit";
$blocks  = ( new MarkdownConsumer( $source ) )->consume()->get_block_markup();
$round   = new MarkdownProducer( $blocks );
echo $round->produce();'''))),
        ('Frontmatter',
            '<p>YAML frontmatter is parsed and exposed as metadata so the block markup stays clean.</p>',
            ('frontmatter.php', php('''use WordPress\\Markdown\\MarkdownConsumer;

$markdown = <<<MD
---
post_title: "WordPress 6.8 was released"
post_date: "2024-12-16"
---

## WordPress 6.8 was released

A short post body.
MD;

$result = ( new MarkdownConsumer( $markdown ) )->consume();
print_r( $result->get_all_metadata() );
echo "\\n--- block markup ---\\n";
echo $result->get_block_markup();'''))),
    ]))

# ---------- XML ----------
COMPONENTS.append(('xml', 'XML',
    'Streaming XML processor without libxml2. Modify attributes, walk namespaces, scan large documents without loading them into memory.',
    'wp-php-toolkit/xml',
    [
        ('Read and rewrite an attribute',
            '<p>The <code>XMLProcessor</code> mirrors the HTML tag processor — find a tag, read or set attributes, get the modified document back.</p>',
            ('rewrite-attr.php', php('''use WordPress\\XML\\XMLProcessor;

$xml = '<catalog><book price="29.99"><title>PHP Internals</title></book></catalog>';
$p   = XMLProcessor::create_from_string( $xml );

if ( $p->next_tag( 'book' ) ) {
\techo "before: " . $p->get_attribute( '', 'price' ) . "\\n";
\t$p->set_attribute( '', 'price', '24.99' );
}
echo $p->get_updated_xml();'''))),
        ('Namespaces are first-class',
            '<p>Methods take a namespace URI as the first argument, never a prefix. The processor resolves prefixes itself.</p>',
            ('namespaces.php', php('''use WordPress\\XML\\XMLProcessor;

$xml = '<root xmlns:wp="http://wordpress.org/export/1.2/">'
\t. '<wp:post wp:status="draft">Content</wp:post></root>';

$p  = XMLProcessor::create_from_string( $xml );
$ns = 'http://wordpress.org/export/1.2/';
if ( $p->next_tag( array( $ns, 'post' ) ) ) {
\techo "tag: " . $p->get_tag_local_name() . "\\n";
\techo "status: " . $p->get_attribute( $ns, 'status' ) . "\\n";
\t$p->set_attribute( $ns, 'status', 'published' );
}
echo $p->get_updated_xml();'''))),
    ]))

# ---------- Encoding ----------
COMPONENTS.append(('encoding', 'Encoding',
    'Pure-PHP UTF-8 validation and scrubbing. Detects malformed bytes, replaces them per the Unicode maximal-subpart algorithm, and works without <code>mbstring</code>.',
    'wp-php-toolkit/encoding',
    [
        ('Validate', None,
            ('validate.php', php('''use function WordPress\\Encoding\\wp_is_valid_utf8;

var_dump( wp_is_valid_utf8( 'plain ASCII' ) );          // true
var_dump( wp_is_valid_utf8( "Pencil: \\xE2\\x9C\\x8F" ) ); // true
var_dump( wp_is_valid_utf8( "stray \\xC0 byte" ) );      // false
var_dump( wp_is_valid_utf8( "\\xC1\\xBF" ) );             // false (overlong)'''))),
        ('Scrub invalid bytes', '<p>Replace each maximal subpart with U+FFFD.</p>',
            ('scrub.php', php('''use function WordPress\\Encoding\\wp_scrub_utf8;

echo wp_scrub_utf8( "caf\\xC0 latte" ) . "\\n";   // caf? latte
echo wp_scrub_utf8( ".\\xE2\\x8C." ) . "\\n";       // .?.   (incomplete)
echo wp_scrub_utf8( ".\\xC1\\xBF." ) . "\\n";       // .??.  (two subparts)'''))),
        ('Detect noncharacters', '<p>Code points like U+FFFE that should never appear in interchange.</p>',
            ('noncharacters.php', php('''use function WordPress\\Encoding\\wp_has_noncharacters;

var_dump( wp_has_noncharacters( "Plain text" ) );      // false
var_dump( wp_has_noncharacters( "\\xEF\\xBF\\xBE" ) ); // true (U+FFFE)'''))),
    ]))

# ---------- Polyfill ----------
COMPONENTS.append(('polyfill', 'Polyfill',
    'PHP 8 string functions on PHP 7.2+, WordPress hook stubs, and translation/escaping passthroughs so toolkit code runs without WordPress.',
    'wp-php-toolkit/polyfill',
    [
        ('PHP 8 strings on 7.2',
            '<p>Polyfills are loaded automatically through Composer\'s <code>autoload.files</code>. They define functions only when missing, so they\'re safe to use alongside PHP 8.</p>',
            ('php8-strings.php', php('''var_dump( str_starts_with( '/var/www', '/var' ) );
var_dump( str_ends_with( 'image.png', '.png' ) );
var_dump( str_contains( 'WordPress Toolkit', 'Toolkit' ) );'''))),
        ('WordPress hooks without WordPress',
            '<p>A minimal but real implementation of <code>add_filter</code>, <code>apply_filters</code>, and the action equivalents — priorities and all.</p>',
            ('hooks.php', php('''add_filter( 'the_title', 'strtoupper' );
add_filter( 'the_title', function ( $title ) {
\treturn '> ' . $title;
}, 20 );

echo apply_filters( 'the_title', 'hello world' ) . "\\n";'''))),
    ]))

# ---------- CLI ----------
COMPONENTS.append(('cli', 'CLI',
    'POSIX-style argument parser. Long options, short bundles, inline values, positional args — one static call.',
    'wp-php-toolkit/cli',
    [
        ('Parse argv',
            '<p>Define options as a four-tuple of <code>[ short, has_value, default, description ]</code>, pass <code>argv</code>, get back positionals and an option map.</p>',
            ('parse-args.php', php('''use WordPress\\CLI\\CLI;

$option_defs = array(
\t'output'  => array( 'o', true,  null,  'Output file path' ),
\t'force'   => array( 'f', false, false, 'Overwrite existing files' ),
\t'verbose' => array( 'v', false, false, 'Verbose output' ),
);

$argv = array( '--output=/tmp/result.txt', '-fv', 'input.json' );
list( $positionals, $options ) = CLI::parse_command_args_and_options( $argv, $option_defs );

print_r( array(
\t'positionals' => $positionals,
\t'options'     => $options,
) );'''))),
    ]))

# ---------- Git ----------
COMPONENTS.append(('git', 'Git',
    'A pure-PHP Git client and server. Commits, branches, diffs, HTTP push/pull — all without shelling out to <code>git</code>.',
    'wp-php-toolkit/git',
    [
        ('Commit files in memory',
            '<p>The repository builds the blob, tree, and commit objects for you. Backed by any <code>Filesystem</code>, including <code>InMemoryFilesystem</code> for ephemeral repos.</p>',
            ('commit.php', php('''use WordPress\\Filesystem\\InMemoryFilesystem;
use WordPress\\Git\\GitRepository;

$repo = new GitRepository( InMemoryFilesystem::create() );

$oid = $repo->commit( array(
\t'updates' => array(
\t\t'README.md'           => '# My Project',
\t\t'src/hello-world.php' => '<?php echo "Hello!";',
\t),
) );

echo "commit: {$oid}\\n";
echo $repo->read_object_by_path( '/README.md' )->consume_all();'''))),
        ('Read objects by hash',
            '<p>Every Git object is identified by its SHA-1. Store a blob, get the hash back, read it later.</p>',
            ('objects.php', php('''use WordPress\\Filesystem\\InMemoryFilesystem;
use WordPress\\Git\\GitRepository;

$repo = new GitRepository( InMemoryFilesystem::create() );
$blob = $repo->add_object( 'blob', 'Hello, world!' );
echo "oid: {$blob}\\n";

$reader = $repo->read_object( $blob );
$reader->pull( 8096 );
echo $reader->peek( 8096 ) . "\\n";'''))),
    ]))

# ---------- Merge ----------
COMPONENTS.append(('merge', 'Merge',
    'Three-way merge and diff. Pluggable differ + merger + optional validator.',
    'wp-php-toolkit/merge',
    [
        ('Merge two branches',
            '<p>Give it a base, branch A, and branch B. Get a merge result with conflicts (if any) and the merged content.</p>',
            ('three-way.php', php('''use WordPress\\Merge\\Diff\\LineDiffer;
use WordPress\\Merge\\Merge\\LineMerger;
use WordPress\\Merge\\MergeStrategy;

$strategy = new MergeStrategy( new LineDiffer(), new LineMerger() );

$result = $strategy->merge(
\t"Line 1\\nLine 2\\nLine 3\\n",
\t"Line 1\\nLine 2 modified\\nLine 3\\n",
\t"Line 1\\nLine 2\\nLine 3\\nLine 4\\n"
);

echo $result->get_merged_content();'''))),
        ('Inspect a diff',
            '<p>The <code>Diff</code> object is a flat list of equal/insert/delete operations.</p>',
            ('diff.php', php('''use WordPress\\Merge\\Diff\\Diff;
use WordPress\\Merge\\Diff\\LineDiffer;

$diff = ( new LineDiffer() )->diff(
\t"The quick brown fox\\njumps over the lazy dog.\\n",
\t"The quick brown fox\\njumps over the lazy cat.\\nA new line.\\n"
);

foreach ( $diff->get_changes() as $change ) {
\t$op = array( Diff::DIFF_EQUAL => '=', Diff::DIFF_DELETE => '-', Diff::DIFF_INSERT => '+' )[ $change[0] ];
\techo $op . ' ' . trim( $change[1] ) . "\\n";
}'''))),
    ]))

# ---------- HttpClient ----------
COMPONENTS.append(('httpclient', 'HttpClient',
    'Async HTTP client without <code>curl</code> required. Uses sockets when curl is missing, supports concurrent requests and streaming responses.',
    'wp-php-toolkit/http-client',
    [
        ('Note',
            '<p class="callout"><strong>Network access in the demo runtime.</strong> Snippets execute inside a sandboxed Playground; outbound HTTP requires the CORS proxy. The example below shows the API, but the request itself may not complete in this environment.</p>',
            None),
        ('GET a URL',
            '<p>Build a <code>Request</code>, hand it to <code>Client::fetch()</code>, await the response, read the body.</p>',
            ('get.php', php('''use WordPress\\HttpClient\\Client;
use WordPress\\HttpClient\\Request;

$client  = new Client();
$stream  = $client->fetch( new Request( 'https://example.com/' ) );

$response = $stream->await_response();
echo "status: " . $response->status_code . "\\n";
echo "first 80 bytes: " . substr( $stream->consume_all(), 0, 80 ) . "\\n";'''))),
    ]))

# ---------- HttpServer ----------
COMPONENTS.append(('httpserver', 'HttpServer',
    'A minimal blocking TCP HTTP server in pure PHP. For CLI tools and tests, not for production traffic.',
    'wp-php-toolkit/http-server',
    [
        ('API shape',
            '<p>Bind a port, set a handler that takes <code>IncomingRequest</code> and writes to a <code>ResponseWriteStream</code>, call <code>serve()</code>. The handler runs synchronously per request.</p>'
            '<p class="callout"><strong>Won\'t bind in this runtime.</strong> The Playground sandbox doesn\'t allow listening on TCP ports, so the snippet below is illustrative — copy it to your machine to run it.</p>',
            ('server.php', '''<?php
// Run on your machine, not in Playground:
//   php server.php
require __DIR__ . '/vendor/autoload.php';

use WordPress\\HttpServer\\TcpServer;
use WordPress\\HttpServer\\IncomingRequest;
use WordPress\\HttpServer\\Response\\ResponseWriteStream;

$server = new TcpServer( '127.0.0.1', 8080 );
$server->set_handler( function ( IncomingRequest $request, ResponseWriteStream $response ) {
\t$response->send_http_code( 200 );
\t$response->send_header( 'Content-Type', 'text/plain' );
\t$response->append_bytes( 'Hello, world!' );
} );

echo "Listening on http://127.0.0.1:8080\\n";
$server->serve();''')),
    ]))

# ---------- CORSProxy ----------
COMPONENTS.append(('corsproxy', 'CORSProxy',
    'A small PHP CORS proxy intended for browser-side code that needs to reach servers without CORS headers.',
    'wp-php-toolkit/corsproxy',
    [
        ('Deployment shape',
            '<p>Drop <code>cors-proxy.php</code> into a webroot. Clients append the upstream URL to the proxy path. The proxy streams the response back with CORS headers and blocks private IP ranges.</p>'
            '<p class="callout"><strong>Operational, not runtime.</strong> The proxy is a deployable PHP file rather than a library you call from code, so there\'s no useful in-browser snippet. See the <a href="https://github.com/WordPress/php-toolkit/blob/trunk/components/CORSProxy/README.md">README</a> for deployment details.</p>',
            None),
    ]))

# ---------- Blueprints ----------
COMPONENTS.append(('blueprints', 'Blueprints',
    'Declarative WordPress site provisioning. Write a JSON description of plugins, options, and content; let the runner execute it.',
    'wp-php-toolkit/blueprints',
    [
        ('Two execution modes',
            '<p><code>EXECUTION_MODE_CREATE_NEW_SITE</code> downloads WordPress and installs it. <code>EXECUTION_MODE_APPLY_TO_EXISTING_SITE</code> applies steps to an installed site. The snippet below shows the second; the first needs filesystem write access this runtime doesn\'t have.</p>',
            None),
        ('Apply a step',
            '<p>You can run a single Blueprint step against the currently-running WordPress install — exactly what the <code>&lt;php-snippet&gt;</code> blueprint mechanism does under the hood.</p>',
            ('apply-step.php', php('''use WordPress\\Blueprints\\Runner;
use WordPress\\Blueprints\\RunnerConfiguration;

$config = ( new RunnerConfiguration() )
\t->set_execution_mode( Runner::EXECUTION_MODE_APPLY_TO_EXISTING_SITE )
\t->set_target_site_root( '/wordpress' )
\t->set_target_site_url( 'http://playground.test/' );

echo "Configured runner for: " . $config->get_target_site_root() . "\\n";
echo "Mode: " . $config->get_execution_mode() . "\\n";'''))),
    ]))

# ---------- DataLiberation ----------
COMPONENTS.append(('dataliberation', 'DataLiberation',
    'Streaming WordPress import/export. WXR, SQL, block markup — without loading whole datasets into memory.',
    'wp-php-toolkit/data-liberation',
    [
        ('Write a WXR export',
            '<p>Feed entities to <code>WXRWriter</code> in logical order: post first, then meta/terms/comments belonging to it.</p>',
            ('wxr-writer.php', php('''use WordPress\\ByteStream\\MemoryPipe;
use WordPress\\DataLiberation\\EntityWriter\\WXRWriter;
use WordPress\\DataLiberation\\ImportEntity;

$output = new MemoryPipe();
$writer = new WXRWriter( $output );

$writer->append_entity( new ImportEntity( 'post', array(
\t'post_title' => 'Hello World',
\t'post_date'  => '2024-01-15',
\t'guid'       => 'https://example.com/?p=1',
\t'content'    => '<p>Welcome to my site.</p>',
\t'post_id'    => '1',
\t'post_name'  => 'hello-world',
\t'status'     => 'publish',
\t'post_type'  => 'post',
) ) );

$writer->finalize();
$writer->close_writing();
$output->close_writing();

echo $output->consume_all();'''))),
    ]))

# ---------- ToolkitCodingStandards ----------
COMPONENTS.append(('coding-standards', 'ToolkitCodingStandards',
    'PHP_CodeSniffer sniffs used by this project: enforce Yoda comparisons, ban the short ternary.',
    'wp-php-toolkit/toolkit-coding-standards',
    [
        ('How to use it',
            '<p>This component is a phpcs ruleset, not runtime code, so there\'s nothing to demo in Playground. Reference the standard from your <code>phpcs.xml</code>:</p>'
            '<pre style="background:var(--code-bg);padding:1rem;border-radius:5px;overflow-x:auto"><code>&lt;ruleset&gt;\n  &lt;rule ref="WordPressToolkitCodingStandards"/&gt;\n&lt;/ruleset&gt;</code></pre>'
            '<p>See the <a href="https://github.com/WordPress/php-toolkit/blob/trunk/components/ToolkitCodingStandards/README.md">README</a> for individual sniff selection and example fixes.</p>',
            None),
    ]))


def render_index():
    cards = []
    for slug, title, lede, _, _ in COMPONENTS:
        # one-line description: strip HTML, take first sentence, cap length
        clean = re.sub(r'<[^>]+>', '', lede)
        first = clean.split('.')[0]
        if len(first) > 110:
            first = first[:107] + '…'
        cards.append(
            f'\t\t<li><a href="{slug}/"><strong>{h(title)}</strong>'
            f'<span>{h(first)}.</span></a></li>'
        )
    cards_html = '\n'.join(cards)
    return f'''<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PHP Toolkit — runnable docs</title>
<meta name="description" content="Eighteen pure-PHP libraries for WordPress and general PHP. Every example on this site runs in your browser.">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="site">
\t<a class="brand" href="./">PHP Toolkit</a>
\t<nav>
\t\t<a href="https://github.com/WordPress/php-toolkit">GitHub</a>
\t</nav>
</header>
<main class="landing">
\t<h1>PHP Toolkit</h1>
\t<p class="lede">Eighteen standalone pure-PHP libraries for WordPress and general PHP, with no extension or Composer dependencies. Every example on this site runs in your browser via WordPress Playground — click <em>Run</em>, edit the code, run again.</p>

\t<h2>Components</h2>
\t<ul class="components">
{cards_html}
\t</ul>

\t<h2>How these examples work</h2>
\t<p>Each page embeds <code>&lt;php-snippet&gt;</code> elements from <a href="https://playground.wordpress.net/">WordPress Playground</a>. The first <em>Run</em> click on a page boots a single shared PHP+WordPress runtime in your browser via WebAssembly and unzips the toolkit into it. Subsequent snippets reuse the same runtime, so only the first run pays the boot cost.</p>
\t<p>The toolkit bundle (<code>docs/assets/php-toolkit.zip</code>, ≈1.8&nbsp;MB) ships with the docs, so no third-party CDN is involved.</p>
</main>
<footer class="site">
\t<a href="https://github.com/WordPress/php-toolkit">WordPress/php-toolkit</a>
</footer>
</body>
</html>
'''


def main():
    # Index
    with open(os.path.join(DOCS, 'index.html'), 'w') as f:
        f.write(render_index())

    # Component pages
    for slug, title, lede, install, sections in COMPONENTS:
        out_dir = os.path.join(DOCS, slug)
        os.makedirs(out_dir, exist_ok=True)
        with open(os.path.join(out_dir, 'index.html'), 'w') as f:
            f.write(render_component(slug, title, lede, install, sections))
        print(f'  wrote {slug}/index.html')


if __name__ == '__main__':
    main()
