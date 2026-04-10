# Filesystem

A unified filesystem abstraction that lets you work with local disks, in-memory trees, SQLite-backed storage, and other backends through a single interface. Every implementation uses forward slashes as path separators regardless of the host OS, so code that works on Linux works identically on Windows and macOS.

## Installation

```bash
composer require wp-php-toolkit/filesystem
```

## Quick Start

```php
use WordPress\Filesystem\InMemoryFilesystem;

$fs = InMemoryFilesystem::create();
$fs->mkdir( '/docs' );
$fs->put_contents( '/docs/readme.txt', 'Hello, world!' );
echo $fs->get_contents( '/docs/readme.txt' ); // "Hello, world!"
```

## Usage

### Local Filesystem

`LocalFilesystem` wraps the real disk. Pass a root directory to `create()` and all paths are resolved relative to it.

```php
use WordPress\Filesystem\LocalFilesystem;

$fs = LocalFilesystem::create( '/var/www/mysite' );

// Write and read files
$fs->put_contents( '/config.json', '{"debug": true}' );
echo $fs->get_contents( '/config.json' ); // '{"debug": true}'

// Directory operations
$fs->mkdir( '/uploads/2024', array( 'recursive' => true ) );
$fs->put_contents( '/uploads/2024/photo.txt', 'image data here' );

// List directory contents
$entries = $fs->ls( '/uploads/2024' ); // ['photo.txt']

// Check paths
$fs->is_dir( '/uploads' );   // true
$fs->is_file( '/config.json' ); // true
$fs->exists( '/missing' );     // false
```

Without a root argument, `LocalFilesystem::create()` defaults to the system root (`/` on Unix, the system drive on Windows).

### In-Memory Filesystem

`InMemoryFilesystem` stores everything in PHP arrays. It is useful for tests, temporary processing, and anywhere you need a fast, disposable filesystem.

```php
use WordPress\Filesystem\InMemoryFilesystem;

$fs = InMemoryFilesystem::create();

$fs->mkdir( '/src/components', array( 'recursive' => true ) );
$fs->put_contents( '/src/components/button.php', '<?php // button' );
$fs->put_contents( '/src/components/form.php', '<?php // form' );

$files = $fs->ls( '/src/components' ); // ['button.php', 'form.php']
```

### SQLite Filesystem

`SQLiteFilesystem` persists files and directories in a SQLite database. It requires the `sqlite3` PHP extension (dev-only dependency, not required by the library at runtime).

```php
use WordPress\Filesystem\SQLiteFilesystem;

// In-memory SQLite database
$fs = SQLiteFilesystem::create( ':memory:' );

// Or persist to a file
$fs = SQLiteFilesystem::create( '/tmp/my-files.sqlite' );

$fs->mkdir( '/data' );
$fs->put_contents( '/data/report.csv', 'id,name\n1,Alice' );
echo $fs->get_contents( '/data/report.csv' );
```

### File and Directory Operations

All filesystem implementations share the same interface. These operations work identically across backends.

```php
// Rename (move) a file
$fs->put_contents( '/old-name.txt', 'content' );
$fs->rename( '/old-name.txt', '/new-name.txt' );

// Copy a file
$fs->put_contents( '/source.txt', 'content' );
$fs->copy( '/source.txt', '/dest.txt' );

// Copy a directory tree
$fs->mkdir( '/src/lib', array( 'recursive' => true ) );
$fs->put_contents( '/src/lib/utils.php', '<?php // utils' );
$fs->copy( '/src', '/backup', array( 'recursive' => true ) );
echo $fs->get_contents( '/backup/lib/utils.php' ); // '<?php // utils'

// Remove files and directories
$fs->rm( '/dest.txt' );
$fs->rmdir( '/backup', array( 'recursive' => true ) );
```

### Streaming Reads and Writes

Every filesystem can open byte streams for reading and writing. This integrates with the ByteStream component for chunk-based processing of large files.

```php
// Write via stream
$writer = $fs->open_write_stream( '/output.bin' );
$writer->append_bytes( 'chunk 1' );
$writer->append_bytes( 'chunk 2' );
$writer->close_writing();

// Read via stream
$reader = $fs->open_read_stream( '/output.bin' );
$contents = $reader->consume_all();
$reader->close_reading();
```

### Copying Between Filesystems

