---
slug: zip
title: Zip
install: wp-php-toolkit/zip

see_also:
  - ../learn/02-streaming-archives.html | Tutorial — Streaming archives | Walk through ZIP and EPUB writers from the toolkit's worked example.
  - filesystem | Filesystem | Treat an archive like a swappable filesystem backend.
  - bytestream | ByteStream | Feed readers and writers without whole-file buffers.
  - httpclient | HttpClient | Stream downloaded archives into validation or extraction workflows.
---

Read and write ZIP archives without <code>libzip</code> or <code>ZipArchive</code>. Stored entries are pure PHP; Deflate entries use PHP's <code>zlib</code> functions. Entries stream one at a time, while ZIP metadata such as the central directory is still held in memory.

## Why this exists

<p>Common PHP ZIP workflows rely on the <code>ZipArchive</code> extension or shelling out to <code>zip</code>. Those are awkward in hosts without libzip, WebAssembly builds, and code paths that need to stream archive data through toolkit byte streams.</p>

<p>The Zip component reads and writes Stored and Deflate archives without <code>ZipArchive</code>. The decoder is pull-based for entry bodies, but <code>ZipFilesystem</code> indexes the central directory in memory and currently rejects archives whose central directory exceeds 2 MB. The encoder accepts any <code>ByteWriteStream</code> as a sink and writes one entry at a time.</p>

## Read a file out of a ZIP

<p><code>ZipFilesystem</code> implements this toolkit's <code>Filesystem</code> interface, so once you wrap the byte reader you can call <code>get_contents()</code>, <code>ls()</code>, and <code>is_dir()</code> just like the other read backends.</p>

<p><strong>Try this:</strong> after <em>Run</em>, add a second <code>append_file()</code> call before <code>$enc-&gt;close()</code> for a <code>notes.md</code> entry, then call <code>print_r( $zip-&gt;ls( '/' ) )</code> at the end. The directory listing reflects the new entry without re-reading the file.</p>

<!-- snippet:
filename: teaser-read.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

use WordPress\ByteStream\MemoryPipe;
use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\ByteStream\WriteStream\FileWriteStream;
use WordPress\Zip\FileEntry;
use WordPress\Zip\ZipDecoder;
use WordPress\Zip\ZipEncoder;
use WordPress\Zip\ZipFilesystem;

$path = tempnam( sys_get_temp_dir(), 'demo' ) . '.zip';
$out  = FileWriteStream::from_path( $path, 'truncate' );
$enc  = new ZipEncoder( $out );
$enc->append_file( new FileEntry( array(
	'path'               => 'readme.txt',
	'compression_method' => ZipDecoder::COMPRESSION_NONE,
	'body_reader'        => new MemoryPipe( 'Hello from inside the zip.' ),
) ) );
$enc->close();
$out->close_writing();

