# ToolkitCodingStandards

Custom PHP_CodeSniffer sniffs used internally by the PHP Toolkit project. This component provides two sniffs that enforce WordPress-style coding conventions: one requires Yoda-style comparisons (literal on the left side of `===`), and the other forbids the short ternary (Elvis) operator `?:`. Both sniffs support automatic fixing via `phpcbf`.

This is internal tooling for the toolkit's own linter pipeline, not a general-purpose coding standard.

## Installation

```bash
composer require wp-php-toolkit/toolkit-coding-standards
```

In practice this component is used through the toolkit's root `composer.json` configuration. It is referenced alongside the main phpcs ruleset in `.phpcs.xml.dist`.

## Usage

### Adding to a PHPCS Configuration

Reference the coding standard in your `phpcs.xml` or `.phpcs.xml.dist` file:

```xml
<?xml version="1.0"?>
<ruleset>
    <!-- Other rules... -->
    <rule ref="WordPressToolkitCodingStandards"/>
</ruleset>
```

Or enable individual sniffs selectively:

```xml
<rule ref="WordPressToolkitCodingStandards.PHP.EnforceYodaComparison"/>
<rule ref="WordPressToolkitCodingStandards.PHP.DisallowShortTernary"/>
```

### Running the Linter

From the toolkit root:

```bash
# Check for violations
composer lint

# Auto-fix violations
composer lint-fix
```

Or directly with phpcs/phpcbf:

```bash
vendor/bin/phpcs -d memory_limit=1G --standard=WordPressToolkitCodingStandards .
vendor/bin/phpcbf -d memory_limit=1G --standard=WordPressToolkitCodingStandards .
```

### Sniff: EnforceYodaComparison

Requires Yoda-style comparisons where the literal or constant value is placed on the left side of a comparison operator. This prevents accidental assignment (`=` instead of `===`) and follows WordPress coding standards.

```php
// Wrong -- variable on the left:
if ( $value === true ) { /* ... */ }
if ( $name === 'admin' ) { /* ... */ }
if ( $count === 0 ) { /* ... */ }

// Correct -- literal on the left (Yoda style):
if ( true === $value ) { /* ... */ }
if ( 'admin' === $name ) { /* ... */ }
if ( 0 === $count ) { /* ... */ }
```

When both sides are dynamic expressions (function calls, variables, etc.), the sniff does not report an error since neither side is "more constant" than the other:

```php
// Both sides are dynamic -- no error:
if ( get_option( 'a' ) === get_option( 'b' ) ) { /* ... */ }
```

The sniff applies to `===`, `!==`, `==`, and `!=` operators.

### Sniff: DisallowShortTernary

Forbids the short ternary (Elvis) operator `?:` and auto-fixes it to a full ternary by duplicating the condition:

```php
// Wrong -- short ternary:
$name = $input ?: 'default';

// Auto-fixed to full ternary:
$name = $input ? $input : 'default';
```

The WordPress coding standards discourage the short ternary because it is often used incorrectly and can reduce readability.

## API Reference

### Sniff Classes

| Class | Code | Description |
|-------|------|-------------|
| `EnforceYodaComparisonSniff` | `DisallowedYodaComparison` | Enforces Yoda-style comparisons (literal on left) |
| `DisallowShortTernarySniff` | `ShortTernaryUsed` | Forbids the Elvis operator `?:`, auto-fixes to full ternary |

### Ruleset

The `WordPressToolkitCodingStandards/ruleset.xml` file registers both sniffs. Including the standard by name activates both rules at once.

### Dependencies

These sniffs extend helpers from the `SlevomatCodingStandard` package:

- `SlevomatCodingStandard\Helpers\YodaHelper` -- used by the Yoda comparison sniff for dynamism analysis and auto-fixing
- `SlevomatCodingStandard\Helpers\TernaryOperatorHelper` -- used by the short ternary sniff to locate operand boundaries
- `SlevomatCodingStandard\Helpers\TokenHelper` and `FixerHelper` -- token navigation and fixer utilities

## Requirements

- PHP 7.2+
- PHP_CodeSniffer 3.x (dev dependency, provided by the toolkit root)
- slevomat/coding-standard (dev dependency, provided by the toolkit root)
- No runtime external dependencies
