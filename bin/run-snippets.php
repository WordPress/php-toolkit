<?php
/**
 * Runs every PHP snippet declared in components/<Name>/README.md against
 * the local toolkit and compares stdout to the captured expected-output
 * block stored next to the snippet in markdown.
 *
 *     php bin/run-snippets.php --check        Verify expected outputs
 *                                             (default; used by CI).
 *     php bin/run-snippets.php --update       Re-run runnable snippets
 *                                             and write captured stdout
 *                                             back into each README.
 *     php bin/run-snippets.php --filter foo   Limit to snippets whose
 *                                             slug or filename contains
 *                                             `foo`.
 *
 * Snippets in NO_EXPECTED are runnable but their stdout is unstable
 * (real network traffic, timestamps); they are required to exit 0
 * but their output is not pinned.
 */

declare(strict_types=1);

namespace WordPress\Toolkit\DocsBuild;

if ( ! is_file( __DIR__ . '/../vendor/autoload.php' ) ) {
	fwrite( STDERR, "Run `composer install` first.\n" );
	exit( 2 );
}
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/build-reference.php';

const VENDOR_AUTOLOAD     = ROOT . '/vendor/autoload.php';
const PLAYGROUND_AUTOLOAD = '/wordpress/wp-content/php-toolkit/vendor/autoload.php';

const NO_EXPECTED = array(
	'httpclient::get.php',
	'httpclient::post.php',
	'httpclient::progress.php',
	'httpclient::sliding-window.php',
	'httpclient::resume-download.php',
	'httpclient::stream-unzip.php',
	'httpclient::fan-out.php',
	'httpclient::stream-to-disk.php',
);

const LOCAL_PRELUDE = "
if ( ! function_exists( 'parse_blocks' ) ) {
\tfunction parse_blocks( \$content ) {
\t\treturn ( new \\WP_Block_Parser() )->parse( \$content );
\t}
}
";

function rewrite_for_local( string $code ): string {
	$code = str_replace( PLAYGROUND_AUTOLOAD, VENDOR_AUTOLOAD, $code );
	if ( preg_match( "/require\s+'[^']*vendor\/autoload\.php';/", $code, $m, PREG_OFFSET_CAPTURE ) ) {
		$insert_at = $m[0][1] + strlen( $m[0][0] );
		$code = substr( $code, 0, $insert_at ) . LOCAL_PRELUDE . substr( $code, $insert_at );
	}
	return $code;
}

/**
 * Run a snippet under PHP and return [exit_code, stdout, stderr].
 */
function run_php( string $code, int $timeout_seconds = 15 ): array {
	$tmp = tempnam( sys_get_temp_dir(), 'snip' ) . '.php';
	file_put_contents( $tmp, rewrite_for_local( $code ) );
	$descriptors = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);
	$proc = proc_open(
		array( PHP_BINARY, '-d', 'display_errors=stderr', $tmp ),
		$descriptors,
		$pipes
	);
	if ( ! is_resource( $proc ) ) {
		@unlink( $tmp );
		return array( -1, '', 'failed to spawn php' );
	}
	fclose( $pipes[0] );
	stream_set_blocking( $pipes[1], false );
	stream_set_blocking( $pipes[2], false );

	$stdout    = '';
	$stderr    = '';
	$deadline  = microtime( true ) + $timeout_seconds;
	$timed_out = false;
	$rc        = -1;
	while ( true ) {
		$status  = proc_get_status( $proc );
		$stdout .= stream_get_contents( $pipes[1] );
		$stderr .= stream_get_contents( $pipes[2] );
		if ( ! $status['running'] ) {
			// proc_get_status reports the real exit code only on the first
			// call where running flips to false; subsequent calls (and
			// proc_close itself) return -1. Capture it here.
			$rc = $status['exitcode'];
			break;
		}
		if ( microtime( true ) > $deadline ) {
			proc_terminate( $proc, 9 );
			$timed_out = true;
			break;
		}
		usleep( 5000 );
	}
	$stdout .= stream_get_contents( $pipes[1] );
	$stderr .= stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	proc_close( $proc );
	@unlink( $tmp );
	if ( $timed_out ) {
		return array( -1, $stdout, "TIMEOUT after {$timeout_seconds}s\n$stderr" );
	}
	return array( $rc, $stdout, $stderr );
}

/**
 * Strip noise that varies between runs (tempfile names, hashes, etc.).
 * Patterns mirror the Python implementation 1:1 so existing
 * expected-output captures continue to match.
 */
