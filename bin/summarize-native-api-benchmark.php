<?php
/**
 * Summarize native API benchmark JSON for the public release page.
 *
 * Usage:
 *   php bin/summarize-native-api-benchmark.php benchmark.json benchmark-summary.json
 *
 * @package WordPress
 */

declare(strict_types=1);

if ( $argc < 3 ) {
	fwrite( STDERR, "Usage: php bin/summarize-native-api-benchmark.php <benchmark.json> <summary.json>\n" );
	exit( 1 );
}

$input_path  = $argv[1];
$output_path = $argv[2];

$contents = file_get_contents( $input_path );
if ( false === $contents ) {
	fwrite( STDERR, "Unable to read benchmark JSON: {$input_path}\n" );
	exit( 1 );
}

$rows = json_decode( $contents, true );
if ( ! is_array( $rows ) ) {
	fwrite( STDERR, "Invalid benchmark JSON: {$input_path}\n" );
	exit( 1 );
}

$by_name = array();
foreach ( $rows as $row ) {
	if ( ! is_array( $row ) || empty( $row['available'] ) || ! isset( $row['name'], $row['implementation'] ) ) {
		continue;
	}

	$name           = (string) $row['name'];
	$implementation = (string) $row['implementation'];
	if ( ! isset( $by_name[ $name ] ) ) {
		$by_name[ $name ] = array();
	}
	$by_name[ $name ][ $implementation ] = $row;
}

$comparisons = array();
foreach ( $by_name as $name => $implementations ) {
	if ( ! isset( $implementations['php'], $implementations['native'] ) ) {
		continue;
	}

	$php_row     = $implementations['php'];
	$native_row  = $implementations['native'];
	$php_wall    = isset( $php_row['wall_seconds'] ) ? (float) $php_row['wall_seconds'] : 0.0;
	$native_wall = isset( $native_row['wall_seconds'] ) ? (float) $native_row['wall_seconds'] : 0.0;

	if ( 0.0 >= $php_wall || 0.0 >= $native_wall ) {
		continue;
	}

	$comparisons[] = array(
		'name'              => $name,
		'label'             => wp_toolkit_native_api_benchmark_label( $name ),
		'component'         => wp_toolkit_native_api_benchmark_component( $name ),
		'iterations'        => isset( $php_row['iterations'] ) ? (int) $php_row['iterations'] : null,
		'operations'        => isset( $php_row['operations'] ) ? (int) $php_row['operations'] : null,
		'phpWallSeconds'    => round( $php_wall, 6 ),
		'nativeWallSeconds' => round( $native_wall, 6 ),
		'speedup'           => round( $php_wall / $native_wall, 2 ),
		'phpClass'          => isset( $php_row['class'] ) ? (string) $php_row['class'] : '',
		'nativeClass'       => isset( $native_row['class'] ) ? (string) $native_row['class'] : '',
	);
}

usort(
	$comparisons,
	function ( $a, $b ) {
		if ( $a['speedup'] === $b['speedup'] ) {
			return strcmp( $a['name'], $b['name'] );
		}
		return $a['speedup'] < $b['speedup'] ? 1 : -1;
	}
);

$summary = array(
	'generatedAt' => gmdate( 'c' ),
	'command'     => 'php -d extension=extensions/native-apis/target/release/libwp_native_apis.so bin/benchmark-native-apis.php --iterations=50 --mode=both --disable-native-defaults --require-native',
	'count'       => count( $comparisons ),
	'top'         => array_slice( $comparisons, 0, 8 ),
	'comparisons' => $comparisons,
);

$json = json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
if ( false === $json || false === file_put_contents( $output_path, $json . "\n" ) ) {
	fwrite( STDERR, "Unable to write benchmark summary: {$output_path}\n" );
	exit( 1 );
}

/**
 * Convert a workload name into a short public label.
 *
 * @param string $name Workload name.
 * @return string Human-friendly label.
 */
