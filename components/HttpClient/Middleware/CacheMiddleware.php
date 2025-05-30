<?php

namespace WordPress\HttpClient\Middleware;

use RuntimeException;
use WordPress\HttpClient\Client;
use WordPress\HttpClient\Request;
use WordPress\HttpClient\Response;

final class CacheMiddleware implements MiddlewareInterface {
	/**
	 * @var MiddlewareInterface
	 */
	private $next_middleware;
	
	/**
	 * @var ClientState
	 */
	private $state;
	
	private $dir;

	/** @var array<string,array{req:Request,meta:array,file:resource|null,headerDone:bool,done:bool}> */
	private $replay = [];

	/** writers keyed by spl_object_hash(req) */
	private $tempHandle = [];
	private $tempPath = [];

	public function __construct( $client_state, $next_middleware, $options = array() ) {
		$this->next_middleware = $next_middleware;
		$this->state = $client_state;
		$this->dir = rtrim( $options['cache_dir'], '/' );
		
		if ( ! is_dir( $this->dir ) ) {
			throw new RuntimeException( "Cache dir {$this->dir} does not exist or is not a directory" );
		}
	}

	public function enqueue( Request $request ) {
		$meth = strtoupper( $request->method );
		if ( ! in_array( $meth, [ 'GET', 'HEAD' ], true ) ) {
			$this->invalidateCache( $request );
			return $this->next_middleware->enqueue( $request );
		}
		
		[ $key, $meta ] = $this->lookup( $request );
		$request->cache_key = $key;
		
		if ( $meta && $this->fresh( $meta ) ) {
			$this->startReplay( $request, $meta );
			return;
		}
		
		if ( $meta ) {
			$this->addValidators( $request, $meta );
		}
		
		return $this->next_middleware->enqueue( $request );
	}

	public function await_next_event( $requests_ids ): bool {
		/* serve cached replay first */
		foreach ( $this->replay as $id => $context ) {
			if ( $context['done'] ) {
				fclose( $context['file'] );
				unset( $this->replay[ $id ] );
				continue;
			}
			$this->fromCache( $id );
			return true;
		}
		
		/* drive next middleware */
		if ( ! $this->next_middleware->await_next_event( $requests_ids ) ) {
			return false;
		}
		
		return $this->handleNetwork();
	}

	/*============ CACHE REPLAY ============*/
	private function startReplay( Request $request, array $meta ): void {
		$id                  = spl_object_hash( $request );
		$file_handle         = fopen( $this->bodyPath( $request->cache_key, $request->url ), 'rb' );
		$this->replay[ $id ] = [
			'req'        => $request,
			'meta'       => $meta,
			'file'       => $file_handle,
			'headerDone' => false,
			'done'       => false,
		];
	}

	private function start304Replay( Request $request, array $meta ): void {
		$id                  = spl_object_hash( $request );
		$file_handle         = fopen( $this->bodyPath( $request->cache_key, $request->url ), 'rb' );
		$this->replay[ $id ] = [
			'req'        => $request,
			'meta'       => $meta,
			'file'       => $file_handle,
			'headerDone' => false, // Still emit headers for 304 replay
			'done'       => false,
			'is304'      => true, // Mark as 304 replay
		];
	}

	private function fromCache( string $id ): void {
		$context       =& $this->replay[ $id ];
		$request = $context['req'];
		
		if ( ! $context['headerDone'] ) {
			$resp                  = new Response( $request );
			// For 304 replays, return 200 status with cached headers
			if ( isset( $context['is304'] ) && $context['is304'] ) {
				$resp->status_code = 200; // Convert 304 to 200 for the client
			} else {
				$resp->status_code = $context['meta']['status'];
			}
			$resp->headers         = $context['meta']['headers'];
			
			$request->response = $resp;
			$this->state->event = Client::EVENT_GOT_HEADERS;
			$this->state->request = $request;
			
			$context['headerDone'] = true;
			return;
		}
		
		$chunk = fread( $context['file'], 64 * 1024 );
		if ( $chunk !== '' && $chunk !== false ) {
			$this->state->event = Client::EVENT_BODY_CHUNK_AVAILABLE;
			$this->state->request = $request;
			$this->state->response_body_chunk = $chunk;
			return;
		}
		
		$context['done'] = true;
		$this->state->event = Client::EVENT_FINISHED;
		$this->state->request = $request;
		
		// For 304 replays, we don't want to override the response
		if ( ! isset( $context['is304'] ) || ! $context['is304'] ) {
			$request->response = null;
		}
	}