function normalize( string $text ): string {
	$text = preg_replace( '#/tmp/\w+\.zip#', '/tmp/<tempfile>.zip', $text ) ?? $text;
	$text = preg_replace( '#(/tmp/\w+)(\.epub|\.tmp\.[a-f0-9]+)?#', '/tmp/<tempfile>$2', $text ) ?? $text;
	$text = preg_replace( '#sys_get_temp_dir\(\) \. \'/[^\']+#', "sys_get_temp_dir() . '/<demo>", $text ) ?? $text;
	$text = preg_replace( '#/(toolkit|atomic|copytree|big|orig|repacked|app|book|demo|sample|hash|gz|dl)-[a-f0-9]+#', '/$1-XXXXXX', $text ) ?? $text;
	$text = preg_replace_callback(
		'/\bnonce(?:: |=")([0-9a-f]{16})"?/',
		fn( array $m ) => str_replace( $m[1], '<random>', $m[0] ),
		$text
	) ?? $text;
	$text = preg_replace( '/\bcommit: [0-9a-f]{40}\b/', 'commit: <oid>', $text ) ?? $text;
	$text = preg_replace( '/\bHEAD:\s+[0-9a-f]{40}/', 'HEAD: <oid>', $text ) ?? $text;
	$text = preg_replace( '/\boid: [0-9a-f]{40}\b/', 'oid: <oid>', $text ) ?? $text;
	$text = preg_replace( '/merge head: [0-9a-f]{40}/', 'merge head: <oid>', $text ) ?? $text;
	$text = preg_replace( '/\b[a-f0-9]{7}  /', '<hash>  ', $text ) ?? $text;
	$text = preg_replace( '/Peak memory: [\d.]+ MB/', 'Peak memory: <N> MB', $text ) ?? $text;
	return $text;
}

function trim_trailing_newlines( string $s ): string {
	return rtrim( $s, "\n" );
}

/**
 * Replace (or insert) the captured-output fence for one snippet inside
 * its component's README.md.
 *
 * Uses CommonMark to find the snippet's exact line range — no regex
 * over the README. The README's body is parsed; we walk top-level
 * children looking for the HtmlBlock whose snippet metadata names the
 * given filename, take the next FencedCode (info=`php`) as the snippet
 * code's last line, then look at the next two children for an existing
 * expected-output marker + fence. We splice line-by-line.
 */
function write_expected_output( string $slug, string $filename, string $new_output ): void {
	$dir_name = null;
	foreach ( COMPONENT_ORDER as $row ) {
		if ( $row[0] === $slug ) {
			$dir_name = $row[1];
			break;
		}
	}
	if ( ! $dir_name ) {
		throw new \RuntimeException( "unknown slug: $slug" );
	}
	$path = COMPONENTS . "/$dir_name/README.md";
	$text = file_get_contents( $path );

	$front_matter = new \Webuni\FrontMatter\FrontMatter();
	$doc          = $front_matter->parse( $text );
	// Lines in the body are line-1-indexed by CommonMark, but the body
	// itself starts after the frontmatter in the file. Compute the
	// offset so node line numbers map back to the original file.
	$body         = $doc->getContent();
	$body_offset  = strpos( $text, $body );
	if ( false === $body_offset ) {
		throw new \RuntimeException( "could not locate body in $path" );
	}
	$prefix_lines = substr_count( substr( $text, 0, $body_offset ), "\n" );

	$parser = new \League\CommonMark\Parser\MarkdownParser( commonmark_env() );
	$tree   = $parser->parse( $body );
	$kids   = iterator_to_array( $tree->children() );

	// Find the snippet metadata HtmlBlock whose meta names this filename.
	$snippet_idx = null;
	foreach ( $kids as $idx => $node ) {
		if ( ! $node instanceof \League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock ) {
			continue;
		}
		if ( 0 !== stripos( ltrim( $node->getLiteral() ), '<!-- snippet:' ) ) {
			continue;
		}
		$meta = parse_snippet_meta( $node->getLiteral() );
		if ( ( $meta['filename'] ?? '' ) === $filename ) {
			$snippet_idx = $idx;
			break;
		}
	}
	if ( null === $snippet_idx ) {
		throw new \RuntimeException( "could not locate snippet $slug::$filename in $path" );
	}

	$php_fence = $kids[ $snippet_idx + 1 ] ?? null;
	if ( ! $php_fence instanceof \League\CommonMark\Extension\CommonMark\Node\Block\FencedCode ) {
		throw new \RuntimeException( "snippet $slug::$filename: expected php fence after metadata" );
	}
	$exp_marker = $kids[ $snippet_idx + 2 ] ?? null;
	$exp_fence  = $kids[ $snippet_idx + 3 ] ?? null;
	$has_existing = $exp_marker instanceof \League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock
		&& 0 === stripos( trim( $exp_marker->getLiteral() ), '<!-- expected-output' )
		&& $exp_fence instanceof \League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;

	// Pick a fence longer than any backtick run inside the new output.
	$fence = '```';
	while ( false !== strpos( $new_output, $fence ) ) {
		$fence .= '`';
	}
	$expected_block = "<!-- expected-output -->\n{$fence}\n" . rtrim( $new_output, "\n" ) . "\n{$fence}";

	$lines = explode( "\n", $text );
	if ( $has_existing ) {
		// Replace the existing marker + fence span with the new block.
		$start_line = $exp_marker->getStartLine() - 1 + $prefix_lines; // 0-indexed
		$end_line   = $exp_fence->getEndLine() - 1 + $prefix_lines;
		array_splice(
			$lines,
			$start_line,
			$end_line - $start_line + 1,
			explode( "\n", $expected_block )
		);
	} else {
		// Insert after the php fence's last line, with a blank separator.
		$insert_at = $php_fence->getEndLine() - 1 + $prefix_lines;
		array_splice(
			$lines,
			$insert_at + 1,
			0,
			array_merge( array( '' ), explode( "\n", $expected_block ) )
		);
	}
	file_put_contents( $path, implode( "\n", $lines ) );
}


