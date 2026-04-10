# Zip

A pure PHP library for reading and writing ZIP archives without the `libzip` extension or `ZipArchive` class. It provides a streaming `ZipFilesystem` reader that exposes ZIP contents through a standard filesystem interface, and a `ZipEncoder` that writes ZIP files incrementally. Handles both stored and deflate-compressed entries.

## Installation

```
composer require wp-php-toolkit/zip
```

## Quick Start

```php
use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\Zip\ZipFilesystem;

// Open a ZIP file and read its contents
$zip = ZipFilesystem::create( FileReadStream::from_path( 'archive.zip' ) );

// List top-level entries
$entries = $zip->ls(); // ['readme.txt', 'src', 'images']

// Read a file
$content = $zip->get_contents( 'readme.txt' );
```

## Usage

### Reading ZIP Archives

`ZipFilesystem` implements the `Filesystem` interface, so you can list directories, check paths, and read files just like a regular filesystem.

```php
use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\Zip\ZipFilesystem;

$zip = ZipFilesystem::create( FileReadStream::from_path( 'book.epub' ) );

// List the root directory
$entries = $zip->ls();
// ['mimetype', 'EPUB', 'META-INF']

// List a subdirectory
$epub_files = $zip->ls( '/EPUB' );
// ['cover.xhtml', 'css', 'images', 'nav.xhtml', 'package.opf', ...]

// Check if a path exists
$zip->exists( 'mimetype' );      // true
$zip->is_file( 'mimetype' );     // true
$zip->is_dir( 'EPUB' );          // true
$zip->is_file( 'EPUB' );         // false

// Read file contents
$mimetype = $zip->get_contents( 'mimetype' );
// "application/epub+zip"

$cover = $zip->get_contents( 'EPUB/cover.xhtml' );
// "<?xml version="1.0" encoding="UTF-8"?>..."
```

### Streaming File Reads

For large files inside the archive, use `open_read_stream()` to read data incrementally instead of loading everything into memory.

```php
use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\Zip\ZipFilesystem;

$zip = ZipFilesystem::create( FileReadStream::from_path( 'archive.zip' ) );

$stream = $zip->open_read_stream( 'large-dataset.csv' );
while ( $bytes = $stream->pull( 4096 ) ) {
    $chunk = $stream->consume( $bytes );
    // Process the chunk...
}
```

### Creating ZIP Archives

Use `ZipEncoder` to build ZIP files from scratch. Write individual files with `append_file()`, or copy an entire filesystem tree with `append_from_filesystem()`.

```php
use WordPress\ByteStream\MemoryPipe;
use WordPress\ByteStream\WriteStream\FileWriteStream;
use WordPress\Zip\FileEntry;
use WordPress\Zip\ZipDecoder;
use WordPress\Zip\ZipEncoder;

// Create a new ZIP file
$output = FileWriteStream::from_path( 'output.zip', 'truncate' );
$encoder = new ZipEncoder( $output );

// Add a file with no compression
$encoder->append_file(
    new FileEntry( array(
        'path'               => 'hello.txt',
        'compression_method' => ZipDecoder::COMPRESSION_NONE,
        'body_reader'        => new MemoryPipe( 'Hello, world!' ),
    ) )
);

// Add a file with deflate compression
$encoder->append_file(
    new FileEntry( array(
        'path'               => 'data/notes.txt',
        'compression_method' => ZipDecoder::COMPRESSION_DEFLATE,
        'body_reader'        => new MemoryPipe( 'This will be compressed.' ),
    ) )
);

// Finalize and close
$encoder->close();
$output->close_writing();
```

### Copying from One ZIP to Another

Because `ZipFilesystem` implements the standard `Filesystem` interface, you can pass it directly to `ZipEncoder::append_from_filesystem()` to repackage a ZIP archive.

```php
use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\ByteStream\WriteStream\FileWriteStream;
use WordPress\Zip\ZipEncoder;
use WordPress\Zip\ZipFilesystem;

// Open the source ZIP
$source = ZipFilesystem::create( FileReadStream::from_path( 'original.zip' ) );

// Create a new ZIP with the same contents
$output = FileWriteStream::from_path( 'copy.zip', 'truncate' );
$encoder = new ZipEncoder( $output );
$encoder->append_from_filesystem( $source );
$encoder->close();
$output->close_writing();
```

## API Reference

### ZipFilesystem

| Method | Description |
|--------|-------------|
| `create( ByteReadStream $reader )` | Create a filesystem view of a ZIP archive |
| `ls( $dir = '/' )` | List entries in a directory |
| `is_file( $path )` | Check if a path is a file |
| `is_dir( $path )` | Check if a path is a directory |
| `exists( $path )` | Check if a path exists |
| `get_contents( $path )` | Read an entire file as a string |
| `open_read_stream( $path )` | Open a streaming reader for a file |

### ZipEncoder

| Method | Description |
|--------|-------------|
| `__construct( ByteWriteStream $output )` | Create an encoder that writes to the given stream |
| `append_file( FileEntry $entry )` | Add a single file to the archive |
| `append_from_filesystem( Filesystem $fs, $path )` | Recursively add files from a filesystem |
| `close()` | Write the central directory and finalize the archive |

### FileEntry

Constructed with an associative array of header fields:

| Field | Description |
|-------|-------------|
| `path` | File path inside the archive |
| `body_reader` | A `ByteReadStream` with the file data |
| `compression_method` | `ZipDecoder::COMPRESSION_NONE` or `ZipDecoder::COMPRESSION_DEFLATE` |

## Requirements

- PHP 7.2+
- No external PHP extensions required (no libzip)