The `copy_between_filesystems()` function streams data from one filesystem to another, even across different backends.

```php
use WordPress\Filesystem\LocalFilesystem;
use WordPress\Filesystem\InMemoryFilesystem;

use function WordPress\Filesystem\copy_between_filesystems;

$local = LocalFilesystem::create( '/var/www/site' );
$memory = InMemoryFilesystem::create();

// Copy an entire directory tree from disk into memory
copy_between_filesystems( array(
    'source_filesystem' => $local,
    'source_path'       => '/wp-content/themes/flavor',
    'target_filesystem' => $memory,
    'target_path'       => '/theme',
) );

echo $memory->get_contents( '/theme/style.css' );
```

### Traversing a Filesystem

`FilesystemVisitor` walks a filesystem tree depth-first, emitting enter and exit events for each directory along with its files.

```php
use WordPress\Filesystem\Visitor\FilesystemVisitor;
use WordPress\Filesystem\Visitor\FileVisitorEvent;

$visitor = new FilesystemVisitor( $fs );
while ( $visitor->next() ) {
    $event = $visitor->get_event();
    if ( $event->is_entering() ) {
        echo "Entering: " . $event->dir . "\n";
        foreach ( $event->files as $file ) {
            echo "  File: " . $file . "\n";
        }
    }
}
```

### Path Helpers

The Filesystem component provides Unix-style path utilities that behave consistently on every OS.

```php
use function WordPress\Filesystem\wp_join_unix_paths;
use function WordPress\Filesystem\wp_unix_dirname;
use function WordPress\Filesystem\wp_unix_path_resolve_dots;

// Join path segments, collapsing duplicate slashes
echo wp_join_unix_paths( '/var/www', 'site', 'index.php' );
// "/var/www/site/index.php"

// Get the parent directory
echo wp_unix_dirname( '/var/www/site/index.php' );
// "/var/www/site"

// Resolve . and .. segments
echo wp_unix_path_resolve_dots( '/var/www/site/../other/./page.php' );
// "/var/www/other/page.php"
```

## API Reference

### Filesystem Interface

All implementations provide these methods:

| Method | Description |
|---|---|
| `ls( $dir )` | List entries in a directory |
| `is_dir( $path )` | Check if path is a directory |
| `is_file( $path )` | Check if path is a file |
| `exists( $path )` | Check if path exists |
| `mkdir( $path, $options )` | Create a directory. Use `['recursive' => true]` for nested paths |
| `rm( $path )` | Remove a file |
| `rmdir( $path, $options )` | Remove a directory. Use `['recursive' => true]` for non-empty dirs |
| `put_contents( $path, $data )` | Write a string to a file |
| `get_contents( $path )` | Read a file into a string |
| `open_read_stream( $path )` | Open a `ByteReadStream` for chunk-based reading |
| `open_write_stream( $path )` | Open a `ByteWriteStream` for chunk-based writing |
| `copy( $from, $to, $options )` | Copy a file or directory |
| `rename( $from, $to )` | Move/rename a file or directory |

### Implementations

| Class | Description |
|---|---|
| `LocalFilesystem` | Wraps the real disk via `LocalFilesystem::create( $root )` |
| `InMemoryFilesystem` | Array-backed filesystem via `InMemoryFilesystem::create()` |
| `SQLiteFilesystem` | SQLite-backed filesystem via `SQLiteFilesystem::create( $path )` |
| `UploadedFilesystem` | Read-only filesystem for handling REST API file uploads |

Other packages extend this interface with additional backends: `GitFilesystem` (from the Git component) and `ZipFilesystem` (from the Zip component).

### Helper Functions

| Function | Description |
|---|---|
| `wp_join_unix_paths( ...$segments )` | Join path segments with forward slashes |
| `wp_unix_dirname( $path )` | Get parent directory (Unix semantics on all OSes) |
| `wp_unix_path_resolve_dots( $path )` | Resolve `.` and `..` segments |
| `wp_unix_sys_get_temp_dir()` | Like `sys_get_temp_dir()` but always uses forward slashes |
| `copy_between_filesystems( $args )` | Stream data between two filesystem instances |
| `pipe_stream( $from, $to )` | Pipe a read stream into a write stream |

## Requirements

- PHP 7.2+
- No external dependencies (SQLiteFilesystem requires the `sqlite3` extension, which is a dev-only dependency)
