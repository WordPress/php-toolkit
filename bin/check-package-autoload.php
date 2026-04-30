<?php
/**
 * Verifies that every class, interface, and trait declared in a Composer
 * package is reachable through the autoloader.
 *
 * Usage: php bin/check-package-autoload.php <package-name> [<package-vendor-dir>]
 *
 * Exits 0 if every declared symbol autoloads, non-zero otherwise. Prints a
 * one-line summary on success and a detailed list of unreachable symbols on
 * failure.
 *
 * Intended to run inside a scratch directory after `composer require
 * <package>:<version>` to catch autoload regressions in published Packagist
 * tarballs (PSR-4 misconfigured against WP `class-*.php` naming, missing
 * `files` entries, sibling-component requires that worked in the monorepo
 * but break post-split, etc.).
 */

$pkg     = $argv[1] ?? null;
$pkg_dir = $argv[2] ?? "vendor/{$pkg}";

if ( ! $pkg ) {
	fwrite( STDERR, "usage: php check-package-autoload.php <package-name> [<package-vendor-dir>]\n" );
	exit( 2 );
}

// Always load the scratch directory's autoloader, never our own. If we
// fell back to __DIR__/../vendor/autoload.php we would be checking against
// the monorepo's classmap (which already reaches every component) instead
// of the published package's autoload, defeating the entire smoke test.
$autoload = getcwd() . '/vendor/autoload.php';
if ( ! is_file( $autoload ) ) {
	fwrite( STDERR, "vendor/autoload.php not found in {$autoload}\n" );
	exit( 2 );
}
require $autoload;

if ( ! is_dir( $pkg_dir ) ) {
	fwrite( STDERR, "package directory not found: {$pkg_dir}\n" );
	exit( 2 );
}

$declared = collect_declared_symbols( $pkg_dir );
if ( empty( $declared ) ) {
	echo "NOTE: {$pkg} declares no class/interface/trait symbols (file-only package)\n";
	exit( 0 );
}

$missing = array();
foreach ( $declared as $fqn => $file ) {
	if ( class_exists( $fqn ) || interface_exists( $fqn ) || trait_exists( $fqn ) ) {
		continue;
	}
	$missing[ $fqn ] = $file;
}

if ( empty( $missing ) ) {
	printf( "OK %s — %d symbols autoload\n", $pkg, count( $declared ) );
	exit( 0 );
}

printf( "FAIL %s — %d/%d symbols unreachable via autoload:\n", $pkg, count( $missing ), count( $declared ) );
foreach ( $missing as $fqn => $file ) {
	printf( "  - %s (%s)\n", $fqn, $file );
}
exit( 1 );

/**
 * Walks every PHP file under $dir (skipping Tests, vendor, fixtures) and
 * extracts the fully-qualified name of each top-level class, interface, and
 * trait. Uses PHP's tokenizer so we never execute the package code.
 */
function collect_declared_symbols( $dir ) {
	$out      = array();
	$iterator = new RecursiveIteratorIterator(
		new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			function ( $current ) {
				$name = $current->getFilename();
				if ( $current->isDir() && in_array( $name, array( 'Tests', 'tests', 'fixtures', 'vendor' ), true ) ) {
					return false;
				}
				return true;
			}
		)
	);
	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}
		foreach ( extract_symbols( file_get_contents( $file->getPathname() ) ) as $fqn ) {
			$out[ $fqn ] = $file->getPathname();
		}
	}
	return $out;
}

function extract_symbols( $source ) {
	$tokens    = token_get_all( $source );
	$namespace = '';
	$symbols   = array();
	$count     = count( $tokens );
	for ( $i = 0; $i < $count; $i++ ) {
		$t = $tokens[ $i ];
		if ( ! is_array( $t ) ) {
			continue;
		}
		if ( T_NAMESPACE === $t[0] ) {
			$namespace = '';
			$i++;
			while ( $i < $count ) {
				$tt = $tokens[ $i ];
				if ( is_string( $tt ) && ( ';' === $tt || '{' === $tt ) ) {
					break;
				}
				if ( is_array( $tt ) && in_array( $tt[0], array( T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true ) ) {
					$namespace .= $tt[1];
				}
				$i++;
			}
			continue;
		}
		if ( in_array( $t[0], array( T_CLASS, T_INTERFACE, T_TRAIT ), true ) ) {
			// Skip anonymous classes ("new class { ... }") and ::class fetches.
			$prev = previous_meaningful_token( $tokens, $i );
			if ( $prev && is_array( $prev ) && in_array( $prev[0], array( T_NEW, T_DOUBLE_COLON, T_PAAMAYIM_NEKUDOTAYIM ), true ) ) {
				continue;
			}
			$j = $i + 1;
			while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
				$j++;
			}
			if ( $j < $count && is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
				$short = $tokens[ $j ][1];
				$fqn   = '' === $namespace ? $short : $namespace . '\\' . $short;
				$symbols[] = $fqn;
			}
		}
	}
	return $symbols;
}

function previous_meaningful_token( $tokens, $index ) {
	for ( $k = $index - 1; $k >= 0; $k-- ) {
		$tt = $tokens[ $k ];
		if ( is_array( $tt ) && T_WHITESPACE === $tt[0] ) {
			continue;
		}
		return $tt;
	}
	return null;
}