$zip = ZipFilesystem::create( FileReadStream::from_path( $path ) );
echo $zip->get_contents( 'readme.txt' );
```

<!-- expected-output -->
```
Hello from inside the zip.
```

## Build an EPUB from scratch

<p>An EPUB follows one strict ZIP rule: write the <code>mimetype</code> entry first and store it without compression. Deflate the rest of the archive normally.</p>

<p>Gotcha: <strong>E-readers reject EPUBs whose <code>mimetype</code> entry has compression.</strong> Use <code>COMPRESSION_NONE</code> for that single entry.</p>

<!-- snippet:
filename: epub.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

use WordPress\ByteStream\MemoryPipe;
use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\ByteStream\WriteStream\FileWriteStream;
use WordPress\Zip\FileEntry;
use WordPress\Zip\ZipDecoder;
use WordPress\Zip\ZipEncoder;
use WordPress\Zip\ZipFilesystem;

$path = tempnam( sys_get_temp_dir(), 'book' ) . '.epub';
$out  = FileWriteStream::from_path( $path, 'truncate' );
$enc  = new ZipEncoder( $out );

// 1) The mimetype entry MUST be first and stored uncompressed.
$enc->append_file( new FileEntry( array(
	'path'               => 'mimetype',
	'compression_method' => ZipDecoder::COMPRESSION_NONE,
	'body_reader'        => new MemoryPipe( 'application/epub+zip' ),
) ) );

$container = <<<'XML'
<?xml version="1.0"?>
<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">
<rootfiles><rootfile full-path="EPUB/package.opf" media-type="application/oebps-package+xml"/></rootfiles>
</container>
XML;

foreach ( array(
	'META-INF/container.xml' => $container,
	'EPUB/package.opf'       => <<<'XML'
<package version="3.0" xmlns="http://www.idpf.org/2007/opf"><metadata/><manifest/><spine/></package>',
	'EPUB/chapter1.xhtml'    => <<<'XML'
<html xmlns="http://www.w3.org/1999/xhtml"><body><h1>Chapter 1</h1><p>It was a dark and stormy night.</p></body></html>
XML,
) as $name => $body ) {
	$enc->append_file( new FileEntry( array(
		'path'               => $name,
		'compression_method' => ZipDecoder::COMPRESSION_DEFLATE,
		'body_reader'        => new MemoryPipe( $body ),
	) ) );
}
$enc->close();
$out->close_writing();

$zip = ZipFilesystem::create( FileReadStream::from_path( $path ) );
printf( "mimetype: %s\n", $zip->get_contents( 'mimetype' ) );
printf( "size on disk: %d bytes\n", filesize( $path ) );
```

<!-- expected-output -->
```
mimetype: application/epub+zip
size on disk: 726 bytes
```

## Stream a large entry without buffering it

<p>Calling <code>get_contents()</code> on a 500 MB CSV inside a ZIP would eat 500 MB of RAM. Use <code>open_read_stream()</code> instead and inflate-as-you-go.</p>

<p>Gotcha: <strong>Only one entry stream open at a time.</strong> Drain or finish the previous stream before opening the next.</p>

<!-- snippet:
filename: stream-large.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

use WordPress\ByteStream\MemoryPipe;
use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\ByteStream\WriteStream\FileWriteStream;
use WordPress\Zip\FileEntry;
use WordPress\Zip\ZipDecoder;
use WordPress\Zip\ZipEncoder;
use WordPress\Zip\ZipFilesystem;

$path = tempnam( sys_get_temp_dir(), 'big' ) . '.zip';
$out  = FileWriteStream::from_path( $path, 'truncate' );
$enc  = new ZipEncoder( $out );
$enc->append_file( new FileEntry( array(
	'path'               => 'data.csv',
	'compression_method' => ZipDecoder::COMPRESSION_DEFLATE,
	'body_reader'        => new MemoryPipe( str_repeat( "id,value,timestamp\n1,foo,2024\n2,bar,2024\n", 5000 ) ),
) ) );
$enc->close();
$out->close_writing();

$zip    = ZipFilesystem::create( FileReadStream::from_path( $path ) );
$stream = $zip->open_read_stream( 'data.csv' );

