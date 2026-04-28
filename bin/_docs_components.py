# Component catalog for the runnable docs site. Imported by bin/build-docs.py.
#
# Format: list of (slug, lede_html, sections), where sections is a list of
#   (heading, body_html, snippet_or_None)
# and snippet is (filename, php_code).
#
# Both body_html and php_code may use HTML entities (&lt; &gt; &amp; &quot; &#x27;)
# — the renderer in build-docs.py decodes them before output. That keeps the
# embedded snippets readable when this file is edited as Python.

LOAD = "require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';\n\n"


def php(snippet):
    return '<?php\n' + LOAD + snippet


COMPONENTS = []

# ===========================================================================
# HTML
# ===========================================================================
COMPONENTS.append(('html', 'HTML',
    'A pure-PHP HTML5 parser and tag rewriter mirroring WordPress core\'s HTML API. Treat HTML the way browsers do — without <code>libxml2</code>, <code>DOMDocument</code>, or regex hacks — and rewrite attributes in a single linear pass.',
    'wp-php-toolkit/html',
    [
        ('Two layers, one mental model',
            '<p>The component gives you two processors. <code>WP_HTML_Tag_Processor</code> is a forward-only cursor over tags and tokens — perfect for attribute rewriting at scale. <code>WP_HTML_Processor</code> layers full HTML5 tree construction on top so you can query by ancestry (breadcrumbs), serialize back to well-formed HTML, and trust that <code>&lt;p&gt;one&lt;p&gt;two</code> parses as two paragraphs the way a browser sees it.</p>'
            '<p><strong>Footgun:</strong> mutations are buffered. Nothing changes in the source string until you call <code>get_updated_html()</code>. If you read <code>get_attribute()</code> after a <code>set_attribute()</code> on the same tag, you see the new value — but downstream tooling reading the original string sees stale HTML until you serialize.</p>',
            None),
        ('Add loading="lazy" to every image',
            '<p>The "hello world" of tag rewriting. One linear pass, no DOM, no reserialization cost beyond the bytes you actually changed.</p>',
            ('lazy-load-images.php', php('''$html = '<article>
\t<img src="hero.jpg" alt="Hero">
\t<p>Intro copy.</p>
\t<img src="inline.jpg" alt="Inline">
</article>';

$tags = new WP_HTML_Tag_Processor( $html );
while ( $tags->next_tag( 'img' ) ) {
\t// Don't clobber an explicit eager hint the author already set.
\tif ( null === $tags->get_attribute( 'loading' ) ) {
\t\t$tags->set_attribute( 'loading', 'lazy' );
\t}
\t$tags->set_attribute( 'decoding', 'async' );
}

echo $tags->get_updated_html();'''))),
        ('Rewrite relative links to absolute URLs',
            '<p>Useful when rendering content for an RSS feed, an email, or a site behind a CDN where relative paths break. The processor only rewrites the bytes that changed, so untouched markup stays byte-identical.</p>',
            ('absolute-links.php', php('''$html = '<p>See <a href="/about">about</a>, <a href="https://example.com/x">x</a>, '
\t. 'and <a href="contact.html">contact</a>.</p>';

$base = 'https://my-site.test/';

$tags = new WP_HTML_Tag_Processor( $html );
while ( $tags->next_tag( 'a' ) ) {
\t$href = $tags->get_attribute( 'href' );
\tif ( null === $href || '' === $href ) {
\t\tcontinue;
\t}
\tif ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $href ) || 0 === strpos( $href, '//' ) || 0 === strpos( $href, '#' ) ) {
\t\tcontinue;
\t}
\t$tags->set_attribute( 'href', rtrim( $base, '/' ) . '/' . ltrim( $href, '/' ) );
}

echo $tags->get_updated_html();'''))),
        ('Strip every script and inline event handler',
            '<p>A common sanitization step: neutralize untrusted HTML before display. Blank a script\'s body with <code>set_modifiable_text()</code> and strip every <code>on*</code> attribute via <code>get_attribute_names_with_prefix()</code>.</p>',
            ('sanitize-html.php', php('''$untrusted = '<p>Hi <b onclick="steal()">friend</b>!</p>'
\t. '<script>alert("xss")</script>'
\t. '<img src=x onerror="boom()">';

$tags = new WP_HTML_Tag_Processor( $untrusted );
while ( $tags->next_tag() ) {
\tif ( 'SCRIPT' === $tags->get_tag() && ! $tags->is_tag_closer() ) {
\t\t$tags->set_modifiable_text( '' );
\t}
\t$on_handlers = $tags->get_attribute_names_with_prefix( 'on' );
\tif ( $on_handlers ) {
\t\tforeach ( $on_handlers as $name ) {
\t\t\t$tags->remove_attribute( $name );
\t\t}
\t}
}

echo $tags->get_updated_html();'''))),
        ('Stamp a CSP nonce on inline scripts and styles',
            '<p>Content Security Policy in <code>nonce-</code> mode requires every inline <code>&lt;script&gt;</code> and <code>&lt;style&gt;</code> to carry a matching nonce attribute. Tag-by-tag is exactly the right granularity.</p>',
            ('csp-nonce.php', php('''$nonce = bin2hex( random_bytes( 8 ) );

$html = '<head><style>body{font:16px sans-serif}</style></head>'
\t. '<body><script>console.log("hi")</script><script src="vendor.js"></script></body>';

$tags = new WP_HTML_Tag_Processor( $html );
while ( $tags->next_tag() ) {
\t$tag = $tags->get_tag();
\tif ( ( 'SCRIPT' === $tag || 'STYLE' === $tag ) && ! $tags->is_tag_closer() ) {
\t\t$tags->set_attribute( 'nonce', $nonce );
\t}
}

echo "nonce: {$nonce}\\n\\n";
echo $tags->get_updated_html();'''))),
        ('Build a srcset from a single src',
            '<p>Generate responsive image markup at render time without touching the editor data model. Read the existing <code>src</code>, derive a <code>srcset</code> with width descriptors, add a <code>sizes</code> hint.</p>',
            ('srcset-rewrite.php', php('''$html = '<figure><img src="https://cdn.test/uploads/photo.jpg" alt="Sunset"></figure>';
$widths = array( 480, 768, 1200 );

$tags = new WP_HTML_Tag_Processor( $html );
while ( $tags->next_tag( 'img' ) ) {
\t$src = $tags->get_attribute( 'src' );
\tif ( null === $src || $tags->get_attribute( 'srcset' ) !== null ) {
\t\tcontinue;
\t}
\t$variants = array();
\tforeach ( $widths as $w ) {
\t\t$variants[] = $src . '?w=' . $w . ' ' . $w . 'w';
\t}
\t$tags->set_attribute( 'srcset', implode( ', ', $variants ) );
\t$tags->set_attribute( 'sizes', '(max-width: 768px) 100vw, 768px' );
}

echo $tags->get_updated_html();'''))),
        ('Decode HTML entities the way the spec demands',
            '<p>The HTML5 entity table has roughly 2,200 named references and a long list of edge cases. <code>WP_HTML_Decoder</code> implements the algorithm — don\'t roll your own.</p>',
            ('decode-entities.php', php('''echo "attribute: " . WP_HTML_Decoder::decode_attribute( 'path?a=1&amp;b=2&amp;copy' ) . "\\n";
echo "text:      " . WP_HTML_Decoder::decode_text_node( 'AT&amp;T &mdash; 100&percnt; &#x1F600;' ) . "\\n";

// Safe URL prefix check that respects encoded colons (a classic XSS vector).
$is_javascript = WP_HTML_Decoder::attribute_starts_with(
\t'java&#x09;script:alert(1)',
\t'javascript:',
\t'ascii-case-insensitive'
);
var_dump( $is_javascript );'''))),
        ('Find images by ancestry with breadcrumbs',
            '<p>The full <code>WP_HTML_Processor</code> understands HTML5 tree construction, so you can ask "find every <code>&lt;img&gt;</code> directly inside a <code>&lt;figure&gt;</code>" without writing your own DOM walker.</p>',
            ('breadcrumbs.php', php('''$html = '<article>'
\t. '<figure><img src="hero.jpg" alt="Hero"><figcaption>Hero shot</figcaption></figure>'
\t. '<p>Body copy <img src="emoji.png" alt=""> mid-paragraph.</p>'
\t. '<figure><img src="diagram.png" alt="Diagram"></figure>'
\t. '</article>';

$p = WP_HTML_Processor::create_fragment( $html );
$figure_images = 0;
while ( $p->next_tag( array( 'breadcrumbs' => array( 'FIGURE', 'IMG' ) ) ) ) {
\t$p->add_class( 'figure-image' );
\t$figure_images++;
}

echo "found {$figure_images} figure images\\n";
echo $p->get_updated_html();'''))),
        ('Outline a document by walking tokens with depth',
            '<p>The full processor exposes <code>get_current_depth()</code> and <code>get_breadcrumbs()</code>. Combine with <code>next_token()</code> to print a structural outline.</p>',
            ('outline.php', php('''$html = '<section><h1>Title</h1>'
\t. '<section><h2>Chapter 1</h2><p>Body</p></section>'
\t. '<section><h2>Chapter 2</h2><p>More body</p></section>'
\t. '</section>';

$p = WP_HTML_Processor::create_fragment( $html );
while ( $p->next_token() ) {
\tif ( '#tag' !== $p->get_token_type() || $p->is_tag_closer() ) {
\t\tcontinue;
\t}
\t$tag = $p->get_tag();
\tif ( ! preg_match( '/^H[1-6]$/', $tag ) ) {
\t\tcontinue;
\t}
\t$indent = str_repeat( '  ', max( 0, $p->get_current_depth() - 2 ) );
\t$text = '';
\twhile ( $p->next_token() ) {
\t\tif ( '#text' === $p->get_token_type() ) {
\t\t\t$text .= $p->get_modifiable_text();
\t\t\tcontinue;
\t\t}
\t\tif ( '#tag' === $p->get_token_type() && $tag === $p->get_tag() && $p->is_tag_closer() ) {
\t\t\tbreak;
\t\t}
\t}
\techo "{$indent}{$tag}  {$text}\\n";
}'''))),
        ('Bookmarks: annotate a parent based on its children',
            '<p>Bookmarks are the one escape from forward-only scanning. Save a position, scan ahead, decide what to do, then <code>seek()</code> back and rewrite the earlier tag.</p>',
            ('bookmarks.php', php('''$html = '<ul>'
\t. '<li><input type="checkbox" checked> Buy milk</li>'
\t. '<li><input type="checkbox"> Walk the dog</li>'
\t. '<li><input type="checkbox" checked> Read book</li>'
\t. '</ul>';

$tags = new WP_HTML_Tag_Processor( $html );
$tags->next_tag( 'ul' );
$tags->set_bookmark( 'list' );

$total = 0;
$done = 0;
while ( $tags->next_tag( 'input' ) ) {
\t$total++;
\tif ( null !== $tags->get_attribute( 'checked' ) ) {
\t\t$done++;
\t}
}

$tags->seek( 'list' );
$tags->set_attribute( 'data-progress', $done . '/' . $total );
$tags->release_bookmark( 'list' );

echo $tags->get_updated_html();'''))),
    ]))

