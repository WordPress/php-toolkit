# CORSProxy

<!-- docs-site-banner -->
> 📚 **Runnable examples:** [https://wordpress.github.io/php-toolkit/reference/corsproxy.html](https://wordpress.github.io/php-toolkit/reference/corsproxy.html)
> Open the page to edit each snippet in your browser and run it in WordPress Playground.
<!-- /docs-site-banner -->

A PHP CORS proxy that lets browser-based JavaScript make cross-origin requests to external services. Built for WordPress Playground to bridge `fetch()` calls to git servers and other APIs that don't set CORS headers. The proxy streams data bidirectionally, blocks requests to private IP ranges, filters sensitive headers, and enforces size limits -- all without external dependencies.

## Installation

```
composer require wp-php-toolkit/corsproxy
```

## Quick Start

Deploy `cors-proxy.php` behind a web server. Clients make requests through the proxy by appending the target URL to the proxy's path:

```
GET https://your-server.com/cors-proxy.php/https://api.example.com/data
```

The proxy fetches `https://api.example.com/data`, streams the response back with CORS headers attached, and the browser's same-origin policy is satisfied.

## Usage

### Deployment

Place `cors-proxy.php` and `cors-proxy-functions.php` in a web-accessible directory. The proxy works with Apache, Nginx, or PHP's built-in development server.

For local development:

```bash
php -S 127.0.0.1:5263 cors-proxy.php
# Then request: http://127.0.0.1:5263/cors-proxy.php/https://w.org/
```

### Rate limiting

The proxy refuses to run without rate limiting configured. You must do one of the following:

1. **Define a rate-limiting function** in a `cors-proxy-config.php` file placed alongside `cors-proxy.php`:

```php
<?php
// cors-proxy-config.php
function playground_cors_proxy_maybe_rate_limit() {
    // Your rate limiting logic here.
    // Call http_response_code( 429 ) and exit if the limit is exceeded.
}
```

2. **Explicitly disable rate limiting** (development only):

```php
<?php
// cors-proxy-config.php
define( 'PLAYGROUND_CORS_PROXY_DISABLE_RATE_LIMIT', true );
```

Or via environment variable:

```bash
PLAYGROUND_CORS_PROXY_DISABLE_RATE_LIMIT=1 php -S 127.0.0.1:5263 cors-proxy.php
```

### Supported request methods

The proxy handles `GET`, `POST`, and `OPTIONS` (preflight) requests. All other HTTP methods return `405 Method Not Allowed`.

### Size limits

| Limit | Default |
|-------|---------|
| Maximum request body | 1 MB |
| Maximum response body | 100 MB |

Requests or responses exceeding these limits are rejected with `413`.

### Security features

**Private IP blocking.** The proxy resolves the target hostname and refuses to connect if it resolves to a private IP address. This prevents server-side request forgery (SSRF) attacks that would use the proxy to probe internal networks.

```php
// These are all blocked:
is_private_ip( '127.0.0.1' );      // true - loopback
is_private_ip( '192.168.1.1' );    // true - RFC 1918
is_private_ip( '10.0.0.1' );       // true - RFC 1918
is_private_ip( '172.16.0.1' );     // true - RFC 1918
is_private_ip( '::1' );            // true - IPv6 loopback
is_private_ip( 'fe80::' );         // true - IPv6 link-local

// Public IPs are allowed:
is_private_ip( '8.8.8.8' );        // false
is_private_ip( '204.79.197.200' ); // false
```

IPv4 and IPv6 private ranges are both covered, including loopback, link-local, carrier-grade NAT, documentation ranges, and multicast addresses.

**Header filtering.** The proxy strips `Cookie` and `Host` headers from forwarded requests. The `Authorization` header requires explicit opt-in through the `X-Cors-Proxy-Allowed-Request-Headers` request header:

```php
$filtered = filter_headers_by_name(
    array(
        'Accept'        => 'application/json',
        'Content-Type'  => 'application/json',
        'Cookie'        => 'session=abc',
        'Host'          => 'example.com',
        'Authorization' => 'Bearer token123',
    ),
    array( 'Cookie', 'Host' ),        // always stripped
    array( 'Authorization' )           // requires opt-in
);
// Result: ['Accept' => 'application/json', 'Content-Type' => 'application/json']
// Authorization was stripped because the client did not send
// X-Cors-Proxy-Allowed-Request-Headers: Authorization
```

**URL validation.** Target URLs are validated for scheme (only `http` and `https`), checked for embedded credentials, and verified not to point back at the proxy server itself.

### Redirect handling

When the target server returns a redirect, the proxy rewrites the `Location` header so the client follows the redirect back through the proxy:

```php
$rewritten = rewrite_relative_redirect(
    'https://w.org/hosting',                              // original request
    '/hosting/',                                           // redirect location
    'https://cors.example.com/proxy.php'                  // proxy URL
);
// Result: "https://cors.example.com/proxy.php?https://w.org/hosting/"
```

This works for both relative and absolute redirects.

### Extracting the target URL

The proxy extracts the target URL from either `PATH_INFO` or `QUERY_STRING`:

```php
// PATH_INFO style:
// GET /cors-proxy.php/https://example.com
get_target_url( array( 'PATH_INFO' => '/https://example.com' ) );
// Returns: "https://example.com"

// Query string style:
// GET /cors-proxy.php?https://example.com
get_target_url( array( 'QUERY_STRING' => 'https://example.com' ) );
// Returns: "https://example.com"
```

### CORS headers

CORS response headers are added for requests originating from:

- `https://playground.wordpress.net` (when the proxy is hosted elsewhere)
- `localhost` or `127.0.0.1` (for local development)

The proxy responds to `OPTIONS` preflight requests with appropriate `Access-Control-Allow-*` headers.

## API Reference

### Functions

| Function | Purpose |
|----------|---------|
| `get_target_url( $server_data )` | Extracts the target URL from `$_SERVER` (or a custom array). Returns the URL string or `false`. |
| `get_current_script_uri( $target_url, $request_uri )` | Returns the proxy's own URI prefix (everything before the target URL in the request). |
| `url_validate_and_resolve( $url, $resolve_function )` | Validates a URL (scheme, no credentials, no private IPs) and resolves the hostname. Returns `array( 'host' => ..., 'ip' => ... )` or throws `CorsProxyException`. |
| `is_private_ip( $ip )` | Returns `true` if the IP address falls within any private, loopback, link-local, or reserved range. Supports both IPv4 and IPv6. |
| `filter_headers_by_name( $headers, $disallowed, $opt_in )` | Filters an associative array of headers, removing disallowed ones and enforcing opt-in for sensitive headers. |
| `rewrite_relative_redirect( $request_url, $redirect_location, $proxy_url )` | Rewrites a redirect `Location` to route back through the proxy. |
| `should_respond_with_cors_headers( $host, $origin )` | Returns `true` if the given origin should receive CORS response headers. |

### Classes

| Class | Purpose |
|-------|---------|
| `IpUtils` | Static methods for private IP detection: `isPrivateIp( $ip )`. Covers RFC 1918, RFC 4193, loopback, link-local, carrier-grade NAT, and more. |
| `CorsProxyException` | Thrown when URL validation fails (invalid scheme, private IP, unresolvable hostname, etc.). |

## Requirements

- PHP 7.2+
- `curl` extension (for proxying HTTP requests)
- No other external dependencies
