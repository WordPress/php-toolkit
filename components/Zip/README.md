# Zip

Read ZIP archives as a virtual read‑only filesystem and encode/decode ZIP structures as streams. Pairs with `ByteStream` and `Filesystem` for efficient large‑file handling.

## Problems Solved
- List and stream files within a ZIP without extracting
- Detect ZIPs from a byte stream
- Read central directory efficiently with safety limits

## Usage: Read a ZIP file
```php
use WordPress\Zip\ZipFilesystem;
use WordPress\ByteStream\ReadStream\FileReadStream;

$stream = FileReadStream::from_path(__DIR__ . '/archive.zip');
$zipFs  = ZipFilesystem::create($stream); // returns a chrooted Filesystem

$entries = $zipFs->ls('/');
if ($zipFs->is_file('docs/readme.txt')) {
  $r = $zipFs->open_read_stream('docs/readme.txt');
  $content = '';
  while (! $r->reached_end_of_data()) {
    $content .= $r->pull(4096);
  }
}
```

## Utility: Detect ZIP stream
```php
use function WordPress\Zip\is_zip_file_stream;
use WordPress\ByteStream\ReadStream\FileReadStream;

$s = FileReadStream::from_path('maybe.zip');
if (is_zip_file_stream($s)) {
  // handle as ZIP
}
```

## Notes
- `ZipFilesystem` is read‑only by design
- Large central directories are guarded by a maximum size to avoid memory issues

