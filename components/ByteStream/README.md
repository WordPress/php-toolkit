# ByteStream

Composable byte stream primitives for efficient, incremental I/O. Provides read/write streams, in‑memory pipes, and transformers (deflate/inflate, checksums) with consistent pull/push semantics.

## Problems Solved
- Stream large files without buffering entire content in memory
- Compose transformations (e.g., gzip/deflate) while reading/writing
- Uniform interface for file, memory, and transformed streams

## Key Types
- `ReadStream` classes: `FileReadStream`, `ByteReadStream`, `InflateReadStream`, `DeflateReadStream`, etc.
- `WriteStream` classes: `FileWriteStream`, `TransformedWriteStream`
- `BytePipe`/`MemoryPipe` for in‑memory streaming

## Read Example
```php
use WordPress\ByteStream\ReadStream\FileReadStream;

$s = FileReadStream::from_path(__DIR__ . '/large.bin');
while (! $s->reached_end_of_data()) {
    $chunk = $s->pull(8192);
    // process $chunk
}
$s->close_reading();
```

## Transform Example (inflate)
```php
use WordPress\ByteStream\ReadStream\InflateReadStream;
use WordPress\ByteStream\ReadStream\FileReadStream;

$compressed = FileReadStream::from_path('file.deflate');
$stream     = new InflateReadStream($compressed);
$data       = '';
while (! $stream->reached_end_of_data()) {
    $data .= $stream->pull(4096);
}
```

## Notes
- Exceptions derive from `WordPress\ByteStream\ByteStreamException`
- Streams expose `length()` when known and support seeking where applicable

