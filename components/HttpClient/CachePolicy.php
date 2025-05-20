<?php

namespace WordPress\HttpClient;

final class CachePolicy {

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

	public static function freshness_lifetime( CacheEntry $e ): int {
		$h = $e->headers;
		$d = self::directives( $h['cache-control'] ?? null );
		if ( isset( $d['s-maxage'] ) ) {
			return $d['s-maxage'];
		}
		if ( isset( $d['max-age'] ) ) {
			return $d['max-age'];
		}
		if ( isset( $h['expires'] ) ) {
			return max( 0, strtotime( $h['expires'] ) - $e->stored_at );
		}

		if ( isset( $h['last-modified'] ) ) {
			$age = $e->stored_at - strtotime( $h['last-modified'] );

			return (int) max( 0, $age / 10 );
		}

		return 0;   // treat as immediately stale
	}

	public static function is_fresh( CacheEntry $e, ?int $now = null ): bool {
		$now         = $now ?? time();
		$age_hdr     = (int) ( $e->headers['age'] ?? 0 );
		$current_age = $age_hdr + ( $now - $e->stored_at );

		return $current_age < self::freshness_lifetime( $e );
	}

	public static function must_revalidate( CacheEntry $e ): bool {
		$d = self::directives( $e->headers['cache-control'] ?? null );

		return isset( $d['must-revalidate'] ) || isset( $d['proxy-revalidate'] );
	}

	public static function parse_max_age( string $cc ): ?int {
		foreach ( explode( ',', $cc ) as $d ) {
			$d = trim( $d );
			if ( strncmp( $d, 'max-age=', strlen( 'max-age=' ) ) === 0 ) {
				return (int) substr( $d, 8 );
			}
		}

		return null;
	}
}