$rows  = 0;
$bytes = 0;
$tail  = '';
while ( ! $stream->reached_end_of_data() ) {
	$n = $stream->pull( 8192 );
	if ( 0 === $n ) break;
	$chunk  = $tail . $stream->consume( $n );
	$lines  = explode( "\n", $chunk );
	$tail   = array_pop( $lines );
	$rows  += count( $lines );
	$bytes += $n;
}
printf( "Inflated %d bytes in 8 KB chunks, parsed %d rows.\n", $bytes, $rows );
```

<!-- expected-output -->
```
Inflated 205000 bytes in 8 KB chunks, parsed 15000 rows.
```

## Repack: modify one file, copy the rest

<p>Updating one file in a ZIP without rewriting the others is impossible at the format level — the central directory points at byte offsets. The pragmatic answer is repack: stream the source archive into a new one, swapping the file you care about.</p>

<!-- snippet:
filename: repack.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

use WordPress\ByteStream\MemoryPipe;
use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\ByteStream\WriteStream\FileWriteStream;
use WordPress\Zip\FileEntry;
use WordPress\Zip\ZipDecoder;
use WordPress\Zip\ZipEncoder;
use WordPress\Zip\ZipFilesystem;

$src_path = tempnam( sys_get_temp_dir(), 'orig' ) . '.zip';
$src_out  = FileWriteStream::from_path( $src_path, 'truncate' );
$src_enc  = new ZipEncoder( $src_out );
foreach ( array(
	'config.json'   => '{"debug":false,"version":"1.0"}',
	'app/index.php' => <<<'HTML'
<?php echo "hello";
XML,
	'app/style.css' => 'body{color:#333}
HTML,
) as $name => $body ) {
	$src_enc->append_file( new FileEntry( array(
		'path'               => $name,
		'compression_method' => ZipDecoder::COMPRESSION_DEFLATE,
		'body_reader'        => new MemoryPipe( $body ),
	) ) );
}
$src_enc->close();
$src_out->close_writing();

$source   = ZipFilesystem::create( FileReadStream::from_path( $src_path ) );
$dst_path = tempnam( sys_get_temp_dir(), 'repacked' ) . '.zip';
$dst_out  = FileWriteStream::from_path( $dst_path, 'truncate' );
$dst_enc  = new ZipEncoder( $dst_out );

$dirs = array( '/' );
while ( $dirs ) {
	$dir = array_shift( $dirs );
	foreach ( $source->ls( $dir ) as $name ) {
		$path = rtrim( $dir, '/' ) . '/' . $name;
		if ( $source->is_dir( $path ) ) {
			$dirs[] = $path;
			continue;
		}
		$rel  = ltrim( $path, '/' );
		$body = ( 'config.json' === $rel )
			? '{"debug":true,"version":"1.0.1"}'
			: $source->get_contents( $rel );
		$dst_enc->append_file( new FileEntry( array(
			'path'               => $rel,
			'compression_method' => ZipDecoder::COMPRESSION_DEFLATE,
			'body_reader'        => new MemoryPipe( $body ),
		) ) );
	}
}
$dst_enc->close();
$dst_out->close_writing();

$repacked = ZipFilesystem::create( FileReadStream::from_path( $dst_path ) );
echo "new config.json: " . $repacked->get_contents( 'config.json' ) . "\n";
echo "untouched: " . $repacked->get_contents( 'app/index.php' ) . "\n";
```

<!-- expected-output -->
```
new config.json: {"debug":true,"version":"1.0.1"}
untouched: <?php echo "hello";
XML,
	'app/style.css' => 'body{color:#333}
```

## Defend against zip-slip

<p>A malicious archive can name an entry <code>../../etc/passwd</code> and trick a naive extractor into clobbering files outside the destination. <code>ZipDecoder::sanitize_path()</code> normalizes slashes and strips leading traversal segments before exposing the path. Treat it as one layer of defense; still extract through a chrooted filesystem target.</p>

<!-- snippet:
filename: zip-slip.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

use WordPress\Zip\ZipDecoder;

