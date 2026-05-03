# Encoding

<!-- docs-site-banner -->
> 📚 **Runnable examples:** [https://wordpress.github.io/php-toolkit/reference/encoding.html](https://wordpress.github.io/php-toolkit/reference/encoding.html)
> Open the page to edit each snippet in your browser and run it in WordPress Playground.
<!-- /docs-site-banner -->

Pure PHP utilities for UTF-8 validation, scrubbing, and conversion. This component detects invalid byte sequences, replaces them with the Unicode Replacement Character using the maximal subpart algorithm, and provides low-level tools for working with Unicode code points -- all without requiring the `mbstring` extension. When `mbstring` is available, the library delegates to it for better performance.

## Installation

```bash
composer require wp-php-toolkit/encoding
```

## Quick Start

```php
use function WordPress\Encoding\wp_is_valid_utf8;
use function WordPress\Encoding\wp_scrub_utf8;

// Validate a string
wp_is_valid_utf8( 'Hello, world!' ); // true
wp_is_valid_utf8( "invalid \xC0 byte" ); // false

// Replace invalid bytes with the replacement character
echo wp_scrub_utf8( "caf\xC0 latte" ); // "caf\xEF\xBF\xBD latte" (caf? latte)
```

## Usage

### Validating UTF-8

`wp_is_valid_utf8()` checks whether a byte string is well-formed UTF-8. It rejects overlong sequences, surrogate halves, bytes that are never valid in UTF-8, and incomplete multi-byte sequences.

```php
use function WordPress\Encoding\wp_is_valid_utf8;

// Valid UTF-8
wp_is_valid_utf8( '' );                  // true (empty string)
wp_is_valid_utf8( 'just a test' );       // true (plain ASCII)
wp_is_valid_utf8( "\xE2\x9C\x8F" );     // true (Pencil, U+270F)

// Invalid UTF-8
wp_is_valid_utf8( "just \xC0 test" );    // false (0xC0 is never valid)
wp_is_valid_utf8( "\xE2\x9C" );          // false (incomplete 3-byte sequence)
wp_is_valid_utf8( "\xC1\xBF" );          // false (overlong encoding)
wp_is_valid_utf8( "\xED\xB0\x80" );      // false (surrogate half U+DC00)
wp_is_valid_utf8( "B\xFCch" );           // false (ISO-8859-1 high byte)
```

### Scrubbing Invalid Bytes

`wp_scrub_utf8()` replaces ill-formed byte sequences with the Unicode Replacement Character (U+FFFD). It follows the "maximal subpart" algorithm recommended by the Unicode Standard for secure and interoperable string handling.

```php
use function WordPress\Encoding\wp_scrub_utf8;

// Valid strings pass through unchanged
wp_scrub_utf8( 'test' ); // "test"

// Single invalid byte becomes one replacement character
wp_scrub_utf8( ".\xC0." ); // ".\\xEF\\xBF\\xBD." (i.e., ".?.")

// Incomplete multi-byte sequence
wp_scrub_utf8( ".\xE2\x8C." ); // ".?."  (missing third byte)

// Each maximal subpart gets its own replacement character
wp_scrub_utf8( ".\xC1\xBF." ); // ".??." (overlong: two invalid subparts)

// Surrogate half U+D800 encoded as three bytes -- all three are invalid
wp_scrub_utf8( ".\xED\xA0\x80." ); // ".???."
```

### Detecting Noncharacters

`wp_has_noncharacters()` checks whether a string contains Unicode noncharacters -- code points that are permanently reserved and should not appear in open data interchange.

```php
use function WordPress\Encoding\wp_has_noncharacters;

// U+FFFE is a noncharacter
wp_has_noncharacters( "\xEF\xBF\xBE" ); // true

// Normal text
wp_has_noncharacters( 'Hello' ); // false
```

The noncharacter ranges are U+FDD0-U+FDEF, plus U+FFFE, U+FFFF, U+1FFFE, U+1FFFF, and so on through U+10FFFE, U+10FFFF.

### Converting Code Points to UTF-8

`codepoint_to_utf8_bytes()` encodes a Unicode code point number into its UTF-8 byte representation. Invalid code points (surrogate halves, values above U+10FFFF) produce the replacement character.

```php
use function WordPress\Encoding\codepoint_to_utf8_bytes;

echo codepoint_to_utf8_bytes( 0x41 );    // "A"
echo codepoint_to_utf8_bytes( 0x270F );  // "\xE2\x9C\x8F" (Pencil)
echo codepoint_to_utf8_bytes( 0x1F170 ); // "\xF0\x9F\x85\xB0" (Negative Squared Latin Capital Letter A)

// Invalid code points produce the replacement character
echo codepoint_to_utf8_bytes( 0xD83C );  // "\xEF\xBF\xBD" (surrogate half)
```

### Decoding UTF-8 to Code Points

`utf8_ord()` converts a single UTF-8 character (byte sequence) back to its Unicode code point number.

```php
use function WordPress\Encoding\utf8_ord;

echo utf8_ord( 'A' );                // 65 (0x41)
echo utf8_ord( "\xE2\x9C\x8F" );    // 9999 (0x270F, Pencil)
echo utf8_ord( "\xF0\x9F\x85\xB0" ); // 127344 (0x1F170)
```

### How the Fallback Works

When `mbstring` is available, `wp_is_valid_utf8()` delegates to `mb_check_encoding()` and `wp_scrub_utf8()` delegates to `mb_scrub()`. Without `mbstring`, the library uses a pure-PHP byte scanner (`_wp_scan_utf8()`) that validates byte sequences against the UTF-8 well-formedness table from the Unicode Standard. This fallback is fully conformant and handles all edge cases, including the maximal subpart algorithm for scrubbing.

The PCRE-based implementation of `wp_has_noncharacters()` is preferred when `PCRE/u` is available. Otherwise, a byte-level fallback scans the string directly.

## API Reference

### Functions

| Function | Description |
|---|---|
| `wp_is_valid_utf8( $bytes )` | Returns `true` if the string is well-formed UTF-8 |
| `wp_scrub_utf8( $text )` | Replaces invalid byte sequences with U+FFFD |
| `wp_has_noncharacters( $text )` | Returns `true` if the string contains Unicode noncharacters |
| `codepoint_to_utf8_bytes( $codepoint )` | Encodes a code point number to its UTF-8 byte sequence |
| `utf8_ord( $character )` | Decodes a UTF-8 character to its code point number |

## Attribution

The `wp_is_valid_utf8()`, `wp_scrub_utf8()`, and `wp_has_noncharacters()` functions originate from [WordPress core](https://github.com/WordPress/wordpress-develop). The pure PHP fallback scanner implements the UTF-8 well-formedness rules from the Unicode Standard. Licensed under GPL v2.

## Requirements

- PHP 7.2+
- No external dependencies (`mbstring` is used when available but is not required)