# ===========================================================================
# Zip
# ===========================================================================
COMPONENTS.append(('zip', 'Zip',
    'Read and write ZIP archives in pure PHP — no <code>libzip</code>, no <code>ZipArchive</code>. Streams entries one at a time, so you can build EPUBs, .docx files, and multi-gigabyte plugin bundles without buffering the archive in memory.',
    'wp-php-toolkit/zip',
    [
        ('Why this exists',
            '<p>The PHP ecosystem has two ZIP options: the <code>ZipArchive</code> extension (often missing on shared hosts and stripped from WebAssembly builds) and shelling out to <code>zip</code>. Neither helps you stream a 4 GB plugin bundle to the browser, peek at an EPUB manifest without unpacking it, or build a <code>.docx</code> on a host without libzip.</p>'
            '<p>The Zip component reads and writes Stored and Deflate archives in pure PHP. The decoder is pull-based, so listing the central directory of a 2 GB ZIP costs roughly the size of the directory itself. The encoder accepts any <code>ByteWriteStream</code> as a sink and writes one entry at a time.</p>',
            None),
        ('Read a file out of a ZIP',
            '<p><code>ZipFilesystem</code> implements the standard <code>Filesystem</code> interface, so once you wrap the byte reader you can call <code>get_contents()</code>, <code>ls()</code>, <code>is_dir()</code> just like local disk.</p>',
            ('teaser-read.php', php('''use WordPress\\ByteStream\\MemoryPipe;
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
\t'path'               => 'readme.txt',
\t'compression_method' => ZipDecoder::COMPRESSION_NONE,
\t'body_reader'        => new MemoryPipe( 'Hello from inside the zip.' ),
) ) );
$enc->close();
$out->close_writing();

$zip = ZipFilesystem::create( FileReadStream::from_path( $path ) );
echo $zip->get_contents( 'readme.txt' );'''))),
        ('Build an EPUB from scratch',
            '<p>An EPUB is a ZIP with one strict rule: the <code>mimetype</code> entry must come first and must be Stored. Everything else can be Deflate.</p>'
            '<p>Gotcha: e-readers reject EPUBs whose <code>mimetype</code> entry has compression. Use <code>COMPRESSION_NONE</code> for that single entry.</p>',
            ('epub.php', php('''use WordPress\\ByteStream\\MemoryPipe;
use WordPress\\ByteStream\\ReadStream\\FileReadStream;
use WordPress\\ByteStream\\WriteStream\\FileWriteStream;
use WordPress\\Zip\\FileEntry;
use WordPress\\Zip\\ZipDecoder;
use WordPress\\Zip\\ZipEncoder;
use WordPress\\Zip\\ZipFilesystem;

$path = tempnam( sys_get_temp_dir(), 'book' ) . '.epub';
$out  = FileWriteStream::from_path( $path, 'truncate' );
$enc  = new ZipEncoder( $out );

// 1) The mimetype entry MUST be first and stored uncompressed.
$enc->append_file( new FileEntry( array(
\t'path'               => 'mimetype',
\t'compression_method' => ZipDecoder::COMPRESSION_NONE,
\t'body_reader'        => new MemoryPipe( 'application/epub+zip' ),
) ) );

$container = '<?xml version="1.0"?>'
\t. '<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">'
\t. '<rootfiles><rootfile full-path="EPUB/package.opf" media-type="application/oebps-package+xml"/></rootfiles>'
\t. '</container>';

foreach ( array(
\t'META-INF/container.xml' => $container,
\t'EPUB/package.opf'       => '<package version="3.0" xmlns="http://www.idpf.org/2007/opf"><metadata/><manifest/><spine/></package>',
\t'EPUB/chapter1.xhtml'    => '<html xmlns="http://www.w3.org/1999/xhtml"><body><h1>Chapter 1</h1><p>It was a dark and stormy night.</p></body></html>',
) as $name => $body ) {
\t$enc->append_file( new FileEntry( array(
\t\t'path'               => $name,
\t\t'compression_method' => ZipDecoder::COMPRESSION_DEFLATE,
\t\t'body_reader'        => new MemoryPipe( $body ),
\t) ) );
}
$enc->close();
$out->close_writing();

$zip = ZipFilesystem::create( FileReadStream::from_path( $path ) );
printf( "mimetype: %s\\n", $zip->get_contents( 'mimetype' ) );
printf( "size on disk: %d bytes\\n", filesize( $path ) );'''))),
        ('Stream a large entry without buffering it',
            '<p>Calling <code>get_contents()</code> on a 500 MB CSV inside a ZIP would eat 500 MB of RAM. Use <code>open_read_stream()</code> instead and inflate-as-you-go.</p>'
            '<p>Gotcha: only one entry stream open at a time. Drain or finish the previous stream before opening the next.</p>',
            ('stream-large.php', php('''use WordPress\\ByteStream\\MemoryPipe;
use WordPress\\ByteStream\\ReadStream\\FileReadStream;
use WordPress\\ByteStream\\WriteStream\\FileWriteStream;
use WordPress\\Zip\\FileEntry;
use WordPress\\Zip\\ZipDecoder;
use WordPress\\Zip\\ZipEncoder;
use WordPress\\Zip\\ZipFilesystem;

$path = tempnam( sys_get_temp_dir(), 'big' ) . '.zip';
$out  = FileWriteStream::from_path( $path, 'truncate' );
$enc  = new ZipEncoder( $out );
$enc->append_file( new FileEntry( array(
\t'path'               => 'data.csv',
\t'compression_method' => ZipDecoder::COMPRESSION_DEFLATE,
\t'body_reader'        => new MemoryPipe( str_repeat( "id,value,timestamp\\n1,foo,2024\\n2,bar,2024\\n", 5000 ) ),
) ) );
$enc->close();
$out->close_writing();

$zip    = ZipFilesystem::create( FileReadStream::from_path( $path ) );
$stream = $zip->open_read_stream( 'data.csv' );

$rows  = 0;
$bytes = 0;
$tail  = '';
while ( ! $stream->reached_end_of_data() ) {
\t$n = $stream->pull( 8192 );
\tif ( 0 === $n ) break;
\t$chunk  = $tail . $stream->consume( $n );
\t$lines  = explode( "\\n", $chunk );
\t$tail   = array_pop( $lines );
\t$rows  += count( $lines );
\t$bytes += $n;
}
printf( "Inflated %d bytes in 8 KB chunks, parsed %d rows.\\n", $bytes, $rows );'''))),
        ('Repack: modify one file, copy the rest',
            '<p>Updating one file in a ZIP without rewriting the others is impossible at the format level — the central directory points at byte offsets. The pragmatic answer is repack: stream the source archive into a new one, swapping the file you care about.</p>',
            ('repack.php', php('''use WordPress\\ByteStream\\MemoryPipe;
use WordPress\\ByteStream\\ReadStream\\FileReadStream;
use WordPress\\ByteStream\\WriteStream\\FileWriteStream;
use WordPress\\Zip\\FileEntry;
use WordPress\\Zip\\ZipDecoder;
use WordPress\\Zip\\ZipEncoder;
use WordPress\\Zip\\ZipFilesystem;

$src_path = tempnam( sys_get_temp_dir(), 'orig' ) . '.zip';
$src_out  = FileWriteStream::from_path( $src_path, 'truncate' );
$src_enc  = new ZipEncoder( $src_out );
foreach ( array(
\t'config.json'   => '{"debug":false,"version":"1.0"}',
\t'app/index.php' => '<?php echo "hello";',
\t'app/style.css' => 'body{color:#333}',
) as $name => $body ) {
\t$src_enc->append_file( new FileEntry( array(
\t\t'path'               => $name,
\t\t'compression_method' => ZipDecoder::COMPRESSION_DEFLATE,
\t\t'body_reader'        => new MemoryPipe( $body ),
\t) ) );
}
$src_enc->close();
$src_out->close_writing();

$source   = ZipFilesystem::create( FileReadStream::from_path( $src_path ) );
$dst_path = tempnam( sys_get_temp_dir(), 'repacked' ) . '.zip';
$dst_out  = FileWriteStream::from_path( $dst_path, 'truncate' );
$dst_enc  = new ZipEncoder( $dst_out );

$walk = function ( $dir ) use ( &$walk, $source, $dst_enc ) {
\tforeach ( $source->ls( $dir ) as $name ) {
\t\t$path = rtrim( $dir, '/' ) . '/' . $name;
\t\tif ( $source->is_dir( $path ) ) {
\t\t\t$walk( $path );
\t\t\tcontinue;
\t\t}
\t\t$rel  = ltrim( $path, '/' );
\t\t$body = ( 'config.json' === $rel )
\t\t\t? '{"debug":true,"version":"1.0.1"}'
\t\t\t: $source->get_contents( $rel );
\t\t$dst_enc->append_file( new FileEntry( array(
\t\t\t'path'               => $rel,
\t\t\t'compression_method' => ZipDecoder::COMPRESSION_DEFLATE,
\t\t\t'body_reader'        => new MemoryPipe( $body ),
\t\t) ) );
\t}
};
$walk( '/' );
$dst_enc->close();
$dst_out->close_writing();

$repacked = ZipFilesystem::create( FileReadStream::from_path( $dst_path ) );
echo "new config.json: " . $repacked->get_contents( 'config.json' ) . "\\n";
echo "untouched: " . $repacked->get_contents( 'app/index.php' ) . "\\n";'''))),
        ('Defend against zip-slip',
            '<p>A malicious archive can name an entry <code>../../etc/passwd</code> and trick a naive extractor into clobbering files outside the destination. <code>ZipDecoder::sanitize_path()</code> strips leading <code>../</code> segments and collapses internal <code>/../</code> sequences before exposing the path.</p>',
            ('zip-slip.php', php('''use WordPress\\Zip\\ZipDecoder;

$evil_inputs = array(
\t'../../etc/passwd',
\t'./safe/path.txt',
\t'a/../../b/secret',
\t'a//b///c.txt',
\t'../../../../root/.ssh/authorized_keys',
);
foreach ( $evil_inputs as $name ) {
\tprintf( "%-45s => %s\\n", $name, ZipDecoder::sanitize_path( $name ) );
}'''))),
        ('Pipe ZIP entries into an InMemoryFilesystem',
            '<p>Real-world recipe: take an uploaded plugin ZIP, expand it into an <code>InMemoryFilesystem</code> so you can validate, edit, or scan it before it ever touches disk. Three components compose into something you couldn\'t build with <code>ZipArchive</code> alone.</p>',
            ('zip-to-memfs.php', php('''use WordPress\\ByteStream\\MemoryPipe;
use WordPress\\ByteStream\\ReadStream\\FileReadStream;
use WordPress\\ByteStream\\WriteStream\\FileWriteStream;
use WordPress\\Filesystem\\InMemoryFilesystem;
use WordPress\\Zip\\FileEntry;
use WordPress\\Zip\\ZipDecoder;
use WordPress\\Zip\\ZipEncoder;
use WordPress\\Zip\\ZipFilesystem;
use function WordPress\\Filesystem\\copy_between_filesystems;

$path = tempnam( sys_get_temp_dir(), 'app' ) . '.zip';
$out  = FileWriteStream::from_path( $path, 'truncate' );
$enc  = new ZipEncoder( $out );
foreach ( array(
\t'app/index.php'        => '<?php echo "ok";',
\t'app/lib/util.php'     => '<?php // util',
\t'app/assets/style.css' => 'body{margin:0}',
\t'app/README.md'        => '# App',
) as $name => $body ) {
\t$enc->append_file( new FileEntry( array(
\t\t'path'               => $name,
\t\t'compression_method' => ZipDecoder::COMPRESSION_DEFLATE,
\t\t'body_reader'        => new MemoryPipe( $body ),
\t) ) );
}
$enc->close();
$out->close_writing();

$zip = ZipFilesystem::create( FileReadStream::from_path( $path ) );
$mem = InMemoryFilesystem::create();
copy_between_filesystems( array(
\t'source_filesystem' => $zip,
\t'source_path'       => '/',
\t'target_filesystem' => $mem,
\t'target_path'       => '/',
) );

$mem->put_contents( '/app/VERSION', '1.0.0' );
echo "files now in memory:\\n";
$walk = function ( $dir ) use ( &$walk, $mem ) {
\tforeach ( $mem->ls( $dir ) as $name ) {
\t\t$p = rtrim( $dir, '/' ) . '/' . $name;
\t\t$mem->is_dir( $p ) ? $walk( $p ) : print( "  " . $p . "\\n" );
\t}
};
$walk( '/' );'''))),
    ]))

# ===========================================================================
# ByteStream
# ===========================================================================
COMPONENTS.append(('bytestream', 'ByteStream',
    'Composable streaming primitives for reading, writing, transforming, hashing, and compressing byte data. Pull/peek/consume semantics let parsers backtrack without copying, and deflate, inflate, and checksum filters snap together like Lego.',
    'wp-php-toolkit/bytestream',
    [
        ('Why this exists',
            '<p>PHP\'s native streams are powerful but inconsistent. <code>fread</code> on a socket may return short reads with no warning; <code>stream_filter_append</code> is awkward to compose; <code>gzopen</code> works only on files. The ByteStream component normalizes all of these behind one tiny interface — <code>pull / peek / consume</code> — so a parser, a hash function, and a deflate filter all see the same shape.</p>'
            '<p>The split between <em>pull</em> (buffer up to N bytes) and <em>consume</em> (advance past N bytes) is the secret. Parsers can <code>peek</code> ahead to detect a record boundary and decide whether to <code>consume</code>, without copying or allocating.</p>',
            None),
        ('Read a file in chunks',
            '<p>The canonical loop. <code>pull()</code> tells you how many bytes are buffered; <code>consume()</code> reads them and advances. The buffer never grows beyond the chunk size you ask for.</p>',
            ('teaser-read.php', php('''use WordPress\\ByteStream\\ReadStream\\FileReadStream;

$path = tempnam( sys_get_temp_dir(), 'demo' );
file_put_contents( $path, str_repeat( "log line\\n", 200 ) );

$reader = FileReadStream::from_path( $path );
$total = 0;
while ( ! $reader->reached_end_of_data() ) {
\t$n = $reader->pull( 256 );
\tif ( 0 === $n ) break;
\t$total += strlen( $reader->consume( $n ) );
}
$reader->close_reading();
echo "Read {$total} bytes in 256-byte chunks.\\n";'''))),
        ('MemoryPipe as write-then-read buffer',
            '<p><code>MemoryPipe</code> is bidirectional: you <code>append_bytes()</code> as a writer and <code>pull/consume</code> as a reader. Easiest way to wire one component\'s output into another\'s input.</p>'
            '<p>Gotcha: a producer must call <code>close_writing()</code> when done — otherwise the consumer eventually throws <code>NotEnoughDataException</code> instead of seeing EOF.</p>',
            ('memory-pipe.php', php('''use WordPress\\ByteStream\\MemoryPipe;

$pipe = new MemoryPipe();
$pipe->append_bytes( "first chunk\\n" );
$pipe->append_bytes( "second chunk\\n" );
$pipe->append_bytes( "third chunk\\n" );
$pipe->close_writing();

while ( ! $pipe->reached_end_of_data() ) {
\t$n = $pipe->pull( 1024 );
\tif ( 0 === $n ) break;
\techo "got: " . $pipe->consume( $n );
}'''))),
        ('Compress on the way in, decompress on the way out',
            '<p>Wrap a stream in <code>DeflateReadStream</code> to get compressed bytes out; wrap it in <code>InflateReadStream</code> to get decompressed bytes out. Both are full <code>ByteReadStream</code> implementations, so they nest into anything else that takes a stream.</p>',
            ('deflate-roundtrip.php', php('''use WordPress\\ByteStream\\MemoryPipe;
use WordPress\\ByteStream\\ReadStream\\DeflateReadStream;
use WordPress\\ByteStream\\ReadStream\\InflateReadStream;

$original = str_repeat( "the quick brown fox. ", 50 );

$src        = new MemoryPipe( $original );
$src->close_writing();
$deflated   = new DeflateReadStream( $src, ZLIB_ENCODING_DEFLATE );
$compressed = $deflated->consume_all();

$src2     = new MemoryPipe( $compressed );
$src2->close_writing();
$inflated = new InflateReadStream( $src2, ZLIB_ENCODING_DEFLATE );
$round    = $inflated->consume_all();

printf( "original  : %d bytes\\n", strlen( $original ) );
printf( "deflated  : %d bytes (%.1f%%)\\n", strlen( $compressed ), 100 * strlen( $compressed ) / strlen( $original ) );
printf( "round-trip: %s\\n", $round === $original ? 'OK' : 'BROKEN' );'''))),
        ('Line-by-line reads from a chunked source',
            '<p>Reading text by line means handling chunk boundaries that fall mid-line. Keep the trailing partial line and prepend it to the next pull. The rest of the loop pretends the data was always whole.</p>',
            ('lines.php', php('''use WordPress\\ByteStream\\MemoryPipe;

$pipe = new MemoryPipe();
$pipe->append_bytes( "alpha\\nbravo\\ncharl" );
$pipe->append_bytes( "ie\\ndelta\\necho\\n" );
$pipe->close_writing();

$tail = '';
$count = 0;
while ( ! $pipe->reached_end_of_data() ) {
\t$n = $pipe->pull( 8 );
\tif ( 0 === $n ) break;
\t$buf   = $tail . $pipe->consume( $n );
\t$lines = explode( "\\n", $buf );
\t$tail  = array_pop( $lines );
\tforeach ( $lines as $line ) {
\t\tprintf( "[%d] %s\\n", ++$count, $line );
\t}
}
if ( '' !== $tail ) {
\tprintf( "[%d] %s\\n", ++$count, $tail );
}'''))),
        ('Limit a stream to a fixed window',
            '<p><code>LimitedByteReadStream</code> exposes only the next N bytes of an underlying stream as if those were the entire stream. This is how the ZIP decoder hands you the body of one entry without letting you read into the next.</p>',
            ('limited.php', php('''use WordPress\\ByteStream\\MemoryPipe;
use WordPress\\ByteStream\\ReadStream\\LimitedByteReadStream;

$source = new MemoryPipe( "HEADER:42|BODY:hello there|FOOTER:done" );
$source->close_writing();

$source->pull( 10 );
$source->consume( 10 );

$body = new LimitedByteReadStream( $source, 16 );
echo "body sees: " . $body->consume_all() . "\\n";
echo "remaining in source: " . $source->consume_all() . "\\n";'''))),
    ]))

