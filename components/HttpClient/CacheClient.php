<?php

namespace WordPress\HttpClient;

use RuntimeException;

final class CacheClient {
	public const EVENT_HEADERS = Client::EVENT_GOT_HEADERS;
	public const EVENT_BODY = Client::EVENT_BODY_CHUNK_AVAILABLE;
	public const EVENT_FINISH = Client::EVENT_FINISHED;

	private Client $upstream;
	private string $dir;

	/** @var array<string,array{req:Request,meta:array,file:resource|null,headerDone:bool,done:bool}> */
	private array $replay = [];

	/** writers keyed by spl_object_hash(req) */
	private array $tempHandle = [];
	private array $tempPath = [];

	/* snapshot for getters */
	private ?string $event = null;
	private ?Request $request = null;
	private ?Response $response = null;
	private ?string $cache_key = null;

	public function __construct( Client $upstream, string $cacheDir ) {
		$this->upstream = $upstream;
		$this->dir      = rtrim( $cacheDir, '/' );
		if ( ! is_dir( $this->dir ) && ! mkdir( $this->dir, 0777, true ) ) {
			throw new RuntimeException( "cannot create cache dir {$this->dir}" );
		}
	}

	/*---------------- enqueue ----------------*/
	public function enqueue( Request|array $requests ): void {
		$list         = is_array( $requests ) ? $requests : [ $requests ];
		$cache_misses = [];
		foreach ( $list as $request ) {
			if ( ! $request instanceof Request ) {
				continue;
			}
			$meth = strtoupper( $request->method );
			if ( ! in_array( $meth, [ 'GET', 'HEAD' ], true ) ) {
				$this->invalidateCache( $request );
				$cache_misses[] = $request;
				continue;
			}
			[ $key, $meta ] = $this->lookup( $request );
			$request->cache_key = $key;
			if ( $meta && $this->fresh( $meta ) ) {
				$this->startReplay( $request, $meta );
				continue;
			}
			if ( $meta ) {
				$this->addValidators( $request, $meta );
			}
			$cache_misses[]     = $request;
		}
		if ( $cache_misses ) {
			$this->upstream->enqueue( $cache_misses );
		}
	}

	/*---------------- await ----------------*/
	public function await_next_event( array $query = [] ): bool {
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
		/* drive upstream */
		while ( true ) {
			if ( ! $this->upstream->await_next_event( $query ) ) {
				return false;
			}
			if ( $this->handleNetwork() ) {
				return true;
			}
			/* loop if event was swallowed (e.g., 304 turned into replay) */
		}
	}

	/*---------------- getters --------------*/
	public function get_event(): ?string {
		return $this->event;
	}

	public function get_request(): ?Request {
		return $this->request;
	}

	public function get_response(): ?Response {
		return $this->response;
	}	

	public function get_response_body_chunk(): ?string {
		return $this->cache_key;
	}

	/*============ CACHE REPLAY ============*/
	private function startReplay( Request $request, array $meta ): void {
		$id                  = spl_object_hash( $request );
		$file_handle         = fopen( $this->bodyPath( $request->cache_key ), 'rb' );
		$this->replay[ $id ] = [
			'req'        => $request,
			'meta'       => $meta,
			'file'       => $file_handle,
			'headerDone' => false,
			'done'       => false,
		];
	}

	private function fromCache( string $id ): void {
		$context       =& $this->replay[ $id ];
		$this->request = $context['req'];
		if ( ! $context['headerDone'] ) {
			$resp                  = new Response( $context['req'] );
			$resp->status_code     = $context['meta']['status'];
			$resp->headers         = $context['meta']['headers'];
			$this->event           = self::EVENT_HEADERS;
			$this->response        = $resp;
			$this->cache_key       = null;
			$context['headerDone'] = true;

			return;
		}
		$chunk = fread( $context['file'], 8192 );
		if ( $chunk !== '' && $chunk !== false ) {
			$this->event     = self::EVENT_BODY;
			$this->cache_key = $chunk;
			$this->response  = null;

			return;
		}
		$context['done'] = true;
		$this->event     = self::EVENT_FINISH;
		$this->response  = $this->cache_key = null;
	}

