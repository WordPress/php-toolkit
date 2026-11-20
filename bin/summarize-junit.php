<?php
/**
 * Summarizes a PHPUnit JUnit XML report as a plain-text table, one row per
 * component (components/<Name>/Tests), followed by the names of skipped tests.
 *
 * Usage: php bin/summarize-junit.php <junit.xml>
 *
 * CI runs this as a separate step after PHPUnit, so every matrix job's log
 * shows which components' tests ran and which were skipped, without changing
 * the PHPUnit console output itself.
 *
 * Test cases are grouped by their `file` attribute rather than by the
 * <testsuite> tree, because the suite attributes differ between PHPUnit 8.5
 * and 9. Windows paths (backslashes) are handled.
 *
 * Exits 0 when the report is missing (PHPUnit never got far enough to write
 * it; the test step already failed) and 1 when it cannot be parsed.
 */

// Components with fewer skipped tests than this get each name listed;
// larger ones (Blueprints, the HTML/XML spec suites) only get a count.
const SKIPPED_LIST_LIMIT = 50;

// Bucket for test cases whose file is not under components/<Name>/Tests.
const OTHER_COMPONENT = '(other)';

$report_path = $argv[1] ?? null;

if ( null === $report_path ) {
	fwrite( STDERR, "usage: php bin/summarize-junit.php <junit.xml>\n" );
	exit( 2 );
}

if ( ! is_file( $report_path ) ) {
	echo "No JUnit report was written ({$report_path} is missing), so PHPUnit did not finish.\n";
	exit( 0 );
}

$components = summarize_junit_report( $report_path );
if ( null === $components ) {
	echo "Could not parse the JUnit report at {$report_path}.\n";
	exit( 1 );
}

echo render_summary( $components );
exit( 0 );

/**
 * Reads a JUnit XML report and tallies its test cases per component.
 *
 * @param string $path Path to the JUnit XML file.
 * @return array|null Per-component totals keyed by component name, sorted by
 *                    name, or null if the file is not valid XML.
 */
function summarize_junit_report( $path ) {
	$xml = file_get_contents( $path );
	if ( false === $xml ) {
		return null;
	}

	$document           = new DOMDocument();
	$previous_use_error = libxml_use_internal_errors( true );
	$loaded             = $document->loadXML( $xml, LIBXML_NONET | LIBXML_PARSEHUGE );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous_use_error );
	if ( ! $loaded ) {
		return null;
	}

	$components = array();
	foreach ( $document->getElementsByTagName( 'testcase' ) as $testcase ) {
		$component = component_from_test_file( $testcase->getAttribute( 'file' ) );
		if ( ! isset( $components[ $component ] ) ) {
			$components[ $component ] = array(
				'tests'      => 0,
				'assertions' => 0,
				'failed'     => 0,
				'skipped'    => array(),
			);
		}

		// <testcase> elements never nest, so a descendant lookup only sees
		// this test's own <failure>, <error>, and <skipped> children.
		$is_failed  = $testcase->getElementsByTagName( 'failure' )->length > 0
			|| $testcase->getElementsByTagName( 'error' )->length > 0;
		$is_skipped = $testcase->getElementsByTagName( 'skipped' )->length > 0;

		++$components[ $component ]['tests'];
		$components[ $component ]['assertions'] += (int) $testcase->getAttribute( 'assertions' );
		if ( $is_failed ) {
			++$components[ $component ]['failed'];
		}
		if ( $is_skipped ) {
			$components[ $component ]['skipped'][] = test_case_label( $testcase );
		}
	}

	ksort( $components, SORT_STRING | SORT_FLAG_CASE );

	// Keep the catch-all bucket at the bottom of the table.
	if ( isset( $components[ OTHER_COMPONENT ] ) ) {
		$other = $components[ OTHER_COMPONENT ];
		unset( $components[ OTHER_COMPONENT ] );
		$components[ OTHER_COMPONENT ] = $other;
	}

	return $components;
}