# ===========================================================================
# Filesystem
# ===========================================================================
COMPONENTS.append(('filesystem', 'Filesystem',
    'One <code>Filesystem</code> interface across local disk, in-memory trees, SQLite databases, and ZIP archives. Forward-slash paths everywhere — even on Windows — so the same code runs in tests, in production, and inside read-only ZIPs.',
    'wp-php-toolkit/filesystem',
    [
        ('Why this exists',
            '<p>Code that touches the filesystem is hard to test, hard to port to Windows, and impossible to point at non-disk storage without rewriting it. Swap <code>LocalFilesystem</code> for <code>InMemoryFilesystem</code> in tests and your suite stops touching <code>/tmp</code>; swap it for <code>SQLiteFilesystem</code> and your "files" become rows in a portable database; swap it for <code>ZipFilesystem</code> and you can read inside an archive with the same calls.</p>'
            '<p>Every backend uses forward slashes regardless of host OS. No <code>DIRECTORY_SEPARATOR</code> juggling, no Windows-only test failures, no surprises when a path moves between backends.</p>',
            None),
        ('In-memory tree',
            '<p>The fastest backend. No disk I/O, no cleanup, no test-isolation problems.</p>',
            ('teaser-memory.php', php('''use WordPress\\Filesystem\\InMemoryFilesystem;

$fs = InMemoryFilesystem::create();
$fs->put_contents( '/hello.txt', 'Hello, world!' );
echo $fs->get_contents( '/hello.txt' );'''))),
        ('Test code without touching disk',
            '<p>Pass production code a <code>Filesystem</code> instead of using <code>file_get_contents</code> directly, and your tests run against an in-memory tree with no setup or teardown.</p>',
            ('test-without-disk.php', php('''use WordPress\\Filesystem\\Filesystem;
use WordPress\\Filesystem\\InMemoryFilesystem;

function bump_version( Filesystem $fs, $path ) {
\t$json = json_decode( $fs->get_contents( $path ), true );
\tlist( $maj, $min, $patch ) = explode( '.', $json['version'] );
\t$json['version'] = $maj . '.' . $min . '.' . ( (int) $patch + 1 );
\t$fs->put_contents( $path, json_encode( $json ) );
}

$fs = InMemoryFilesystem::create();
$fs->put_contents( '/package.json', '{"version":"1.2.3"}' );
bump_version( $fs, '/package.json' );

echo $fs->get_contents( '/package.json' ) . "\\n";'''))),
        ('Local disk with a chrooted root',
            '<p><code>LocalFilesystem::create($root)</code> is implicitly chrooted: every path resolves relative to <code>$root</code> and a <code>../</code> can\'t escape. Useful when the path comes from user input.</p>',
            ('local-chroot.php', php('''use WordPress\\Filesystem\\LocalFilesystem;

$root = sys_get_temp_dir() . '/toolkit-' . uniqid();
$fs   = LocalFilesystem::create( $root );

$fs->mkdir( '/uploads', array( 'recursive' => true ) );
$fs->put_contents( '/uploads/note.txt', 'Hi from local disk.' );

echo $fs->get_contents( '/uploads/../uploads/note.txt' ) . "\\n";

$fs->rmdir( '/', array( 'recursive' => true ) );
echo "exists after cleanup? " . ( is_dir( $root ) ? 'yes' : 'no' ) . "\\n";'''))),
        ('SQLite as a portable file store',
            '<p>The whole tree lives in one SQLite file you can ship anywhere PHP runs. Useful for plugins that want self-contained scratch storage that survives process boundaries without leaving loose files behind.</p>',
            ('sqlite.php', php('''use WordPress\\Filesystem\\SQLiteFilesystem;

$fs = SQLiteFilesystem::create( ':memory:' );
$fs->mkdir( '/posts', array( 'recursive' => true ) );
for ( $i = 1; $i <= 3; $i++ ) {
\t$fs->put_contents( "/posts/post-{$i}.md", "# Post {$i}\\n\\nBody {$i}." );
}

foreach ( $fs->ls( '/posts' ) as $name ) {
\t$first = strtok( $fs->get_contents( '/posts/' . $name ), "\\n" );
\techo "{$name}: {$first}\\n";
}'''))),
        ('Copy a tree across backends',
            '<p>The killer composability move: <code>copy_between_filesystems()</code> streams files chunk-by-chunk from any source to any target. Pull a ZIP into SQLite, snapshot SQLite to disk, mirror disk into RAM — all the same call.</p>',
            ('cross-backend-copy.php', php('''use WordPress\\Filesystem\\InMemoryFilesystem;
use WordPress\\Filesystem\\LocalFilesystem;
use WordPress\\Filesystem\\SQLiteFilesystem;
use function WordPress\\Filesystem\\copy_between_filesystems;

$root  = sys_get_temp_dir() . '/copytree-' . uniqid();
$local = LocalFilesystem::create( $root );
$local->mkdir( '/site/posts', array( 'recursive' => true ) );
$local->put_contents( '/site/posts/2024-01.md', '# Hello 2024' );
$local->put_contents( '/site/index.html', '<h1>Home</h1>' );

$sqlite = SQLiteFilesystem::create( ':memory:' );
copy_between_filesystems( array(
\t'source_filesystem' => $local,
\t'source_path'       => '/site',
\t'target_filesystem' => $sqlite,
\t'target_path'       => '/snapshot',
) );

$mem = InMemoryFilesystem::create();
copy_between_filesystems( array(
\t'source_filesystem' => $sqlite,
\t'source_path'       => '/snapshot',
\t'target_filesystem' => $mem,
\t'target_path'       => '/copy',
) );

echo "in memory after two copies:\\n";
echo "  posts: " . implode( ', ', $mem->ls( '/copy/posts' ) ) . "\\n";
echo "  index: " . $mem->get_contents( '/copy/index.html' ) . "\\n";

$local->rmdir( '/', array( 'recursive' => true ) );'''))),
        ('Atomic write via tempfile rename',
            '<p>Write to a sibling tempfile, then rename — that\'s how you avoid leaving a half-written file on crash. <code>rename()</code> is atomic within a single filesystem.</p>',
            ('atomic-write.php', php('''use WordPress\\Filesystem\\Filesystem;
use WordPress\\Filesystem\\LocalFilesystem;

function atomic_put_contents( Filesystem $fs, $path, $bytes ) {
\t$tmp = $path . '.tmp.' . bin2hex( random_bytes( 4 ) );
\t$fs->put_contents( $tmp, $bytes );
\t$fs->rename( $tmp, $path );
}

$root = sys_get_temp_dir() . '/atomic-' . uniqid();
$fs   = LocalFilesystem::create( $root );

$fs->put_contents( '/config.json', '{"v":1}' );
atomic_put_contents( $fs, '/config.json', '{"v":2}' );

echo "config: " . $fs->get_contents( '/config.json' ) . "\\n";
echo "no .tmp leftovers: " . count( $fs->ls( '/' ) ) . " entries in root\\n";

$fs->rmdir( '/', array( 'recursive' => true ) );'''))),
        ('Path helpers that behave the same on Windows',
            '<p>Unix path semantics on every host OS. Useful when the path is something abstract — a key in a SQLite filesystem, an entry name in a ZIP — that doesn\'t live on a real drive.</p>',
            ('path-helpers.php', php('''use function WordPress\\Filesystem\\wp_join_unix_paths;
use function WordPress\\Filesystem\\wp_unix_dirname;
use function WordPress\\Filesystem\\wp_unix_path_resolve_dots;

echo wp_join_unix_paths( '/var/www', '/site/', '/index.php' ) . "\\n";
echo wp_unix_dirname( '/a/b/c/d.txt', 2 ) . "\\n";
echo wp_unix_path_resolve_dots( '/a/b/../c/./d/../e' ) . "\\n";'''))),
    ]))