	/*============ NETWORK HANDLING ============*/
	private function handleNetwork(): bool {
		$event   = $this->upstream->get_event();
		$request = $this->upstream->get_request();
		/* HEADERS */
		if ( $event === self::EVENT_HEADERS ) {
			$response = $this->upstream->get_response();
			if ( $response->status_code === 304 && isset( $request->cache_key ) ) {
				[ , $meta ] = $this->lookup( $request, $request->cache_key );
				if ( $meta ) {
					$this->startReplay( $request, $meta ); /* swallow 304 events */

					return false;
				}
			}
			if ( $response->status_code === 200 && $this->cacheable( $response ) ) {
				$tmp = $this->tempPath( $request->cache_key );

				$this->tempPath[ spl_object_hash( $request ) ]   = $tmp;
				$this->tempHandle[ spl_object_hash( $request ) ] = fopen( $tmp, 'wb' );
			}
			$this->event     = $event;
			$this->request   = $request;
			$this->response  = $response;
			$this->cache_key = null;

			return true;
		}
		/* BODY */
		if ( $event === self::EVENT_BODY ) {
			$chunk = $this->upstream->get_response_body_chunk();
			$hash  = spl_object_hash( $request );
			if ( isset( $this->tempHandle[ $hash ] ) ) {
				fwrite( $this->tempHandle[ $hash ], $chunk );
			}
			$this->event     = $event;
			$this->request   = $request;
			$this->cache_key = $chunk;
			$this->response  = null;

			return true;
		}
		/* FINISH */
		if ( $event === self::EVENT_FINISH ) {
			$hash = spl_object_hash( $request );
			if ( isset( $this->tempHandle[ $hash ] ) ) {
				fclose( $this->tempHandle[ $hash ] );
				$this->commit( $request, $this->upstream->get_response(), $this->tempPath[ $hash ] );
				unset( $this->tempHandle[ $hash ], $this->tempPath[ $hash ] );
			}
			$this->event     = $event;
			$this->request   = $request;
			$this->response  = $this->upstream->get_response();
			$this->cache_key = null;

			return true;
		}
		/* passthrough others */
		$this->event     = $event;
		$this->request   = $request;
		$this->response  = $event === self::EVENT_BODY ? null : $this->upstream->get_response();
		$this->cache_key = $event === self::EVENT_BODY ? $this->upstream->get_response_body_chunk() : null;

		return true;
	}

	/*============ CACHE UTILITIES ============*/
	private function metaPath( string $key ): string {
		return "$this->dir/{$key}.json";
	}

	private function bodyPath( string $key ): string {
		return "$this->dir/{$key}.body";
	}

	private function tempPath( string $key ): string {
		return "$this->dir/{$key}.tmp";
	}

	private function varyKey( Request $request, ?array $vary_keys ): string {
		$parts = [ $request->url ];
		if ( $vary_keys ) {
			foreach ( $vary_keys as $header_name ) {
				$parts[] = strtolower( $header_name ) . ':' . $request->get_header( $header_name );
			}
		}

		return sha1( implode( '|', $parts ) );
	}

	/** @return array{string,array|null} */
	private function lookup( Request $request, ?string $forced = null ): array {
		if ( $forced && is_file( $this->metaPath( $forced ) ) ) {
			return [ $forced, json_decode( file_get_contents( $this->metaPath( $forced ) ), true ) ];
		}
		$glob = glob( $this->dir . '/' . sha1( $request->url ) . '*.json' );
		foreach ( $glob as $meta_path ) {
			$meta = json_decode( file_get_contents( $meta_path ), true );
			if ( basename( $meta_path, '.json' ) === $this->varyKey( $request, $meta['vary'] ?? [] ) ) {
				return [ basename( $meta_path, '.json' ), $meta ];
			}
		}

		return [ $this->varyKey( $request, null ), null ];
	}

