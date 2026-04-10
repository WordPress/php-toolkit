# Polyfill

Provides polyfills for PHP functions and WordPress core APIs so that WordPress-adjacent code can run in standalone PHP applications without a full WordPress installation. It backports PHP 8.0 string functions to PHP 7.2, stubs common WordPress escaping and translation functions, and implements a minimal but functional WordPress hook system (`add_filter`/`apply_filters`/`add_action`/`do_action`).

## Installation

```bash
composer require wp-php-toolkit/polyfill
```

All polyfills are loaded automatically via Composer's `autoload.files` mechanism. No manual `require` or initialization is needed.

## Quick Start

```php
// After `composer require`, all polyfills are available globally.

// PHP 8.0 string functions work on PHP 7.2+:
str_starts_with( 'hello world', 'hello' ); // true
str_contains( 'hello world', 'world' );    // true
str_ends_with( 'hello world', 'world' );   // true

// WordPress functions work without WordPress:
$safe = esc_html( '<script>alert("xss")</script>' );
$text = __( 'Translatable string' ); // returns the string as-is

// WordPress hook system works standalone:
add_filter( 'the_title', 'strtoupper' );
$title = apply_filters( 'the_title', 'hello world' ); // 'HELLO WORLD'
```

## Usage

### PHP Function Polyfills

These functions are defined only when they do not already exist, so they are safe to use alongside PHP 8.0+ or other polyfill libraries.

```php
// str_starts_with (PHP 8.0+)
str_starts_with( '/var/www/html', '/var' ); // true
str_starts_with( '/var/www/html', '/tmp' ); // false

// str_ends_with (PHP 8.0+)
str_ends_with( 'image.png', '.png' ); // true
str_ends_with( 'image.png', '.jpg' ); // false

// str_contains (PHP 8.0+)
str_contains( 'WordPress Toolkit', 'Toolkit' ); // true
str_contains( 'WordPress Toolkit', 'Drupal' );  // false

// array_key_first (PHP 7.3+)
$data = array( 'alpha' => 1, 'beta' => 2 );
array_key_first( $data ); // 'alpha'
```

### WordPress Function Stubs

These stubs provide pass-through implementations of common WordPress functions. They allow code that calls WordPress APIs to run without modification in non-WordPress environments.

```php
// Translation: returns the input string unchanged.
echo __( 'Hello' ); // 'Hello'

// Escaping: applies htmlspecialchars().
echo esc_html( '<b>Bold</b>' );  // '&lt;b&gt;Bold&lt;/b&gt;'
echo esc_attr( 'a "quoted" value' ); // 'a &quot;quoted&quot; value'
echo esc_url( 'https://example.com/?a=1&b=2' );

// Error reporting stubs:
_doing_it_wrong( 'my_function', 'Use new_function() instead.', '2.0.0' );
// Stores messages in $GLOBALS['_doing_it_wrong_messages']

wp_trigger_error( 'my_function', 'Something went wrong', E_USER_NOTICE );
// Triggers a PHP notice. E_USER_ERROR throws a WP_Exception instead.
```

### WordPress Hook System

A minimal but fully functional implementation of the WordPress filter and action system. Hooks support priorities and multiple callbacks.

```php
// Filters transform a value through one or more callbacks.
add_filter( 'sanitize_title', 'strtolower' );
add_filter( 'sanitize_title', 'trim' );

$title = apply_filters( 'sanitize_title', '  My Post Title  ' );
// $title === 'my post title'

// Priorities control execution order (default is 10, lower runs first).
add_filter( 'the_content', 'first_callback', 5 );
add_filter( 'the_content', 'second_callback', 20 );

// Actions are hooks that do not return a value.
add_action( 'init', function () {
    // Perform initialization...
} );
do_action( 'init' );

// Actions can pass arguments to callbacks.
add_action( 'save_post', function ( $post_id ) {
    // React to a post being saved...
}, 10, 1 );
do_action( 'save_post', 42 );
```

### WordPress Classes

#### WP_Error

A minimal stub of the WordPress `WP_Error` class:

```php
$error = new WP_Error( 'not_found', 'The item was not found.', array( 'status' => 404 ) );
echo $error->code;    // 'not_found'
echo $error->message; // 'The item was not found.'
```

#### WP_Exception

Extends PHP's base `Exception` class. Used by `wp_trigger_error()` when called with `E_USER_ERROR`:

```php
try {
    wp_trigger_error( 'my_function', 'Fatal problem', E_USER_ERROR );
} catch ( WP_Exception $e ) {
    echo $e->getMessage(); // 'my_function(): Fatal problem'
}
```

### Block Parser and Serializer

When the `BlockParser` component is available, the polyfill provides `parse_blocks()` and `serialize_blocks()`:

```php
$html   = '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->';
$blocks = parse_blocks( $html );
$output = serialize_blocks( $blocks );
// $output === $html
```

### mbstring Polyfills

Safe encoding helpers for working with binary data when `mbstring.func_overload` is enabled:

```php
// Switch mbstring to binary-safe encoding.
mbstring_binary_safe_encoding();
$length = strlen( $binary_data ); // byte length, not character length
reset_mbstring_encoding();

// mb_str_split (PHP 7.4+)
$chars = mb_str_split( 'Hello', 1 ); // array( 'H', 'e', 'l', 'l', 'o' )
```

## API Reference

### PHP Function Polyfills

| Function | Polyfills | Description |
|----------|-----------|-------------|
| `str_starts_with( $haystack, $needle )` | PHP 8.0 | Check if string starts with substring |
| `str_ends_with( $haystack, $needle )` | PHP 8.0 | Check if string ends with substring |
| `str_contains( $haystack, $needle )` | PHP 8.0 | Check if string contains substring |
| `array_key_first( $array )` | PHP 7.3 | Get the first key of an array |

### mbstring Polyfills

| Function | Description |
|----------|-------------|
| `mbstring_binary_safe_encoding( $reset = false )` | Switch to binary-safe encoding |
| `reset_mbstring_encoding()` | Restore previous mbstring encoding |
| `mb_str_split( $string, $split_length, $encoding )` | Split a multibyte string into an array |

### WordPress Function Stubs

| Function | Description |
|----------|-------------|
| `__( $input )` | Translation stub (returns input unchanged) |
| `esc_attr( $input )` | Attribute escaping via `htmlspecialchars()` |
| `esc_html( $input )` | HTML escaping via `htmlspecialchars()` |
| `esc_url( $url )` | URL escaping via `htmlspecialchars()` |
| `add_filter( $hook, $callback, $priority, $accepted_args )` | Register a filter callback |
| `apply_filters( $hook, $value, ...$args )` | Apply all registered filter callbacks |
| `add_action( $hook, $callback, $priority, $accepted_args )` | Register an action callback |
| `do_action( $hook, ...$args )` | Execute all registered action callbacks |
| `parse_blocks( $input )` | Parse block markup into an array of blocks |
| `serialize_blocks( $blocks )` | Serialize an array of blocks back to markup |
| `_doing_it_wrong( $method, $message, $version )` | Log a developer notice |
| `wp_trigger_error( $function_name, $message, $error_level )` | Trigger a PHP error or throw `WP_Exception` |

### WordPress Classes

| Class | Description |
|-------|-------------|
| `WP_Error` | Minimal error container with `$code`, `$message`, and `$data` properties |
| `WP_Exception` | Exception subclass used by `wp_trigger_error()` |

## Requirements

- PHP 7.2+
- No external dependencies