# ===========================================================================
# BlockParser
# ===========================================================================
COMPONENTS.append(('blockparser', 'BlockParser',
    'WordPress core\'s block parser, packaged as a standalone library. Turn block markup into a structured tree, lint posts for common authoring mistakes, and migrate attributes between block versions — all without booting WordPress.',
    'wp-php-toolkit/blockparser',
    [
        ('What you get back',
            '<p><code>WP_Block_Parser::parse()</code> returns an array of blocks. Each block is an associative array with five keys: <code>blockName</code>, <code>attrs</code>, <code>innerBlocks</code>, <code>innerHTML</code>, and <code>innerContent</code>.</p>'
            '<p><code>innerHTML</code> is the HTML inside the block <em>with inner blocks stripped out</em>. <code>innerContent</code> is the interleaved version: an array of HTML strings with <code>null</code> placeholders marking where each inner block belongs.</p>'
            '<p><strong>Footgun:</strong> freeform HTML between blocks shows up as a block with <code>blockName === null</code>. Walkers that key off <code>blockName</code> need to handle the null case.</p>',
            None),
        ('Parse a document',
            '<p>The simplest possible use. Pass a string, get back a tree.</p>',
            ('parse.php', php('''$document = "<!-- wp:heading {\\"level\\":2} -->\\n<h2>Welcome</h2>\\n<!-- /wp:heading -->\\n\\n"
\t. "<!-- wp:paragraph -->\\n<p>Hello from the block editor.</p>\\n<!-- /wp:paragraph -->";

$blocks = ( new WP_Block_Parser() )->parse( $document );
foreach ( $blocks as $block ) {
\tif ( null === $block['blockName'] ) continue;
\techo $block['blockName'] . ': ' . trim( strip_tags( $block['innerHTML'] ) ) . "\\n";
}'''))),
        ('Count every block type in a post',
            '<p>The first thing most plugin authors actually want: a histogram. Combine recursion with <code>blockName</code> to handle <code>core/columns</code>, <code>core/group</code>, and other containers.</p>',
            ('count-blocks.php', php('''$document = "<!-- wp:group --><div class=\\"wp-block-group\\">"
\t. "<!-- wp:heading --><h2>Title</h2><!-- /wp:heading -->"
\t. "<!-- wp:paragraph --><p>One.</p><!-- /wp:paragraph -->"
\t. "<!-- wp:paragraph --><p>Two.</p><!-- /wp:paragraph -->"
\t. "<!-- wp:image {\\"id\\":1} --><figure><img src=\\"a.jpg\\"/></figure><!-- /wp:image -->"
\t. "</div><!-- /wp:group -->";

$blocks = ( new WP_Block_Parser() )->parse( $document );

$counts = array();
$walk = null;
$walk = function ( $blocks ) use ( &$walk, &$counts ) {
\tforeach ( $blocks as $block ) {
\t\tif ( null !== $block['blockName'] ) {
\t\t\t$counts[ $block['blockName'] ] = isset( $counts[ $block['blockName'] ] ) ? $counts[ $block['blockName'] ] + 1 : 1;
\t\t}
\t\tif ( ! empty( $block['innerBlocks'] ) ) $walk( $block['innerBlocks'] );
\t}
};
$walk( $blocks );

arsort( $counts );
foreach ( $counts as $name => $n ) {
\techo str_pad( (string) $n, 4, ' ', STR_PAD_LEFT ) . '  ' . $name . "\\n";
}'''))),
        ('Lint headings for hierarchy mistakes',
            '<p>"Don\'t skip from h2 to h4" is a real accessibility rule. Walk every <code>core/heading</code>, look at its <code>level</code> attribute (default 2), and warn whenever the level jumps by more than one.</p>',
            ('lint-headings.php', php('''$document = "<!-- wp:heading -->\\n<h2>Intro</h2>\\n<!-- /wp:heading -->"
\t. "<!-- wp:heading {\\"level\\":4} -->\\n<h4>Subsection</h4>\\n<!-- /wp:heading -->"
\t. "<!-- wp:heading {\\"level\\":3} -->\\n<h3>Body</h3>\\n<!-- /wp:heading -->";

$blocks = ( new WP_Block_Parser() )->parse( $document );

$last = 1;
$index = 0;
$walk = null;
$walk = function ( $blocks ) use ( &$walk, &$last, &$index ) {
\tforeach ( $blocks as $block ) {
\t\tif ( 'core/heading' === $block['blockName'] ) {
\t\t\t$index++;
\t\t\t$level = isset( $block['attrs']['level'] ) ? (int) $block['attrs']['level'] : 2;
\t\t\tif ( $level > $last + 1 ) {
\t\t\t\techo "WARN heading #{$index}: jumped from H{$last} to H{$level}\\n";
\t\t\t} else {
\t\t\t\techo "ok   heading #{$index}: H{$level}\\n";
\t\t\t}
\t\t\t$last = $level;
\t\t}
\t\tif ( ! empty( $block['innerBlocks'] ) ) $walk( $block['innerBlocks'] );
\t}
};
$walk( $blocks );'''))),
        ('Find all instances of a custom block',
            '<p>Useful when auditing an export for a block your plugin owns: every <code>my-plugin/testimonial</code>, with its attributes and inner content.</p>',
            ('find-custom-block.php', php('''$document = "<!-- wp:paragraph --><p>Reviews</p><!-- /wp:paragraph -->"
\t. "<!-- wp:my-plugin/testimonial {\\"author\\":\\"Jane\\",\\"rating\\":5} -->"
\t. "<blockquote>Loved it.</blockquote>"
\t. "<!-- /wp:my-plugin/testimonial -->"
\t. "<!-- wp:my-plugin/testimonial {\\"author\\":\\"Joe\\",\\"rating\\":4} -->"
\t. "<blockquote>Pretty good.</blockquote>"
\t. "<!-- /wp:my-plugin/testimonial -->";

$blocks = ( new WP_Block_Parser() )->parse( $document );

$find = null;
$find = function ( $blocks, $name ) use ( &$find ) {
\t$out = array();
\tforeach ( $blocks as $block ) {
\t\tif ( $name === $block['blockName'] ) $out[] = $block;
\t\tif ( ! empty( $block['innerBlocks'] ) ) $out = array_merge( $out, $find( $block['innerBlocks'], $name ) );
\t}
\treturn $out;
};

foreach ( $find( $blocks, 'my-plugin/testimonial' ) as $i => $b ) {
\techo ( $i + 1 ) . '. ' . $b['attrs']['author'] . ' (' . $b['attrs']['rating'] . '/5): '
\t\t. trim( strip_tags( $b['innerHTML'] ) ) . "\\n";
}'''))),
        ('Migrate attributes from an old block version',
            '<p>Block schemas evolve. A common migration: rename <code>textColor</code> to <code>color</code>, or split a combined attribute. Walk the tree, detect the old shape, write a new block array.</p>',
            ('migrate-attrs.php', php('''$document = '<!-- wp:my-plugin/callout {"textColor":"red","bold":true} -->'
\t. '<p>Heads up!</p>'
\t. '<!-- /wp:my-plugin/callout -->';

$blocks = ( new WP_Block_Parser() )->parse( $document );

$migrate = null;
$migrate = function ( $blocks ) use ( &$migrate ) {
\t$out = array();
\tforeach ( $blocks as $block ) {
\t\tif ( 'my-plugin/callout' === $block['blockName'] ) {
\t\t\t$attrs = $block['attrs'];
\t\t\tif ( isset( $attrs['textColor'] ) ) {
\t\t\t\t$attrs['color'] = $attrs['textColor'];
\t\t\t\tunset( $attrs['textColor'] );
\t\t\t}
\t\t\tif ( isset( $attrs['bold'] ) ) {
\t\t\t\t$attrs['fontWeight'] = $attrs['bold'] ? 700 : 400;
\t\t\t\tunset( $attrs['bold'] );
\t\t\t}
\t\t\t$block['attrs'] = $attrs;
\t\t}
\t\tif ( ! empty( $block['innerBlocks'] ) ) {
\t\t\t$block['innerBlocks'] = $migrate( $block['innerBlocks'] );
\t\t}
\t\t$out[] = $block;
\t}
\treturn $out;
};

$migrated = $migrate( $blocks );
echo json_encode( $migrated[0]['attrs'], JSON_PRETTY_PRINT ) . "\\n";'''))),
        ('Detect blocks with stale embed URLs',
            '<p>A real-world scrub: find every <code>core/embed</code> whose URL points at a domain you\'ve retired. Useful when auditing a multi-thousand-post export.</p>',
            ('audit-embeds.php', php('''$document = '<!-- wp:embed {"url":"https://twitter.com/wordpress/status/1","providerNameSlug":"twitter"} /-->'
\t. '<!-- wp:embed {"url":"https://youtube.com/watch?v=abc","providerNameSlug":"youtube"} /-->'
\t. '<!-- wp:embed {"url":"https://vine.co/v/xyz","providerNameSlug":"vine"} /-->';

$retired = array( 'vine.co', 'plus.google.com' );

foreach ( ( new WP_Block_Parser() )->parse( $document ) as $b ) {
\tif ( 'core/embed' !== $b['blockName'] ) continue;
\t$url  = isset( $b['attrs']['url'] ) ? $b['attrs']['url'] : '';
\t$host = parse_url( $url, PHP_URL_HOST );
\t$bad  = $host && in_array( $host, $retired, true );
\techo ( $bad ? 'STALE  ' : 'ok     ' ) . $url . "\\n";
}'''))),
    ]))

# ===========================================================================
# Markdown
# ===========================================================================
COMPONENTS.append(('markdown', 'Markdown',
    'Bidirectional converter between Markdown and WordPress block markup. Round-trips faithfully so you can keep Markdown files and a WP database in sync.',
    'wp-php-toolkit/markdown',
    [
        ('Markdown to blocks',
            '<p>Feed Markdown into <code>MarkdownConsumer</code>, get block markup back. The result is a <code>BlocksWithMetadata</code> object that holds both the rendered blocks and any frontmatter parsed from the document.</p>',
            ('quickstart.php', php('''use WordPress\\Markdown\\MarkdownConsumer;

$result = ( new MarkdownConsumer( "# Hello\\n\\nWelcome to **WordPress**." ) )->consume();
echo $result->get_block_markup();'''))),
        ('Round-trip: blocks back to Markdown',
            '<p>Pair <code>MarkdownProducer</code> with <code>MarkdownConsumer</code> to convert in either direction. Round-tripping is lossy for block attributes that have no Markdown representation (custom classes, alignment), so do not expect byte-perfect equality.</p>',
            ('roundtrip.php', php('''use WordPress\\Markdown\\MarkdownConsumer;
use WordPress\\Markdown\\MarkdownProducer;

$md       = "## Round trip\\n\\n- one\\n- two\\n- three\\n";
$blocks   = ( new MarkdownConsumer( $md ) )->consume();
$markdown = ( new MarkdownProducer( $blocks ) )->produce();

echo $markdown;'''))),
        ('Reading YAML frontmatter as post meta',
            '<p>Frontmatter keys come back as arrays so a single key can hold multiple values. Use <code>get_meta_value()</code> when you only want the first scalar.</p>',
            ('frontmatter.php', php('''use WordPress\\Markdown\\MarkdownConsumer;

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

echo 'Title: '   . $consumer->get_meta_value( 'post_title' )  . "\\n";
echo 'Status: '  . $consumer->get_meta_value( 'post_status' ) . "\\n";
print_r( $consumer->get_all_metadata() );'''))),
        ('Migrating an Obsidian or Hugo folder of Markdown',
            '<p>Walk a directory of <code>.md</code> files (Obsidian vault, Hugo <code>content/</code>, Jekyll <code>_posts</code>) and emit one block-markup record per file.</p>',
            ('migrate-folder.php', php('''use WordPress\\Markdown\\MarkdownConsumer;

@mkdir( '/tmp/vault', 0777, true );
file_put_contents( '/tmp/vault/welcome.md', "---\\ntitle: Welcome\\n---\\n\\nHello world." );
file_put_contents( '/tmp/vault/roadmap.md', "# Roadmap\\n\\n1. Ship\\n2. Iterate" );

foreach ( glob( '/tmp/vault/*.md' ) as $path ) {
\t$consumer = new MarkdownConsumer( file_get_contents( $path ) );
\t$consumer->consume();
\t$title = $consumer->get_meta_value( 'title' );
\tif ( ! $title ) $title = basename( $path, '.md' );
\techo "=== $title ($path) ===\\n";
\techo substr( $consumer->get_block_markup(), 0, 120 ) . "...\\n\\n";
}'''))),
        ('Counting blocks produced by a Markdown document',
            '<p>After conversion, the block markup is plain WordPress block markup, so <code>parse_blocks()</code> works on it directly. The standard way to introspect what the converter emitted before saving to the database.</p>',
            ('count-blocks.php', php('''use WordPress\\Markdown\\MarkdownConsumer;

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
foreach ( parse_blocks( $blocks ) as $block ) {
\tif ( ! $block['blockName'] ) continue;
\t$counts[ $block['blockName'] ] = ( isset( $counts[ $block['blockName'] ] ) ? $counts[ $block['blockName'] ] : 0 ) + 1;
}
print_r( $counts );'''))),
    ]))

# ===========================================================================
# XML
# ===========================================================================
COMPONENTS.append(('xml', 'XML',
    'A streaming, namespace-aware XML processor in pure PHP. Read and modify huge feeds, WXR exports, ePub manifests, and Office Open XML parts without ever loading the document into memory and without depending on <code>libxml2</code>.',
    'wp-php-toolkit/xml',
    [
        ('Why a streaming XML processor',
            '<p><code>SimpleXMLElement</code> and <code>DOMDocument</code> both need <code>libxml2</code> and both build a complete in-memory tree. <code>XMLProcessor</code> walks the document forward as a cursor, keeps modifications in a side buffer, and emits the full updated XML with <code>get_updated_xml()</code> only when you ask for it.</p>'
            '<p><strong>Footgun #1:</strong> namespaces are addressed by URI, never by prefix. <code>get_attribute( \'wp\', \'status\' )</code> always returns null; you want <code>get_attribute( \'http://wordpress.org/export/1.2/\', \'status\' )</code>.</p>'
            '<p><strong>Footgun #2:</strong> in streaming mode <code>next_tag()</code> can return false because input ran out, not because the document ended. Check <code>is_paused_at_incomplete_input()</code> before assuming you\'re done.</p>',
            None),
        ('Bump every price in a catalog',
            '<p>Find each <code>&lt;book&gt;</code>, read its price, write a new one, emit the updated document.</p>',
            ('bump-prices.php', php('''use WordPress\\XML\\XMLProcessor;

$xml = '<catalog>'
\t. '<book sku="A1" price="29.99"><title>PHP Internals</title></book>'
\t. '<book sku="A2" price="14.50"><title>WordPress at Scale</title></book>'
\t. '</catalog>';

$p = XMLProcessor::create_from_string( $xml );
while ( $p->next_tag( 'book' ) ) {
\t$old = (float) $p->get_attribute( '', 'price' );
\t$new = number_format( $old * 1.10, 2, '.', '' );
\t$p->set_attribute( '', 'price', $new );
}

echo $p->get_updated_xml();'''))),
        ('Read namespaced attributes from a WXR export',
            '<p>WordPress\'s WXR uses <code>wp:</code>, <code>dc:</code>, and <code>content:</code> prefixes. Always pass the URI, not the prefix; the processor handles whichever prefix the document actually uses.</p>',
            ('wxr-namespaces.php', php('''use WordPress\\XML\\XMLProcessor;

$wxr = '<?xml version="1.0"?>'
\t. '<rss xmlns:wp="http://wordpress.org/export/1.2/" xmlns:dc="http://purl.org/dc/elements/1.1/">'
\t. '<channel><item>'
\t. '<title>Hello World</title>'
\t. '<dc:creator>admin</dc:creator>'
\t. '<wp:post_id>42</wp:post_id>'
\t. '<wp:status>publish</wp:status>'
\t. '</item></channel></rss>';

$WP = 'http://wordpress.org/export/1.2/';
$DC = 'http://purl.org/dc/elements/1.1/';

$p = XMLProcessor::create_from_string( $wxr );
while ( $p->next_tag( 'item' ) ) {
\twhile ( $p->next_token() ) {
\t\tif ( $p->is_tag_closer() && 'item' === $p->get_tag_local_name() ) break;
\t\tif ( ! $p->is_tag_opener() ) continue;
\t\t$ns = $p->get_tag_namespace();
\t\t$local = $p->get_tag_local_name();
\t\t$prefix = ( $WP === $ns ) ? 'wp/' : ( ( $DC === $ns ) ? 'dc/' : '' );
\t\techo "{$prefix}{$local}: ";
\t\twhile ( $p->next_token() && '#text' !== $p->get_token_name() ) {}
\t\techo trim( $p->get_modifiable_text() ) . "\\n";
\t}
}'''))),
        ('Rewrite URLs across an entire WXR export',
            '<p>WXR holds tens of thousands of URLs in <code>&lt;link&gt;</code>, <code>&lt;guid&gt;</code>, and post content. Streaming the file lets you rewrite multi-hundred-megabyte exports without going OOM.</p>',
            ('rewrite-wxr-urls.php', php('''use WordPress\\XML\\XMLProcessor;

$wxr = '<?xml version="1.0"?><rss xmlns:wp="http://wordpress.org/export/1.2/"><channel>'
\t. '<wp:base_site_url>https://old.example.com</wp:base_site_url>'
\t. '<item><link>https://old.example.com/2024/post-1</link>'
\t. '<guid>https://old.example.com/?p=1</guid></item>'
\t. '</channel></rss>';

$from = 'https://old.example.com';
$to   = 'https://new.example.com';

$p = XMLProcessor::create_from_string( $wxr );
$rewritten = 0;

while ( $p->next_token() ) {
\tif ( '#text' !== $p->get_token_name() ) continue;
\t$text = $p->get_modifiable_text();
\tif ( false === strpos( $text, $from ) ) continue;
\t$p->set_modifiable_text( str_replace( $from, $to, $text ) );
\t$rewritten++;
}

echo "rewrote {$rewritten} text nodes\\n\\n";
echo $p->get_updated_xml();'''))),
        ('Parse OPML to extract feed URLs',
            '<p>OPML is the format Feedly and many readers use to import/export feed lists. Flat, attribute-heavy XML — exactly what a tag processor handles best.</p>',
            ('opml.php', php('''use WordPress\\XML\\XMLProcessor;

$opml = '<?xml version="1.0"?><opml version="2.0"><head><title>My Feeds</title></head>'
\t. '<body>'
\t. '<outline text="Tech"><outline text="Hacker News" type="rss" xmlUrl="https://news.ycombinator.com/rss"/>'
\t. '<outline text="LWN" type="rss" xmlUrl="https://lwn.net/headlines/rss"/></outline>'
\t. '<outline text="WordPress" type="rss" xmlUrl="https://wordpress.org/news/feed/"/>'
\t. '</body></opml>';

$p = XMLProcessor::create_from_string( $opml );
while ( $p->next_tag( 'outline' ) ) {
\t$url = $p->get_attribute( '', 'xmlUrl' );
\tif ( null === $url ) continue;
\techo $p->get_attribute( '', 'text' ) . "\\t" . $url . "\\n";
}'''))),
    ]))