	/*============ NETWORK HANDLING ============*/
	private function handleNetwork(): bool {
		$event   = $this->state->event;
		$request = $this->state->request;
		$response = $request->response;
		
		/* HEADERS */
		if ( $event === Client::EVENT_GOT_HEADERS ) {
			if ( $response->status_code === 304 && isset( $request->cache_key ) ) {
				[ , $meta ] = $this->lookup( $request, $request->cache_key );
				if ( $meta ) {
					// For 304, start a special replay that serves cached body
					$this->start304Replay( $request, $meta );
					return true;
				}
			}
			if ( $this->cacheable( $response ) ) {
				// Update cache key based on vary headers if present
				$vary = $response->get_header( 'Vary' );
				if ( $vary ) {
					$vary_keys = array_map( 'trim', explode( ',', $vary ) );
					$request->cache_key = $this->varyKey( $request, $vary_keys );
				}
				
				$tmp = $this->tempPath( $request->cache_key );

				$this->tempPath[ spl_object_hash( $request ) ]   = $tmp;
				$this->tempHandle[ spl_object_hash( $request ) ] = fopen( $tmp, 'wb' );
			}
			return true;
		}
		/* BODY */
		if ( $event === Client::EVENT_BODY_CHUNK_AVAILABLE ) {
			$chunk = $this->state->response_body_chunk;
			$hash  = spl_object_hash( $request );
			if ( isset( $this->tempHandle[ $hash ] ) ) {
				fwrite( $this->tempHandle[ $hash ], $chunk );
			}
			return true;
		}
		/* FINISH */
		if ( $event === Client::EVENT_FINISHED ) {
			$hash = spl_object_hash( $request );
			if ( isset( $this->tempHandle[ $hash ] ) ) {
				fclose( $this->tempHandle[ $hash ] );
				$this->commit( $request, $response, $this->tempPath[ $hash ] );
				unset( $this->tempHandle[ $hash ], $this->tempPath[ $hash ] );
			}
			return true;
		}
		
		return true;
	}

	/*============ CACHE UTILITIES ============*/
	private function metaPath( string $key, ?string $url = null ): string {
		if ( $url ) {
			$url_hash = sha1( $url );
			return "$this->dir/{$url_hash}_{$key}.json";
		}
		return "$this->dir/{$key}.json";
	}

	private function bodyPath( string $key, ?string $url = null ): string {
		if ( $url ) {
			$url_hash = sha1( $url );
			return "$this->dir/{$url_hash}_{$key}.body";
		}
		return "$this->dir/{$key}.body";
	}

	private function tempPath( string $key ): string {
		return "$this->dir/{$key}.tmp";
	}

	private function varyKey( Request $request, ?array $vary_keys ): string {
		$parts = [ $request->url ];
		if ( $vary_keys ) {
			// Build a lowercased map of headers for case-insensitive lookup
			$header_map = [];
			foreach ($request->headers as $k => $v) {
				$header_map[strtolower($k)] = $v;
			}
			foreach ( $vary_keys as $header_name ) {
				$header_lc = strtolower( $header_name );
				$header_value = $header_map[$header_lc] ?? '';
				$parts[] = $header_lc . ':' . $header_value;
			}
		}
		return sha1( implode( '|', $parts ) );
	}

	/** @return array{string,array|null} */
	public function lookup( Request $request, ?string $forced = null ): array {
		if ( $forced && is_file( $this->metaPath( $forced, $request->url ) ) ) {
			return [ $forced, json_decode( file_get_contents( $this->metaPath( $forced, $request->url ) ), true ) ];
		}
		
		// Look for cache files that match this URL
		$url_hash = sha1( $request->url );
		$glob = glob( $this->dir . '/' . $url_hash . '_*.json' );
		foreach ( $glob as $meta_path ) {
			$meta = json_decode( file_get_contents( $meta_path ), true );
			$expected_key = $this->varyKey( $request, $meta['vary'] ?? [] );
			$actual_filename = basename( $meta_path, '.json' );
			$expected_filename = $url_hash . '_' . $expected_key;
			
			if ( $actual_filename === $expected_filename ) {
				return [ $expected_key, $meta ];
			}
		}

		return [ $this->varyKey( $request, null ), null ];
	}

