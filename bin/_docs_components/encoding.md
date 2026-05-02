---
slug: encoding
title: Encoding
install: wp-php-toolkit/encoding
---

UTF-8 validation and scrubbing with a pure-PHP fallback when <code>mbstring</code> is unavailable. Detects malformed bytes and replaces them per the Unicode maximal-subpart algorithm.

## Why this exists

<p>Every parser in this toolkit eventually has to decide what to do with text bytes. XML rejects malformed UTF-8. JSON and databases can fail late. CSS, HTML, WXR, and Blueprint validation all need consistent answers about whether a string is well-formed Unicode.</p>

<p>The Encoding component provides the small UTF-8 primitives the rest of the toolkit can share: validate bytes, scrub invalid sequences, scan code points, and detect Unicode noncharacters. When <code>mbstring</code> is available it can delegate to it; when it is not, the component uses its own byte scanner so behavior stays available in restricted PHP environments.</p>

<p>Historically, this became the common foundation for Blueprint validation and CSS/XML processing, replacing ad hoc Unicode helpers with the WordPress core UTF-8 routines used here.</p>

## Validating UTF-8 before storing it

<p><code>wp_is_valid_utf8()</code> rejects overlong sequences, surrogate halves, and stray ISO-8859-1 bytes. Use it as a guard in front of any code path that assumes UTF-8 (database, JSON, XML).</p>

<!-- snippet:
filename: validate.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use function WordPress\Encoding\wp_is_valid_utf8;

$samples = array(
	'ASCII'          => 'just a test',
	'UTF-8 pencil'   => "\xE2\x9C\x8F",
	'latin-1 byte'   => "B\xFCch",
	'overlong slash' => "\xC1\xBF",
	'surrogate half' => "\xED\xB0\x80",
);

foreach ( $samples as $label => $bytes ) {
	echo sprintf( "%-14s %s\n", $label . ':', wp_is_valid_utf8( $bytes ) ? 'valid' : 'invalid' );
}
```

## Scrubbing invalid bytes with U+FFFD

<p>Replace each ill-formed sequence with the Unicode replacement character. Useful right before serializing to XML, JSON, or sending to an LLM that will choke on broken bytes.</p>

<!-- snippet:
filename: scrub.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use function WordPress\Encoding\wp_scrub_utf8;

$broken = "the byte \xC0 should not be here.";
echo wp_scrub_utf8( $broken ) . "\n";

echo wp_scrub_utf8( ".\xE2\x8C\xE2\x8C." ) . "\n";
```

## Detecting noncharacters MySQL/utf8mb4 will reject

<p>Code points like U+FFFE, U+FFFF, and the U+FDD0–U+FDEF block are valid Unicode but forbidden in XML and rejected by some databases. Check before inserting user-submitted content into a strict <code>utf8mb4</code> column.</p>

<!-- snippet:
filename: noncharacters.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use function WordPress\Encoding\wp_has_noncharacters;

$samples = array(
	'normal text' => 'normal text',
	'U+FFFE'      => "oops \u{FFFE}",
	'U+FDD0'      => "hi \u{FDD0} bye",
);

foreach ( $samples as $label => $text ) {
	echo sprintf( "%-12s %s\n", $label . ':', wp_has_noncharacters( $text ) ? 'reject' : 'ok' );
}
```

## Three-way pipeline: validate, scrub, then check noncharacters

<p>Real-world inputs are messy: an old WXR export, a CSV with mixed encodings, a paste from Word. Combination of validate + scrub + noncharacter-check covers the three classes of breakage that bite later.</p>

<!-- snippet:
filename: pipeline.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use function WordPress\Encoding\wp_is_valid_utf8;
use function WordPress\Encoding\wp_scrub_utf8;
use function WordPress\Encoding\wp_has_noncharacters;

$inputs = array(
	'good'      => 'Café',
	'latin1'    => "caf\xE9",
	'overlong'  => "x\xC1\xBFy",
	'noncharac' => "hi \u{FFFE} there",
);

foreach ( $inputs as $label => $bytes ) {
	$valid    = wp_is_valid_utf8( $bytes );
	$cleaned  = wp_scrub_utf8( $bytes );
	$weird    = wp_has_noncharacters( $cleaned );
	echo sprintf( "%-10s valid=%s noncharacter=%s -> %s\n", $label, $valid ? 'Y' : 'N', $weird ? 'Y' : 'N', $cleaned );
}
```

## Salvaging a legacy ISO-8859-1 column inside a UTF-8 corpus

<p>Old WordPress databases sometimes mix encodings: most rows are UTF-8 but a few were stored as latin-1. Detect the bad rows with <code>wp_is_valid_utf8()</code> and only re-encode those.</p>

<!-- snippet:
filename: mixed-encoding.php
runnable: true
-->
```php
<?php
require '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

use function WordPress\Encoding\wp_is_valid_utf8;
use function WordPress\Encoding\wp_scrub_utf8;

$rows = array(
	1 => 'Plain ASCII',
	2 => 'Café',
	3 => "caf\xE9",
	4 => "weird \xC0 byte",
);

foreach ( $rows as $id => $value ) {
	if ( wp_is_valid_utf8( $value ) ) {
		echo "#$id ok: $value\n";
		continue;
	}
	$converted = @iconv( 'ISO-8859-1', 'UTF-8', $value );
	if ( false !== $converted && wp_is_valid_utf8( $converted ) ) {
		echo "#$id recovered as latin1: $converted\n";
	} else {
		echo "#$id unrecoverable, scrubbing: " . wp_scrub_utf8( $value ) . "\n";
	}
}
```