# ===========================================================================
# Encoding
# ===========================================================================
COMPONENTS.append(('encoding', 'Encoding',
    'Pure-PHP UTF-8 validation and scrubbing. Detects malformed bytes, replaces them per the Unicode maximal-subpart algorithm, and works without <code>mbstring</code>.',
    'wp-php-toolkit/encoding',
    [
        ('Validating UTF-8 before storing it',
            '<p><code>wp_is_valid_utf8()</code> rejects overlong sequences, surrogate halves, and stray ISO-8859-1 bytes. Use it as a guard in front of any code path that assumes UTF-8 (database, JSON, XML).</p>',
            ('validate.php', php('''use function WordPress\\Encoding\\wp_is_valid_utf8;

var_dump( wp_is_valid_utf8( 'just a test' ) );
var_dump( wp_is_valid_utf8( "\\xE2\\x9C\\x8F" ) );
var_dump( wp_is_valid_utf8( "B\\xFCch" ) );
var_dump( wp_is_valid_utf8( "\\xC1\\xBF" ) );
var_dump( wp_is_valid_utf8( "\\xED\\xB0\\x80" ) );'''))),
        ('Scrubbing invalid bytes with U+FFFD',
            '<p>Replace each ill-formed sequence with the Unicode replacement character. Useful right before serializing to XML, JSON, or sending to an LLM that will choke on broken bytes.</p>',
            ('scrub.php', php('''use function WordPress\\Encoding\\wp_scrub_utf8;

$broken = "the byte \\xC0 should not be here.";
echo wp_scrub_utf8( $broken ) . "\\n";

echo wp_scrub_utf8( ".\\xE2\\x8C\\xE2\\x8C." ) . "\\n";'''))),
        ('Detecting noncharacters MySQL/utf8mb4 will reject',
            '<p>Code points like U+FFFE, U+FFFF, and the U+FDD0–U+FDEF block are valid Unicode but forbidden in XML and rejected by some databases. Check before inserting user-submitted content into a strict <code>utf8mb4</code> column.</p>',
            ('noncharacters.php', php('''use function WordPress\\Encoding\\wp_has_noncharacters;

var_dump( wp_has_noncharacters( 'normal text' ) );
var_dump( wp_has_noncharacters( "oops \\u{FFFE}" ) );
var_dump( wp_has_noncharacters( "hi \\u{FDD0} bye" ) );'''))),
        ('Three-way pipeline: validate, scrub, then check noncharacters',
            '<p>Real-world inputs are messy: an old WXR export, a CSV with mixed encodings, a paste from Word. Combination of validate + scrub + noncharacter-check covers the three classes of breakage that bite later.</p>',
            ('pipeline.php', php('''use function WordPress\\Encoding\\wp_is_valid_utf8;
use function WordPress\\Encoding\\wp_scrub_utf8;
use function WordPress\\Encoding\\wp_has_noncharacters;

$inputs = array(
\t'good'      => 'Café',
\t'latin1'    => "caf\\xE9",
\t'overlong'  => "x\\xC1\\xBFy",
\t'noncharac' => "hi \\u{FFFE} there",
);

foreach ( $inputs as $label => $bytes ) {
\t$valid    = wp_is_valid_utf8( $bytes );
\t$cleaned  = wp_scrub_utf8( $bytes );
\t$weird    = wp_has_noncharacters( $cleaned );
\techo sprintf( "%-10s valid=%s noncharacter=%s -> %s\\n", $label, $valid ? 'Y' : 'N', $weird ? 'Y' : 'N', $cleaned );
}'''))),
        ('Salvaging a legacy ISO-8859-1 column inside a UTF-8 corpus',
            '<p>Old WordPress databases sometimes mix encodings: most rows are UTF-8 but a few were stored as latin-1. Detect the bad rows with <code>wp_is_valid_utf8()</code> and only re-encode those.</p>',
            ('mixed-encoding.php', php('''use function WordPress\\Encoding\\wp_is_valid_utf8;
use function WordPress\\Encoding\\wp_scrub_utf8;

$rows = array(
\t1 => 'Plain ASCII',
\t2 => 'Café',
\t3 => "caf\\xE9",
\t4 => "weird \\xC0 byte",
);

foreach ( $rows as $id => $value ) {
\tif ( wp_is_valid_utf8( $value ) ) {
\t\techo "#$id ok: $value\\n";
\t\tcontinue;
\t}
\t$converted = @iconv( 'ISO-8859-1', 'UTF-8', $value );
\tif ( false !== $converted && wp_is_valid_utf8( $converted ) ) {
\t\techo "#$id recovered as latin1: $converted\\n";
\t} else {
\t\techo "#$id unrecoverable, scrubbing: " . wp_scrub_utf8( $value ) . "\\n";
\t}
}'''))),
    ]))

# ===========================================================================
# DataLiberation
# ===========================================================================
COMPONENTS.append(('dataliberation', 'DataLiberation',
    'Streaming WordPress import/export. WXR, SQL, block markup — without loading whole datasets into memory.',
    'wp-php-toolkit/data-liberation',
    [
        ('Write a WXR file in five lines',
            '<p>Stream a single post into a WXR document via <code>WXRWriter</code>. The writer holds no buffer beyond what is needed to close currently-open tags, so memory stays flat regardless of input size.</p>',
            ('wxr-quickstart.php', php('''use WordPress\\ByteStream\\MemoryPipe;
use WordPress\\DataLiberation\\EntityWriter\\WXRWriter;
use WordPress\\DataLiberation\\ImportEntity;

$pipe   = new MemoryPipe();
$writer = new WXRWriter( $pipe );
$writer->append_entity( new ImportEntity( 'post', array(
\t'post_title' => 'Hello',
\t'content'    => 'World.',
\t'post_id'    => '1',
\t'status'     => 'publish',
) ) );
$writer->finalize();
$writer->close_writing();
$pipe->close_writing();
echo $pipe->consume_all();'''))),
        ('Build a WXR programmatically from any source',
            '<p>The writer doesn\'t care where entities come from. Loop over rows from a CMS, a CSV, or a Notion API dump and emit posts plus their meta and comments.</p>',
            ('build-wxr.php', php('''use WordPress\\ByteStream\\MemoryPipe;
use WordPress\\DataLiberation\\EntityWriter\\WXRWriter;
use WordPress\\DataLiberation\\ImportEntity;

$rows = array(
\tarray( 'id' => 10, 'title' => 'About', 'body' => '<p>About us.</p>', 'tags' => array( 'company' ) ),
\tarray( 'id' => 11, 'title' => 'Blog',  'body' => '<p>Hello world.</p>', 'tags' => array( 'news', 'launch' ) ),
);

$pipe   = new MemoryPipe();
$writer = new WXRWriter( $pipe );

foreach ( $rows as $row ) {
\t$writer->append_entity( new ImportEntity( 'post', array(
\t\t'post_id'    => (string) $row['id'],
\t\t'post_title' => $row['title'],
\t\t'content'    => $row['body'],
\t\t'status'     => 'publish',
\t\t'post_type'  => 'post',
\t) ) );
\tforeach ( $row['tags'] as $i => $tag ) {
\t\t$writer->append_entity( new ImportEntity( 'term', array(
\t\t\t'term_id'  => (string) ( $row['id'] * 100 + $i ),
\t\t\t'taxonomy' => 'post_tag',
\t\t\t'slug'     => $tag,
\t\t\t'parent'   => '0',
\t\t) ) );
\t}
}

$writer->finalize();
$writer->close_writing();
$pipe->close_writing();

echo $pipe->consume_all();'''))),
        ('Read entities from a WXR file with constant memory',
            '<p><code>WXREntityReader</code> emits one entity at a time. A 10 GB WXR uses the same memory as a 10 KB one.</p>',
            ('wxr-read.php', php('''use WordPress\\DataLiberation\\EntityReader\\WXREntityReader;

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
\t$entity = $reader->get_entity();
\techo $entity->get_type() . ': ' . json_encode( $entity->get_data() ) . "\\n";
}'''))),
        ('Streaming transform: rewrite URLs while copying WXR',
            '<p>Wire reader to writer to rewrite a WXR file on the fly. This pattern is how you migrate a staging export to production: swap <code>staging.example.com</code> for <code>example.com</code> without ever loading the file into memory.</p>',
            ('rewrite-urls.php', php('''use WordPress\\ByteStream\\MemoryPipe;
use WordPress\\DataLiberation\\EntityReader\\WXREntityReader;
use WordPress\\DataLiberation\\EntityWriter\\WXRWriter;
use WordPress\\DataLiberation\\ImportEntity;

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
\t$entity = $reader->get_entity();
\t$data   = $entity->get_data();
\tforeach ( array( 'post_content', 'content', 'description' ) as $field ) {
\t\tif ( isset( $data[ $field ] ) ) {
\t\t\t$data[ $field ] = str_replace( 'staging.example.com', 'example.com', $data[ $field ] );
\t\t}
\t}
\tif ( 'post' === $entity->get_type() ) {
\t\t$data['content'] = isset( $data['post_content'] ) ? $data['post_content'] : ( isset( $data['content'] ) ? $data['content'] : '' );
\t}
\t$writer->append_entity( new ImportEntity( $entity->get_type(), $data ) );
}

$writer->finalize();
$writer->close_writing();
$out_pipe->close_writing();

echo $out_pipe->consume_all();'''))),
        ('Render Markdown into a WXR import in one pipeline',
            '<p>Compose <code>MarkdownConsumer</code> with <code>WXRWriter</code> to publish a folder of Markdown directly as a WordPress import file.</p>',
            ('md-to-wxr.php', php('''use WordPress\\ByteStream\\MemoryPipe;
use WordPress\\DataLiberation\\EntityWriter\\WXRWriter;
use WordPress\\DataLiberation\\ImportEntity;
use WordPress\\Markdown\\MarkdownConsumer;

@mkdir( '/tmp/md-src', 0777, true );
file_put_contents( '/tmp/md-src/hello.md',  "---\\ntitle: Hello\\n---\\n\\n# Hello\\n\\nFirst post." );
file_put_contents( '/tmp/md-src/second.md', "---\\ntitle: Second\\n---\\n\\nMore text **here**." );

$pipe   = new MemoryPipe();
$writer = new WXRWriter( $pipe );

$id = 1;
foreach ( glob( '/tmp/md-src/*.md' ) as $path ) {
\t$consumer = new MarkdownConsumer( file_get_contents( $path ) );
\t$consumer->consume();
\t$writer->append_entity( new ImportEntity( 'post', array(
\t\t'post_id'    => (string) $id++,
\t\t'post_title' => $consumer->get_meta_value( 'title' ) ?: basename( $path, '.md' ),
\t\t'content'    => $consumer->get_block_markup(),
\t\t'status'     => 'publish',
\t\t'post_type'  => 'post',
\t\t'post_name'  => basename( $path, '.md' ),
\t) ) );
}

$writer->finalize();
$writer->close_writing();
$pipe->close_writing();

echo $pipe->consume_all();'''))),
    ]))

