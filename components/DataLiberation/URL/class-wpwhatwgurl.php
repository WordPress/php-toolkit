<?php

namespace WordPress\DataLiberation\URL;

/**
 * Adapter that exposes the same property-based API as Rowbot\URL\URL on top of
 * PHP 8.5's native Uri\WhatWg\Url parser.
 *
 * The native PHP 8.5 parser is immutable: every modification returns a new
 * instance via withX() methods, and properties are read through getX() methods
 * with slightly different shapes than the Rowbot counterparts (e.g. getPort()
 * returns ?int, getScheme() omits the trailing colon, getQuery()/getFragment()
 * omit the leading "?"/"#"). This wrapper mirrors the Rowbot value shapes so
 * that callers across the DataLiberation component (WPURL::replace_base_url,
 * BlockMarkupUrlProcessor, the URL-in-text/CSS processors, etc.) can keep
 * mutating $url->hostname, $url->protocol, $url->pathname, ... without caring
 * whether the underlying parser is Rowbot or PHP-native.
 *
 * Available only on PHP 8.5+ where the Uri\WhatWg\Url class exists.
 */
class WPWhatwgUrl {

	/** @var \Uri\WhatWg\Url */
	private $url;

	public function __construct( \Uri\WhatWg\Url $url ) {
		$this->url = $url;
	}

	/**
	 * Parses a URL string and returns a wrapper instance, or null on failure.
	 *
	 * Mirrors Rowbot\URL\URL::parse(). The base may be a string, another
	 * WPWhatwgUrl, or null. PHP's native parser only accepts a Uri\WhatWg\Url
	 * base, so a string base is parsed first.
	 *
	 * @param string                                  $url
	 * @param string|WPWhatwgUrl|\Uri\WhatWg\Url|null $base
	 * @return WPWhatwgUrl|null
	 */
	public static function parse( $url, $base = null ) {
		$native_base = self::resolve_base( $base );
		if ( null !== $base && null === $native_base ) {
			return null;
		}
		$parsed = \Uri\WhatWg\Url::parse( (string) $url, $native_base );
		if ( null === $parsed ) {
			return null;
		}
		return new self( $parsed );
	}

	// Method name mirrors the Rowbot\URL\URL / WHATWG JS API.
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	public static function canParse( $url, $base = null ) {
		return null !== self::parse( $url, $base );
	}

	private static function resolve_base( $base ) {
		if ( null === $base ) {
			return null;
		}
		if ( $base instanceof \Uri\WhatWg\Url ) {
			return $base;
		}
		if ( $base instanceof self ) {
			return $base->url;
		}
		// String (or stringable). Parse it.
		return \Uri\WhatWg\Url::parse( (string) $base );
	}

	public function __clone() {
		// The inner Uri\WhatWg\Url is immutable, so a shallow copy of the
		// reference is sufficient. Mutations always replace $this->url with a
		// fresh withX() result.
	}

	public function __get( $name ) {
		switch ( $name ) {
			case 'protocol':
				return $this->url->getScheme() . ':';

			case 'hostname':
				$h = $this->url->getAsciiHost();
				return null === $h ? '' : $h;

			case 'host':
				$h    = $this->url->getAsciiHost();
				$port = $this->url->getPort();
				if ( null === $h ) {
					return '';
				}
				return null === $port ? $h : $h . ':' . $port;

			case 'port':
				$p = $this->url->getPort();
				return null === $p ? '' : (string) $p;

			case 'username':
				$u = $this->url->getUsername();
				return null === $u ? '' : $u;

			case 'password':
				$p = $this->url->getPassword();
				return null === $p ? '' : $p;

			case 'pathname':
				return $this->url->getPath();

			case 'search':
				$q = $this->url->getQuery();
				return ( null === $q || '' === $q ) ? '' : '?' . $q;

			case 'hash':
				$f = $this->url->getFragment();
				return ( null === $f || '' === $f ) ? '' : '#' . $f;

			case 'href':
				return $this->url->toAsciiString();

			case 'origin':
				return $this->compute_origin();
		}

		return null;
	}

	public function __set( $name, $value ) {
		switch ( $name ) {
			case 'protocol':
				// Rowbot accepts both "https" and "https:".
				$value     = rtrim( (string) $value, ':' );
				$this->url = $this->url->withScheme( $value );
				return;

			case 'hostname':
				$this->url = $this->url->withHost( '' === $value ? null : (string) $value );
				return;

			case 'port':
				if ( '' === $value || null === $value ) {
					$this->url = $this->url->withPort( null );
				} else {
					$this->url = $this->url->withPort( (int) $value );
				}
				return;

			case 'username':
				$this->url = $this->url->withUsername( '' === $value ? null : (string) $value );
				return;

			case 'password':
				$this->url = $this->url->withPassword( '' === $value ? null : (string) $value );
				return;

			case 'pathname':
				$this->url = $this->url->withPath( (string) $value );
				return;

			case 'search':
				if ( null === $value || '' === $value ) {
					$this->url = $this->url->withQuery( null );
				} else {
					$value     = ltrim( (string) $value, '?' );
					$this->url = $this->url->withQuery( $value );
				}
				return;

			case 'hash':
				if ( null === $value || '' === $value ) {
					$this->url = $this->url->withFragment( null );
				} else {
					$value     = ltrim( (string) $value, '#' );
					$this->url = $this->url->withFragment( $value );
				}
				return;
		}
	}

	// Method name mirrors the Rowbot\URL\URL / WHATWG JS API.
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	public function toString(): string {
		return $this->url->toAsciiString();
	}

	public function __toString(): string {
		return $this->toString();
	}

	public function get_native_url(): \Uri\WhatWg\Url {
		return $this->url;
	}

	/**
	 * Computes the WHATWG origin string for the URL.
	 *
	 * The PHP 8.5 native parser does not expose origin directly. Mirror the
	 * subset of Rowbot's behaviour the DataLiberation tests rely on: tuple
	 * origins for special schemes (http, https, ws, wss, ftp), "null" for
	 * everything else (including blob:, file:, opaque schemes).
	 *
	 * @see https://url.spec.whatwg.org/#origin
	 */
	private function compute_origin(): string {
		$scheme        = $this->url->getScheme();
		$tuple_schemes = array( 'http', 'https', 'ws', 'wss', 'ftp' );
		if ( ! in_array( $scheme, $tuple_schemes, true ) ) {
			return 'null';
		}

		$host = $this->url->getAsciiHost();
		if ( null === $host ) {
			return 'null';
		}

		$origin = $scheme . '://' . $host;
		$port   = $this->url->getPort();
		if ( null !== $port ) {
			$origin .= ':' . $port;
		}
		return $origin;
	}
}