function run_snippets_main( array $argv ): int {
	$update = false;
	$check  = false;
	$filter = null;
	for ( $i = 1; $i < count( $argv ); $i++ ) {
		switch ( $argv[ $i ] ) {
			case '--update':
				$update = true;
				break;
			case '--check':
				$check = true;
				break;
			case '--filter':
				$filter = $argv[ ++$i ] ?? null;
				break;
			default:
				fwrite( STDERR, "unknown arg: {$argv[$i]}\n" );
				return 2;
		}
	}
	if ( ! $update && ! $check ) {
		$check = true;
	}
	if ( ! is_file( VENDOR_AUTOLOAD ) ) {
		fwrite( STDERR, 'ERROR: ' . VENDOR_AUTOLOAD . " not found. Run composer install first.\n" );
		return 2;
	}

	$components      = load_components();
	$matched         = 0;
	$skipped         = 0;
	$drift           = array();
	$failures        = array();
	$pending_writes  = array();

	foreach ( $components as $c ) {
		$slug = $c['slug'];
		foreach ( $c['sections'] as $section ) {
			$snippet = $section['snippet'];
			if ( ! $snippet || ! $snippet['runnable'] ) {
				continue;
			}
			$filename = $snippet['filename'];
			if ( $filter !== null && false === strpos( $slug, $filter ) && false === strpos( $filename, $filter ) ) {
				continue;
			}
			list( $rc, $stdout, $stderr ) = run_php( $snippet['code'] );
			if ( 0 !== $rc ) {
				$lines       = explode( "\n", trim( $stderr ) );
				$failures[]  = array( $slug, $filename, array_slice( $lines, 0, 2 ) );
				$skipped++;
				continue;
			}
			$key = "$slug::$filename";
			if ( in_array( $key, NO_EXPECTED, true ) ) {
				$matched++;
				continue;
			}

			$normalized = normalize( $stdout );
			$expected   = $snippet['expected_output'];

			if ( null === $expected ) {
				$drift[] = array( $slug, $filename, 'NEW (run --update to capture)' );
				if ( $update ) {
					$pending_writes[] = array( $slug, $filename, $normalized );
				}
				continue;
			}
			$expected_norm = trim_trailing_newlines( normalize( $expected ) );
			$got_norm      = trim_trailing_newlines( $normalized );
			if ( $expected_norm !== $got_norm ) {
				$drift[] = array( $slug, $filename, 'OUTPUT CHANGED' );
				if ( $update ) {
					$pending_writes[] = array( $slug, $filename, $normalized );
				}
				continue;
			}
			$matched++;
		}
	}

	$total = $matched + count( $drift );
	echo "\nRan $total snippets; $skipped couldn't run locally.\n";
	foreach ( $failures as $f ) {
		list( $slug, $filename, $why ) = $f;
		$why_text = $why ? implode( ' ', $why ) : '(no stderr)';
		printf( "  skip   %-38s %s\n", "$slug/$filename", substr( $why_text, 0, 80 ) );
	}
	if ( $check ) {
		foreach ( $drift as $d ) {
			list( $slug, $filename, $kind ) = $d;
			printf( "  DRIFT  %-38s %s\n", "$slug/$filename", $kind );
		}
	}

	if ( $update ) {
		foreach ( $pending_writes as $w ) {
			list( $slug, $filename, $new_output ) = $w;
			write_expected_output( $slug, $filename, $new_output );
			echo "  wrote $slug/$filename\n";
		}
		printf( "\nUpdated %d expected-output blocks in markdown.\n", count( $pending_writes ) );
		return 0;
	}

	if ( $drift ) {
		printf( "\n%d snippet(s) drifted. Run `php bin/run-snippets.php --update` to refresh.\n", count( $drift ) );
		return 1;
	}
	echo "\nAll snippets match expected outputs.\n";
	return 0;
}

exit( run_snippets_main( $argv ) );