function wp_toolkit_native_api_benchmark_label( $name ) {
	$labels = array(
		'html-tag-processor'                         => 'HTML tag scan',
		'html-tag-prefix-count'                      => 'HTML prefix count',
		'html-tag-batch'                             => 'HTML tag batch',
		'html-matching-tag-batch'                    => 'HTML matching tag batch',
		'html-matching-tag-attribute-batch'          => 'HTML tag + attribute batch',
		'html-matching-tag-attributes-batch'         => 'HTML tag + attributes batch',
		'html-link-audit-summary'                    => 'HTML link audit summary',
		'html-tag-inventory-summary'                 => 'HTML tag inventory',
		'html-heading-inventory-summary'             => 'HTML heading inventory',
		'html-id-inventory-summary'                  => 'HTML ID inventory',
		'html-attribute-inventory-summary'           => 'HTML attribute inventory',
		'html-data-attribute-inventory-summary'      => 'HTML data-* inventory',
		'html-aria-attribute-inventory-summary'      => 'HTML ARIA inventory',
		'html-class-inventory-summary'               => 'HTML class inventory',
		'html-resource-inventory-summary'            => 'HTML resource inventory',
		'html-image-inventory-summary'               => 'HTML image inventory',
		'html-script-inventory-summary'              => 'HTML script inventory',
		'html-form-inventory-summary'                => 'HTML form inventory',
		'html-tag-prefix-batch'                      => 'HTML prefix batch',
		'html-tag-prefix-count-batch'                => 'HTML prefix count batch',
		'html-tag-prefix-summary'                    => 'HTML prefix summary',
		'html-tag-sanitizer'                         => 'HTML sanitizer',
		'html-processor'                             => 'HTML fragment processor',
		'html-token-batch'                           => 'HTML token batch',
		'xml-processor'                              => 'XML processor scan',
		'xml-token-summary'                          => 'XML token summary',
		'xml-document-inventory-summary'             => 'XML document inventory',
		'xml-element-inventory-summary'              => 'XML element inventory',
		'xml-depth-inventory-summary'                => 'XML depth inventory',
		'xml-leaf-inventory-summary'                 => 'XML leaf inventory',
		'xml-structural-inventory-summary'           => 'XML structural inventory',
		'xml-attribute-inventory-summary'            => 'XML attribute inventory',
		'xml-id-inventory-summary'                   => 'XML ID inventory',
		'xml-namespace-inventory-summary'            => 'XML namespace inventory',
		'xml-text-inventory-summary'                 => 'XML text inventory',
		'xml-processing-instruction-inventory-summary' => 'XML processing instruction inventory',
		'xml-comment-inventory-summary'              => 'XML comment inventory',
		'xml-payload-inventory-summary'              => 'XML payload inventory',
		'xml-content-inventory-summary'              => 'XML content inventory',
		'xml-import-inventory-summary'               => 'XML import inventory',
		'xml-token-batch'                            => 'XML token batch',
		'xml-tag-summary'                            => 'XML tag summary',
		'xml-tag-batch'                              => 'XML tag batch',
		'xml-matching-tag-batch'                     => 'XML matching tag batch',
		'xml-matching-tag-count-batch'               => 'XML matching tag count batch',
		'xml-matching-tag-summary'                   => 'XML matching tag summary',
		'xml-matching-tag-attributes-summary'        => 'XML matching tag attributes summary',
		'xml-tag-count-batch'                        => 'XML tag count batch',
		'xml-prefix-summary'                         => 'XML prefix summary',
		'xml-prefix-sanitizer'                       => 'XML prefix sanitizer',
		'url-in-text-processor'                      => 'URL-in-text scan',
	);

	if ( isset( $labels[ $name ] ) ) {
		return $labels[ $name ];
	}

	return ucwords( str_replace( '-', ' ', $name ) );
}

/**
 * Infer component name from workload name.
 *
 * @param string $name Workload name.
 * @return string Component name.
 */
function wp_toolkit_native_api_benchmark_component( $name ) {
	if ( 0 === strpos( $name, 'html-' ) ) {
		return 'HTML';
	}
	if ( 0 === strpos( $name, 'xml-' ) ) {
		return 'XML';
	}
	if ( 0 === strpos( $name, 'url-' ) ) {
		return 'URL-in-text';
	}
	return 'Native APIs';
}
