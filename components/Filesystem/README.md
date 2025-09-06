# Filesystem

Unified filesystem interface with multiple backends (local, in‑memory, SQLite, uploaded files) and streaming APIs. Designed for composition with `ByteStream`, `Zip`, and higher‑level tools.

## Problems Solved
- Portable file operations across backends
- Stream files without loading whole contents into memory
- Safe helpers for recursive copy/mkdir/rename/rmdir

## Interfaces and Implementations
- Interface: `WordPress\Filesystem\Filesystem`
- Implementations: `LocalFilesystem`, `InMemoryFilesystem`, `SQLiteFilesystem`, `UploadedFilesystem`

## Example: Local filesystem
```php
use WordPress\Filesystem\LocalFilesystem;

$fs = new LocalFilesystem(__DIR__);
$fs->mkdir('out');
$fs->put_contents('out/hello.txt', "Hello World\n");
$files = $fs->ls('out');            // ['hello.txt']
$data  = $fs->get_contents('out/hello.txt');
```

## Example: Stream a large file
```php
use WordPress\Filesystem\LocalFilesystem;

$fs = new LocalFilesystem('/data');
$r  = $fs->open_read_stream('big.bin');
while (! $r->reached_end_of_data()) {
  $chunk = $r->pull(8192);
  // process $chunk
}
$r->close_reading();
```

## Notes
- Exceptions derive from `WordPress\Filesystem\FilesystemException`
- Helper functions available in `functions.php` (e.g., `wp_join_unix_paths`)

