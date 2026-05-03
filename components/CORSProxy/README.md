---
slug: corsproxy
title: CORSProxy
install: wp-php-toolkit/corsproxy

see_also: httpclient | HttpClient | Fetch upstream responses from PHP when browser CORS blocks direct access.
see_also: httpserver | HttpServer | Understand the local-server shape before deploying a proxy endpoint.
---

A small PHP CORS proxy intended for browser-side code that needs to reach servers without CORS headers.

## Why this exists

<p>A Playground-style browser tool reads <code>https://api.github.com/repos/WordPress/php-toolkit</code>, a plugin ZIP from <code>downloads.wordpress.org</code>, or a raw fixture from GitHub. The browser blocks the response when the upstream server does not send the required CORS headers, even though PHP can fetch the same public URL server-side.</p>

<p>The CORSProxy component is that server-side bridge. It accepts a target URL, fetches it from PHP, and returns a browser-readable response. Because an open proxy is a security and abuse risk, real deployments should add host allowlists, rate limits, header controls, and private-network protections appropriate to their environment.</p>

## Run the proxy locally

<p class="callout"><strong>Run on your machine:</strong> the proxy needs to listen on a port. Start PHP's built-in server and request any HTTPS URL through it.</p>

<pre><code>PLAYGROUND_CORS_PROXY_DISABLE_RATE_LIMIT=1 \
  php -S 127.0.0.1:5263 vendor/wp-php-toolkit/corsproxy/cors-proxy.php

# In another terminal:
curl -s "http://127.0.0.1:5263/cors-proxy.php/https://api.github.com/repos/WordPress/php-toolkit" | head
</code></pre>

## Production rate limiting

<p>Drop a <code>cors-proxy-config.php</code> next to <code>cors-proxy.php</code>. If that file defines a <code>playground_cors_proxy_maybe_rate_limit()</code> function, the proxy calls it before forwarding any request — your one chance to reject early. Without the file, the proxy applies its default rate limiter, which is fine for development but should be replaced for any deployment that gets real traffic.</p>

<p>This example uses a per-IP token bucket stored on disk. Replace with Redis or memcached for multi-host deployments.</p>

<!-- snippet:
filename: cors-proxy-config.php
runnable: false
-->
```php
<?php
// cors-proxy-config.php — placed next to cors-proxy.php.

function playground_cors_proxy_maybe_rate_limit() {
	$ip      = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
	$bucket  = sys_get_temp_dir() . '/cors-rl-' . md5( $ip );
	$now     = time();
	$window  = 60;
	$max_req = 30;

	$hits = array();
	if ( file_exists( $bucket ) ) {
		$hits = json_decode( file_get_contents( $bucket ), true );
		if ( ! is_array( $hits ) ) $hits = array();
	}
	$hits = array_filter( $hits, function ( $t ) use ( $now, $window ) {
		return $t > $now - $window;
	} );

	if ( count( $hits ) >= $max_req ) {
		header( 'Retry-After: ' . $window );
		http_response_code( 429 );
		echo 'Rate limit exceeded';
		exit;
	}

	$hits[] = $now;
	file_put_contents( $bucket, json_encode( array_values( $hits ) ) );
}

echo "Config loaded — rate limiter armed.\n";
```

## Allowlist upstream hosts

<p>Out of the box the proxy will fetch any public URL. Most real deployments want a fixed list of upstreams — GitHub, Packagist, wp.org. Both the rate-limit logic and the allowlist live in the same hook, since <code>cors-proxy.php</code> only calls <code>playground_cors_proxy_maybe_rate_limit()</code> once. The example below shows just the allowlist concern; in practice you stack both in one function inside <code>cors-proxy-config.php</code>.</p>

<!-- snippet:
filename: cors-proxy-config-allowlist.php
runnable: false
-->
```php
<?php
// cors-proxy-config.php — combine with the rate-limit example above.

function playground_cors_proxy_maybe_rate_limit() {
	$allow = array(
		'api.github.com',
		'raw.githubusercontent.com',
		'codeload.github.com',
		'repo.packagist.org',
		'downloads.wordpress.org',
		'api.wordpress.org',
	);

	$target = isset( $_SERVER['PATH_INFO'] ) ? $_SERVER['PATH_INFO'] : ( '/' . ( isset( $_SERVER['QUERY_STRING'] ) ? $_SERVER['QUERY_STRING'] : '' ) );
	$target = ltrim( $target, '/' );
	$host   = parse_url( $target, PHP_URL_HOST );

	if ( ! $host || ! in_array( strtolower( $host ), $allow, true ) ) {
		http_response_code( 403 );
		header( 'Content-Type: text/plain' );
		echo "Upstream not allowed: " . ( $host ? $host : '(none)' );
		exit;
	}
}

echo "Allowlist config active.\n";
```

## Browser-side fetch through the proxy

<p>Once deployed, the client side is just <code>fetch()</code> with the proxy URL. Drop this into any HTML page.</p>

<pre><code>const PROXY = "https://cors.example.com/cors-proxy.php";

async function viaProxy(url, init = {}) {
  const res = await fetch(`${PROXY}/${url}`, {
    ...init,
    headers: {
      ...(init.headers || {}),
      "X-Cors-Proxy-Allowed-Request-Headers": "Authorization",
    },
  });
  if (!res.ok) throw new Error(`Proxy returned ${res.status}`);
  return res;
}

const repo = await viaProxy("https://api.github.com/repos/WordPress/php-toolkit").then(r =&gt; r.json());
console.log(repo.full_name, repo.stargazers_count);
</code></pre>

## Deploy behind nginx

<p>The proxy is a single PHP script — any SAPI works. nginx + php-fpm is a common production setup. <code>PATH_INFO</code> is what the proxy reads to learn the target URL.</p>

<pre><code>server {
  listen 443 ssl http2;
  server_name cors.example.com;

  root /var/www/cors-proxy;
  index cors-proxy.php;

  location ~ ^/cors-proxy\.php(/.*)?$ {
    fastcgi_pass unix:/run/php/php8.1-fpm.sock;
    fastcgi_split_path_info ^(.+\.php)(/.*)$;
    fastcgi_param SCRIPT_FILENAME $document_root/cors-proxy.php;
    fastcgi_param PATH_INFO $fastcgi_path_info;
    include fastcgi_params;
  }
}
</code></pre>
