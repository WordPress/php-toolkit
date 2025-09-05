<?php
declare(strict_types=1);

namespace WordPress\Filesystem\Path;

final class WindowsPath {

	private const SEP = '\\';

	/** Converts every “/” to “\” so that later code can assume one separator. */
	private static function normalizeSeparators( string $path ): string {
		return str_replace( '/', self::SEP, $path );
	}

	/** Absolute = “C:\…\” or “\\server\share\…”. */
	private static function isAbsolute( string $path ): bool {
		$path = self::normalizeSeparators( $path );
		return (bool) preg_match( '/^(?:[A-Za-z]:\\\\|\\\\\\\\[^\\\\]+\\\\[^\\\\]+)/', $path );
	}

	/** Returns “C:\foo\bar” → ["C:", "foo", "bar"]; “\\srv\share\dir” → ["srv", "share", "dir"]. */
	public static function pathSegments( string $path ): array {
		$canonical = self::canonicalize( $path );
		$trimmed   = trim( $canonical, self::SEP );

		// root “C:\” or “\\srv\share\” gives empty $trimmed, keep the original string
		if ( $trimmed === '' ) {
			return array( $canonical );
		}
		return array_values( array_filter( explode( self::SEP, $trimmed ), 'strlen' ) );
	}

	/** Joins segments with a single backslash and keeps any drive-letter or UNC prefix intact. */
	public static function joinPaths( string ...$segments ): string {
		if ( ! $segments ) {
			return '';
		}

		$pieces = array();
		$first  = null;
		foreach ( $segments as $seg ) {
			if ( $seg === '' ) {
				continue;
			}
			if ( $first === null ) {
				$first = $seg;
			}
			$pieces[] = trim( self::normalizeSeparators( $seg ), '\\/' );
		}
		$joined = implode( self::SEP, $pieces );

		// restore UNC double backslash if needed
		if ( preg_match( '/^\\\\\\\\/', self::normalizeSeparators( $first ) ) ) {
			return '\\\\' . ltrim( $joined, self::SEP );
		}
		return $joined;
	}

	/** Mirrors Node.js path.resolve for Windows rules. */
	public static function resolvePath( string ...$segments ): string {
		for ( $i = count( $segments ) - 1; $i >= 0; --$i ) {
			if ( self::isAbsolute( $segments[ $i ] ) ) {
				return self::canonicalize(
					self::joinPaths( ...array_slice( $segments, $i ) )
				);
			}
		}
		array_unshift( $segments, getcwd() );
		return self::canonicalize( self::joinPaths( ...$segments ) );
	}

	/**
	 * Cleans up a path, guarantees it is absolute and free of “\.” / “\..”.
	 * Keeps trailing backslash only for drive-root (“C:\”) or UNC-root (“\\srv\share\”).
	 */
	public static function canonicalize( string $path ): string {
		$path = self::normalizeSeparators( trim( $path ) );

		if ( ! self::isAbsolute( $path ) ) {
			$cwd  = rtrim( getcwd(), self::SEP );
			$path = $cwd . self::SEP . $path;
		}

		// split prefix (drive or UNC) from the rest
		preg_match( '/^(\\\\\\\\[^\\\\]+\\\\[^\\\\]+|[A-Za-z]:)(?:\\\\)?(.*)$/', $path, $m );
		$prefix = $m[1] ?? '';
		$rest   = $m[2] ?? '';

		$stack = array();
		foreach ( explode( self::SEP, $rest ) as $part ) {
			if ( $part === '' || $part === '.' ) {
				continue;
			}
			if ( $part === '..' ) {
				array_pop( $stack );
				continue;
			}
			$stack[] = $part;
		}

		$resolved = $prefix;
		if ( $resolved !== '' && substr( $resolved, -1 ) !== self::SEP ) {
			$resolved .= self::SEP;
		}
		$resolved .= implode( self::SEP, $stack );

		// Drive-root and UNC-root must end with “\”
		if ( $resolved === $prefix ) {
			$resolved .= self::SEP;
		}
		return $resolved;
	}

	/** Consistent dirname() that sticks to backslashes. */
	public static function dirname( string $path ): string {
		$path = self::normalizeSeparators( $path );
		$dir  = dirname( $path );

		// dirname("C:\foo") returns "C:\", keep that trailing sep; but dirname("C:\") yields "C:\"
		if ( preg_match( '/^[A-Za-z]:$/', $dir ) ) {
			$dir .= self::SEP;
		}
		return $dir;
	}
}
