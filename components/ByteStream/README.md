# ByteStream

Composable streaming primitives for reading, writing, and transforming byte data in pure PHP. ByteStream provides a pull-based model where you request bytes from a source, peek at or consume them, and optionally transform them through filters like compression or checksums -- all without loading entire files into memory.

## Installation

```bash
composer require wp-php-toolkit/bytestream
```

## Quick Start

```php
use WordPress\ByteStream\ReadStream\FileReadStream;

// Read a file in chunks
$reader = FileReadStream::from_path( '/path/to/file.txt' );
while ( ! $reader->reached_end_of_data() ) {
    $available = $reader->pull( 1024 );
    $chunk = $reader->consume( $available );
    // Process $chunk...
}
$reader->close_reading();
```

## Usage

### Reading Files

`FileReadStream` opens a file and exposes it through the pull/consume model. Use `pull()` to buffer bytes, `peek()` to inspect them without advancing, and `consume()` to read and advance the position.

```php
use WordPress\ByteStream\ReadStream\FileReadStream;

$reader = FileReadStream::from_path( '/path/to/data.bin' );

// Pull up to 100 bytes into the internal buffer
$reader->pull( 100 );

// Peek at the first 10 bytes without consuming them
$header = $reader->peek( 10 );

// Consume (read and advance past) 10 bytes
$header = $reader->consume( 10 );

// Read the current position
$offset = $reader->tell(); // 10

// Seek to a specific offset
$reader->seek( 0 );

// Read all remaining bytes at once
$rest = $reader->consume_all();

$reader->close_reading();
```

You can also create a `FileReadStream` from an existing resource handle:

```php
$handle = fopen( '/path/to/file.txt', 'r' );
$reader = FileReadStream::from_resource( $handle, filesize( '/path/to/file.txt' ) );
```

### In-Memory Streams with MemoryPipe

`MemoryPipe` holds data in memory and supports both reading and writing. It is useful for testing, for wrapping string data in the stream interface, or for piping data between components.

```php
use WordPress\ByteStream\MemoryPipe;

// Wrap a string as a readable stream
$pipe = new MemoryPipe( 'Hello, world!' );
$pipe->pull( 5 );
echo $pipe->consume( 5 ); // "Hello"

// Use as a write-then-read pipe
$pipe = new MemoryPipe( null, 1024 ); // Expected length of 1024
$pipe->append_bytes( 'chunk one ' );
$pipe->append_bytes( 'chunk two' );
$pipe->close_writing();

echo $pipe->consume_all(); // "chunk one chunk two"
```

### Writing Files

`FileWriteStream` appends data to a file. It supports truncating or appending to existing files.

```php
use WordPress\ByteStream\WriteStream\FileWriteStream;

// Truncate and write
$writer = FileWriteStream::from_path( '/path/to/output.txt', 'truncate' );
$writer->append_bytes( 'First line' );
$writer->append_bytes( "\nSecond line" );
$writer->close_writing();

// Append to existing file
$writer = FileWriteStream::from_path( '/path/to/output.txt', 'append' );
$writer->append_bytes( "\nThird line" );
$writer->close_writing();
```

### Reading and Writing the Same File

`FileReadWriteStream` provides both read and write access to a single file. Writes always append to the end while reads track their own position independently.

```php
use WordPress\ByteStream\FileReadWriteStream;

$stream = FileReadWriteStream::from_path( '/tmp/buffer.bin', true );
$stream->append_bytes( 'Hello' );
$stream->append_bytes( ' World' );

// Read back from the beginning
$stream->pull( 11 );
echo $stream->consume( 11 ); // "Hello World"

$stream->close_writing();
$stream->close_reading();
```

### Compression and Decompression

`DeflateReadStream` compresses data as you read it, and `InflateReadStream` decompresses. They wrap any `ByteReadStream` and produce a new stream of transformed bytes.

```php
use WordPress\ByteStream\MemoryPipe;
use WordPress\ByteStream\ReadStream\DeflateReadStream;
use WordPress\ByteStream\ReadStream\InflateReadStream;

$original = 'The quick brown fox jumps over the lazy dog.';

// Compress
$source = new MemoryPipe( $original );
$deflated = new DeflateReadStream( $source, ZLIB_ENCODING_DEFLATE );
$compressed = $deflated->consume_all();

// Decompress
$compressed_source = new MemoryPipe( $compressed );
$inflated = new InflateReadStream( $compressed_source, ZLIB_ENCODING_DEFLATE );
echo $inflated->consume_all(); // "The quick brown fox jumps over the lazy dog."
```

