# Zip

<!-- docs-site-banner -->
> **Runnable examples:** [https://wordpress.github.io/php-toolkit/reference/zip.html](https://wordpress.github.io/php-toolkit/reference/zip.html)
> Snippets execute live in WordPress Playground — edit them in your browser and see real output.
<!-- /docs-site-banner -->

## Why this exists

PHP ships with `ZipArchive`, a convenient class for reading and writing ZIP files. The catch: it requires the `libzip` native extension, which isn't available everywhere. WordPress Playground compiles PHP to WebAssembly and runs it in the browser — no native extensions, no `libzip`, no `ZipArchive`.

WordPress Playground needs ZIP files constantly. Installing a plugin, importing a theme, exporting a site — all of these move data as ZIP archives. This component implements ZIP reading and writing entirely in pure PHP so that Playground (and any other extension-free PHP environment) can work with ZIP files without restriction.

## How it works

A ZIP file is structured with the actual file data at the front and a "central directory" at the end. The central directory is an index: it lists every file in the archive along with the offset where its data starts. This layout is what makes ZIP files streamable — you can start writing file data immediately without knowing the final offsets, then write the index at the end.

### Reading: ZipFilesystem

`ZipFilesystem` reads the central directory first (from the end of the file) to build an in-memory index, then lazily reads individual file entries on demand. It implements the `Filesystem` interface from this toolkit, so reading a ZIP archive looks identical to reading a local directory. Code that accepts a `Filesystem` argument works against a ZIP file without any changes.

The central directory is capped at 2 MB to keep memory usage predictable even for large archives.

### Writing: ZipEncoder

`ZipEncoder` writes a ZIP archive incrementally to a `ByteWriteStream`. You add files one at a time; it writes each local file header and data immediately. When you call `finish()`, it writes the central directory and end-of-central-directory record, completing the archive.

Files can be stored uncompressed (`STORE`) or compressed with DEFLATE. The encoder handles CRC32 checksums and compressed/uncompressed size tracking automatically.

## Usage

### Read files from a ZIP archive

```php
use WordPress\Zip\ZipFilesystem;

$fs = ZipFilesystem::create( '/path/to/plugin.zip' );

// Works just like any other Filesystem.
foreach ( $fs->ls( '/' ) as $name ) {
    echo $name . "\n";
}

$contents = $fs->get_contents( '/readme.txt' );
```

### Check if a path exists

```php
if ( $fs->is_file( '/plugin.php' ) ) {
    $main_file = $fs->get_contents( '/plugin.php' );
}

if ( $fs->is_dir( '/assets' ) ) {
    foreach ( $fs->ls( '/assets' ) as $asset ) {
        echo $asset . "\n";
    }
}
```

### Mount a ZIP archive alongside other filesystems

Because `ZipFilesystem` implements `Filesystem`, you can pass it anywhere a filesystem is expected — including to code that recursively copies files:

```php
use WordPress\Zip\ZipFilesystem;
use WordPress\Filesystem\LocalFilesystem;
use WordPress\Filesystem\Visitor\FilesystemVisitor;

$zip   = ZipFilesystem::create( '/tmp/theme.zip' );
$local = new LocalFilesystem( '/var/www/html/wp-content/themes' );

// Extract the ZIP to the local filesystem.
$visitor = new FilesystemVisitor( $zip, '/' );
while ( $visitor->next() ) {
    $event = $visitor->get_event();
    $path  = $event->get_path();
    if ( $event->is_dir() ) {
        $local->mkdir( $path );
    } elseif ( $event->is_file() ) {
        $local->put_contents( $path, $zip->get_contents( $path ) );
    }
}
```

### Create a ZIP archive

```php
use WordPress\Zip\ZipEncoder;
use WordPress\Zip\FileEntry;

// Write to a file on disk.
$handle = fopen( '/tmp/output.zip', 'wb' );
$stream = new FileWriteStream( $handle );

$encoder = new ZipEncoder( $stream );

// Add a simple text file (stored uncompressed).
$entry = new FileEntry( 'hello.txt', 'Hello, world.' );
$encoder->append_file( $entry );

// Add a compressed file.
$entry = new FileEntry( 'data.json', json_encode( $data ), ZipEncoder::COMPRESSION_DEFLATE );
$encoder->append_file( $entry );

$encoder->finish();
fclose( $handle );
```

### Package a filesystem as a ZIP

`ZipEncoder` can recursively archive any `Filesystem` implementation — a local directory, an in-memory tree, or even another ZIP:

```php
use WordPress\Zip\ZipEncoder;
use WordPress\Filesystem\LocalFilesystem;

$fs      = new LocalFilesystem( '/var/www/html' );
$handle  = fopen( '/tmp/site-backup.zip', 'wb' );
$encoder = new ZipEncoder( new FileWriteStream( $handle ) );

$encoder->append_from_filesystem( $fs, '/' );
$encoder->finish();
fclose( $handle );
```

### Stream a ZIP archive directly to the browser

Because `ZipEncoder` writes to any `ByteWriteStream`, you can send a ZIP to the browser without creating a temporary file:

```php
header( 'Content-Type: application/zip' );
header( 'Content-Disposition: attachment; filename="export.zip"' );

$encoder = new ZipEncoder( new StdoutWriteStream() );
$encoder->append_from_filesystem( $fs, '/' );
$encoder->finish();
```

## ZIP format notes

ZIP stores file data as individual local file records followed by a central directory at the end. Because the encoder writes data before it knows final sizes (for streamed or large files), it uses data descriptors — a technique allowed by the ZIP specification — to record CRC32 and size values after the file data rather than before it.

Compression uses PHP's built-in `deflate_init()` / `deflate_add()` functions from the `zlib` extension. If `zlib` is unavailable, files can still be stored uncompressed; only DEFLATE compression requires it.
