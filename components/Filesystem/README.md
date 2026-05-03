# Filesystem

<!-- docs-site-banner -->
> 📚 **Runnable examples:** [https://wordpress.github.io/php-toolkit/reference/filesystem.html](https://wordpress.github.io/php-toolkit/reference/filesystem.html)
> Open the page to edit each snippet in your browser and run it in WordPress Playground.
<!-- /docs-site-banner -->

## Why this exists

PHP's built-in file functions (`file_get_contents`, `fopen`, `mkdir`, etc.) are tightly coupled to the local disk. That's fine for simple scripts, but it creates a real problem when you want to:

- **Test code without touching the disk.** Unit tests that create real files are slow, fragile, and leave cleanup responsibilities behind.
- **Work with non-disk storage.** WordPress Playground runs entirely in the browser using a virtual filesystem backed by a SQLite database. Your code needs to work the same way against both a real disk and an in-memory tree.
- **Operate on ZIP archives as if they were directories.** Instead of extracting first and then reading, you want to walk a ZIP file the same way you'd walk a folder.
- **Stay portable across operating systems.** Windows uses backslashes; everything else uses forward slashes. Code that hardcodes separators breaks on the other platform.

This component defines a single `Filesystem` interface and several implementations behind it. Write your code against the interface once, and it works against any backend.

## How it works

The `Filesystem` interface defines the operations every backend must support: listing directories, reading and writing files, checking existence, copying, renaming, deleting. Implementations handle the translation to whatever storage mechanism is underneath.

All paths use forward slashes (`/`) regardless of OS. On Windows, the `LocalFilesystem` translates them to backslashes internally, but your code never sees that.

Reads and writes are stream-based under the hood. `open_read_stream()` returns a handle you can read in chunks; `open_write_stream()` gives you a handle to write to. `get_contents()` and `put_contents()` are convenience wrappers that read or write the entire file at once.

The `FilesystemVisitor` handles recursive tree traversal, emitting events for each directory and file it encounters.

### The implementations

**`LocalFilesystem`** — wraps PHP's built-in file functions. Works on the actual disk.

**`InMemoryFilesystem`** — stores everything in a PHP array. Fast, zero I/O, perfect for tests and ephemeral scratch space.

**`SQLiteFilesystem`** — stores files in a SQLite database. Used by WordPress Playground to persist a WordPress installation in a single database file that can be serialized, snapshotted, and restored.

**`ZipFilesystem`** (from the Zip component) — mounts a ZIP archive as a read-only directory tree.

**`UploadedFilesystem`** — wraps another filesystem and tracks which paths were written, for auditing what an operation produced.

### ChrootLayer

Many factory methods wrap a filesystem in a `ChrootLayer`, which jails all path operations to a specific root directory. This prevents code from accidentally escaping to `/` and makes it safe to hand a filesystem object to untrusted code.

## Usage

### Read a file

```php
use WordPress\Filesystem\LocalFilesystem;

$fs = new LocalFilesystem( '/var/www/html' );

if ( $fs->is_file( '/wp-config.php' ) ) {
    $contents = $fs->get_contents( '/wp-config.php' );
}
```

### Write a file

```php
$fs->put_contents( '/uploads/hello.txt', 'Hello, world.' );
```

### List a directory

```php
foreach ( $fs->ls( '/wp-content/plugins' ) as $name ) {
    echo $name . "\n";  // plugin directory names only, not full paths
}
```

### Use an in-memory filesystem for tests

Because your code accepts a `Filesystem` interface, you can swap in `InMemoryFilesystem` in tests without changing anything else:

```php
use WordPress\Filesystem\InMemoryFilesystem;

$fs = new InMemoryFilesystem();
$fs->put_contents( '/config.json', json_encode( [ 'debug' => true ] ) );

// Pass $fs to the code under test — it never touches the real disk.
$result = my_config_loader( $fs );
```

### Walk a directory tree

```php
use WordPress\Filesystem\Visitor\FilesystemVisitor;

$visitor = new FilesystemVisitor( $fs, '/' );
while ( $visitor->next() ) {
    $event = $visitor->get_event();
    echo $event->get_path() . ( $event->is_dir() ? '/' : '' ) . "\n";
}
```

### Stream large files

For large files, streaming avoids loading everything into memory at once:

```php
$read_stream  = $fs->open_read_stream( '/large-export.sql' );
$write_stream = $fs->open_write_stream( '/large-export-copy.sql' );

while ( ! $read_stream->is_finished() ) {
    $chunk = $read_stream->read( 65536 );  // 64 KB at a time
    $write_stream->write( $chunk );
}

$read_stream->close();
$write_stream->close();
```

### Copy files between different backends

Because every backend speaks the same interface, you can copy between them directly:

```php
use WordPress\Filesystem\LocalFilesystem;
use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Filesystem\Visitor\FilesystemVisitor;

$local  = new LocalFilesystem( '/var/www/html' );
$memory = new InMemoryFilesystem();

// Copy everything from disk to memory.
$visitor = new FilesystemVisitor( $local, '/' );
while ( $visitor->next() ) {
    $event = $visitor->get_event();
    $path  = $event->get_path();
    if ( $event->is_file() ) {
        $memory->put_contents( $path, $local->get_contents( $path ) );
    } elseif ( $event->is_dir() ) {
        $memory->mkdir( $path );
    }
}
```

## Path conventions

- Always use forward slashes: `/wp-content/uploads/photo.jpg`.
- Paths are absolute from the filesystem root. The root itself is `/`.
- On Windows, `LocalFilesystem` converts slashes internally; you never need to use `DIRECTORY_SEPARATOR`.
- `ChrootLayer` jails all paths to the configured root. A path of `/` inside a chrooted filesystem refers to the configured root directory on disk, not the actual system root.
