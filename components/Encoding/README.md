# Encoding

High‑performance UTF‑8 utilities for validation, code point counting, slicing, and decoding without requiring `mbstring`.

## Problems Solved
- Validate arbitrary byte streams as UTF‑8 and locate first error
- Count code points efficiently, even with invalid sequences
- Slice by code points and handle invalid sequences as U+FFFD

## Usage
```php
use function WordPress\Encoding\utf8_is_valid_byte_stream;
use function WordPress\Encoding\utf8_codepoint_count;
use function WordPress\Encoding\utf8_substr;

$ok = utf8_is_valid_byte_stream("Hello 🌎");            // true
$n  = utf8_codepoint_count("Aβ字🌟");                   // 4
$s  = utf8_substr("Aβ字🌟", 1, 2);                      // "β字"

// Find first error offset
$invalid = "Latin1 n\xF6t UTF-8"; // not valid
if (! utf8_is_valid_byte_stream($invalid, 0, $pos)) {
  // $pos is the byte offset of the first invalid sequence
}
```

All helpers live in `utf8-decoder.php` and `utf8-encoder.php`.