# ===========================================================================
# Git
# ===========================================================================
COMPONENTS.append(('git', 'Git',
    'A pure-PHP Git client and server. Commits, branches, diffs, HTTP push/pull — all without shelling out to <code>git</code>.',
    'wp-php-toolkit/git',
    [
        ('Commit files into an in-memory repo',
            '<p>The simplest possible repository: an <code>InMemoryFilesystem</code> as object storage and one <code>commit()</code> call. Reach for this in tests, in WP-CLI snapshots, or any place you want versioning without touching disk.</p>',
            ('commit-in-memory.php', php('''use WordPress\\Filesystem\\InMemoryFilesystem;
use WordPress\\Git\\GitRepository;

$repo = new GitRepository( InMemoryFilesystem::create() );

$oid = $repo->commit( array(
\t'updates' => array(
\t\t'README.md'           => "# My Project\\n",
\t\t'src/hello-world.php' => '<?php echo "Hello!";',
\t),
) );

echo "commit: {$oid}\\n";
echo "HEAD:   " . $repo->get_branch_tip( 'HEAD' ) . "\\n";
echo "README: " . $repo->read_object_by_path( '/README.md' )->consume_all();'''))),
        ('Walk the commit history',
            '<p>Follow the parent chain from <code>HEAD</code> backwards. Building block for a WP-CLI "post revisions" log or a "what changed since release X" report.</p>',
            ('walk-history.php', php('''use WordPress\\Filesystem\\InMemoryFilesystem;
use WordPress\\Git\\GitRepository;
use WordPress\\Git\\Model\\Commit;

$repo = new GitRepository( InMemoryFilesystem::create() );
foreach ( array( 'add intro', 'fix typo', 'expand examples' ) as $i => $msg ) {
\t$repo->commit( array(
\t\t'updates' => array( 'post.md' => "# Draft {$i}" ),
\t\t'commit'  => array( 'message' => $msg ),
\t) );
}

$oid = $repo->get_branch_tip( 'HEAD' );
while ( ! Commit::is_null_hash( $oid ) ) {
\t$c = $repo->read_object( $oid )->as_commit();
\techo substr( $c->hash, 0, 7 ) . '  ' . trim( $c->message ) . "\\n";
\t$oid = $c->get_first_parent_hash();
\tif ( ! $oid || ! $repo->has_object( $oid ) ) break;
}'''))),
        ('Treat a repository like a filesystem',
            '<p><code>GitFilesystem</code> wraps a repository in the standard <code>Filesystem</code> interface. Every <code>put_contents()</code> auto-commits.</p>',
            ('git-filesystem.php', php('''use WordPress\\Filesystem\\InMemoryFilesystem;
use WordPress\\Git\\GitFilesystem;
use WordPress\\Git\\GitRepository;

$repo = new GitRepository( InMemoryFilesystem::create() );
$fs   = GitFilesystem::create( $repo );

$fs->put_contents( '/posts/hello.md', "# Hello\\nFirst draft." );
$fs->put_contents( '/posts/about.md', "# About\\nWho we are." );
$fs->put_contents( '/posts/hello.md', "# Hello\\nSecond draft." );

echo "tree:\\n";
foreach ( $fs->ls( '/posts' ) as $name ) {
\techo "  /posts/{$name}\\n";
}
echo "\\nhello.md now:\\n" . $fs->get_contents( '/posts/hello.md' ) . "\\n";'''))),
        ('Branch, edit, and switch back',
            '<p>Create a feature branch off the current commit, change files, flip <code>HEAD</code> back. Useful for experimental edits in collaborative tools.</p>',
            ('branches.php', php('''use WordPress\\Filesystem\\InMemoryFilesystem;
use WordPress\\Git\\GitRepository;

$repo = new GitRepository( InMemoryFilesystem::create() );
$base = $repo->commit( array(
\t'updates' => array( 'config.json' => '{"flag":false}' ),
\t'commit'  => array( 'message' => 'baseline' ),
) );

$repo->create_branch( 'refs/heads/experiment', $base );
$repo->checkout( 'refs/heads/experiment' );
$repo->commit( array(
\t'updates' => array( 'config.json' => '{"flag":true}' ),
\t'commit'  => array( 'message' => 'flip the flag' ),
) );

echo "on experiment: " . $repo->read_object_by_path( '/config.json' )->consume_all() . "\\n";

$repo->checkout( 'refs/heads/trunk' );
echo "on trunk:      " . $repo->read_object_by_path( '/config.json' )->consume_all() . "\\n";'''))),
        ('Three-way merge two branches',
            '<p>The classic Git workflow: branch off, edit on each side, merge. <code>$repo-&gt;merge()</code> finds the common ancestor, three-way-merges every file, and creates a merge commit.</p>',
            ('merge-branches.php', php('''use WordPress\\Filesystem\\InMemoryFilesystem;
use WordPress\\Git\\GitRepository;

$repo = new GitRepository( InMemoryFilesystem::create() );
$base = $repo->commit( array( 'updates' => array(
\t'todo.txt' => "buy milk\\nwalk dog\\nread book\\n",
) ) );

$repo->commit( array( 'updates' => array(
\t'todo.txt' => "buy oat milk\\nwalk dog\\nread book\\n",
) ) );

$repo->create_branch( 'refs/heads/feature', $base );
$repo->checkout( 'refs/heads/feature' );
$repo->commit( array( 'updates' => array(
\t'todo.txt' => "buy milk\\nwalk dog\\nread book\\nwrite blog post\\n",
) ) );

$repo->checkout( 'refs/heads/trunk' );
$result = $repo->merge( 'refs/heads/feature' );

echo "merge head: {$result['new_head']}\\n";
echo "conflicts:  " . ( $result['conflicts'] ? implode( ',', $result['conflicts'] ) : 'none' ) . "\\n";
echo "result:\\n" . $repo->read_object_by_path( '/todo.txt' )->consume_all();'''))),
        ('Snapshot WordPress options into a repo',
            '<p>Serialize a chunk of WP state (options, post meta, a theme config) on every save and commit it. You get free history, diffs between snapshots, and a "rollback to last week" button.</p>',
            ('options-snapshot.php', php('''use WordPress\\Filesystem\\InMemoryFilesystem;
use WordPress\\Git\\GitRepository;

$repo = new GitRepository( InMemoryFilesystem::create() );

$snapshots = array(
\tarray( 'blogname' => 'My Site',  'posts_per_page' => 10, 'timezone_string' => 'UTC' ),
\tarray( 'blogname' => 'My Site',  'posts_per_page' => 20, 'timezone_string' => 'UTC' ),
\tarray( 'blogname' => 'New Name', 'posts_per_page' => 20, 'timezone_string' => 'Europe/Warsaw' ),
);

foreach ( $snapshots as $i => $options ) {
\t$repo->commit( array(
\t\t'updates' => array( 'options.json' => json_encode( $options, JSON_PRETTY_PRINT ) ),
\t\t'commit'  => array( 'message' => "snapshot #{$i}" ),
\t) );
}

$head    = $repo->get_branch_tip( 'HEAD' );
$parent  = $repo->read_object( $head )->as_commit()->get_first_parent_hash();
$diff    = $repo->diff_commits( $head, $parent );

echo "Files changed in last snapshot:\\n";
foreach ( $diff as $name => $entry ) {
\techo "  {$name}\\n";
}'''))),
    ]))

# ===========================================================================
# Merge
# ===========================================================================
COMPONENTS.append(('merge', 'Merge',
    'Three-way merge and diff. Pluggable differ + merger + optional validator.',
    'wp-php-toolkit/merge',
    [
        ('Diff two strings line by line',
            '<p>Feed two strings to <code>LineDiffer</code> and inspect the operations. Every <code>get_changes()</code> entry is a <code>[op, text]</code> pair.</p>',
            ('line-diff.php', php('''use WordPress\\Merge\\Diff\\Diff;
use WordPress\\Merge\\Diff\\LineDiffer;

$diff = ( new LineDiffer() )->diff(
\t"alpha\\nbeta\\ngamma\\n",
\t"alpha\\nBETA\\ngamma\\ndelta\\n"
);

$labels = array( Diff::DIFF_EQUAL => '=', Diff::DIFF_DELETE => '-', Diff::DIFF_INSERT => '+' );
foreach ( $diff->get_changes() as $change ) {
\techo $labels[ $change[0] ] . ' ' . rtrim( $change[1] ) . "\\n";
}'''))),
        ('Render a unified patch',
            '<p><code>format_as_git_patch()</code> produces output that mirrors <code>git diff</code>, including hunk headers — handy for emails, CI annotations, or a "what changed?" panel.</p>',
            ('git-patch.php', php('''use WordPress\\Merge\\Diff\\LineDiffer;

$old = "title: Hello\\nauthor: Alice\\nstatus: draft\\n";
$new = "title: Hello, world\\nauthor: Alice\\nstatus: published\\ntags: greeting\\n";

$diff = ( new LineDiffer() )->diff( $old, $new );
echo $diff->format_as_git_patch( array(
\t'a_source' => 'a/post.yml',
\t'b_source' => 'b/post.yml',
) );'''))),
        ('Three-way merge with no conflicts',
            '<p>The classic case: each branch changes a different region. Pass the common ancestor plus both edits to <code>MergeStrategy::merge()</code> and read the merged result.</p>',
            ('three-way.php', php('''use WordPress\\Merge\\Diff\\LineDiffer;
use WordPress\\Merge\\Merge\\LineMerger;
use WordPress\\Merge\\MergeStrategy;

$strategy = new MergeStrategy( new LineDiffer(), new LineMerger() );

$result = $strategy->merge(
\t"intro\\nbody\\noutro\\n",
\t"intro updated\\nbody\\noutro\\n",
\t"intro\\nbody\\noutro\\nappendix\\n"
);

echo $result->has_conflicts() ? "conflicts!\\n" : "clean merge:\\n";
echo $result->get_merged_content();'''))),
        ('Inspect and surface conflicts',
            '<p>When both sides edit the same region, the merger produces a <code>MergeConflict</code>. The merged content carries Git-style markers, but the structured <code>get_conflicts()</code> output is what you want for a UI that lets the user pick a side.</p>',
            ('conflicts.php', php('''use WordPress\\Merge\\Diff\\LineDiffer;
use WordPress\\Merge\\Merge\\LineMerger;
use WordPress\\Merge\\MergeStrategy;

$strategy = new MergeStrategy( new LineDiffer(), new LineMerger() );
$result = $strategy->merge(
\t"line 1\\nline 2\\n",
\t"line 1\\nline 2 from Alice\\n",
\t"line 1\\nline 2 from Bob\\n"
);

if ( $result->has_conflicts() ) {
\tforeach ( $result->get_conflicts() as $c ) {
\t\techo "ours:   " . trim( $c->ours ) . "\\n";
\t\techo "theirs: " . trim( $c->theirs ) . "\\n";
\t}
}
echo "\\n--- merged content with markers ---\\n";
echo $result->get_merged_content();'''))),
        ('Sync a Markdown folder against an edited DB copy',
            '<p>A real-world scenario: posts live both in a Git-tracked Markdown folder and in WordPress, and someone edits each. Three-way-merge each post against its common ancestor.</p>',
            ('sync-folder-vs-db.php', php('''use WordPress\\Merge\\Diff\\LineDiffer;
use WordPress\\Merge\\Merge\\LineMerger;
use WordPress\\Merge\\MergeStrategy;

$strategy = new MergeStrategy( new LineDiffer(), new LineMerger() );

$posts = array(
\t'hello.md' => array(
\t\t'base' => "# Hello\\nDraft body.\\n",
\t\t'disk' => "# Hello\\nDraft body, expanded on disk.\\n",
\t\t'db'   => "# Hello\\nDraft body.\\nNew section from the editor.\\n",
\t),
\t'about.md' => array(
\t\t'base' => "# About\\nWho we are.\\n",
\t\t'disk' => "# About\\nWho *they* are.\\n",
\t\t'db'   => "# About\\nWho we really are.\\n",
\t),
);

foreach ( $posts as $name => $sides ) {
\t$result = $strategy->merge( $sides['base'], $sides['disk'], $sides['db'] );
\techo "=== {$name} ===\\n";
\techo $result->has_conflicts() ? "(conflict — needs review)\\n" : "(auto-merged)\\n";
\techo $result->get_merged_content() . "\\n";
}'''))),
    ]))