/**
 * Maps a test file path to the component it belongs to.
 *
 * Accepts absolute or relative paths with either slash style, e.g.
 * `/home/runner/work/php-toolkit/php-toolkit/components/Zip/Tests/ZipEncoderTest.php`
 * or `D:\a\php-toolkit\php-toolkit\components\Zip\Tests\ZipEncoderTest.php`.
 *
 * @param string $file The <testcase> `file` attribute.
 * @return string The component name, or OTHER_COMPONENT.
 */
function component_from_test_file( $file ) {
	$file = str_replace( '\\', '/', $file );
	if ( preg_match( '#(?:^|/)components/([^/]+)/Tests/#', $file, $matches ) ) {
		return $matches[1];
	}
	return OTHER_COMPONENT;
}

/**
 * Builds a readable `Class::method with data set ...` label for a test case.
 *
 * @param DOMElement $testcase The <testcase> element.
 * @return string
 */
function test_case_label( $testcase ) {
	$class = $testcase->getAttribute( 'class' );
	if ( '' === $class ) {
		$class = $testcase->getAttribute( 'classname' );
	}
	$name = $testcase->getAttribute( 'name' );
	return '' === $class ? $name : $class . '::' . $name;
}

/**
 * Renders the per-component table and the skipped-test lists as plain text.
 *
 * @param array $components Output of summarize_junit_report().
 * @return string
 */
function render_summary( $components ) {
	if ( empty( $components ) ) {
		return "The JUnit report contains no test cases.\n";
	}

	$rows   = array( array( 'Component', 'Tests', 'Assertions', 'Failed', 'Skipped' ) );
	$totals = array( 0, 0, 0, 0 );
	foreach ( $components as $name => $counts ) {
		$row    = array(
			$counts['tests'],
			$counts['assertions'],
			$counts['failed'],
			count( $counts['skipped'] ),
		);
		$rows[] = array_merge( array( $name ), array_map( 'number_format', $row ) );
		foreach ( $row as $i => $value ) {
			$totals[ $i ] += $value;
		}
	}
	$rows[] = array_merge( array( 'Total' ), array_map( 'number_format', $totals ) );

	// Left-align the component column, right-align the numbers.
	$widths = array();
	foreach ( $rows as $row ) {
		foreach ( $row as $i => $cell ) {
			$widths[ $i ] = max( isset( $widths[ $i ] ) ? $widths[ $i ] : 0, strlen( $cell ) );
		}
	}
	$lines = array();
	foreach ( $rows as $row ) {
		$cells = array();
		foreach ( $row as $i => $cell ) {
			$cells[] = str_pad( $cell, $widths[ $i ], ' ', 0 === $i ? STR_PAD_RIGHT : STR_PAD_LEFT );
		}
		$lines[] = implode( '  ', $cells );
	}
	// Separate the header and the total row from the components.
	$rule = str_repeat( '-', strlen( $lines[0] ) );
	array_splice( $lines, 1, 0, $rule );
	array_splice( $lines, count( $lines ) - 1, 0, $rule );
	$out = implode( "\n", $lines ) . "\n";

	if ( 0 === $totals[3] ) {
		return $out;
	}

	$out .= "\nSkipped tests:\n";
	foreach ( $components as $name => $counts ) {
		$skipped = count( $counts['skipped'] );
		if ( 0 === $skipped ) {
			continue;
		}
		if ( $skipped >= SKIPPED_LIST_LIMIT ) {
			$out .= sprintf( "  %s: %s skipped (too many to list)\n", $name, number_format( $skipped ) );
			continue;
		}
		$out .= sprintf( "  %s (%d):\n", $name, $skipped );
		foreach ( $counts['skipped'] as $label ) {
			$out .= '    ' . single_line_text( $label ) . "\n";
		}
	}

	return $out;
}

/**
 * Flattens a test label to one bounded line for the log.
 *
 * Data set names are arbitrary strings, so they may contain newlines or be
 * very long.
 *
 * @param string $text Raw text.
 * @return string
 */
function single_line_text( $text ) {
	$text = preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $text );
	if ( strlen( $text ) > 200 ) {
		$text = substr( $text, 0, 197 ) . '...';
	}
	return $text;
}
