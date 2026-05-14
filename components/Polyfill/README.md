---
slug: polyfill
title: Polyfill
install: wp-php-toolkit/polyfill

credit_title: WordPress-shaped behavior
credit_body: |
  When WordPress is loaded, every function in this component defers to WordPress. The standalone implementations of <code>esc_html()</code>, <code>add_filter()</code>, <code>__()</code>, and friends match WordPress core's behavior so the same code runs inside and outside the platform.

see_also:
  - html | HTML | Run WordPress-shaped escaping and translation helpers beside HTML processors.
  - blockparser | BlockParser | Keep standalone block tooling familiar outside WordPress.
---

PHP 8 string functions on PHP 7.2+, WordPress hook stubs, and translation/escaping passthroughs so toolkit code runs without WordPress.

## Why this exists

<p>A lot of WordPress-adjacent code wants to call <code>esc_html()</code>, <code>__()</code>, or <code>apply_filters()</code> without booting WordPress. The polyfill component provides minimal but real implementations so that code runs unchanged outside WordPress, and stays out of the way when WordPress is loaded (every function uses <code>function_exists()</code> guards).</p>

## PHP 8 string functions on PHP 7.2

<p>The polyfills define <code>str_contains</code>, <code>str_starts_with</code>, <code>str_ends_with</code>, and <code>array_key_first</code> only when missing.</p>

<!-- snippet:
filename: php8-strings.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

var_dump( str_starts_with( '/var/www/html', '/var' ) );
var_dump( str_ends_with( 'image.png', '.png' ) );
var_dump( str_contains( 'WordPress Toolkit', 'Toolkit' ) );

$first_key = array_key_first( array( 'alpha' => 1, 'beta' => 2 ) );
echo "first key: {$first_key}\n";
```

<!-- expected-output -->
```
bool(true)
bool(true)
bool(true)
first key: alpha
```

## Escaping and translation stubs

<p>Pass-through implementations let you write code that looks WordPressy and runs anywhere.</p>

<!-- snippet:
filename: wp-stubs.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

echo __( 'Hello, world' ) . "\n";
echo esc_html( '<script>alert("xss")</script>' ) . "\n";
echo esc_attr( 'a "quoted" value' ) . "\n";
echo esc_url( 'https://example.com/?a=1&b=2' ) . "\n";
```

<!-- expected-output -->
```
Hello, world
&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;
a &quot;quoted&quot; value
https://example.com/?a=1&amp;b=2
```

## A simple filter chain

<p>The hook system is a real implementation of the WordPress filter API: registered callbacks get applied in priority order, and each one transforms the running value.</p>

<!-- snippet:
filename: filter-chain.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

add_filter( 'sanitize_title', 'trim' );
add_filter( 'sanitize_title', 'strtolower' );
add_filter( 'sanitize_title', function ( $title ) {
	return preg_replace( '/\s+/', '-', $title );
} );

echo apply_filters( 'sanitize_title', '  My Post Title  ' ) . "\n";
```

<!-- expected-output -->
```
my-post-title
```

## Priority ordering and multi-arg passing

<p>Lower priority numbers run first. The fourth argument to <code>add_filter</code> controls how many context values get passed to the callback.</p>

<!-- snippet:
filename: priority-args.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

add_filter( 'render_price', function ( $html, $price, $currency ) {
	return $html . " ({$currency} markup)";
}, 30, 3 );

add_filter( 'render_price', function ( $html, $price ) {
	return "<strong>{$html}</strong>";
}, 10, 2 );

add_filter( 'render_price', function ( $html, $price, $currency ) {
	if ( 'EUR' === $currency ) return $html . ' EUR';
	return $html . " {$currency}";
}, 20, 3 );

echo apply_filters( 'render_price', '19.99', 19.99, 'EUR' ) . "\n";
```

<!-- expected-output -->
```
<strong>19.99</strong> EUR (EUR markup)
```

## Hook-based extension points in standalone libraries

<p>Use <code>do_action</code> and <code>apply_filters</code> as cheap extension points in your own code, without depending on WordPress.</p>

<!-- snippet:
filename: library-hooks.php
runnable: true
-->
```php
<?php
require '/php-toolkit/vendor/autoload.php';

class ImportPipeline {
	public function process( array $row ) {
		$row = apply_filters( 'import_pipeline_normalize', $row );
		do_action( 'import_pipeline_row_processed', $row );
		return $row;
	}
}

add_filter( 'import_pipeline_normalize', function ( $row ) {
	$row['email'] = strtolower( trim( $row['email'] ) );
	return $row;
} );

$log = array();
add_action( 'import_pipeline_row_processed', function ( $row ) use ( &$log ) {
	$log[] = $row['email'];
} );

$pipeline = new ImportPipeline();
$pipeline->process( array( 'email' => '  USER@EXAMPLE.COM  ' ) );
$pipeline->process( array( 'email' => 'OTHER@example.com' ) );

echo implode( "\n", $log ) . "\n";
```

<!-- expected-output -->
```
user@example.com
other@example.com
```