$evil_inputs = array(
	'../../etc/passwd',
	'./safe/path.txt',
	'a/../../b/secret',
	'a//b///c.txt',
	'../../../../root/.ssh/authorized_keys',
);
foreach ( $evil_inputs as $name ) {
	printf( "%-45s => %s\n", $name, ZipDecoder::sanitize_path( $name ) );
}
```

<!-- expected-output -->
```
../../etc/passwd                              => etc/passwd
./safe/path.txt                               => ./safe/path.txt
a/../../b/secret                              => a/../b/secret
a//b///c.txt                                  => a/b/c.txt
../../../../root/.ssh/authorized_keys         => root/.ssh/authorized_keys
```

## Pipe ZIP entries into an InMemoryFilesystem

<p>Real-world recipe: take an uploaded plugin ZIP, expand it into an <code>InMemoryFilesystem</code> so you can validate, edit, or scan it before it ever touches disk. Three components compose into something you couldn't build with <code>ZipArchive</code> alone.</p>

<!-- snippet:
filename: zip-to-memfs.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

use WordPress\ByteStream\MemoryPipe;
use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\ByteStream\WriteStream\FileWriteStream;
use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Zip\FileEntry;
use WordPress\Zip\ZipDecoder;
use WordPress\Zip\ZipEncoder;
use WordPress\Zip\ZipFilesystem;
use function WordPress\Filesystem\copy_between_filesystems;

$path = tempnam( sys_get_temp_dir(), 'app' ) . '.zip';
$out  = FileWriteStream::from_path( $path, 'truncate' );
$enc  = new ZipEncoder( $out );
foreach ( array(
	'app/index.php'        => <<<'HTML'
<?php echo "ok";',
	'app/lib/util.php'     => '<?php // util
HTML,
	'app/assets/style.css' => 'body{margin:0}',
	'app/README.md'        => '# App',
) as $name => $body ) {
	$enc->append_file( new FileEntry( array(
		'path'               => $name,
		'compression_method' => ZipDecoder::COMPRESSION_DEFLATE,
		'body_reader'        => new MemoryPipe( $body ),
	) ) );
}
$enc->close();
$out->close_writing();

$zip = ZipFilesystem::create( FileReadStream::from_path( $path ) );
$mem = InMemoryFilesystem::create();
copy_between_filesystems( array(
	'source_filesystem' => $zip,
	'source_path'       => '/',
	'target_filesystem' => $mem,
	'target_path'       => '/',
) );

$mem->put_contents( '/app/VERSION', '1.0.0' );
echo "files now in memory:\n";
$dirs = array( '/' );
$files = array();
while ( $dirs ) {
	$dir = array_shift( $dirs );
	foreach ( $mem->ls( $dir ) as $name ) {
		$p = rtrim( $dir, '/' ) . '/' . $name;
		if ( $mem->is_dir( $p ) ) {
			$dirs[] = $p;
			continue;
		}
		$files[] = $p;
	}
}
sort( $files );
foreach ( $files as $path ) {
	echo "  " . $path . "\n";
}
```

<!-- expected-output -->
```
files now in memory:
  /app/README.md
  /app/VERSION
  /app/assets/style.css
  /app/index.php
```

## When to use which type

<table class="api-table">
<tr><th>Use</th><th>For</th></tr>
<tr><td><code>ZipFilesystem::create()</code></td><td>Reading. You want <code>get_contents()</code>, <code>ls()</code>, <code>is_dir()</code> over a ZIP. The most common case.</td></tr>
<tr><td><code>ZipEncoder</code></td><td>Writing. Stream entries into any <code>ByteWriteStream</code> sink. Required when format rules matter (EPUB, .docx).</td></tr>
<tr><td><code>ZipDecoder</code></td><td>Low-level read access to the central directory and individual entry headers. Most code reaches for <code>ZipFilesystem</code> instead.</td></tr>
<tr><td><code>open_read_stream()</code> on a ZipFilesystem</td><td>Inflating a single large entry without buffering it whole in memory.</td></tr>
<tr><td><code>copy_between_filesystems()</code></td><td>Moving entries from a ZIP into another filesystem (memory, local, SQLite).</td></tr>
</table>

<p>Footgun: <strong>Updating an entry in place is impossible.</strong> The central directory points at byte offsets — change one entry's compressed size and every later offset shifts. Repack into a new archive instead.</p>

<p>Footgun: <strong>Never extract entry paths verbatim.</strong> Always run paths through <code>ZipDecoder::sanitize_path()</code> and write through a filesystem layer that prevents path escape. Without those checks, a hostile archive can write outside the destination directory.</p>

<p>Footgun: <strong>Encrypted archives aren't supported.</strong> If you need to read AES-encrypted ZIPs, this isn't the component. The file format technically allows encryption, but the toolkit deliberately excludes it because the implementation surface is large and the use case is rare in WordPress contexts.</p>

<p>Footgun: <strong>ZIP64 and very large central directories aren't supported by <code>ZipFilesystem</code>.</strong> Entry bodies can stream, but the archive index is bounded by <code>MAX_CENTRAL_DIRECTORY_SIZE</code>.</p>