	private function fresh( array $meta ): bool {
		$now = time();

		// If explicit expiry timestamp is set, use it
		if ( isset( $meta['expires'] ) ) {
			$expires = is_numeric( $meta['expires'] ) ? (int)$meta['expires'] : strtotime( $meta['expires'] );
			if ( $expires !== false ) {
				return $expires > $now;
			}
		}

		// If explicit TTL (absolute timestamp) is set, use it
		if ( isset( $meta['ttl'] ) ) {
			if ( is_numeric( $meta['ttl'] ) ) {
				return (int)$meta['ttl'] > $now;
			}
		}

		// If max_age is set, check if still valid
		if ( isset( $meta['max_age'] ) && isset( $meta['stored_at'] ) ) {
			return ($meta['stored_at'] + (int)$meta['max_age']) > $now;
		}

		// If s-maxage is set, check if still valid
		if ( isset( $meta['s_maxage'] ) && isset( $meta['stored_at'] ) ) {
			return ($meta['stored_at'] + (int)$meta['s_maxage']) > $now;
		}

		// Heuristic: if Last-Modified is present, cache for 10% of its age at storage time
		if ( isset( $meta['last_modified'] ) && isset( $meta['stored_at'] ) ) {
			$lm = is_numeric( $meta['last_modified'] ) ? (int)$meta['last_modified'] : strtotime( $meta['last_modified'] );
			if ( $lm !== false ) {
				$age = $meta['stored_at'] - $lm;
				$heuristic_lifetime = (int) max( 0, $age / 10 );
				return ($meta['stored_at'] + $heuristic_lifetime) > $now;
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
			$request->headers['If-None-Match'] = $meta['etag'];
		}
		if ( ! empty( $meta['last_modified'] ) ) {
			$request->headers['If-Modified-Since'] = $meta['last_modified'];
		}
	}

	protected function commit( Request $request ) {
		$url   = $request->url;
		$meta = [
			'url' => $url,
			'status' => $request->response->status_code,
			'headers' => $request->response->headers,
			'stored_at' => time(),
			'etag' => $request->response->get_header( 'ETag' ),
			'last_modified' => $request->response->get_header( 'Last-Modified' ),
		];
		// Parse Cache-Control for max-age, if present
		$cacheControl = $request->response->get_header( 'Cache-Control' );
		if ( $cacheControl ) {
			$directives = self::directives( $cacheControl );
			if ( isset( $directives['max-age'] ) && is_int( $directives['max-age'] ) ) {
				$meta['max_age'] = $directives['max-age'];
			}
		}

		// Determine file paths
		$key      = $request->cache_key;
		$bodyFile = $this->bodyPath( $key );
		$tempFile = $this->tempPath( $key );
		$metaFile = $this->metaPath( $key );

		// Close the temp body stream if open (flushes data)
		$file_handle = $this->tempHandle[ spl_object_hash( $request ) ];
		if ( $file_handle && is_resource( $file_handle ) ) {
			fclose( $file_handle );
		}
		unset( $this->tempHandle[ spl_object_hash( $request ) ] );

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
		$key      = $request->cache_key;
		$bodyFile = $this->bodyPath( $key );
		$metaFile = $this->metaPath( $key );

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
		foreach ( explode( ',', $value ) as $part ) {
			$part = trim( $part );
			if ( $part === '' ) {
				continue;
			}
			if ( strpos( $part, '=' ) !== false ) {
				[ $k, $v ] = array_map( 'trim', explode( '=', $part, 2 ) );
				$out[ strtolower( $k ) ] = ctype_digit( $v ) ? (int) $v : strtolower( $v );
			} else {
				$out[ strtolower( $part ) ] = true;
			}
		}

		return $out;
	}

	public static function response_is_cacheable( Response $r ): bool {
		$req = $r->request;
		if ( $req->method !== 'GET' ) {
			return false;
		}
		if ( $r->status_code !== 200 && $r->status_code !== 206 ) {
			return false;
		}

		$d = self::directives( $r->get_header( 'cache-control' ) );
		if ( isset( $d['no-store'] ) ) {
			return false;
		}
		if ( $r->get_header( 'expires' ) || isset( $d['max-age'] ) || isset( $d['s-maxage'] ) ) {
			return true;
		}

		// heuristic: if Last-Modified present and older than 24 h cache for 10 %
		return (bool) $r->get_header( 'last-modified' );
	}
	
}