# ===========================================================================
# HttpClient
# ===========================================================================
COMPONENTS.append(('httpclient', 'HttpClient',
    'Async HTTP client without <code>curl</code> required. Uses sockets when curl is missing, supports concurrent requests and streaming responses.',
    'wp-php-toolkit/http-client',
    [
        ('Note',
            '<p class="callout"><strong>Network access in the demo runtime.</strong> Snippets execute inside a sandboxed Playground; outbound HTTP requires the CORS proxy. The examples below show the API; real network calls may not complete in this environment.</p>',
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
        ('Inspect headers without reading the body',
            '<p>Call <code>await_response()</code> to get the <code>Response</code> as soon as headers arrive. Useful for HEAD-style metadata checks, content-type sniffing, or deciding whether to keep reading.</p>',
            ('head-metadata.php', php('''use WordPress\\HttpClient\\Client;
use WordPress\\HttpClient\\Request;

$client  = new Client();
$request = new Request( 'https://wordpress.org/latest.zip', array(
\t'method' => 'HEAD',
) );

$stream   = $client->fetch( $request );
$response = $stream->await_response();

echo "Status: " . $response->status_code . " " . $response->get_reason_phrase() . "\\n";
echo "Type:   " . $response->get_header( 'content-type' ) . "\\n";
echo "Size:   " . $response->get_header( 'content-length' ) . " bytes\\n";'''))),
        ('POST JSON with a request body',
            '<p>Stream a JSON body up to the server using <code>MemoryPipe</code>. Same pattern works for any payload by switching the <code>content-type</code> header.</p>',
            ('post-json.php', php('''use WordPress\\HttpClient\\Client;
use WordPress\\HttpClient\\Request;
use WordPress\\ByteStream\\MemoryPipe;

$payload = json_encode( array( 'title' => 'Hello', 'tags' => array( 'http', 'php' ) ) );

$client  = new Client();
$request = new Request( 'https://httpbin.org/post', array(
\t'method'      => 'POST',
\t'headers'     => array(
\t\t'content-type'   => 'application/json',
\t\t'content-length' => (string) strlen( $payload ),
\t),
\t'body_stream' => new MemoryPipe( $payload ),
) );

$response = $client->fetch( $request )->json();
echo "Server saw body: " . $response['data'] . "\\n";'''))),
        ('Parallel fan-out: fetch many URLs at once',
            '<p>Enqueue a batch of requests and react to events as they fire. The client multiplexes them — total wall time is roughly the slowest request, not the sum.</p>',
            ('fan-out.php', php('''use WordPress\\HttpClient\\Client;
use WordPress\\HttpClient\\Request;

$urls = array(
\t'https://wordpress.org/',
\t'https://make.wordpress.org/',
\t'https://developer.wordpress.org/',
);

$client = new Client();
$client->enqueue( array_map( function ( $url ) {
\treturn new Request( $url, array( 'method' => 'HEAD' ) );
}, $urls ) );

$results = array();
while ( $client->await_next_event() ) {
\t$request = $client->get_request();
\tif ( Client::EVENT_GOT_HEADERS === $client->get_event() ) {
\t\t$results[ $request->url ] = $request->response->status_code;
\t} elseif ( Client::EVENT_FAILED === $client->get_event() ) {
\t\t$results[ $request->url ] = 'ERR ' . $request->error->message;
\t}
}

foreach ( $results as $url => $status ) {
\tprintf( "%-40s %s\\n", $url, $status );
}'''))),
        ('Stream a download to disk without OOM',
            '<p>Process the body chunk-by-chunk via the event loop. Memory stays flat regardless of file size.</p>',
            ('stream-to-disk.php', php('''use WordPress\\HttpClient\\Client;
use WordPress\\HttpClient\\Request;

$dest   = sys_get_temp_dir() . '/wp-readme.html';
$client = new Client();
$client->enqueue( array( new Request( 'https://wordpress.org/' ) ) );

$bytes = 0;
@unlink( $dest );

while ( $client->await_next_event() ) {
\tswitch ( $client->get_event() ) {
\t\tcase Client::EVENT_BODY_CHUNK_AVAILABLE:
\t\t\t$chunk  = $client->get_response_body_chunk();
\t\t\t$bytes += strlen( $chunk );
\t\t\tfile_put_contents( $dest, $chunk, FILE_APPEND );
\t\t\tbreak;
\t\tcase Client::EVENT_FINISHED:
\t\t\techo "Wrote {$bytes} bytes to {$dest}\\n";
\t\t\tbreak;
\t}
}

echo "Peak memory: " . round( memory_get_peak_usage( true ) / 1024 / 1024, 2 ) . " MB\\n";'''))),
    ]))

# ===========================================================================
# HttpServer
# ===========================================================================
COMPONENTS.append(('httpserver', 'HttpServer',
    'A minimal blocking TCP HTTP server in pure PHP. For CLI tools and tests, not for production traffic.',
    'wp-php-toolkit/http-server',
    [
        ('Hello world on port 8080',
            '<p class="callout"><strong>Run on your machine:</strong> the Playground sandbox does not allow processes to bind listening TCP ports. Save this snippet locally and run <code>php hello-server.php</code>.</p>',
            ('hello-server.php', '''<?php
require __DIR__ . '/vendor/autoload.php';

use WordPress\\HttpServer\\TcpServer;
use WordPress\\HttpServer\\IncomingRequest;
use WordPress\\HttpServer\\Response\\ResponseWriteStream;

$server = new TcpServer( '127.0.0.1', 8080 );

$server->set_handler( function ( IncomingRequest $request, ResponseWriteStream $response ) {
\t$response->send_http_code( 200 );
\t$response->send_header( 'Content-Type', 'text/plain' );
\t$response->append_bytes( "Hello from " . $request->method . " " . $request->url . "\\n" );
} );

$server->serve( function ( $host, $port ) {
\techo "Listening on http://{$host}:{$port}\\n";
} );''')),
        ('A tiny JSON router',
            '<p class="callout"><strong>Run on your machine:</strong> needs a listening port. Once running, try <code>curl localhost:8080/api/status</code>.</p>'
            '<p>Build a CLI tool with a web UI by switching on the parsed path and method.</p>',
            ('mini-router.php', '''<?php
require __DIR__ . '/vendor/autoload.php';

use WordPress\\HttpServer\\TcpServer;
use WordPress\\HttpServer\\IncomingRequest;
use WordPress\\HttpServer\\Response\\ResponseWriteStream;

$server = new TcpServer( '127.0.0.1', 8080 );

$server->set_handler( function ( IncomingRequest $request, ResponseWriteStream $response ) {
\t$path = $request->get_parsed_url()->pathname;

\tif ( '/api/status' === $path ) {
\t\t$response->send_http_code( 200 );
\t\t$response->send_header( 'Content-Type', 'application/json' );
\t\t$response->append_bytes( json_encode( array(
\t\t\t'ok'     => true,
\t\t\t'pid'    => getmypid(),
\t\t\t'memory' => memory_get_usage( true ),
\t\t) ) );
\t\treturn;
\t}

\tif ( '/api/echo' === $path && 'POST' === $request->method ) {
\t\t$body = '';
\t\twhile ( ! $request->body_stream->reached_end_of_data() ) {
\t\t\t$n = $request->body_stream->pull( 4096 );
\t\t\tif ( $n > 0 ) $body .= $request->body_stream->consume( $n );
\t\t}
\t\t$response->send_http_code( 200 );
\t\t$response->send_header( 'Content-Type', 'text/plain' );
\t\t$response->append_bytes( $body );
\t\treturn;
\t}

\t$response->send_http_code( 404 );
\t$response->append_bytes( "Not found\\n" );
} );

$server->serve();''')),
        ('Buffered response with auto Content-Length',
            '<p>Use <code>BufferingResponseWriter</code> when you want the framework to compute <code>Content-Length</code> for you, or when the runtime is CGI-shaped and expects the full body up front. This one runs anywhere — no socket required.</p>',
            ('buffered-writer.php', php('''use WordPress\\HttpServer\\Response\\BufferingResponseWriter;

$writer = new BufferingResponseWriter();
$writer->send_http_code( 200 );
$writer->send_header( 'Content-Type', 'text/html' );
$writer->append_bytes( '<!doctype html><title>Hi</title><h1>Hello</h1>' );
$writer->append_bytes( '<p>Generated at ' . date( 'c' ) . '</p>' );
$writer->close_writing();

echo "Captured response:\\n\\n";
echo $writer->get_buffer();'''))),
    ]))

# ===========================================================================
# CORSProxy
# ===========================================================================
COMPONENTS.append(('corsproxy', 'CORSProxy',
    'A small PHP CORS proxy intended for browser-side code that needs to reach servers without CORS headers.',
    'wp-php-toolkit/corsproxy',
    [
        ('Run the proxy locally',
            '<p class="callout"><strong>Run on your machine:</strong> the proxy needs to listen on a port. Start PHP\'s built-in server and request any HTTPS URL through it.</p>'
            '<pre><code>PLAYGROUND_CORS_PROXY_DISABLE_RATE_LIMIT=1 \\\n  php -S 127.0.0.1:5263 vendor/wp-php-toolkit/corsproxy/cors-proxy.php\n\n# In another terminal:\ncurl -s "http://127.0.0.1:5263/cors-proxy.php/https://api.github.com/repos/WordPress/php-toolkit" | head\n</code></pre>',
            None),
        ('Production rate limiting',
            '<p>Drop a <code>cors-proxy-config.php</code> next to <code>cors-proxy.php</code>. The proxy refuses to boot without one — that is the point.</p>'
            '<p>This example uses a per-IP token bucket stored on disk. Replace with Redis / memcached for multi-host deployments.</p>',
            ('cors-proxy-config.php', '''<?php
// cors-proxy-config.php — placed next to cors-proxy.php.

function playground_cors_proxy_maybe_rate_limit() {
\t$ip      = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
\t$bucket  = sys_get_temp_dir() . '/cors-rl-' . md5( $ip );
\t$now     = time();
\t$window  = 60;
\t$max_req = 30;

\t$hits = array();
\tif ( file_exists( $bucket ) ) {
\t\t$hits = json_decode( file_get_contents( $bucket ), true );
\t\tif ( ! is_array( $hits ) ) $hits = array();
\t}
\t$hits = array_filter( $hits, function ( $t ) use ( $now, $window ) {
\t\treturn $t > $now - $window;
\t} );

\tif ( count( $hits ) >= $max_req ) {
\t\theader( 'Retry-After: ' . $window );
\t\thttp_response_code( 429 );
\t\techo 'Rate limit exceeded';
\t\texit;
\t}

\t$hits[] = $now;
\tfile_put_contents( $bucket, json_encode( array_values( $hits ) ) );
}

echo "Config loaded — rate limiter armed.\\n";''')),
        ('Allowlist upstream hosts',
            '<p>Out of the box the proxy will fetch any public URL. Most real deployments want a fixed list of upstreams — GitHub, Packagist, wp.org.</p>',
            ('allowlist-config.php', '''<?php
function playground_cors_proxy_maybe_rate_limit() {
\t$allow = array(
\t\t'api.github.com',
\t\t'raw.githubusercontent.com',
\t\t'codeload.github.com',
\t\t'repo.packagist.org',
\t\t'downloads.wordpress.org',
\t\t'api.wordpress.org',
\t);

\t$target = isset( $_SERVER['PATH_INFO'] ) ? $_SERVER['PATH_INFO'] : ( '/' . ( isset( $_SERVER['QUERY_STRING'] ) ? $_SERVER['QUERY_STRING'] : '' ) );
\t$target = ltrim( $target, '/' );
\t$host   = parse_url( $target, PHP_URL_HOST );

\tif ( ! $host || ! in_array( strtolower( $host ), $allow, true ) ) {
\t\thttp_response_code( 403 );
\t\theader( 'Content-Type: text/plain' );
\t\techo "Upstream not allowed: " . ( $host ? $host : '(none)' );
\t\texit;
\t}
}

echo "Allowlist config active.\\n";''')),
        ('Browser-side fetch through the proxy',
            '<p>Once deployed, the client side is just <code>fetch()</code> with the proxy URL. Drop this into any HTML page.</p>'
            '<pre><code>const PROXY = "https://cors.example.com/cors-proxy.php";\n\nasync function viaProxy(url, init = {}) {\n  const res = await fetch(`${PROXY}/${url}`, {\n    ...init,\n    headers: {\n      ...(init.headers || {}),\n      "X-Cors-Proxy-Allowed-Request-Headers": "Authorization",\n    },\n  });\n  if (!res.ok) throw new Error(`Proxy returned ${res.status}`);\n  return res;\n}\n\nconst repo = await viaProxy("https://api.github.com/repos/WordPress/php-toolkit").then(r =&gt; r.json());\nconsole.log(repo.full_name, repo.stargazers_count);\n</code></pre>',
            None),
        ('Deploy behind nginx',
            '<p>The proxy is a single PHP script — any SAPI works. nginx + php-fpm is a common production setup. <code>PATH_INFO</code> is what the proxy reads to learn the target URL.</p>'
            '<pre><code>server {\n  listen 443 ssl http2;\n  server_name cors.example.com;\n\n  root /var/www/cors-proxy;\n  index cors-proxy.php;\n\n  location ~ ^/cors-proxy\\.php(/.*)?$ {\n    fastcgi_pass unix:/run/php/php8.1-fpm.sock;\n    fastcgi_split_path_info ^(.+\\.php)(/.*)$;\n    fastcgi_param SCRIPT_FILENAME $document_root/cors-proxy.php;\n    fastcgi_param PATH_INFO $fastcgi_path_info;\n    include fastcgi_params;\n  }\n}\n</code></pre>',
            None),
    ]))

# ===========================================================================
# CLI
# ===========================================================================
COMPONENTS.append(('cli', 'CLI',
    'POSIX-style argument parser. Long options, short bundles, inline values, positional args — one static call.',
    'wp-php-toolkit/cli',
    [
        ('Why this exists',
            '<p>Real CLI tools in PHP usually mean either pulling in <code>symfony/console</code> (and 30+ transitive packages) or hand-rolling argv parsing that breaks the first time someone writes <code>-vvv</code> or <code>--port=8080</code>. The toolkit\'s <code>CLI</code> class is one static method, no dependencies, and handles the POSIX shapes you actually see.</p>',
            None),
        ('Parse a single flag',
            '<p>The smallest useful invocation: one boolean flag, one positional. Each option is a four-tuple of <code>[ short, has_value, default, description ]</code>.</p>',
            ('parse-flag.php', php('''use WordPress\\CLI\\CLI;

$option_defs = array(
\t'verbose' => array( 'v', false, false, 'Enable verbose output' ),
);

list( $positionals, $options ) = CLI::parse_command_args_and_options(
\tarray( '-v', 'input.txt' ),
\t$option_defs
);

var_dump( $options['verbose'] );
var_dump( $positionals );'''))),
        ('Mix values, flags, and bundles',
            '<p>Values can be passed as <code>--port 8080</code>, <code>--port=8080</code>, <code>-p 8080</code>, or <code>-p=8080</code>. Boolean shorts can be bundled (<code>-afv</code>).</p>',
            ('mix-shapes.php', php('''use WordPress\\CLI\\CLI;

$option_defs = array(
\t'all'     => array( 'a', false, false, 'Process everything' ),
\t'force'   => array( 'f', false, false, 'Overwrite existing files' ),
\t'verbose' => array( 'v', false, false, 'Verbose output' ),
\t'output'  => array( 'o', true,  null,  'Output path' ),
\t'port'    => array( 'p', true,  '3000', 'Server port' ),
);

$argv = array( '-afv', '--port=8080', '-o', '/tmp/result.txt', 'input.json' );
list( $positionals, $options ) = CLI::parse_command_args_and_options( $argv, $option_defs );

print_r( array( 'positionals' => $positionals, 'options' => $options ) );'''))),
        ('Validate required options',
            '<p>The parser fills in defaults but never enforces "required". Check for <code>null</code> after parsing — full control over the error message.</p>',
            ('require-options.php', php('''use WordPress\\CLI\\CLI;

$option_defs = array(
\t'site-url'  => array( 'u', true, null, 'Public site URL (required)' ),
\t'site-path' => array( null, true, null, 'Target directory (required)' ),
);

$argv = array( '--site-url', 'https://mysite.test' );

try {
\tlist( , $options ) = CLI::parse_command_args_and_options( $argv, $option_defs );
\tforeach ( array( 'site-url', 'site-path' ) as $name ) {
\t\tif ( null === $options[ $name ] ) {
\t\t\tthrow new RuntimeException( "Missing required option --{$name}" );
\t\t}
\t}
\techo "All good.\\n";
} catch ( Exception $e ) {
\techo "error: " . $e->getMessage() . "\\n";
}'''))),
        ('Generate --help from definitions',
            '<p>Because each option carries its own description, you can render help text by walking the same definitions you parse with. No second source of truth.</p>',
            ('help-text.php', php('''use WordPress\\CLI\\CLI;

$option_defs = array(
\t'output'  => array( 'o', true,  null,  'Write result to FILE' ),
\t'force'   => array( 'f', false, false, 'Overwrite existing files' ),
\t'verbose' => array( 'v', false, false, 'Verbose output' ),
\t'help'    => array( 'h', false, false, 'Show this help and exit' ),
);

function render_help( array $defs ) {
\techo "Usage: mytool [options] <input>\\n\\nOptions:\\n";
\tforeach ( $defs as $long => $def ) {
\t\tlist( $short, $has_value, $default, $desc ) = $def;
\t\t$flag = ( $short ? "-{$short}, " : '    ' ) . "--{$long}";
\t\tif ( $has_value ) $flag .= '=VALUE';
\t\techo sprintf( "  %-28s %s\\n", $flag, $desc );
\t}
}

list( , $options ) = CLI::parse_command_args_and_options( array( '-h' ), $option_defs );
if ( $options['help'] ) render_help( $option_defs );'''))),
        ('Git-style subcommands',
            '<p>To build a tool with subcommands like <code>mytool deploy</code>, peel the first positional off <code>argv</code>, dispatch, and parse the rest with a per-command option set.</p>',
            ('subcommands.php', php('''use WordPress\\CLI\\CLI;

$commands = array(
\t'deploy' => array(
\t\t'env'     => array( 'e', true, 'staging', 'Target environment' ),
\t\t'dry-run' => array( 'n', false, false, 'Preview without applying' ),
\t),
\t'rollback' => array(
\t\t'to' => array( 't', true, null, 'Revision to roll back to' ),
\t),
);

function run( array $argv, array $commands ) {
\tif ( empty( $argv ) ) {
\t\techo "Usage: mytool <command> [options]\\nCommands: " . implode( ', ', array_keys( $commands ) ) . "\\n";
\t\treturn;
\t}
\t$command = array_shift( $argv );
\tif ( ! isset( $commands[ $command ] ) ) {
\t\techo "Unknown command: {$command}\\n";
\t\treturn;
\t}
\tlist( $positionals, $options ) = CLI::parse_command_args_and_options( $argv, $commands[ $command ] );
\techo "command={$command}\\n";
\techo "options: " . json_encode( $options ) . "\\n";
\techo "positionals: " . json_encode( $positionals ) . "\\n";
}

run( array( 'deploy', '--env=production', '-n', 'web-01', 'web-02' ), $commands );
echo "---\\n";
run( array( 'rollback', '-t', 'abc123' ), $commands );'''))),
    ]))

# ===========================================================================
# Polyfill
# ===========================================================================
COMPONENTS.append(('polyfill', 'Polyfill',
    'PHP 8 string functions on PHP 7.2+, WordPress hook stubs, and translation/escaping passthroughs so toolkit code runs without WordPress.',
    'wp-php-toolkit/polyfill',
    [
        ('Why this exists',
            '<p>A lot of WordPress-adjacent code wants to call <code>esc_html()</code>, <code>__()</code>, or <code>apply_filters()</code> without booting WordPress. The polyfill component provides minimal but real implementations so that code runs unchanged outside WordPress, and stays out of the way when WordPress is loaded (every function uses <code>function_exists()</code> guards).</p>',
            None),
        ('PHP 8 string functions on PHP 7.2',
            '<p>The polyfills define <code>str_contains</code>, <code>str_starts_with</code>, <code>str_ends_with</code>, and <code>array_key_first</code> only when missing.</p>',
            ('php8-strings.php', php('''var_dump( str_starts_with( '/var/www/html', '/var' ) );
var_dump( str_ends_with( 'image.png', '.png' ) );
var_dump( str_contains( 'WordPress Toolkit', 'Toolkit' ) );

$first_key = array_key_first( array( 'alpha' => 1, 'beta' => 2 ) );
echo "first key: {$first_key}\\n";'''))),
        ('Escaping and translation stubs',
            '<p>Pass-through implementations let you write code that looks WordPressy and runs anywhere.</p>',
            ('wp-stubs.php', php('''echo __( 'Hello, world' ) . "\\n";
echo esc_html( '<script>alert("xss")</script>' ) . "\\n";
echo esc_attr( 'a "quoted" value' ) . "\\n";
echo esc_url( 'https://example.com/?a=1&b=2' ) . "\\n";'''))),
        ('A simple filter chain',
            '<p>The hook system is a real implementation of the WordPress filter API: registered callbacks get applied in priority order, and each one transforms the running value.</p>',
            ('filter-chain.php', php('''add_filter( 'sanitize_title', 'trim' );
add_filter( 'sanitize_title', 'strtolower' );
add_filter( 'sanitize_title', function ( $title ) {
\treturn preg_replace( '/\\s+/', '-', $title );
} );

echo apply_filters( 'sanitize_title', '  My Post Title  ' ) . "\\n";'''))),
        ('Priority ordering and multi-arg passing',
            '<p>Lower priority numbers run first. The fourth argument to <code>add_filter</code> controls how many context values get passed to the callback.</p>',
            ('priority-args.php', php('''add_filter( 'render_price', function ( $html, $price, $currency ) {
\treturn $html . " ({$currency} markup)";
}, 30, 3 );

add_filter( 'render_price', function ( $html, $price ) {
\treturn "<strong>{$html}</strong>";
}, 10, 2 );

add_filter( 'render_price', function ( $html, $price, $currency ) {
\tif ( 'EUR' === $currency ) return $html . ' EUR';
\treturn $html . " {$currency}";
}, 20, 3 );

echo apply_filters( 'render_price', '19.99', 19.99, 'EUR' ) . "\\n";'''))),
        ('Hook-based extension points in standalone libraries',
            '<p>Use <code>do_action</code> and <code>apply_filters</code> as cheap extension points in your own code, without depending on WordPress.</p>',
            ('library-hooks.php', php('''class ImportPipeline {
\tpublic function process( array $row ) {
\t\t$row = apply_filters( 'import_pipeline_normalize', $row );
\t\tdo_action( 'import_pipeline_row_processed', $row );
\t\treturn $row;
\t}
}

add_filter( 'import_pipeline_normalize', function ( $row ) {
\t$row['email'] = strtolower( trim( $row['email'] ) );
\treturn $row;
} );

$log = array();
add_action( 'import_pipeline_row_processed', function ( $row ) use ( &$log ) {
\t$log[] = $row['email'];
} );

$pipeline = new ImportPipeline();
$pipeline->process( array( 'email' => '  USER@EXAMPLE.COM  ' ) );
$pipeline->process( array( 'email' => 'OTHER@example.com' ) );

print_r( $log );'''))),
    ]))

# ===========================================================================
# Blueprints
# ===========================================================================
COMPONENTS.append(('blueprints', 'Blueprints',
    'Declarative WordPress site provisioning. Write a JSON description of plugins, options, and content; let the runner execute it.',
    'wp-php-toolkit/blueprints',
    [
        ('Two execution modes',
            '<p>Blueprints can <em>create</em> a new WordPress install (download core, set up the database, apply steps) or <em>apply to an existing</em> site. Creating a fresh site needs filesystem access this in-browser runtime doesn\'t have, so the snippets focus on <code>APPLY_TO_EXISTING_SITE</code>.</p>',
            None),
        ('Configure a runner for an existing site',
            '<p><code>RunnerConfiguration</code> is a fluent builder. The minimum: target site root, target site URL, execution mode.</p>',
            ('configure.php', php('''use WordPress\\Blueprints\\Runner;
use WordPress\\Blueprints\\RunnerConfiguration;

$config = ( new RunnerConfiguration() )
\t->set_execution_mode( Runner::EXECUTION_MODE_APPLY_TO_EXISTING_SITE )
\t->set_target_site_root( '/wordpress' )
\t->set_target_site_url( 'http://playground.test/' );

echo "mode: " . $config->get_execution_mode() . "\\n";
echo "root: " . $config->get_target_site_root() . "\\n";
echo "url:  " . $config->get_target_site_url() . "\\n";'''))),
        ('The Blueprint JSON shape',
            '<p>A blueprint is a JSON document with a <code>version</code> field and a <code>steps</code> array. Each step has a <code>"step"</code> discriminator and step-specific fields. This is the same shape used by <a href="https://playground.wordpress.net/">WordPress Playground</a>.</p>'
            '<pre><code>{\n  "version": 2,\n  "steps": [\n    { "step": "setSiteOptions",\n      "options": {\n        "blogname": "Demo Site",\n        "permalink_structure": "/%postname%/"\n      } },\n    { "step": "installPlugin",\n      "pluginData": "https://downloads.wordpress.org/plugin/gutenberg.zip" },\n    { "step": "activatePlugin",\n      "plugin": "gutenberg/gutenberg.php" }\n  ]\n}</code></pre>',
            None),
    ]))

# ===========================================================================
# ToolkitCodingStandards
# ===========================================================================
COMPONENTS.append(('coding-standards', 'ToolkitCodingStandards',
    'PHP_CodeSniffer sniffs used by this project: enforce Yoda comparisons, ban the short ternary.',
    'wp-php-toolkit/toolkit-coding-standards',
    [
        ('Reference the standard from your phpcs.xml',
            '<p>The component is a phpcs ruleset, so there\'s no runtime code to demo. Activate both sniffs at once by referencing <code>WordPressToolkitCodingStandards</code>:</p>'
            '<pre><code>&lt;?xml version="1.0"?&gt;\n&lt;ruleset name="My Project"&gt;\n  &lt;file&gt;src/&lt;/file&gt;\n\n  &lt;!-- Activate both toolkit sniffs --&gt;\n  &lt;rule ref="WordPressToolkitCodingStandards"/&gt;\n\n  &lt;!-- Or pick them individually --&gt;\n  &lt;!-- &lt;rule ref="WordPressToolkitCodingStandards.PHP.EnforceYodaComparison"/&gt; --&gt;\n  &lt;!-- &lt;rule ref="WordPressToolkitCodingStandards.PHP.DisallowShortTernary"/&gt; --&gt;\n&lt;/ruleset&gt;</code></pre>'
            '<p>Then run phpcs and phpcbf the usual way:</p>'
            '<pre><code>vendor/bin/phpcs --standard=phpcs.xml .\nvendor/bin/phpcbf --standard=phpcs.xml .</code></pre>',
            None),
        ('EnforceYodaComparison: catches accidental assignment',
            '<p>Yoda comparisons (<code>true === $x</code>) make typo-induced assignments into syntax errors:</p>'
            '<pre><code>// Bug: single = inside a condition. Always truthy, mutates $status.\nif ( $status = \'published\' ) {\n    publish_post( $post );\n}\n\n// Yoda style: writing this typo would be a parse error.\nif ( \'published\' === $status ) {\n    publish_post( $post );\n}</code></pre>'
            '<p>The sniff covers <code>===</code>, <code>!==</code>, <code>==</code>, and <code>!=</code>, and stays quiet when both sides are dynamic.</p>',
            None),
        ('Why ban the short ternary',
            '<p>The short ternary (<code>$a ?: $b</code>) is often confused with the null-coalescing operator (<code>$a ?? $b</code>). They differ on falsy-but-not-null values: <code>0 ?: \'fallback\'</code> returns <code>\'fallback\'</code>, but <code>0 ?? \'fallback\'</code> returns <code>0</code>. The sniff bans <code>?:</code> entirely so reviewers don\'t have to relitigate this on every PR.</p>',
            None),
    ]))
