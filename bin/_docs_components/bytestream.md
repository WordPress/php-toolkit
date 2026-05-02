---
slug: bytestream
title: ByteStream
install: wp-php-toolkit/bytestream
---

Composable streaming primitives for reading, writing, transforming, hashing, and compressing byte data. Pull/peek/consume semantics let parsers backtrack without copying, and deflate, inflate, and checksum filters snap together like Lego.

## Why this exists

<p>PHP's native streams are powerful but inconsistent. <code>fread</code> on a socket may return short reads with no warning; <code>stream_filter_append</code> is awkward to compose; gzip helpers and file handles expose different APIs. The ByteStream component normalizes these behind one small interface — <code>pull / peek / consume</code> — so a parser, a hash function, and a deflate filter all see the same shape.</p>

<p>The split between <em>pull</em> (buffer up to N bytes) and <em>consume</em> (advance past N bytes) is the secret. Parsers can <code>peek</code> ahead to detect a record boundary and decide whether to <code>consume</code>, without copying or allocating.</p>

## Read a file in chunks

<p>The canonical loop. <code>pull(N)</code> reads up to <code>N</code> bytes from the underlying source into an internal buffer and returns how many ended up there; <code>consume(N)</code> reads <code>N</code> bytes from that buffer and advances past them. The buffer never grows beyond the chunk size you ask for.</p>

<!-- snippet:
filename: teaser-read.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use WordPress\ByteStream\ReadStream\FileReadStream;

$path = tempnam( sys_get_temp_dir(), 'demo' );
file_put_contents( $path, str_repeat( "log line\n", 200 ) );

$reader = FileReadStream::from_path( $path );
$total = 0;
while ( ! $reader->reached_end_of_data() ) {
	$n = $reader->pull( 256 );
	if ( 0 === $n ) break;
	$total += strlen( $reader->consume( $n ) );
}
$reader->close_reading();
echo "Read {$total} bytes in 256-byte chunks.\n";
```

## MemoryPipe as write-then-read buffer

<p><code>MemoryPipe</code> is bidirectional: you <code>append_bytes()</code> as a writer and <code>pull/consume</code> as a reader. Easiest way to wire one component's output into another's input.</p>

<p>Gotcha: a producer must call <code>close_writing()</code> when done — otherwise the consumer eventually throws <code>NotEnoughDataException</code> instead of seeing EOF.</p>

<!-- snippet:
filename: memory-pipe.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use WordPress\ByteStream\MemoryPipe;

$pipe = new MemoryPipe();
$pipe->append_bytes( "first chunk\n" );
$pipe->append_bytes( "second chunk\n" );
$pipe->append_bytes( "third chunk\n" );
$pipe->close_writing();

while ( ! $pipe->reached_end_of_data() ) {
	$n = $pipe->pull( 1024 );
	if ( 0 === $n ) break;
	echo "got: " . $pipe->consume( $n );
}
```

## Compress on the way in, decompress on the way out

<p>Wrap a stream in <code>DeflateReadStream</code> to get compressed bytes out; wrap it in <code>InflateReadStream</code> to get decompressed bytes out. Both are full <code>ByteReadStream</code> implementations, so they nest into anything else that takes a stream.</p>

<!-- snippet:
filename: deflate-roundtrip.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use WordPress\ByteStream\MemoryPipe;
use WordPress\ByteStream\ReadStream\DeflateReadStream;
use WordPress\ByteStream\ReadStream\InflateReadStream;

$original = str_repeat( "the quick brown fox. ", 50 );

$src        = new MemoryPipe( $original );
$src->close_writing();
$deflated   = new DeflateReadStream( $src, ZLIB_ENCODING_DEFLATE );
$compressed = $deflated->consume_all();

$src2     = new MemoryPipe( $compressed );
$src2->close_writing();
$inflated = new InflateReadStream( $src2, ZLIB_ENCODING_DEFLATE );
$round    = $inflated->consume_all();

printf( "original  : %d bytes\n", strlen( $original ) );
printf( "deflated  : %d bytes (%.1f%%)\n", strlen( $compressed ), 100 * strlen( $compressed ) / strlen( $original ) );
printf( "round-trip: %s\n", $round === $original ? 'OK' : 'BROKEN' );
```

## Line-by-line reads from a chunked source

<p>Reading text by line means handling chunk boundaries that fall mid-line. Keep the trailing partial line and prepend it to the next pull. The rest of the loop pretends the data was always whole.</p>

<!-- snippet:
filename: lines.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use WordPress\ByteStream\MemoryPipe;

$pipe = new MemoryPipe();
$pipe->append_bytes( "alpha\nbravo\ncharl" );
$pipe->append_bytes( "ie\ndelta\necho\n" );
$pipe->close_writing();

$tail = '';
$count = 0;
while ( ! $pipe->reached_end_of_data() ) {
	$n = $pipe->pull( 8 );
	if ( 0 === $n ) break;
	$buf   = $tail . $pipe->consume( $n );
	$lines = explode( "\n", $buf );
	$tail  = array_pop( $lines );
	foreach ( $lines as $line ) {
		printf( "[%d] %s\n", ++$count, $line );
	}
}
if ( '' !== $tail ) {
	printf( "[%d] %s\n", ++$count, $tail );
}
```

## Limit a stream to a fixed window

<p><code>LimitedByteReadStream</code> exposes only the next N bytes of an underlying stream as if those were the entire stream. This is how the ZIP decoder hands you the body of one entry without letting you read into the next.</p>

<!-- snippet:
filename: limited.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use WordPress\ByteStream\MemoryPipe;
use WordPress\ByteStream\ReadStream\LimitedByteReadStream;

$source = new MemoryPipe( "HEADER:42|BODY:hello there|FOOTER:done" );
$source->close_writing();

$source->pull( 10 );
$source->consume( 10 );

$body = new LimitedByteReadStream( $source, 16 );
echo "body sees: " . $body->consume_all() . "\n";
echo "remaining in source: " . $source->consume_all() . "\n";
```