	private function fresh( array $meta ): bool {
		$now = time();

		// Check for must-revalidate directive - if present, never consider fresh without explicit expiry
		$cache_control = $meta['headers']['cache-control'] ?? '';
		$directives = self::directives( $cache_control );
		if ( isset( $directives['must-revalidate'] ) ) {
			// With must-revalidate, only consider fresh if we have explicit expiry info
			if ( isset( $meta['max_age'] ) && isset( $meta['stored_at'] ) ) {
				$fresh = ($meta['stored_at'] + (int)$meta['max_age']) > $now;
				return $fresh;
			}
			if ( isset( $meta['s_maxage'] ) && isset( $meta['stored_at'] ) ) {
				$fresh = ($meta['stored_at'] + (int)$meta['s_maxage']) > $now;
				return $fresh;
			}
			if ( isset( $meta['expires'] ) ) {
				$expires = is_numeric( $meta['expires'] ) ? (int)$meta['expires'] : strtotime( $meta['expires'] );
				if ( $expires !== false ) {
					$fresh = $expires > $now;
					return $fresh;
				}
			}
			// With must-revalidate, don't use heuristic caching
			return false;
		}

		// If explicit expiry timestamp is set, use it
		if ( isset( $meta['expires'] ) ) {
			$expires = is_numeric( $meta['expires'] ) ? (int)$meta['expires'] : strtotime( $meta['expires'] );
			if ( $expires !== false ) {
				$fresh = $expires > $now;
				return $fresh;
			}
		}

		// If explicit TTL (absolute timestamp) is set, use it
		if ( isset( $meta['ttl'] ) ) {
			if ( is_numeric( $meta['ttl'] ) ) {
				$fresh = (int)$meta['ttl'] > $now;
				return $fresh;
			}
		}

		// If s-maxage is set, check if still valid (takes precedence over max-age)
		if ( isset( $meta['s_maxage'] ) && isset( $meta['stored_at'] ) ) {
			$fresh = ($meta['stored_at'] + (int)$meta['s_maxage']) > $now;
			return $fresh;
		}

		// If max_age is set, check if still valid
		if ( isset( $meta['max_age'] ) && isset( $meta['stored_at'] ) ) {
			$fresh = ($meta['stored_at'] + (int)$meta['max_age']) > $now;
			return $fresh;
		}

		// Heuristic: if Last-Modified is present, cache for 10% of its age at storage time
		if ( isset( $meta['last_modified'] ) && isset( $meta['stored_at'] ) ) {
			$lm = is_numeric( $meta['last_modified'] ) ? (int)$meta['last_modified'] : strtotime( $meta['last_modified'] );
			if ( $lm !== false ) {
				$age = $meta['stored_at'] - $lm;
				$heuristic_lifetime = (int) max( 0, $age / 10 );
				$fresh = ($meta['stored_at'] + $heuristic_lifetime) > $now;
				return $fresh;
			}
		}

		// Not fresh by any rule
		return false;
	}

	private function cacheable( Response $response ): bool {
		return self::response_is_cacheable( $response );
	}

	private function addValidators( Request $request, array $meta ): void {
		if ( ! empty( $meta['etag'] ) ) {
			$request->headers['if-none-match'] = $meta['etag'];
		}
		if ( ! empty( $meta['last_modified'] ) ) {
			$request->headers['if-modified-since'] = $meta['last_modified'];
		}
	}

	protected function commit( Request $request, Response $response, string $tempFile ) {
		$url   = $request->url;
		$meta = [
			'url' => $url,
			'status' => $response->status_code,
			'headers' => $response->headers,
			'stored_at' => time(),
			'etag' => $response->get_header( 'ETag' ),
			'last_modified' => $response->get_header( 'Last-Modified' ),
		];
		
		// Check for Vary header and store vary keys
		$vary = $response->get_header( 'Vary' );
		if ( $vary ) {
			$meta['vary'] = array_map( 'trim', explode( ',', $vary ) );
		}
		
		// Parse Cache-Control for max-age, if present
		$cacheControl = $response->get_header( 'Cache-Control' );
		if ( $cacheControl ) {
			$directives = self::directives( $cacheControl );
			if ( isset( $directives['max-age'] ) && is_int( $directives['max-age'] ) ) {
				$meta['max_age'] = $directives['max-age'];
			}
			if ( isset( $directives['s-maxage'] ) && is_int( $directives['s-maxage'] ) ) {
				$meta['s_maxage'] = $directives['s-maxage'];
			}
		}

		// Determine file paths
		$key      = $request->cache_key;
		$bodyFile = $this->bodyPath( $key, $url );
		$metaFile = $this->metaPath( $key, $url );

		// Atomically replace/rename the temp body file to final cache file
		if ( ! rename( $tempFile, $bodyFile ) ) {
			// Handle error (e.g., log failure and abort caching)
			return;
		}

		// Write metadata with exclusive lock
		$fp = fopen( $metaFile, 'c' );
		if ( $fp ) {
			flock( $fp, LOCK_EX );
			ftruncate( $fp, 0 );
			// Serialize or encode CacheEntry (e.g., JSON)
			$metaData = json_encode( $meta );
			fwrite( $fp, $metaData );
			fflush( $fp );
			flock( $fp, LOCK_UN );
			fclose( $fp );
		}
	}