### Transforming Streams with Filters

`TransformedReadStream` and `TransformedWriteStream` apply a chain of `ByteTransformer` filters as data flows through the stream. Built-in transformers include `ChecksumTransformer`, `DeflateTransformer`, and `InflateTransformer`.

```php
use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\ByteStream\ReadStream\TransformedReadStream;
use WordPress\ByteStream\ByteTransformer\ChecksumTransformer;

// Read a file and compute its SHA-1 hash as you go
$checksum = new ChecksumTransformer( 'sha1' );
$reader = FileReadStream::from_path( '/path/to/file.txt' );
$stream = new TransformedReadStream( $reader, array( 'checksum' => $checksum ) );

$contents = $stream->consume_all();
echo $stream['checksum']->get_hash(); // SHA-1 hex digest
```

Compress data as you write it:

```php
use WordPress\ByteStream\WriteStream\FileWriteStream;
use WordPress\ByteStream\WriteStream\TransformedWriteStream;
use WordPress\ByteStream\ByteTransformer\DeflateTransformer;

$file_writer = FileWriteStream::from_path( '/path/to/output.deflate', 'truncate' );
$writer = new TransformedWriteStream(
    $file_writer,
    array( new DeflateTransformer( ZLIB_ENCODING_DEFLATE ) )
);
$writer->append_bytes( 'Data to compress...' );
$writer->close_writing();
$file_writer->close_writing();
```

### Limiting Read Length

`LimitedByteReadStream` restricts reading to a fixed number of bytes from a larger stream. This is useful for reading structured binary formats where you know the length of each section.

```php
use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\ByteStream\ReadStream\LimitedByteReadStream;

$reader = FileReadStream::from_path( '/path/to/archive.bin' );

// Read only the first 256 bytes
$header_reader = new LimitedByteReadStream( $reader, 256 );
$header = $header_reader->consume_all();
echo $header_reader->length(); // 256
```

### Pull Modes

The `pull()` method supports two modes that control how bytes are buffered:

```php
use WordPress\ByteStream\ReadStream\ByteReadStream;

// PULL_NO_MORE_THAN (default): pull up to N bytes, may return fewer
$available = $reader->pull( 1024 );
$chunk = $reader->consume( $available );

// PULL_EXACTLY: pull exactly N bytes, throws NotEnoughDataException if
// the stream doesn't have enough data
$reader->pull( 100, ByteReadStream::PULL_EXACTLY );
$chunk = $reader->consume( 100 );
```

## API Reference

### Interfaces

| Interface | Methods |
|---|---|
| `ByteReadStream` | `pull()`, `peek()`, `consume()`, `consume_all()`, `seek()`, `tell()`, `length()`, `reached_end_of_data()`, `close_reading()` |
| `ByteWriteStream` | `append_bytes()`, `close_writing()` |
| `BytePipe` | Combines `ByteReadStream` and `ByteWriteStream` |
| `ByteTransformer` | `filter_bytes()`, `flush()` |

### Read Stream Classes

| Class | Description |
|---|---|
| `FileReadStream` | Reads from a file via `from_path()` or `from_resource()` |
| `InflateReadStream` | Decompresses a wrapped `ByteReadStream` |
| `DeflateReadStream` | Compresses a wrapped `ByteReadStream` |
| `TransformedReadStream` | Applies a chain of `ByteTransformer` filters while reading |
| `LimitedByteReadStream` | Limits reading to a fixed byte count from a larger stream |

### Write Stream Classes

| Class | Description |
|---|---|
| `FileWriteStream` | Writes to a file via `from_path()` or `from_resource_handle()` |
| `TransformedWriteStream` | Applies a chain of `ByteTransformer` filters while writing |

### Other Classes

| Class | Description |
|---|---|
| `MemoryPipe` | In-memory read/write buffer (implements `BytePipe`) |
| `FileReadWriteStream` | File-backed read/write stream (implements `BytePipe`) |
| `ChecksumTransformer` | Computes a hash (SHA-1, MD5, etc.) as bytes flow through |
| `DeflateTransformer` | Compresses bytes as a write-side transformer |
| `InflateTransformer` | Decompresses bytes as a write-side transformer |

## Requirements

- PHP 7.2+
- No external dependencies
