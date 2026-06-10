<?php

namespace WordPress\Svn;

/**
 * Parses an svn:externals property value.
 *
 * Externals nest other repositories (or other parts of the same
 * repository) inside a working copy. Each non-empty, non-comment line
 * defines one external in either the modern format:
 *
 *     [-r REV] URL[@PEG] TARGET
 *
 * or the historical pre-1.5 format:
 *
 *     TARGET [-r REV] URL
 *
 * URLs may be absolute or relative:
 *
 *     ../   relative to the URL of the directory the property is on
 *     ^/    relative to the repository root
 *     //    relative to the URL scheme
 *     /     relative to the server root
 *
 * @param  string $property_value   The raw svn:externals value.
 * @param  string $directory_url    Absolute URL of the directory carrying the property.
 * @param  string $repository_root  Absolute URL of the repository root.
 * @return array[] One entry per external: {
 *     @type string   $url       Absolute URL of the external.
 *     @type string   $target    Directory the external lives at, relative
 *                               to the directory carrying the property.
 *     @type int|null $revision  Pinned revision, or null for HEAD.
 * }
 * @throws SvnException When a line cannot be parsed.
 */
function svn_parse_externals( $property_value, $directory_url, $repository_root ) {
	$externals = array();
	foreach ( explode( "\n", $property_value ) as $line ) {
		$line = trim( $line );
		if ( '' === $line || '#' === $line[0] ) {
			continue;
		}

		// Tokenize, honoring double-quoted tokens with spaces.
		preg_match_all( '/"([^"]*)"|(\S+)/', $line, $matches, PREG_SET_ORDER );
		$tokens = array();
		foreach ( $matches as $match ) {
			$tokens[] = isset( $match[2] ) && '' !== $match[2] ? $match[2] : $match[1];
		}

		// Extract the optional -r REV / -rREV operative revision.
		$revision    = null;
		$token_count = count( $tokens );
		for ( $i = 0; $i < $token_count; $i++ ) {
			if ( '-r' === $tokens[ $i ] && isset( $tokens[ $i + 1 ] ) ) {
				$revision = (int) $tokens[ $i + 1 ];
				array_splice( $tokens, $i, 2 );
				break;
			}
			if ( 0 === strpos( $tokens[ $i ], '-r' ) && strlen( $tokens[ $i ] ) > 2 ) {
				$revision = (int) substr( $tokens[ $i ], 2 );
				array_splice( $tokens, $i, 1 );
				break;
			}
		}

		if ( 2 !== count( $tokens ) ) {
			throw new SvnException( "Cannot parse svn:externals line: '{$line}'." );
		}

		if ( svn_is_url( $tokens[0] ) ) {
			$url    = $tokens[0];
			$target = $tokens[1];
		} elseif ( svn_is_url( $tokens[1] ) ) {
			$url    = $tokens[1];
			$target = $tokens[0];
		} else {
			throw new SvnException( "Cannot parse svn:externals line, no URL found: '{$line}'." );
		}

		// A peg revision (URL@N) pins the external too. Only numeric and
		// HEAD pegs are meaningful for a checkout.
		$at_position = strrpos( $url, '@' );
		if ( false !== $at_position && false === strpos( substr( $url, $at_position ), '/' ) ) {
			$peg = substr( $url, $at_position + 1 );
			$url = substr( $url, 0, $at_position );
			if ( preg_match( '/^\d+$/', $peg ) ) {
				if ( null === $revision ) {
					$revision = (int) $peg;
				}
			} elseif ( 'HEAD' !== $peg && '' !== $peg ) {
				throw new SvnException( "Unsupported peg revision '{$peg}' in svn:externals line: '{$line}'." );
			}
		}

		$target = trim( $target, '/' );
		if ( '' === $target || '..' === $target || 0 === strpos( $target, '../' ) || false !== strpos( $target, '/../' ) ) {
			throw new SvnException( "svn:externals target must be a relative path below the directory: '{$line}'." );
		}

		$externals[] = array(
			'url'      => svn_resolve_url( $url, $directory_url, $repository_root ),
			'target'   => $target,
			'revision' => $revision,
		);
	}

	return $externals;
}

/**
 * @param  string $candidate  A token from an svn:externals line.
 * @return bool Whether the token is an absolute or relative URL rather than a target path.
 */
function svn_is_url( $candidate ) {
	return false !== strpos( $candidate, '://' )
		|| 0 === strpos( $candidate, '^/' )
		|| 0 === strpos( $candidate, '//' )
		|| 0 === strpos( $candidate, '/' )
		|| 0 === strpos( $candidate, '../' );
}

/**
 * Resolves a possibly-relative svn:externals URL to an absolute URL.
 *
 * @param  string $url              The URL token from the externals definition.
 * @param  string $directory_url    Absolute URL of the directory carrying the property.
 * @param  string $repository_root  Absolute URL of the repository root.
 * @return string The absolute URL.
 * @throws SvnException When the URL escapes the server root.
 */
function svn_resolve_url( $url, $directory_url, $repository_root ) {
	if ( false !== strpos( $url, '://' ) ) {
		return rtrim( $url, '/' );
	}
	if ( 0 === strpos( $url, '^/' ) ) {
		return svn_join_url( $repository_root, substr( $url, 2 ) );
	}
	if ( 0 === strpos( $url, '//' ) ) {
		$scheme = (string) parse_url( $directory_url, PHP_URL_SCHEME );

		return rtrim( $scheme . ':' . $url, '/' );
	}
	if ( 0 === strpos( $url, '/' ) ) {
		$parts     = parse_url( $directory_url );
		$authority = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

		return rtrim( $authority . $url, '/' );
	}

	return svn_join_url( $directory_url, $url );
}

/**
 * Joins a base URL with a relative path, resolving "." and ".." segments.
 *
 * @param  string $base_url  An absolute URL.
 * @param  string $relative  A relative path, possibly with ".." segments.
 * @return string The joined absolute URL.
 * @throws SvnException When ".." segments escape the server root.
 */
function svn_join_url( $base_url, $relative ) {
	$parts     = parse_url( $base_url );
	$authority = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	$segments  = array();
	$base_path = isset( $parts['path'] ) ? trim( $parts['path'], '/' ) : '';
	if ( '' !== $base_path ) {
		$segments = explode( '/', $base_path );
	}
	foreach ( explode( '/', trim( $relative, '/' ) ) as $segment ) {
		if ( '' === $segment || '.' === $segment ) {
			continue;
		}
		if ( '..' === $segment ) {
			if ( 0 === count( $segments ) ) {
				throw new SvnException( "The relative URL '{$relative}' escapes the server root of '{$base_url}'." );
			}
			array_pop( $segments );
			continue;
		}
		$segments[] = $segment;
	}

	return $authority . ( count( $segments ) > 0 ? '/' . implode( '/', $segments ) : '' );
}