	public function invalidateCache( Request $request ): void {
		// Generate cache key if not already set
		if ( ! isset( $request->cache_key ) ) {
			[ $key, ] = $this->lookup( $request );
			$request->cache_key = $key;
		}
		
		$key      = $request->cache_key;
		$bodyFile = $this->bodyPath( $key, $request->url );
		$metaFile = $this->metaPath( $key, $request->url );

		// Optionally, acquire lock on meta file to prevent concurrent writes
		if ( $fp = @fopen( $metaFile, 'c' ) ) {
			flock( $fp, LOCK_EX );
		}
		// Delete cache files if they exist
		@unlink( $bodyFile );
		@unlink( $metaFile );
		// Also remove any temp files for this entry
		foreach ( glob( $bodyFile . '.tmp*' ) as $tmp ) {
			@unlink( $tmp );
		}
		if ( isset( $fp ) && $fp ) {
			flock( $fp, LOCK_UN );
			fclose( $fp );
		}
	}


	/** return ['no-store'=>true, 'max-age'=>60, …] */
	public static function directives( ?string $value ): array {
		if ( $value === null ) {
			return [];
		}
		$out = [];
		
		// Handle quoted values properly by not splitting on commas inside quotes
		$parts = [];
		$current = '';
		$in_quotes = false;
		$quote_char = null;
		
		for ( $i = 0; $i < strlen( $value ); $i++ ) {
			$char = $value[ $i ];
			
			if ( ! $in_quotes && ( $char === '"' || $char === "'" ) ) {
				$in_quotes = true;
				$quote_char = $char;
				$current .= $char;
			} elseif ( $in_quotes && $char === $quote_char ) {
				$in_quotes = false;
				$quote_char = null;
				$current .= $char;
			} elseif ( ! $in_quotes && $char === ',' ) {
				$parts[] = trim( $current );
				$current = '';
			} else {
				$current .= $char;
			}
		}
		
		if ( $current !== '' ) {
			$parts[] = trim( $current );
		}
		
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( $part === '' ) {
				continue;
			}
			if ( strpos( $part, '=' ) !== false ) {
				[ $k, $v ] = array_map( 'trim', explode( '=', $part, 2 ) );
				$out[ strtolower( $k ) ] = ctype_digit( $v ) ? (int) $v : $v;
			} else {
				$out[ strtolower( $part ) ] = true;
			}
		}

		return $out;
	}

	public static function response_is_cacheable( Response $r ): bool {
		$req = $r->request;
		if ( $req->method !== 'GET' && $req->method !== 'HEAD' ) {
			return false;
		}
		
		// Allow caching of successful responses and redirects
		if ( ! ( ( $r->status_code >= 200 && $r->status_code < 300 ) || ( $r->status_code >= 300 && $r->status_code < 400 ) ) ) {
			return false;
		}

		$d = self::directives( $r->get_header( 'cache-control' ) );
		if ( isset( $d['no-store'] ) ) {
			return false;
		}
		
		// Check for explicit freshness indicators, but also validate they're not expired
		if ( isset( $d['max-age'] ) ) {
			// Don't cache responses with max-age=0
			if ( is_int( $d['max-age'] ) && $d['max-age'] <= 0 ) {
				return false;
			}
			return true;
		}
		
		if ( isset( $d['s-maxage'] ) ) {
			// Don't cache responses with s-maxage=0
			if ( is_int( $d['s-maxage'] ) && $d['s-maxage'] <= 0 ) {
				return false;
			}
			return true;
		}
		
		if ( $r->get_header( 'expires' ) ) {
			// Check if expires header indicates an already expired response
			$expires = strtotime( $r->get_header( 'expires' ) );
			if ( $expires !== false && $expires <= time() ) {
				return false; // Don't cache already expired responses
			}
			return true;
		}

		// Cache responses with validation headers (ETag or Last-Modified)
		if ( $r->get_header( 'etag' ) || $r->get_header( 'last-modified' ) ) {
			return true;
		}

		return false;
	}
}