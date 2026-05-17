<?php
/**
 * Benchmark representative HTML, XML, and URL-in-text API workloads.
 *
 * @package WordPress
 */

use WordPress\DataLiberation\URL\URLInTextProcessor;
use WordPress\XML\XMLProcessor;

$options    = wp_toolkit_native_api_benchmark_parse_options( $argv );
$iterations = $options['iterations'];
$component  = $options['component'];
$mode       = $options['mode'];
$required   = $options['required'];
$results    = array();

if ( $options['disable_native_defaults'] && ! defined( 'WP_NATIVE_APIS_DISABLE_DEFAULTS' ) ) {
	define( 'WP_NATIVE_APIS_DISABLE_DEFAULTS', true );
}

require_once dirname( __DIR__ ) . '/bootstrap.php';

$GLOBALS['wp_toolkit_native_api_benchmark_name_filter'] = $options['name'];

if ( 'all' === $component || 'html' === $component ) {
	if ( wp_toolkit_native_api_benchmark_should_run( $mode, 'php' ) ) {
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-tag-processor',
			'php',
			$iterations,
			'WP_HTML_Tag_Processor',
			'wp_toolkit_native_api_benchmark_html_tags'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-tag-prefix-count',
			'php',
			$iterations,
			'WP_HTML_Tag_Processor',
			'wp_toolkit_native_api_benchmark_html_tag_prefix_count'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-tag-batch',
			'php',
			$iterations,
			'WP_HTML_Tag_Processor',
			'wp_toolkit_native_api_benchmark_html_tag_batch'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-matching-tag-batch',
			'php',
			$iterations,
			'WP_HTML_Tag_Processor',
			'wp_toolkit_native_api_benchmark_html_matching_tag_batch'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-matching-tag-attribute-batch',
			'php',
			$iterations,
			'WP_HTML_Tag_Processor',
			'wp_toolkit_native_api_benchmark_html_matching_tag_attribute_batch'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-matching-tag-attributes-batch',
			'php',
			$iterations,
			'WP_HTML_Tag_Processor',
			'wp_toolkit_native_api_benchmark_html_matching_tag_attributes_batch'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-link-audit-summary',
			'php',
			$iterations,
			'WP_HTML_Tag_Processor',
			'wp_toolkit_native_api_benchmark_html_link_audit_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-tag-inventory-summary',
			'php',
			$iterations,
			'WP_HTML_Tag_Processor',
			'wp_toolkit_native_api_benchmark_html_tag_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-heading-inventory-summary',
			'php',
			$iterations,
			'WP_HTML_Tag_Processor',
			'wp_toolkit_native_api_benchmark_html_heading_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-id-inventory-summary',
			'php',
			$iterations,
			'WP_HTML_Tag_Processor',
			'wp_toolkit_native_api_benchmark_html_id_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-attribute-inventory-summary',
			'php',
			$iterations,
			'WP_HTML_Tag_Processor',
			'wp_toolkit_native_api_benchmark_html_attribute_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-data-attribute-inventory-summary',
			'php',
			$iterations,
			'WP_HTML_Tag_Processor',
			'wp_toolkit_native_api_benchmark_html_data_attribute_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-aria-attribute-inventory-summary',
			'php',
			$iterations,
			'WP_HTML_Tag_Processor',
			'wp_toolkit_native_api_benchmark_html_aria_attribute_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-class-inventory-summary',
			'php',
			$iterations,
			'WP_HTML_Tag_Processor',
			'wp_toolkit_native_api_benchmark_html_class_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-resource-inventory-summary',
			'php',
			$iterations,
			'WP_HTML_Tag_Processor',
			'wp_toolkit_native_api_benchmark_html_resource_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-image-inventory-summary',
			'php',
			$iterations,
			'WP_HTML_Tag_Processor',
			'wp_toolkit_native_api_benchmark_html_image_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-script-inventory-summary',
			'php',
			$iterations,
			'WP_HTML_Tag_Processor',
			'wp_toolkit_native_api_benchmark_html_script_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-form-inventory-summary',
			'php',
			$iterations,
			'WP_HTML_Tag_Processor',
			'wp_toolkit_native_api_benchmark_html_form_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-tag-prefix-batch',
			'php',
			$iterations,
			'WP_HTML_Tag_Processor',
			'wp_toolkit_native_api_benchmark_html_tag_prefix_batch'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-tag-prefix-count-batch',
			'php',
			$iterations,
			'WP_HTML_Tag_Processor',
			'wp_toolkit_native_api_benchmark_html_tag_prefix_count_batch'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-tag-prefix-summary',
			'php',
			$iterations,
			'WP_HTML_Tag_Processor',
			'wp_toolkit_native_api_benchmark_html_tag_prefix_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-tag-sanitizer',
			'php',
			$iterations,
			'WP_HTML_Tag_Processor',
			'wp_toolkit_native_api_benchmark_html_tag_sanitizer'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-processor',
			'php',
			$iterations,
			'WP_HTML_Processor',
			'wp_toolkit_native_api_benchmark_html_processor'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-token-batch',
			'php',
			$iterations,
			'WP_HTML_Processor',
			'wp_toolkit_native_api_benchmark_html_token_batch'
		);
	}

	if ( wp_toolkit_native_api_benchmark_should_run( $mode, 'native' ) ) {
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-tag-processor',
			'native',
			$iterations,
			'WP_HTML_Native_Tag_Processor',
			'wp_toolkit_native_api_benchmark_native_html_tags'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-tag-prefix-count',
			'native',
			$iterations,
			'WP_HTML_Native_Tag_Processor',
			'wp_toolkit_native_api_benchmark_native_html_tag_prefix_count'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-tag-batch',
			'native',
			$iterations,
			'WP_HTML_Native_Tag_Processor',
			'wp_toolkit_native_api_benchmark_native_html_tag_batch'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-matching-tag-batch',
			'native',
			$iterations,
			'WP_HTML_Native_Tag_Processor',
			'wp_toolkit_native_api_benchmark_native_html_matching_tag_batch'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-matching-tag-attribute-batch',
			'native',
			$iterations,
			'WP_HTML_Native_Tag_Processor',
			'wp_toolkit_native_api_benchmark_native_html_matching_tag_attribute_batch'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-matching-tag-attributes-batch',
			'native',
			$iterations,
			'WP_HTML_Native_Tag_Processor',
			'wp_toolkit_native_api_benchmark_native_html_matching_tag_attributes_batch'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-link-audit-summary',
			'native',
			$iterations,
			'WP_HTML_Native_Tag_Processor',
			'wp_toolkit_native_api_benchmark_native_html_link_audit_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-tag-inventory-summary',
			'native',
			$iterations,
			'WP_HTML_Native_Tag_Processor',
			'wp_toolkit_native_api_benchmark_native_html_tag_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-heading-inventory-summary',
			'native',
			$iterations,
			'WP_HTML_Native_Tag_Processor',
			'wp_toolkit_native_api_benchmark_native_html_heading_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-id-inventory-summary',
			'native',
			$iterations,
			'WP_HTML_Native_Tag_Processor',
			'wp_toolkit_native_api_benchmark_native_html_id_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-attribute-inventory-summary',
			'native',
			$iterations,
			'WP_HTML_Native_Tag_Processor',
			'wp_toolkit_native_api_benchmark_native_html_attribute_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-data-attribute-inventory-summary',
			'native',
			$iterations,
			'WP_HTML_Native_Tag_Processor',
			'wp_toolkit_native_api_benchmark_native_html_data_attribute_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-aria-attribute-inventory-summary',
			'native',
			$iterations,
			'WP_HTML_Native_Tag_Processor',
			'wp_toolkit_native_api_benchmark_native_html_aria_attribute_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-class-inventory-summary',
			'native',
			$iterations,
			'WP_HTML_Native_Tag_Processor',
			'wp_toolkit_native_api_benchmark_native_html_class_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-resource-inventory-summary',
			'native',
			$iterations,
			'WP_HTML_Native_Tag_Processor',
			'wp_toolkit_native_api_benchmark_native_html_resource_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-image-inventory-summary',
			'native',
			$iterations,
			'WP_HTML_Native_Tag_Processor',
			'wp_toolkit_native_api_benchmark_native_html_image_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-script-inventory-summary',
			'native',
			$iterations,
			'WP_HTML_Native_Tag_Processor',
			'wp_toolkit_native_api_benchmark_native_html_script_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-form-inventory-summary',
			'native',
			$iterations,
			'WP_HTML_Native_Tag_Processor',
			'wp_toolkit_native_api_benchmark_native_html_form_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-tag-prefix-batch',
			'native',
			$iterations,
			'WP_HTML_Native_Tag_Processor',
			'wp_toolkit_native_api_benchmark_native_html_tag_prefix_batch'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-tag-prefix-count-batch',
			'native',
			$iterations,
			'WP_HTML_Native_Tag_Processor',
			'wp_toolkit_native_api_benchmark_native_html_tag_prefix_count_batch'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-tag-prefix-summary',
			'native',
			$iterations,
			'WP_HTML_Native_Tag_Processor',
			'wp_toolkit_native_api_benchmark_native_html_tag_prefix_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-tag-sanitizer',
			'native',
			$iterations,
			'WP_HTML_Native_Tag_Processor',
			'wp_toolkit_native_api_benchmark_native_html_tag_sanitizer'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-processor',
			'native',
			$iterations,
			'WP_HTML_Native_Processor',
			'wp_toolkit_native_api_benchmark_native_html_processor'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'html-token-batch',
			'native',
			$iterations,
			'WP_HTML_Native_Processor',
			'wp_toolkit_native_api_benchmark_native_html_token_batch'
		);
	}
}

if ( 'all' === $component || 'xml' === $component ) {
	if ( wp_toolkit_native_api_benchmark_should_run( $mode, 'php' ) ) {
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-processor',
			'php',
			$iterations,
			XMLProcessor::class,
			'wp_toolkit_native_api_benchmark_xml_processor'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-token-summary',
			'php',
			$iterations,
			XMLProcessor::class,
			'wp_toolkit_native_api_benchmark_xml_token_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-document-inventory-summary',
			'php',
			$iterations,
			XMLProcessor::class,
			'wp_toolkit_native_api_benchmark_xml_document_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-element-inventory-summary',
			'php',
			$iterations,
			XMLProcessor::class,
			'wp_toolkit_native_api_benchmark_xml_element_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-depth-inventory-summary',
			'php',
			$iterations,
			XMLProcessor::class,
			'wp_toolkit_native_api_benchmark_xml_depth_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-leaf-inventory-summary',
			'php',
			$iterations,
			XMLProcessor::class,
			'wp_toolkit_native_api_benchmark_xml_leaf_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-structural-inventory-summary',
			'php',
			$iterations,
			XMLProcessor::class,
			'wp_toolkit_native_api_benchmark_xml_structural_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-attribute-inventory-summary',
			'php',
			$iterations,
			XMLProcessor::class,
			'wp_toolkit_native_api_benchmark_xml_attribute_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-id-inventory-summary',
			'php',
			$iterations,
			XMLProcessor::class,
			'wp_toolkit_native_api_benchmark_xml_id_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-namespace-inventory-summary',
			'php',
			$iterations,
			XMLProcessor::class,
			'wp_toolkit_native_api_benchmark_xml_namespace_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-text-inventory-summary',
			'php',
			$iterations,
			XMLProcessor::class,
			'wp_toolkit_native_api_benchmark_xml_text_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-processing-instruction-inventory-summary',
			'php',
			$iterations,
			XMLProcessor::class,
			'wp_toolkit_native_api_benchmark_xml_processing_instruction_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-comment-inventory-summary',
			'php',
			$iterations,
			XMLProcessor::class,
			'wp_toolkit_native_api_benchmark_xml_comment_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-payload-inventory-summary',
			'php',
			$iterations,
			XMLProcessor::class,
			'wp_toolkit_native_api_benchmark_xml_payload_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-content-inventory-summary',
			'php',
			$iterations,
			XMLProcessor::class,
			'wp_toolkit_native_api_benchmark_xml_content_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-import-inventory-summary',
			'php',
			$iterations,
			XMLProcessor::class,
			'wp_toolkit_native_api_benchmark_xml_import_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-token-batch',
			'php',
			$iterations,
			XMLProcessor::class,
			'wp_toolkit_native_api_benchmark_xml_token_batch'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-tag-summary',
			'php',
			$iterations,
			XMLProcessor::class,
			'wp_toolkit_native_api_benchmark_xml_tag_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-tag-batch',
			'php',
			$iterations,
			XMLProcessor::class,
			'wp_toolkit_native_api_benchmark_xml_tag_batch'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-matching-tag-batch',
			'php',
			$iterations,
			XMLProcessor::class,
			'wp_toolkit_native_api_benchmark_xml_matching_tag_batch'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-matching-tag-count-batch',
			'php',
			$iterations,
			XMLProcessor::class,
			'wp_toolkit_native_api_benchmark_xml_matching_tag_count_batch'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-matching-tag-summary',
			'php',
			$iterations,
			XMLProcessor::class,
			'wp_toolkit_native_api_benchmark_xml_matching_tag_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-matching-tag-attributes-summary',
			'php',
			$iterations,
			XMLProcessor::class,
			'wp_toolkit_native_api_benchmark_xml_matching_tag_attributes_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-tag-count-batch',
			'php',
			$iterations,
			XMLProcessor::class,
			'wp_toolkit_native_api_benchmark_xml_tag_count_batch'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-prefix-summary',
			'php',
			$iterations,
			XMLProcessor::class,
			'wp_toolkit_native_api_benchmark_xml_prefix_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-prefix-sanitizer',
			'php',
			$iterations,
			XMLProcessor::class,
			'wp_toolkit_native_api_benchmark_xml_prefix_sanitizer'
		);
	}

	if ( wp_toolkit_native_api_benchmark_should_run( $mode, 'native' ) ) {
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-processor',
			'native',
			$iterations,
			'WordPress\\XML\\NativeXMLProcessor',
			'wp_toolkit_native_api_benchmark_native_xml_processor'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-token-summary',
			'native',
			$iterations,
			'WordPress\\XML\\NativeXMLProcessor',
			'wp_toolkit_native_api_benchmark_native_xml_token_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-document-inventory-summary',
			'native',
			$iterations,
			'WordPress\\XML\\NativeXMLProcessor',
			'wp_toolkit_native_api_benchmark_native_xml_document_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-element-inventory-summary',
			'native',
			$iterations,
			'WordPress\\XML\\NativeXMLProcessor',
			'wp_toolkit_native_api_benchmark_native_xml_element_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-depth-inventory-summary',
			'native',
			$iterations,
			'WordPress\\XML\\NativeXMLProcessor',
			'wp_toolkit_native_api_benchmark_native_xml_depth_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-leaf-inventory-summary',
			'native',
			$iterations,
			'WordPress\\XML\\NativeXMLProcessor',
			'wp_toolkit_native_api_benchmark_native_xml_leaf_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-structural-inventory-summary',
			'native',
			$iterations,
			'WordPress\\XML\\NativeXMLProcessor',
			'wp_toolkit_native_api_benchmark_native_xml_structural_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-attribute-inventory-summary',
			'native',
			$iterations,
			'WordPress\\XML\\NativeXMLProcessor',
			'wp_toolkit_native_api_benchmark_native_xml_attribute_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-id-inventory-summary',
			'native',
			$iterations,
			'WordPress\\XML\\NativeXMLProcessor',
			'wp_toolkit_native_api_benchmark_native_xml_id_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-namespace-inventory-summary',
			'native',
			$iterations,
			'WordPress\\XML\\NativeXMLProcessor',
			'wp_toolkit_native_api_benchmark_native_xml_namespace_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-text-inventory-summary',
			'native',
			$iterations,
			'WordPress\\XML\\NativeXMLProcessor',
			'wp_toolkit_native_api_benchmark_native_xml_text_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-processing-instruction-inventory-summary',
			'native',
			$iterations,
			'WordPress\\XML\\NativeXMLProcessor',
			'wp_toolkit_native_api_benchmark_native_xml_processing_instruction_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-comment-inventory-summary',
			'native',
			$iterations,
			'WordPress\\XML\\NativeXMLProcessor',
			'wp_toolkit_native_api_benchmark_native_xml_comment_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-payload-inventory-summary',
			'native',
			$iterations,
			'WordPress\\XML\\NativeXMLProcessor',
			'wp_toolkit_native_api_benchmark_native_xml_payload_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-content-inventory-summary',
			'native',
			$iterations,
			'WordPress\\XML\\NativeXMLProcessor',
			'wp_toolkit_native_api_benchmark_native_xml_content_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-import-inventory-summary',
			'native',
			$iterations,
			'WordPress\\XML\\NativeXMLProcessor',
			'wp_toolkit_native_api_benchmark_native_xml_import_inventory_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-token-batch',
			'native',
			$iterations,
			'WordPress\\XML\\NativeXMLProcessor',
			'wp_toolkit_native_api_benchmark_native_xml_token_batch'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-tag-summary',
			'native',
			$iterations,
			'WordPress\\XML\\NativeXMLProcessor',
			'wp_toolkit_native_api_benchmark_native_xml_tag_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-tag-batch',
			'native',
			$iterations,
			'WordPress\\XML\\NativeXMLProcessor',
			'wp_toolkit_native_api_benchmark_native_xml_tag_batch'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-matching-tag-batch',
			'native',
			$iterations,
			'WordPress\\XML\\NativeXMLProcessor',
			'wp_toolkit_native_api_benchmark_native_xml_matching_tag_batch'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-matching-tag-count-batch',
			'native',
			$iterations,
			'WordPress\\XML\\NativeXMLProcessor',
			'wp_toolkit_native_api_benchmark_native_xml_matching_tag_count_batch'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-matching-tag-summary',
			'native',
			$iterations,
			'WordPress\\XML\\NativeXMLProcessor',
			'wp_toolkit_native_api_benchmark_native_xml_matching_tag_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-matching-tag-attributes-summary',
			'native',
			$iterations,
			'WordPress\\XML\\NativeXMLProcessor',
			'wp_toolkit_native_api_benchmark_native_xml_matching_tag_attributes_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-tag-count-batch',
			'native',
			$iterations,
			'WordPress\\XML\\NativeXMLProcessor',
			'wp_toolkit_native_api_benchmark_native_xml_tag_count_batch'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-prefix-summary',
			'native',
			$iterations,
			'WordPress\\XML\\NativeXMLProcessor',
			'wp_toolkit_native_api_benchmark_native_xml_prefix_summary'
		);
		$results[] = wp_toolkit_native_api_benchmark_run(
			'xml-prefix-sanitizer',
			'native',
			$iterations,
			'WordPress\\XML\\NativeXMLProcessor',
			'wp_toolkit_native_api_benchmark_native_xml_prefix_sanitizer'
		);
	}
}

if ( 'all' === $component || 'url' === $component ) {
	if ( wp_toolkit_native_api_benchmark_should_run( $mode, 'php' ) ) {
		$results[] = wp_toolkit_native_api_benchmark_run(
			'url-in-text-processor',
			'php',
			$iterations,
			URLInTextProcessor::class,
			'wp_toolkit_native_api_benchmark_url_in_text_processor'
		);
	}

	if ( wp_toolkit_native_api_benchmark_should_run( $mode, 'native' ) ) {
		$results[] = wp_toolkit_native_api_benchmark_run(
			'url-in-text-processor',
			'native',
			$iterations,
			'WordPress\\DataLiberation\\URL\\NativeURLInTextProcessor',
			'wp_toolkit_native_api_benchmark_native_url_in_text_processor'
		);
	}
}

$results = array_values( array_filter( $results ) );

if ( null !== $options['name'] && empty( $results ) ) {
	fwrite( STDERR, "No benchmark workload matched --name={$options['name']}.\n" );
	exit( 1 );
}

echo json_encode( $results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";

wp_toolkit_native_api_benchmark_maybe_fail_for_missing_results( $results, $required );

/**
 * Parse command line options.
 *
 * @param array $argv Raw CLI arguments.
 * @return array
 */
function wp_toolkit_native_api_benchmark_parse_options( $argv ) {
	$options = array(
		'iterations'              => 25,
		'component'               => 'all',
		'mode'                    => 'php',
		'required'                => false,
		'name'                    => null,
		'disable_native_defaults' => false,
	);

	foreach ( array_slice( $argv, 1 ) as $arg ) {
		if ( 0 === strpos( $arg, '--iterations=' ) ) {
			$options['iterations'] = max( 1, (int) substr( $arg, strlen( '--iterations=' ) ) );
			continue;
		}

		if ( 0 === strpos( $arg, '--component=' ) ) {
			$options['component'] = strtolower( substr( $arg, strlen( '--component=' ) ) );
			continue;
		}

		if ( 0 === strpos( $arg, '--mode=' ) ) {
			$options['mode'] = strtolower( substr( $arg, strlen( '--mode=' ) ) );
			continue;
		}

		if ( 0 === strpos( $arg, '--name=' ) ) {
			$options['name'] = substr( $arg, strlen( '--name=' ) );
			continue;
		}

		if ( '--help' === $arg || '-h' === $arg ) {
			fwrite(
				STDOUT,
				"Usage: php bin/benchmark-native-apis.php [--iterations=25] [--component=all|html|xml|url] [--mode=php|native|both] [--name=workload] [--disable-native-defaults] [--require-native]\n"
			);
			exit( 0 );
		}

		if ( '--disable-native-defaults' === $arg ) {
			$options['disable_native_defaults'] = true;
			continue;
		}

		if ( '--require-native' === $arg ) {
			$options['required'] = 'native';
			continue;
		}
	}

	if ( ! in_array( $options['component'], array( 'all', 'html', 'xml', 'url' ), true ) ) {
		fwrite( STDERR, "Invalid --component value. Expected all, html, xml, or url.\n" );
		exit( 1 );
	}

	if ( ! in_array( $options['mode'], array( 'php', 'native', 'both' ), true ) ) {
		fwrite( STDERR, "Invalid --mode value. Expected php, native, or both.\n" );
		exit( 1 );
	}

	return $options;
}

/**
 * Fail the benchmark when a required implementation row is unavailable.
 *
 * PHP-only development environments intentionally emit unavailable native rows
 * without failing. Release and post-build benchmarking jobs should pass
 * --require-native so missing extension classes fail loudly.
 *
 * @param array       $results Benchmark result rows.
 * @param string|bool $required Required implementation mode.
 */
function wp_toolkit_native_api_benchmark_maybe_fail_for_missing_results( $results, $required ) {
	if ( false === $required ) {
		return;
	}

	$missing = array();
	foreach ( $results as $result ) {
		if ( $required !== $result['implementation'] || ! empty( $result['available'] ) ) {
			continue;
		}

		$missing[] = sprintf(
			'%s (%s): %s',
			$result['name'],
			$result['implementation'],
			isset( $result['message'] ) ? $result['message'] : 'implementation is unavailable'
		);
	}

	if ( ! $missing ) {
		return;
	}

	fwrite(
		STDERR,
		"Required benchmark implementations are unavailable:\n - " . implode( "\n - ", $missing ) . "\n"
	);
	exit( 1 );
}

/**
 * Checks whether the selected implementation mode should run.
 *
 * @param string $selected_mode Selected benchmark mode.
 * @param string $target_mode   Candidate implementation mode.
 * @return bool Whether to run the implementation.
 */
function wp_toolkit_native_api_benchmark_should_run( $selected_mode, $target_mode ) {
	return 'both' === $selected_mode || $selected_mode === $target_mode;
}

/**
 * Run a benchmark workload.
 *
 * @param string   $name       Workload name.
 * @param string   $mode       Implementation mode.
 * @param int      $iterations Iteration count.
 * @param string   $class_name Expected class name.
 * @param callable $callback   Workload callback.
 * @return array
 */
function wp_toolkit_native_api_benchmark_run( $name, $mode, $iterations, $class_name, $callback ) {
	if (
		isset( $GLOBALS['wp_toolkit_native_api_benchmark_name_filter'] ) &&
		null !== $GLOBALS['wp_toolkit_native_api_benchmark_name_filter'] &&
		$name !== $GLOBALS['wp_toolkit_native_api_benchmark_name_filter']
	) {
		return null;
	}

	if ( ! class_exists( $class_name ) ) {
		return array(
			'name'           => $name,
			'implementation' => $mode,
			'class'          => $class_name,
			'available'      => false,
			'message'        => "Class {$class_name} is not available.",
		);
	}

	gc_collect_cycles();
	$start_memory = memory_get_usage( true );
	$start_peak   = memory_get_peak_usage( true );
	$start_wall   = microtime( true );
	$start_cpu    = wp_toolkit_native_api_benchmark_cpu_time();
	$operations   = 0;

	try {
		for ( $i = 0; $i < $iterations; $i++ ) {
			$operations += call_user_func( $callback );
		}
	} catch ( Throwable $exception ) {
		return array(
			'name'           => $name,
			'implementation' => $mode,
			'class'          => $class_name,
			'available'      => false,
			'message'        => $exception->getMessage(),
		);
	}

	$end_cpu = wp_toolkit_native_api_benchmark_cpu_time();
	$wall    = microtime( true ) - $start_wall;
	$cpu     = null === $start_cpu || null === $end_cpu ? null : $end_cpu - $start_cpu;
	$peak    = memory_get_peak_usage( true );

	return array(
		'name'             => $name,
		'implementation'   => $mode,
		'class'            => $class_name,
		'available'        => true,
		'iterations'       => $iterations,
		'operations'       => $operations,
		'wall_seconds'     => round( $wall, 6 ),
		'cpu_seconds'      => null === $cpu ? null : round( $cpu, 6 ),
		'peak_memory'      => $peak,
		'peak_memory_delta' => max( 0, $peak - $start_peak ),
		'memory_delta'     => memory_get_usage( true ) - $start_memory,
	);
}

/**
 * Get process CPU time when available.
 *
 * @return float|null
 */
function wp_toolkit_native_api_benchmark_cpu_time() {
	if ( ! function_exists( 'getrusage' ) ) {
		return null;
	}

	$usage = getrusage();
	if ( ! isset( $usage['ru_utime.tv_sec'], $usage['ru_utime.tv_usec'], $usage['ru_stime.tv_sec'], $usage['ru_stime.tv_usec'] ) ) {
		return null;
	}

	return (float) $usage['ru_utime.tv_sec']
		+ ( $usage['ru_utime.tv_usec'] / 1000000 )
		+ (float) $usage['ru_stime.tv_sec']
		+ ( $usage['ru_stime.tv_usec'] / 1000000 );
}

/**
 * Benchmark the lower-level HTML tag processor.
 *
 * @return int Number of tags visited.
 */
function wp_toolkit_native_api_benchmark_html_tags() {
	$html       = wp_toolkit_native_api_benchmark_html_document();
	$processor  = new WP_HTML_Tag_Processor( $html );
	$tag_count  = 0;
	$attr_count = 0;

	while ( $processor->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
		++$tag_count;
		$names = $processor->get_attribute_names_with_prefix( 'data-' );
		if ( is_array( $names ) ) {
			$attr_count += count( $names );
		}
	}

	return $tag_count + $attr_count;
}

/**
 * Benchmark the lower-level HTML tag processor with prefix-count reads.
 *
 * @return int Number of tags visited plus matching attributes counted.
 */
function wp_toolkit_native_api_benchmark_html_tag_prefix_count() {
	$html       = wp_toolkit_native_api_benchmark_html_document();
	$processor  = new WP_HTML_Tag_Processor( $html );
	$tag_count  = 0;
	$attr_count = 0;

	while ( $processor->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
		++$tag_count;
		$count = $processor->count_attribute_names_with_prefix( 'data-' );
		if ( is_int( $count ) ) {
			$attr_count += $count;
		}
	}

	return $tag_count + $attr_count;
}

/**
 * Benchmark the lower-level HTML tag processor with chunked tag summaries.
 *
 * @return int Number of tags visited.
 */
function wp_toolkit_native_api_benchmark_html_tag_batch() {
	$html      = wp_toolkit_native_api_benchmark_html_document();
	$processor = new WP_HTML_Tag_Processor( $html );
	$count     = 0;

	do {
		$batch = $processor->next_tag_compact_summary_batch( 256, true );
		if ( is_string( $batch ) && '' !== $batch ) {
			$count += wp_toolkit_native_api_benchmark_count_html_tag_batch( $batch );
		}
	} while ( is_string( $batch ) && '' !== $batch );

	return $count;
}

/**
 * Benchmark the lower-level HTML tag processor with chunked tag-name summaries.
 *
 * @return int Number of matching tags visited.
 */
function wp_toolkit_native_api_benchmark_html_matching_tag_batch() {
	$html      = wp_toolkit_native_api_benchmark_html_document();
	$processor = new WP_HTML_Tag_Processor( $html );
	$count     = 0;

	do {
		$batch = $processor->next_matching_tag_compact_summary_batch( 'a', 256, true );
		if ( is_string( $batch ) && '' !== $batch ) {
			$count += wp_toolkit_native_api_benchmark_count_html_tag_batch( $batch );
		}
	} while ( is_string( $batch ) && '' !== $batch );

	return $count;
}

/**
 * Benchmark the lower-level HTML tag processor with chunked tag-name and attribute summaries.
 *
 * @return int Number of matching tags visited plus attribute bytes consumed.
 */
function wp_toolkit_native_api_benchmark_html_matching_tag_attribute_batch() {
	$html      = wp_toolkit_native_api_benchmark_html_document();
	$processor = new WP_HTML_Tag_Processor( $html );
	$count     = 0;

	do {
		$batch = $processor->next_matching_tag_attribute_compact_summary_batch( 'a', 'href', 256, true );
		if ( is_string( $batch ) && '' !== $batch ) {
			$count += wp_toolkit_native_api_benchmark_count_html_tag_attribute_batch( $batch );
		}
	} while ( is_string( $batch ) && '' !== $batch );

	return $count;
}

/**
 * Benchmark the lower-level HTML tag processor with chunked tag-name and multi-attribute summaries.
 *
 * @return int Number of matching tags visited plus attribute bytes consumed.
 */
function wp_toolkit_native_api_benchmark_html_matching_tag_attributes_batch() {
	$html      = wp_toolkit_native_api_benchmark_html_link_document();
	$processor = new WP_HTML_Tag_Processor( $html );
	$count     = 0;

	do {
		$batch = $processor->next_matching_tag_attributes_compact_summary_batch( 'a', array( 'href', 'title', 'rel' ), 256, true );
		if ( is_string( $batch ) && '' !== $batch ) {
			$count += wp_toolkit_native_api_benchmark_count_html_tag_attributes_batch( $batch, 3 );
		}
	} while ( is_string( $batch ) && '' !== $batch );

	return $count;
}

/**
 * Benchmark a fused lower-level HTML link-audit summary.
 *
 * @return int Number of matching tags plus attributes and attribute bytes consumed.
 */
function wp_toolkit_native_api_benchmark_html_link_audit_summary() {
	$html      = wp_toolkit_native_api_benchmark_html_link_document();
	$processor = new WP_HTML_Tag_Processor( $html );
	$summary   = $processor->summarize_matching_tag_attributes( 'a', array( 'href', 'title', 'rel' ), true );

	if ( ! is_array( $summary ) || ! isset( $summary['tag_count'], $summary['attribute_count'], $summary['attribute_value_bytes'] ) ) {
		throw new RuntimeException( 'HTML link audit summary benchmark returned an invalid summary.' );
	}

	return (int) $summary['tag_count'] + (int) $summary['attribute_count'] + (int) $summary['attribute_value_bytes'];
}

/**
 * Benchmark a fused lower-level HTML tag inventory summary.
 *
 * @return int Number of tags plus attributes and unique tag names counted.
 */
function wp_toolkit_native_api_benchmark_html_tag_inventory_summary() {
	$html      = wp_toolkit_native_api_benchmark_html_document();
	$processor = new WP_HTML_Tag_Processor( $html );
	$summary   = $processor->summarize_tag_inventory( true );

	if (
		! is_array( $summary ) ||
		! isset( $summary['tag_count'], $summary['attribute_count'], $summary['unique_tag_name_count'] )
	) {
		throw new RuntimeException( 'HTML tag inventory summary benchmark returned an invalid summary.' );
	}

	return (int) $summary['tag_count'] + (int) $summary['attribute_count'] + (int) $summary['unique_tag_name_count'];
}

/**
 * Benchmark a fused lower-level HTML heading inventory summary.
 *
 * @return int Number of tags plus headings counted.
 */
function wp_toolkit_native_api_benchmark_html_heading_inventory_summary() {
	$html      = wp_toolkit_native_api_benchmark_html_document();
	$processor = new WP_HTML_Tag_Processor( $html );
	$summary   = $processor->summarize_heading_inventory( true );

	if (
		! is_array( $summary ) ||
		! isset( $summary['tag_count'], $summary['heading_count'], $summary['h2_count'] )
	) {
		throw new RuntimeException( 'HTML heading inventory summary benchmark returned an invalid summary.' );
	}

	return (int) $summary['tag_count'] + (int) $summary['heading_count'] + (int) $summary['h2_count'];
}

/**
 * Benchmark a fused lower-level HTML ID inventory summary.
 *
 * @return int Number of tags plus ID counts and value bytes counted.
 */
function wp_toolkit_native_api_benchmark_html_id_inventory_summary() {
	$html      = wp_toolkit_native_api_benchmark_html_id_document();
	$processor = new WP_HTML_Tag_Processor( $html );
	$summary   = $processor->summarize_id_inventory( true );

	if (
		! is_array( $summary ) ||
		! isset( $summary['tag_count'], $summary['id_tag_count'], $summary['duplicate_id_count'], $summary['id_value_bytes'] )
	) {
		throw new RuntimeException( 'HTML ID inventory summary benchmark returned an invalid summary.' );
	}

	return (int) $summary['tag_count'] + (int) $summary['id_tag_count'] + (int) $summary['duplicate_id_count'] + (int) $summary['id_value_bytes'];
}

/**
 * Benchmark a fused lower-level HTML attribute inventory summary.
 *
 * @return int Number of tags plus attributes and value bytes counted.
 */
function wp_toolkit_native_api_benchmark_html_attribute_inventory_summary() {
	$html      = wp_toolkit_native_api_benchmark_html_document();
	$processor = new WP_HTML_Tag_Processor( $html );
	$summary   = $processor->summarize_attribute_inventory( true );

	if (
		! is_array( $summary ) ||
		! isset( $summary['tag_count'], $summary['attribute_count'], $summary['attribute_value_bytes'] )
	) {
		throw new RuntimeException( 'HTML attribute inventory summary benchmark returned an invalid summary.' );
	}

	return (int) $summary['tag_count'] + (int) $summary['attribute_count'] + (int) $summary['attribute_value_bytes'];
}

/**
 * Benchmark a fused lower-level HTML data-attribute inventory summary.
 *
 * @return int Number of tags plus data attributes and value bytes counted.
 */
function wp_toolkit_native_api_benchmark_html_data_attribute_inventory_summary() {
	$html      = wp_toolkit_native_api_benchmark_html_document();
	$processor = new WP_HTML_Tag_Processor( $html );
	$summary   = $processor->summarize_data_attribute_inventory( true );

	if (
		! is_array( $summary ) ||
		! isset( $summary['tag_count'], $summary['data_attribute_count'], $summary['data_attribute_value_bytes'] )
	) {
		throw new RuntimeException( 'HTML data-attribute inventory summary benchmark returned an invalid summary.' );
	}

	return (int) $summary['tag_count'] + (int) $summary['data_attribute_count'] + (int) $summary['data_attribute_value_bytes'];
}

/**
 * Benchmark a fused lower-level HTML ARIA attribute inventory summary.
 *
 * @return int Number of tags plus ARIA attributes and value bytes counted.
 */
function wp_toolkit_native_api_benchmark_html_aria_attribute_inventory_summary() {
	$html      = wp_toolkit_native_api_benchmark_html_document();
	$processor = new WP_HTML_Tag_Processor( $html );
	$summary   = $processor->summarize_aria_attribute_inventory( true );

	if (
		! is_array( $summary ) ||
		! isset( $summary['tag_count'], $summary['aria_attribute_count'], $summary['aria_attribute_value_bytes'] )
	) {
		throw new RuntimeException( 'HTML ARIA attribute inventory summary benchmark returned an invalid summary.' );
	}

	return (int) $summary['tag_count'] + (int) $summary['aria_attribute_count'] + (int) $summary['aria_attribute_value_bytes'];
}

/**
 * Benchmark a fused lower-level HTML class inventory summary.
 *
 * @return int Number of tags plus class names and class value bytes counted.
 */
function wp_toolkit_native_api_benchmark_html_class_inventory_summary() {
	$html      = wp_toolkit_native_api_benchmark_html_document();
	$processor = new WP_HTML_Tag_Processor( $html );
	$summary   = $processor->summarize_class_inventory( true );

	if (
		! is_array( $summary ) ||
		! isset( $summary['tag_count'], $summary['class_name_count'], $summary['class_value_bytes'] )
	) {
		throw new RuntimeException( 'HTML class inventory summary benchmark returned an invalid summary.' );
	}

	return (int) $summary['tag_count'] + (int) $summary['class_name_count'] + (int) $summary['class_value_bytes'];
}

/**
 * Benchmark a fused lower-level HTML resource inventory summary.
 *
 * @return int Number of tags plus resource attributes and value bytes counted.
 */
function wp_toolkit_native_api_benchmark_html_resource_inventory_summary() {
	$html      = wp_toolkit_native_api_benchmark_html_document();
	$processor = new WP_HTML_Tag_Processor( $html );
	$summary   = $processor->summarize_resource_inventory( true );

	if (
		! is_array( $summary ) ||
		! isset( $summary['tag_count'], $summary['resource_attribute_count'], $summary['resource_value_bytes'] )
	) {
		throw new RuntimeException( 'HTML resource inventory summary benchmark returned an invalid summary.' );
	}

	return (int) $summary['tag_count'] + (int) $summary['resource_attribute_count'] + (int) $summary['resource_value_bytes'];
}

/**
 * Benchmark a fused lower-level HTML image inventory summary.
 *
 * @return int Number of tags plus images, attributes, and value bytes counted.
 */
function wp_toolkit_native_api_benchmark_html_image_inventory_summary() {
	$html      = wp_toolkit_native_api_benchmark_html_image_document();
	$processor = new WP_HTML_Tag_Processor( $html );
	$summary   = $processor->summarize_image_inventory( true );

	if (
		! is_array( $summary ) ||
		! isset( $summary['tag_count'], $summary['image_count'], $summary['src_value_bytes'], $summary['alt_value_bytes'] )
	) {
		throw new RuntimeException( 'HTML image inventory summary benchmark returned an invalid summary.' );
	}

	return (int) $summary['tag_count'] + (int) $summary['image_count'] + (int) $summary['src_value_bytes'] + (int) $summary['alt_value_bytes'];
}

/**
 * Benchmark a fused lower-level HTML script inventory summary.
 *
 * @return int Number of tags plus scripts, attributes, and value bytes counted.
 */
function wp_toolkit_native_api_benchmark_html_script_inventory_summary() {
	$html      = wp_toolkit_native_api_benchmark_html_script_document();
	$processor = new WP_HTML_Tag_Processor( $html );
	$summary   = $processor->summarize_script_inventory( true );

	if (
		! is_array( $summary ) ||
		! isset( $summary['tag_count'], $summary['script_count'], $summary['inline_script_bytes'], $summary['src_value_bytes'] )
	) {
		throw new RuntimeException( 'HTML script inventory summary benchmark returned an invalid summary.' );
	}

	return (
		(int) $summary['tag_count'] +
		(int) $summary['script_count'] +
		(int) $summary['inline_script_bytes'] +
		(int) $summary['src_value_bytes']
	);
}

/**
 * Benchmark a fused lower-level HTML form inventory summary.
 *
 * @return int Number of tags plus controls and name bytes counted.
 */
function wp_toolkit_native_api_benchmark_html_form_inventory_summary() {
	$html      = wp_toolkit_native_api_benchmark_html_form_document();
	$processor = new WP_HTML_Tag_Processor( $html );
	$summary   = $processor->summarize_form_inventory( true );

	if (
		! is_array( $summary ) ||
		! isset( $summary['tag_count'], $summary['control_count'], $summary['control_name_value_bytes'] )
	) {
		throw new RuntimeException( 'HTML form inventory summary benchmark returned an invalid summary.' );
	}

	return (int) $summary['tag_count'] + (int) $summary['control_count'] + (int) $summary['control_name_value_bytes'];
}

/**
 * Benchmark the lower-level HTML tag processor with chunked prefix summaries.
 *
 * @return int Number of tags visited plus matching attributes counted.
 */
function wp_toolkit_native_api_benchmark_html_tag_prefix_batch() {
	$html      = wp_toolkit_native_api_benchmark_html_document();
	$processor = new WP_HTML_Tag_Processor( $html );
	$count     = 0;

	do {
		$batch = $processor->next_tag_prefix_compact_summary_batch( 'data-', 256, true );
		if ( is_string( $batch ) && '' !== $batch ) {
			$count += wp_toolkit_native_api_benchmark_count_html_tag_prefix_batch( $batch );
		}
	} while ( is_string( $batch ) && '' !== $batch );

	return $count;
}

/**
 * Benchmark the lower-level HTML tag processor with chunked prefix-count summaries.
 *
 * @return int Number of tags visited plus matching attributes counted.
 */
function wp_toolkit_native_api_benchmark_html_tag_prefix_count_batch() {
	$html      = wp_toolkit_native_api_benchmark_html_document();
	$processor = new WP_HTML_Tag_Processor( $html );
	$count     = 0;

	do {
		$summary = $processor->next_tag_prefix_count_compact_batch( 'data-', 256, true );
		if ( is_string( $summary ) && '' !== $summary ) {
			$count += wp_toolkit_native_api_benchmark_count_html_tag_prefix_count_batch( $summary );
		}
	} while ( is_string( $summary ) && '' !== $summary );

	return $count;
}

/**
 * Benchmark a fused lower-level HTML prefix-count summary.
 *
 * @return int Number of tags visited plus matching attributes counted.
 */
function wp_toolkit_native_api_benchmark_html_tag_prefix_summary() {
	$html      = wp_toolkit_native_api_benchmark_html_document();
	$processor = new WP_HTML_Tag_Processor( $html );
	$summary   = $processor->summarize_attribute_names_with_prefix( 'data-', true );

	if ( ! is_array( $summary ) || ! isset( $summary['tag_count'], $summary['attribute_count'] ) ) {
		throw new RuntimeException( 'HTML tag prefix summary benchmark returned an invalid summary.' );
	}

	return (int) $summary['tag_count'] + (int) $summary['attribute_count'];
}

/**
 * Benchmark the native lower-level HTML tag processor.
 *
 * @return int Number of tags visited.
 */
function wp_toolkit_native_api_benchmark_native_html_tags() {
	$html        = wp_toolkit_native_api_benchmark_html_document();
	$processor   = new WP_HTML_Native_Tag_Processor( $html );
	$has_strings = method_exists( $processor, 'get_attribute_names_with_prefix_string' );
	$tag_count   = 0;
	$attr_count  = 0;

	while ( $processor->next_tag_any( true, 1 ) ) {
		++$tag_count;
		if ( $has_strings ) {
			$names = $processor->get_attribute_names_with_prefix_string( 'data-' );
			if ( is_string( $names ) && '' !== $names ) {
				$attr_count += substr_count( $names, "\x1f" ) + 1;
			}
		} else {
			$names = $processor->get_attribute_names_with_prefix( 'data-' );
			if ( is_array( $names ) ) {
				$attr_count += count( $names );
			}
		}
	}

	return $tag_count + $attr_count;
}

/**
 * Benchmark the native lower-level HTML tag processor with prefix-count reads.
 *
 * @return int Number of tags visited plus matching attributes counted.
 */
function wp_toolkit_native_api_benchmark_native_html_tag_prefix_count() {
	$html       = wp_toolkit_native_api_benchmark_html_document();
	$processor  = new WP_HTML_Native_Tag_Processor( $html );
	$tag_count  = 0;
	$attr_count = 0;

	while ( $processor->next_tag_any( true, 1 ) ) {
		++$tag_count;
		$count = $processor->count_attribute_names_with_prefix( 'data-' );
		if ( is_int( $count ) ) {
			$attr_count += $count;
		}
	}

	return $tag_count + $attr_count;
}

/**
 * Benchmark the native lower-level HTML tag processor with chunked tag summaries.
 *
 * @return int Number of tags visited.
 */
function wp_toolkit_native_api_benchmark_native_html_tag_batch() {
	$html      = wp_toolkit_native_api_benchmark_html_document();
	$processor = new WP_HTML_Native_Tag_Processor( $html );
	$count     = 0;

	if ( method_exists( $processor, 'next_tag_compact_summary_batch' ) ) {
		do {
			$batch = $processor->next_tag_compact_summary_batch( 256, true );
			if ( is_string( $batch ) && '' !== $batch ) {
				$count += wp_toolkit_native_api_benchmark_count_html_tag_batch( $batch );
			}
		} while ( is_string( $batch ) && '' !== $batch );

		return $count;
	}

	while ( $processor->next_tag_any( true, 1 ) ) {
		++$count;
	}

	return $count;
}

/**
 * Benchmark the native lower-level HTML tag processor with chunked tag-name summaries.
 *
 * @return int Number of matching tags visited.
 */
function wp_toolkit_native_api_benchmark_native_html_matching_tag_batch() {
	$html      = wp_toolkit_native_api_benchmark_html_document();
	$processor = new WP_HTML_Native_Tag_Processor( $html );
	$count     = 0;

	if ( method_exists( $processor, 'next_matching_tag_compact_summary_batch' ) ) {
		do {
			$batch = $processor->next_matching_tag_compact_summary_batch( 'a', 256, true );
			if ( is_string( $batch ) && '' !== $batch ) {
				$count += wp_toolkit_native_api_benchmark_count_html_tag_batch( $batch );
			}
		} while ( is_string( $batch ) && '' !== $batch );

		return $count;
	}

	while ( $processor->next_tag_any( true, 1 ) ) {
		if ( 'A' === $processor->get_tag() ) {
			++$count;
		}
	}

	return $count;
}

/**
 * Benchmark the native lower-level HTML tag processor with chunked tag-name and attribute summaries.
 *
 * @return int Number of matching tags visited plus attribute bytes consumed.
 */
function wp_toolkit_native_api_benchmark_native_html_matching_tag_attribute_batch() {
	$html      = wp_toolkit_native_api_benchmark_html_document();
	$processor = new WP_HTML_Native_Tag_Processor( $html );
	$count     = 0;

	if ( method_exists( $processor, 'next_matching_tag_attribute_compact_summary_batch' ) ) {
		do {
			$batch = $processor->next_matching_tag_attribute_compact_summary_batch( 'a', 'href', 256, true );
			if ( is_string( $batch ) && '' !== $batch ) {
				$count += wp_toolkit_native_api_benchmark_count_html_tag_attribute_batch( $batch );
			}
		} while ( is_string( $batch ) && '' !== $batch );

		return $count;
	}

	while ( $processor->next_tag_any( true, 1 ) ) {
		if ( 'A' === $processor->get_tag() ) {
			++$count;
			$value = $processor->get_attribute( 'href' );
			if ( is_string( $value ) ) {
				$count += strlen( $value );
			}
		}
	}

	return $count;
}

/**
 * Benchmark the native lower-level HTML tag processor with chunked tag-name and multi-attribute summaries.
 *
 * @return int Number of matching tags visited plus attribute bytes consumed.
 */
function wp_toolkit_native_api_benchmark_native_html_matching_tag_attributes_batch() {
	$html      = wp_toolkit_native_api_benchmark_html_link_document();
	$processor = new WP_HTML_Native_Tag_Processor( $html );
	$count     = 0;

	if ( method_exists( $processor, 'next_matching_tag_attributes_compact_summary_batch' ) ) {
		do {
			$batch = $processor->next_matching_tag_attributes_compact_summary_batch( 'a', "href\x1ftitle\x1frel", 256, true );
			if ( is_string( $batch ) && '' !== $batch ) {
				$count += wp_toolkit_native_api_benchmark_count_html_tag_attributes_batch( $batch, 3 );
			}
		} while ( is_string( $batch ) && '' !== $batch );

		return $count;
	}

	while ( $processor->next_tag_any( true, 1 ) ) {
		if ( 'A' === $processor->get_tag() ) {
			++$count;
			foreach ( array( 'href', 'title', 'rel' ) as $attribute_name ) {
				$value = $processor->get_attribute( $attribute_name );
				if ( is_string( $value ) ) {
					$count += strlen( $value );
				}
			}
		}
	}

	return $count;
}

/**
 * Benchmark the native lower-level HTML link-audit summary.
 *
 * @return int Number of matching tags plus attributes and attribute bytes consumed.
 */
function wp_toolkit_native_api_benchmark_native_html_link_audit_summary() {
	$html      = wp_toolkit_native_api_benchmark_html_link_document();
	$processor = new WP_HTML_Native_Tag_Processor( $html );

	if ( method_exists( $processor, 'summarize_matching_tag_attributes' ) ) {
		$summary = $processor->summarize_matching_tag_attributes( 'a', "href\x1ftitle\x1frel", true );
		if ( is_string( $summary ) ) {
			$parts = explode( "\x1f", $summary, 3 );
			if ( 3 === count( $parts ) ) {
				return (int) $parts[0] + (int) $parts[1] + (int) $parts[2];
			}
		}
	}

	return wp_toolkit_native_api_benchmark_native_html_matching_tag_attributes_batch();
}

/**
 * Benchmark the native lower-level HTML tag inventory summary.
 *
 * @return int Number of tags plus attributes and unique tag names counted.
 */
function wp_toolkit_native_api_benchmark_native_html_tag_inventory_summary() {
	$html      = wp_toolkit_native_api_benchmark_html_document();
	$processor = new WP_HTML_Native_Tag_Processor( $html );

	if ( ! method_exists( $processor, 'summarize_tag_inventory' ) ) {
		return 0;
	}

	$summary = $processor->summarize_tag_inventory( true );
	if ( ! is_string( $summary ) ) {
		throw new RuntimeException( 'Native HTML tag inventory summary benchmark returned an invalid summary.' );
	}

	$parts = explode( "\x1f", $summary, 5 );
	if ( 5 !== count( $parts ) ) {
		throw new RuntimeException( 'Native HTML tag inventory summary benchmark returned an invalid compact row.' );
	}

	return (int) $parts[0] + (int) $parts[3] + (int) $parts[4];
}

/**
 * Benchmark the native lower-level HTML heading inventory summary.
 *
 * @return int Number of tags plus headings counted.
 */
function wp_toolkit_native_api_benchmark_native_html_heading_inventory_summary() {
	$html      = wp_toolkit_native_api_benchmark_html_document();
	$processor = new WP_HTML_Native_Tag_Processor( $html );

	if ( ! method_exists( $processor, 'summarize_heading_inventory' ) ) {
		return 0;
	}

	$summary = $processor->summarize_heading_inventory( true );
	if ( ! is_string( $summary ) ) {
		throw new RuntimeException( 'Native HTML heading inventory summary benchmark returned an invalid summary.' );
	}

	$parts = explode( "\x1f", $summary, 8 );
	if ( 8 !== count( $parts ) ) {
		throw new RuntimeException( 'Native HTML heading inventory summary benchmark returned an invalid compact row.' );
	}

	return (int) $parts[0] + (int) $parts[1] + (int) $parts[3];
}

/**
 * Benchmark the native lower-level HTML ID inventory summary.
 *
 * @return int Number of tags plus ID counts and value bytes counted.
 */
function wp_toolkit_native_api_benchmark_native_html_id_inventory_summary() {
	$html      = wp_toolkit_native_api_benchmark_html_id_document();
	$processor = new WP_HTML_Native_Tag_Processor( $html );

	if ( ! method_exists( $processor, 'summarize_id_inventory' ) ) {
		return 0;
	}

	$summary = $processor->summarize_id_inventory( true );
	if ( ! is_string( $summary ) ) {
		throw new RuntimeException( 'Native HTML ID inventory summary benchmark returned an invalid summary.' );
	}

	$parts = explode( "\x1f", $summary, 5 );
	if ( 5 !== count( $parts ) ) {
		throw new RuntimeException( 'Native HTML ID inventory summary benchmark returned an invalid compact row.' );
	}

	return (int) $parts[0] + (int) $parts[1] + (int) $parts[3] + (int) $parts[4];
}

/**
 * Benchmark the native lower-level HTML attribute inventory summary.
 *
 * @return int Number of tags plus attributes and value bytes counted.
 */
function wp_toolkit_native_api_benchmark_native_html_attribute_inventory_summary() {
	$html      = wp_toolkit_native_api_benchmark_html_document();
	$processor = new WP_HTML_Native_Tag_Processor( $html );

	if ( ! method_exists( $processor, 'summarize_attribute_inventory' ) ) {
		return 0;
	}

	$summary = $processor->summarize_attribute_inventory( true );
	if ( ! is_string( $summary ) ) {
		throw new RuntimeException( 'Native HTML attribute inventory summary benchmark returned an invalid summary.' );
	}

	$parts = explode( "\x1f", $summary, 4 );
	if ( 4 !== count( $parts ) ) {
		throw new RuntimeException( 'Native HTML attribute inventory summary benchmark returned an invalid compact row.' );
	}

	return (int) $parts[0] + (int) $parts[1] + (int) $parts[3];
}

/**
 * Benchmark the native lower-level HTML data-attribute inventory summary.
 *
 * @return int Number of tags plus data attributes and value bytes counted.
 */
function wp_toolkit_native_api_benchmark_native_html_data_attribute_inventory_summary() {
	$html      = wp_toolkit_native_api_benchmark_html_document();
	$processor = new WP_HTML_Native_Tag_Processor( $html );

	if ( ! method_exists( $processor, 'summarize_data_attribute_inventory' ) ) {
		return 0;
	}

	$summary = $processor->summarize_data_attribute_inventory( true );
	if ( ! is_string( $summary ) ) {
		throw new RuntimeException( 'Native HTML data-attribute inventory summary benchmark returned an invalid summary.' );
	}

	$parts = explode( "\x1f", $summary, 5 );
	if ( 5 !== count( $parts ) ) {
		throw new RuntimeException( 'Native HTML data-attribute inventory summary benchmark returned an invalid compact row.' );
	}

	return (int) $parts[0] + (int) $parts[2] + (int) $parts[4];
}

/**
 * Benchmark the native lower-level HTML ARIA attribute inventory summary.
 *
 * @return int Number of tags plus ARIA attributes and value bytes counted.
 */
function wp_toolkit_native_api_benchmark_native_html_aria_attribute_inventory_summary() {
	$html      = wp_toolkit_native_api_benchmark_html_document();
	$processor = new WP_HTML_Native_Tag_Processor( $html );

	if ( ! method_exists( $processor, 'summarize_aria_attribute_inventory' ) ) {
		return 0;
	}

	$summary = $processor->summarize_aria_attribute_inventory( true );
	if ( ! is_string( $summary ) ) {
		throw new RuntimeException( 'Native HTML ARIA attribute inventory summary benchmark returned an invalid summary.' );
	}

	$parts = explode( "\x1f", $summary, 5 );
	if ( 5 !== count( $parts ) ) {
		throw new RuntimeException( 'Native HTML ARIA attribute inventory summary benchmark returned an invalid compact row.' );
	}

	return (int) $parts[0] + (int) $parts[2] + (int) $parts[4];
}

/**
 * Benchmark the native lower-level HTML class inventory summary.
 *
 * @return int Number of tags plus class names and class value bytes counted.
 */
function wp_toolkit_native_api_benchmark_native_html_class_inventory_summary() {
	$html      = wp_toolkit_native_api_benchmark_html_document();
	$processor = new WP_HTML_Native_Tag_Processor( $html );

	if ( ! method_exists( $processor, 'summarize_class_inventory' ) ) {
		return 0;
	}

	$summary = $processor->summarize_class_inventory( true );
	if ( ! is_string( $summary ) ) {
		throw new RuntimeException( 'Native HTML class inventory summary benchmark returned an invalid summary.' );
	}

	$parts = explode( "\x1f", $summary, 5 );
	if ( 5 !== count( $parts ) ) {
		throw new RuntimeException( 'Native HTML class inventory summary benchmark returned an invalid compact row.' );
	}

	return (int) $parts[0] + (int) $parts[2] + (int) $parts[4];
}

/**
 * Benchmark the native lower-level HTML resource inventory summary.
 *
 * @return int Number of tags plus resource attributes and value bytes counted.
 */
function wp_toolkit_native_api_benchmark_native_html_resource_inventory_summary() {
	$html      = wp_toolkit_native_api_benchmark_html_document();
	$processor = new WP_HTML_Native_Tag_Processor( $html );

	if ( ! method_exists( $processor, 'summarize_resource_inventory' ) ) {
		return 0;
	}

	$summary = $processor->summarize_resource_inventory( true );
	if ( ! is_string( $summary ) ) {
		throw new RuntimeException( 'Native HTML resource inventory summary benchmark returned an invalid summary.' );
	}

	$parts = explode( "\x1f", $summary, 5 );
	if ( 5 !== count( $parts ) ) {
		throw new RuntimeException( 'Native HTML resource inventory summary benchmark returned an invalid compact row.' );
	}

	return (int) $parts[0] + (int) $parts[2] + (int) $parts[4];
}

/**
 * Benchmark the native lower-level HTML image inventory summary.
 *
 * @return int Number of tags plus images, attributes, and value bytes counted.
 */
function wp_toolkit_native_api_benchmark_native_html_image_inventory_summary() {
	$html      = wp_toolkit_native_api_benchmark_html_image_document();
	$processor = new WP_HTML_Native_Tag_Processor( $html );

	if ( ! method_exists( $processor, 'summarize_image_inventory' ) ) {
		return 0;
	}

	$summary = $processor->summarize_image_inventory( true );
	if ( ! is_string( $summary ) ) {
		throw new RuntimeException( 'Native HTML image inventory summary benchmark returned an invalid summary.' );
	}

	$parts = explode( "\x1f", $summary, 8 );
	if ( 8 !== count( $parts ) ) {
		throw new RuntimeException( 'Native HTML image inventory summary benchmark returned an invalid compact row.' );
	}

	return (int) $parts[0] + (int) $parts[1] + (int) $parts[6] + (int) $parts[7];
}

/**
 * Benchmark the native lower-level HTML script inventory summary.
 *
 * @return int Number of tags plus scripts, attributes, and value bytes counted.
 */
function wp_toolkit_native_api_benchmark_native_html_script_inventory_summary() {
	$html      = wp_toolkit_native_api_benchmark_html_script_document();
	$processor = new WP_HTML_Native_Tag_Processor( $html );

	if ( ! method_exists( $processor, 'summarize_script_inventory' ) ) {
		return 0;
	}

	$summary = $processor->summarize_script_inventory( true );
	if ( ! is_string( $summary ) ) {
		throw new RuntimeException( 'Native HTML script inventory summary benchmark returned an invalid summary.' );
	}

	$parts = explode( "\x1f", $summary, 8 );
	if ( 8 !== count( $parts ) ) {
		throw new RuntimeException( 'Native HTML script inventory summary benchmark returned an invalid compact row.' );
	}

	return (int) $parts[0] + (int) $parts[1] + (int) $parts[6] + (int) $parts[7];
}

/**
 * Benchmark the native lower-level HTML form inventory summary.
 *
 * @return int Number of tags plus controls and name bytes counted.
 */
function wp_toolkit_native_api_benchmark_native_html_form_inventory_summary() {
	$html      = wp_toolkit_native_api_benchmark_html_form_document();
	$processor = new WP_HTML_Native_Tag_Processor( $html );

	if ( ! method_exists( $processor, 'summarize_form_inventory' ) ) {
		return 0;
	}

	$summary = $processor->summarize_form_inventory( true );
	if ( ! is_string( $summary ) ) {
		throw new RuntimeException( 'Native HTML form inventory summary benchmark returned an invalid summary.' );
	}

	$parts = explode( "\x1f", $summary, 6 );
	if ( 6 !== count( $parts ) ) {
		throw new RuntimeException( 'Native HTML form inventory summary benchmark returned an invalid compact row.' );
	}

	return (int) $parts[0] + (int) $parts[2] + (int) $parts[5];
}

/**
 * Benchmark the native lower-level HTML tag processor with chunked prefix summaries.
 *
 * @return int Number of tags visited plus matching attributes counted.
 */
function wp_toolkit_native_api_benchmark_native_html_tag_prefix_batch() {
	$html      = wp_toolkit_native_api_benchmark_html_document();
	$processor = new WP_HTML_Native_Tag_Processor( $html );
	$count     = 0;

	if ( method_exists( $processor, 'next_tag_prefix_summary_batch' ) ) {
		do {
			$batch = $processor->next_tag_prefix_summary_batch( 'data-', 256, true );
			if ( is_string( $batch ) && '' !== $batch ) {
				$count += wp_toolkit_native_api_benchmark_count_html_tag_prefix_batch( $batch );
			}
		} while ( is_string( $batch ) && '' !== $batch );

		return $count;
	}

	return wp_toolkit_native_api_benchmark_native_html_tag_prefix_count();
}

/**
 * Benchmark the native lower-level HTML tag processor with chunked prefix-count summaries.
 *
 * @return int Number of tags visited plus matching attributes counted.
 */
function wp_toolkit_native_api_benchmark_native_html_tag_prefix_count_batch() {
	$html      = wp_toolkit_native_api_benchmark_html_document();
	$processor = new WP_HTML_Native_Tag_Processor( $html );
	$count     = 0;

	if ( ! method_exists( $processor, 'next_tag_prefix_count_compact_batch' ) ) {
		return wp_toolkit_native_api_benchmark_native_html_tag_prefix_batch();
	}

	do {
		$summary = $processor->next_tag_prefix_count_compact_batch( 'data-', 256, true );
		if ( is_string( $summary ) && '' !== $summary ) {
			$count += wp_toolkit_native_api_benchmark_count_html_tag_prefix_count_batch( $summary );
		}
	} while ( is_string( $summary ) && '' !== $summary );

	return $count;
}

/**
 * Counts compact HTML tag batch rows without allocating per-row arrays.
 *
 * @param string $batch Compact tag summary batch.
 * @return int Number of tags visited.
 */
function wp_toolkit_native_api_benchmark_count_html_tag_batch( $batch ) {
	$count  = 0;
	$offset = 0;
	$length = strlen( $batch );

	while ( $offset < $length ) {
		$row_end = strpos( $batch, "\x1e", $offset );
		if ( false === $row_end ) {
			$row_end = $length;
		}

		$first = strpos( $batch, "\x1f", $offset );
		if ( false === $first || $first >= $row_end ) {
			throw new RuntimeException( 'HTML tag batch benchmark returned an invalid summary row.' );
		}

		++$count;
		$offset = $row_end + 1;
	}

	return $count;
}

/**
 * Counts compact HTML tag-attribute batch rows without allocating per-row arrays.
 *
 * @param string $batch Compact tag-attribute summary batch.
 * @return int Number of tags visited plus attribute bytes consumed.
 */
function wp_toolkit_native_api_benchmark_count_html_tag_attribute_batch( $batch ) {
	$count  = 0;
	$offset = 0;
	$length = strlen( $batch );

	while ( $offset < $length ) {
		$row_end = strpos( $batch, "\x1e", $offset );
		if ( false === $row_end ) {
			$row_end = $length;
		}

		$first = strpos( $batch, "\x1f", $offset );
		if ( false === $first || $first >= $row_end ) {
			throw new RuntimeException( 'HTML tag attribute batch benchmark returned an invalid summary row.' );
		}

		$second = strpos( $batch, "\x1f", $first + 1 );
		if ( false === $second || $second >= $row_end ) {
			throw new RuntimeException( 'HTML tag attribute batch benchmark returned an invalid summary row.' );
		}

		++$count;
		if ( '1' === substr( $batch, $second + 1, 1 ) ) {
			$count += $row_end - $second - 2;
		}
		$offset = $row_end + 1;
	}

	return $count;
}

/**
 * Counts compact HTML tag multi-attribute batch rows without allocating per-row arrays.
 *
 * @param string $batch           Compact tag-attribute summary batch.
 * @param int    $attribute_count Number of attribute fields per row.
 * @return int Number of tags visited plus attribute bytes consumed.
 */
function wp_toolkit_native_api_benchmark_count_html_tag_attributes_batch( $batch, $attribute_count ) {
	$count  = 0;
	$offset = 0;
	$length = strlen( $batch );

	while ( $offset < $length ) {
		$row_end = strpos( $batch, "\x1e", $offset );
		if ( false === $row_end ) {
			$row_end = $length;
		}

		$field_start = $offset;
		for ( $field_index = 0; $field_index < 2 + $attribute_count; ++$field_index ) {
			$field_end = strpos( $batch, "\x1f", $field_start );
			if ( false === $field_end || $field_end > $row_end ) {
				$field_end = $row_end;
			}

			if ( $field_start > $row_end || ( $field_index < 1 + $attribute_count && $field_end >= $row_end ) ) {
				throw new RuntimeException( 'HTML tag attributes batch benchmark returned an invalid summary row.' );
			}

			if ( $field_index >= 2 && '1' === substr( $batch, $field_start, 1 ) ) {
				$count += $field_end - $field_start - 1;
			}

			$field_start = $field_end + 1;
		}

		++$count;
		$offset = $row_end + 1;
	}

	return $count;
}

/**
 * Counts compact HTML tag-prefix batch rows without allocating per-row arrays.
 *
 * @param string $batch Compact tag-prefix summary batch.
 * @return int Number of tags visited plus matching attributes counted.
 */
function wp_toolkit_native_api_benchmark_count_html_tag_prefix_batch( $batch ) {
	$count  = 0;
	$offset = 0;
	$length = strlen( $batch );

	while ( $offset < $length ) {
		$row_end = strpos( $batch, "\x1e", $offset );
		if ( false === $row_end ) {
			$row_end = $length;
		}

		$first = strpos( $batch, "\x1f", $offset );
		if ( false === $first || $first >= $row_end ) {
			throw new RuntimeException( 'HTML tag prefix batch benchmark returned an invalid summary row.' );
		}

		$second = strpos( $batch, "\x1f", $first + 1 );
		if ( false === $second || $second >= $row_end ) {
			throw new RuntimeException( 'HTML tag prefix batch benchmark returned an invalid summary row.' );
		}

		++$count;
		$count += (int) substr( $batch, $second + 1, $row_end - $second - 1 );
		$offset = $row_end + 1;
	}

	return $count;
}

/**
 * Counts a compact HTML tag-prefix count batch.
 *
 * @param string $summary Compact tag and attribute count summary.
 * @return int Number of tags visited plus matching attributes counted.
 */
function wp_toolkit_native_api_benchmark_count_html_tag_prefix_count_batch( $summary ) {
	$parts = explode( "\x1f", $summary, 2 );
	if ( 2 !== count( $parts ) ) {
		throw new RuntimeException( 'HTML tag prefix count batch benchmark returned an invalid summary row.' );
	}

	return (int) $parts[0] + (int) $parts[1];
}

/**
 * Benchmark the native lower-level HTML prefix-count summary.
 *
 * @return int Number of tags visited plus matching attributes counted.
 */
function wp_toolkit_native_api_benchmark_native_html_tag_prefix_summary() {
	$html      = wp_toolkit_native_api_benchmark_html_document();
	$processor = new WP_HTML_Native_Tag_Processor( $html );

	if ( method_exists( $processor, 'summarize_attribute_names_with_prefix' ) ) {
		$summary = $processor->summarize_attribute_names_with_prefix( 'data-', true );
		if ( is_string( $summary ) ) {
			$parts = explode( "\x1f", $summary, 2 );
			if ( 2 === count( $parts ) ) {
				return (int) $parts[0] + (int) $parts[1];
			}
		}
	}

	return wp_toolkit_native_api_benchmark_native_html_tag_prefix_count();
}

/**
 * Benchmark an HTML prefix-scan and remove-attribute sanitization workflow.
 *
 * @return int Number of tags visited plus attributes removed.
 */
function wp_toolkit_native_api_benchmark_html_tag_sanitizer() {
	$html      = wp_toolkit_native_api_benchmark_html_document();
	$processor = new WP_HTML_Tag_Processor( $html );
	$summary   = $processor->remove_attributes_with_prefix_from_document( 'data-', true );

	if ( ! is_array( $summary ) || ! isset( $summary['tag_count'], $summary['removed_count'], $summary['html'] ) ) {
		throw new RuntimeException( 'HTML tag sanitizer benchmark returned an invalid summary.' );
	}

	$updated_html = $summary['html'];
	if ( false !== strpos( $updated_html, 'data-' ) ) {
		throw new RuntimeException( 'HTML tag sanitizer benchmark left data-* attributes in the output.' );
	}

	return (int) $summary['tag_count'] + (int) $summary['removed_count'];
}

/**
 * Benchmark the native lower-level HTML prefix-scan and remove-attribute path.
 *
 * @return int Number of tags visited plus attributes removed.
 */
function wp_toolkit_native_api_benchmark_native_html_tag_sanitizer() {
	$html          = wp_toolkit_native_api_benchmark_html_document();
	$processor     = new WP_HTML_Native_Tag_Processor( $html );
	$tag_count     = 0;
	$removed_count = 0;

	if ( method_exists( $processor, 'remove_attributes_with_prefix_from_document' ) ) {
		$summary = $processor->remove_attributes_with_prefix_from_document( 'data-', true );
		if ( is_string( $summary ) ) {
			$parts = explode( "\x1f", $summary, 3 );
			if ( 3 === count( $parts ) ) {
				if ( false !== strpos( $parts[2], 'data-' ) ) {
					throw new RuntimeException( 'Native HTML tag sanitizer benchmark left data-* attributes in the output.' );
				}

				return (int) $parts[0] + (int) $parts[1];
			}
		}
	}

	while ( $processor->next_tag_any( true, 1 ) ) {
		++$tag_count;
		if ( method_exists( $processor, 'remove_attributes_with_prefix' ) ) {
			$count = $processor->remove_attributes_with_prefix( 'data-' );
			if ( is_int( $count ) ) {
				$removed_count += $count;
			}
			continue;
		}

		$names = $processor->get_attribute_names_with_prefix( 'data-' );
		if ( ! is_array( $names ) ) {
			continue;
		}

		foreach ( $names as $name ) {
			if ( $processor->remove_attribute( $name ) ) {
				++$removed_count;
			}
		}
	}

	$updated_html = $processor->get_updated_html();
	if ( false !== strpos( $updated_html, 'data-' ) ) {
		throw new RuntimeException( 'Native HTML tag sanitizer benchmark left data-* attributes in the output.' );
	}

	return $tag_count + $removed_count;
}

/**
 * Benchmark the full HTML processor.
 *
 * @return int Number of tokens visited.
 */
function wp_toolkit_native_api_benchmark_html_processor() {
	$html      = wp_toolkit_native_api_benchmark_html_document();
	$processor = WP_HTML_Processor::create_fragment( $html );
	$count     = 0;

	while ( $processor->next_token() ) {
		++$count;
		$processor->get_token_type();
		$processor->get_token_name();
		$processor->get_breadcrumbs();
	}

	return $count;
}

/**
 * Benchmark the native full HTML processor.
 *
 * @return int Number of tokens visited.
 */
function wp_toolkit_native_api_benchmark_native_html_processor() {
	$html      = wp_toolkit_native_api_benchmark_html_document();
	$processor = WP_HTML_Native_Processor::create_fragment( $html );
	$count     = 0;

	while ( $processor->next_token() ) {
		++$count;
		$processor->get_token_type();
		$processor->get_token_name();
		$processor->get_breadcrumbs();
	}

	return $count;
}

/**
 * Benchmark public HTML processor token summary batches.
 *
 * @return int Number of tokens visited.
 */
function wp_toolkit_native_api_benchmark_html_token_batch() {
	$html      = wp_toolkit_native_api_benchmark_html_document();
	$processor = WP_HTML_Processor::create_fragment( $html );
	$count     = 0;

	do {
		$batch = $processor->next_token_compact_summary_batch( 256 );
		if ( is_string( $batch ) && '' !== $batch ) {
			$count += wp_toolkit_native_api_benchmark_count_html_token_batch( $batch );
		}
	} while ( is_string( $batch ) && '' !== $batch );

	return $count;
}

/**
 * Benchmark native HTML processor token summary batches.
 *
 * @return int Number of tokens visited.
 */
function wp_toolkit_native_api_benchmark_native_html_token_batch() {
	$html      = wp_toolkit_native_api_benchmark_html_document();
	$processor = WP_HTML_Native_Processor::create_fragment( $html );
	$count     = 0;

	do {
		$batch = $processor->next_token_compact_summary_batch( 256 );
		if ( is_string( $batch ) && '' !== $batch ) {
			$count += wp_toolkit_native_api_benchmark_count_html_token_batch( $batch );
		}
	} while ( is_string( $batch ) && '' !== $batch );

	return $count;
}

/**
 * Counts compact HTML token batch rows without allocating per-row arrays.
 *
 * @param string $batch Compact token summary batch.
 * @return int Number of tokens visited.
 */
function wp_toolkit_native_api_benchmark_count_html_token_batch( $batch ) {
	$count  = 0;
	$offset = 0;
	$length = strlen( $batch );

	while ( $offset < $length ) {
		$row_end = strpos( $batch, "\x1e", $offset );
		if ( false === $row_end ) {
			$row_end = $length;
		}

		$first = strpos( $batch, "\x1f", $offset );
		if ( false === $first || $first >= $row_end ) {
			throw new RuntimeException( 'HTML token batch benchmark returned an invalid summary row.' );
		}

		++$count;
		$offset = $row_end + 1;
	}

	return $count;
}

/**
 * Benchmark the public URL-in-text processor.
 *
 * @return int Number of URLs visited.
 */
function wp_toolkit_native_api_benchmark_url_in_text_processor() {
	$text      = wp_toolkit_native_api_benchmark_url_in_text_document();
	$processor = new URLInTextProcessor( $text, 'https://example.com' );
	$count     = 0;

	while ( $processor->next_url() ) {
		++$count;
		$raw_url    = $processor->get_raw_url();
		$parsed_url = $processor->get_parsed_url();
		if ( false === $raw_url || false === $parsed_url ) {
			throw new RuntimeException( 'URLInTextProcessor benchmark exposed an invalid URL row.' );
		}
	}

	if ( 360 !== $count ) {
		throw new RuntimeException( "URLInTextProcessor benchmark expected 360 URLs, found {$count}." );
	}

	return $count;
}

/**
 * Benchmark the direct native URL-in-text processor.
 *
 * @return int Number of URLs visited.
 */
function wp_toolkit_native_api_benchmark_native_url_in_text_processor() {
	$text            = wp_toolkit_native_api_benchmark_url_in_text_document();
	$native_class    = 'WordPress\\DataLiberation\\URL\\NativeURLInTextProcessor';
	$processor       = new $native_class( $text );
	$count           = 0;
	$total_url_bytes = 0;

	while ( $processor->next_url() ) {
		++$count;
		$raw_url = $processor->get_raw_url();
		if ( ! is_string( $raw_url ) || '' === $raw_url ) {
			throw new RuntimeException( 'Native URL-in-text benchmark exposed an invalid URL row.' );
		}
		$total_url_bytes += $processor->get_url_length();
		$processor->get_url_starts_at();
		$processor->had_protocol();
	}

	if ( 360 !== $count || 0 === $total_url_bytes ) {
		throw new RuntimeException( "Native URL-in-text benchmark expected 360 URLs, found {$count}." );
	}

	return $count;
}

/**
 * Benchmark the XML processor.
 *
 * @return int Number of tokens visited.
 */
function wp_toolkit_native_api_benchmark_xml_processor() {
	$xml       = wp_toolkit_native_api_benchmark_xml_document();
	$processor = XMLProcessor::create_from_string( $xml );
	$count     = 0;

	if ( false === $processor ) {
		throw new RuntimeException( 'XMLProcessor could not parse benchmark document.' );
	}

	while ( $processor->next_token() ) {
		++$count;
		$processor->get_token_type();
		$processor->get_token_name();
		if ( null !== $processor->get_tag_local_name() ) {
			$processor->get_tag_namespace_and_local_name();
			$processor->get_attribute( '', 'id' );
		}
	}

	return $count;
}

/**
 * Benchmark a fused public XML token summary.
 *
 * @return int Number of tokens visited plus matching attributes counted.
 */
function wp_toolkit_native_api_benchmark_xml_token_summary() {
	$xml       = wp_toolkit_native_api_benchmark_xml_document();
	$processor = XMLProcessor::create_from_string( $xml );

	if ( false === $processor ) {
		throw new RuntimeException( 'XMLProcessor could not parse benchmark document.' );
	}

	$summary = $processor->summarize_token_stream( 'id' );
	if ( ! is_array( $summary ) || ! isset( $summary['token_count'], $summary['attribute_count'] ) ) {
		throw new RuntimeException( 'XML token summary benchmark returned an invalid summary.' );
	}

	return (int) $summary['token_count'] + (int) $summary['attribute_count'];
}

/**
 * Benchmark a fused public XML document inventory summary.
 *
 * @return int Number of inventoried tokens and structural markers.
 */
function wp_toolkit_native_api_benchmark_xml_document_inventory_summary() {
	$xml       = wp_toolkit_native_api_benchmark_xml_inventory_document();
	$processor = XMLProcessor::create_from_string( $xml );

	if ( false === $processor ) {
		throw new RuntimeException( 'XMLProcessor could not parse benchmark document.' );
	}

	$summary = $processor->summarize_document_inventory();
	if ( ! is_array( $summary ) || ! isset( $summary['token_count'], $summary['tag_count'], $summary['closing_tag_count'], $summary['max_depth'] ) ) {
		throw new RuntimeException( 'XML document inventory benchmark returned an invalid summary.' );
	}

	if (
		3 !== (int) $summary['max_depth'] ||
		0 === (int) $summary['comment_count'] ||
		0 === (int) $summary['cdata_count']
	) {
		throw new RuntimeException( 'XML document inventory benchmark returned unexpected counts.' );
	}

	return (
		(int) $summary['token_count'] +
		(int) $summary['tag_count'] +
		(int) $summary['closing_tag_count'] +
		(int) $summary['text_token_count'] +
		(int) $summary['comment_count'] +
		(int) $summary['cdata_count'] +
		(int) $summary['empty_element_count']
	);
}

/**
 * Benchmark a fused public XML element inventory summary.
 *
 * @return int Number of inventoried element-name markers.
 */
function wp_toolkit_native_api_benchmark_xml_element_inventory_summary() {
	$xml       = wp_toolkit_native_api_benchmark_xml_element_document();
	$processor = XMLProcessor::create_from_string( $xml );

	if ( false === $processor ) {
		throw new RuntimeException( 'XMLProcessor could not parse benchmark document.' );
	}

	$summary = $processor->summarize_element_inventory();
	if ( ! is_array( $summary ) || ! isset( $summary['token_count'], $summary['tag_count'], $summary['unique_tag_name_count'], $summary['duplicate_tag_name_count'] ) ) {
		throw new RuntimeException( 'XML element inventory benchmark returned an invalid summary.' );
	}

	if (
		7 !== (int) $summary['unique_tag_name_count'] ||
		0 === (int) $summary['duplicate_tag_name_count'] ||
		0 === (int) $summary['namespaced_tag_count'] ||
		0 === (int) $summary['empty_element_count']
	) {
		throw new RuntimeException( 'XML element inventory benchmark returned unexpected counts.' );
	}

	return (
		(int) $summary['token_count'] +
		(int) $summary['tag_count'] +
		(int) $summary['closing_tag_count'] +
		(int) $summary['unique_tag_name_count'] +
		(int) $summary['duplicate_tag_name_count'] +
		(int) $summary['namespaced_tag_count'] +
		(int) $summary['empty_element_count']
	);
}

/**
 * Benchmark a fused public XML depth inventory summary.
 *
 * @return int Number of inventoried tags and depth markers.
 */
function wp_toolkit_native_api_benchmark_xml_depth_inventory_summary() {
	$xml       = wp_toolkit_native_api_benchmark_xml_depth_document();
	$processor = XMLProcessor::create_from_string( $xml );

	if ( false === $processor ) {
		throw new RuntimeException( 'XMLProcessor could not parse benchmark document.' );
	}

	$summary = $processor->summarize_depth_inventory();
	if ( ! is_array( $summary ) || ! isset( $summary['token_count'], $summary['tag_count'], $summary['total_tag_depth'], $summary['max_depth'] ) ) {
		throw new RuntimeException( 'XML depth inventory benchmark returned an invalid summary.' );
	}

	if (
		(int) $summary['max_depth'] < 4 ||
		0 === (int) $summary['empty_element_count'] ||
		0 === (int) $summary['nested_tag_count'] ||
		0 === (int) $summary['total_tag_depth']
	) {
		throw new RuntimeException( 'XML depth inventory benchmark returned unexpected counts.' );
	}

	return (
		(int) $summary['token_count'] +
		(int) $summary['tag_count'] +
		(int) $summary['closing_tag_count'] +
		(int) $summary['empty_element_count'] +
		(int) $summary['root_level_tag_count'] +
		(int) $summary['nested_tag_count'] +
		(int) $summary['total_tag_depth']
	);
}

/**
 * Benchmark a fused public XML leaf inventory summary.
 *
 * @return int Number of inventoried tags and leaf/branch markers.
 */
function wp_toolkit_native_api_benchmark_xml_leaf_inventory_summary() {
	$xml       = wp_toolkit_native_api_benchmark_xml_depth_document();
	$processor = XMLProcessor::create_from_string( $xml );

	if ( false === $processor ) {
		throw new RuntimeException( 'XMLProcessor could not parse benchmark document.' );
	}

	$summary = $processor->summarize_leaf_inventory();
	if ( ! is_array( $summary ) || ! isset( $summary['token_count'], $summary['leaf_element_count'], $summary['branch_element_count'] ) ) {
		throw new RuntimeException( 'XML leaf inventory benchmark returned an invalid summary.' );
	}

	if (
		0 === (int) $summary['empty_element_count'] ||
		0 === (int) $summary['leaf_element_count'] ||
		0 === (int) $summary['branch_element_count'] ||
		(int) $summary['max_child_element_count'] < 2
	) {
		throw new RuntimeException( 'XML leaf inventory benchmark returned unexpected counts.' );
	}

	return (
		(int) $summary['token_count'] +
		(int) $summary['tag_count'] +
		(int) $summary['closing_tag_count'] +
		(int) $summary['empty_element_count'] +
		(int) $summary['leaf_element_count'] +
		(int) $summary['branch_element_count'] +
		(int) $summary['max_child_element_count']
	);
}

/**
 * Benchmark a fused public XML structural inventory summary.
 *
 * @return int Number of inventoried structural markers.
 */
function wp_toolkit_native_api_benchmark_xml_structural_inventory_summary() {
	$xml       = wp_toolkit_native_api_benchmark_xml_depth_document();
	$processor = XMLProcessor::create_from_string( $xml );

	if ( false === $processor ) {
		throw new RuntimeException( 'XMLProcessor could not parse benchmark document.' );
	}

	$summary = $processor->summarize_structural_inventory();
	if ( ! is_array( $summary ) || ! isset( $summary['token_count'], $summary['unique_tag_name_count'], $summary['total_tag_depth'], $summary['leaf_element_count'] ) ) {
		throw new RuntimeException( 'XML structural inventory benchmark returned an invalid summary.' );
	}

	if (
		0 === (int) $summary['empty_element_count'] ||
		0 === (int) $summary['nested_tag_count'] ||
		0 === (int) $summary['total_tag_depth'] ||
		0 === (int) $summary['leaf_element_count'] ||
		0 === (int) $summary['branch_element_count'] ||
		(int) $summary['max_child_element_count'] < 2
	) {
		throw new RuntimeException( 'XML structural inventory benchmark returned unexpected counts.' );
	}

	return (
		(int) $summary['token_count'] +
		(int) $summary['tag_count'] +
		(int) $summary['closing_tag_count'] +
		(int) $summary['unique_tag_name_count'] +
		(int) $summary['duplicate_tag_name_count'] +
		(int) $summary['namespaced_tag_count'] +
		(int) $summary['empty_element_count'] +
		(int) $summary['root_level_tag_count'] +
		(int) $summary['nested_tag_count'] +
		(int) $summary['total_tag_depth'] +
		(int) $summary['leaf_element_count'] +
		(int) $summary['branch_element_count'] +
		(int) $summary['max_child_element_count']
	);
}

/**
 * Benchmark a fused public XML attribute inventory summary.
 *
 * @return int Number of inventoried tokens and attributes.
 */
function wp_toolkit_native_api_benchmark_xml_attribute_inventory_summary() {
	$xml       = wp_toolkit_native_api_benchmark_xml_attribute_document();
	$processor = XMLProcessor::create_from_string( $xml );

	if ( false === $processor ) {
		throw new RuntimeException( 'XMLProcessor could not parse benchmark document.' );
	}

	$summary = $processor->summarize_attribute_inventory();
	if ( ! is_array( $summary ) || ! isset( $summary['token_count'], $summary['attribute_count'], $summary['max_attribute_count'] ) ) {
		throw new RuntimeException( 'XML attribute inventory benchmark returned an invalid summary.' );
	}

	if (
		0 === (int) $summary['attribute_count'] ||
		0 === (int) $summary['namespaced_attribute_count'] ||
		0 === (int) $summary['tags_with_attributes_count'] ||
		(int) $summary['max_attribute_count'] < 3
	) {
		throw new RuntimeException( 'XML attribute inventory benchmark returned unexpected counts.' );
	}

	return (
		(int) $summary['token_count'] +
		(int) $summary['attribute_count'] +
		(int) $summary['namespaced_attribute_count'] +
		(int) $summary['tags_with_attributes_count'] +
		(int) $summary['max_attribute_count']
	);
}

/**
 * Benchmark a fused public XML ID inventory summary.
 *
 * @return int Number of inventoried tokens and IDs.
 */
function wp_toolkit_native_api_benchmark_xml_id_inventory_summary() {
	$xml       = wp_toolkit_native_api_benchmark_xml_id_document();
	$processor = XMLProcessor::create_from_string( $xml );

	if ( false === $processor ) {
		throw new RuntimeException( 'XMLProcessor could not parse benchmark document.' );
	}

	$summary = $processor->summarize_id_inventory();
	if ( ! is_array( $summary ) || ! isset( $summary['token_count'], $summary['id_attribute_count'], $summary['duplicate_id_count'] ) ) {
		throw new RuntimeException( 'XML ID inventory benchmark returned an invalid summary.' );
	}

	if (
		0 === (int) $summary['id_attribute_count'] ||
		0 === (int) $summary['unique_id_count'] ||
		0 === (int) $summary['duplicate_id_count'] ||
		0 === (int) $summary['id_value_bytes']
	) {
		throw new RuntimeException( 'XML ID inventory benchmark returned unexpected counts.' );
	}

	return (
		(int) $summary['token_count'] +
		(int) $summary['id_attribute_count'] +
		(int) $summary['unique_id_count'] +
		(int) $summary['duplicate_id_count'] +
		(int) $summary['id_value_bytes']
	);
}

/**
 * Benchmark a fused public XML namespace inventory summary.
 *
 * @return int Number of inventoried namespace-related tokens and attributes.
 */
function wp_toolkit_native_api_benchmark_xml_namespace_inventory_summary() {
	$xml       = wp_toolkit_native_api_benchmark_xml_namespace_document();
	$processor = XMLProcessor::create_from_string( $xml );

	if ( false === $processor ) {
		throw new RuntimeException( 'XMLProcessor could not parse benchmark document.' );
	}

	$summary = $processor->summarize_namespace_inventory();
	if ( ! is_array( $summary ) || ! isset( $summary['token_count'], $summary['namespaced_tag_count'], $summary['unique_namespace_count'] ) ) {
		throw new RuntimeException( 'XML namespace inventory benchmark returned an invalid summary.' );
	}

	if (
		0 === (int) $summary['namespaced_tag_count'] ||
		0 === (int) $summary['namespaced_attribute_count'] ||
		(int) $summary['unique_namespace_count'] < 2
	) {
		throw new RuntimeException( 'XML namespace inventory benchmark returned unexpected counts.' );
	}

	return (
		(int) $summary['token_count'] +
		(int) $summary['namespaced_tag_count'] +
		(int) $summary['attribute_count'] +
		(int) $summary['namespaced_attribute_count'] +
		(int) $summary['unique_namespace_count']
	);
}

/**
 * Benchmark a fused public XML text inventory summary.
 *
 * @return int Number of inventoried text tokens and bytes.
 */
function wp_toolkit_native_api_benchmark_xml_text_inventory_summary() {
	$xml       = wp_toolkit_native_api_benchmark_xml_text_document();
	$processor = XMLProcessor::create_from_string( $xml );

	if ( false === $processor ) {
		throw new RuntimeException( 'XMLProcessor could not parse benchmark document.' );
	}

	$summary = $processor->summarize_text_inventory();
	if ( ! is_array( $summary ) || ! isset( $summary['token_count'], $summary['text_token_count'], $summary['total_text_bytes'] ) ) {
		throw new RuntimeException( 'XML text inventory benchmark returned an invalid summary.' );
	}

	if (
		0 === (int) $summary['text_token_count'] ||
		0 === (int) $summary['cdata_count'] ||
		0 === (int) $summary['non_empty_text_count'] ||
		0 === (int) $summary['whitespace_text_count'] ||
		(int) $summary['max_text_bytes'] < 10
	) {
		throw new RuntimeException( 'XML text inventory benchmark returned unexpected counts.' );
	}

	return (
		(int) $summary['token_count'] +
		(int) $summary['text_token_count'] +
		(int) $summary['cdata_count'] +
		(int) $summary['non_empty_text_count'] +
		(int) $summary['whitespace_text_count'] +
		(int) $summary['total_text_bytes']
	);
}

/**
 * Benchmark a fused public XML processing instruction inventory summary.
 *
 * @return int Number of inventoried processing instruction tokens and bytes.
 */
function wp_toolkit_native_api_benchmark_xml_processing_instruction_inventory_summary() {
	$xml       = wp_toolkit_native_api_benchmark_xml_processing_instruction_document();
	$processor = XMLProcessor::create_from_string( $xml );

	if ( false === $processor ) {
		throw new RuntimeException( 'XMLProcessor could not parse benchmark document.' );
	}

	$summary = $processor->summarize_processing_instruction_inventory();
	if ( ! is_array( $summary ) || ! isset( $summary['token_count'], $summary['processing_instruction_count'], $summary['total_instruction_bytes'] ) ) {
		throw new RuntimeException( 'XML processing instruction inventory benchmark returned an invalid summary.' );
	}

	if (
		0 === (int) $summary['processing_instruction_count'] ||
		0 === (int) $summary['xml_declaration_count'] ||
		0 === (int) $summary['non_empty_instruction_count'] ||
		(int) $summary['max_instruction_bytes'] < 20
	) {
		throw new RuntimeException( 'XML processing instruction inventory benchmark returned unexpected counts.' );
	}

	return (
		(int) $summary['token_count'] +
		(int) $summary['processing_instruction_count'] +
		(int) $summary['xml_declaration_count'] +
		(int) $summary['non_empty_instruction_count'] +
		(int) $summary['total_instruction_bytes']
	);
}

/**
 * Benchmark a fused public XML comment inventory summary.
 *
 * @return int Number of inventoried comment tokens and bytes.
 */
function wp_toolkit_native_api_benchmark_xml_comment_inventory_summary() {
	$xml       = wp_toolkit_native_api_benchmark_xml_comment_document();
	$processor = XMLProcessor::create_from_string( $xml );

	if ( false === $processor ) {
		throw new RuntimeException( 'XMLProcessor could not parse benchmark document.' );
	}

	$summary = $processor->summarize_comment_inventory();
	if ( ! is_array( $summary ) || ! isset( $summary['token_count'], $summary['comment_count'], $summary['total_comment_bytes'] ) ) {
		throw new RuntimeException( 'XML comment inventory benchmark returned an invalid summary.' );
	}

	if (
		0 === (int) $summary['comment_count'] ||
		0 === (int) $summary['non_empty_comment_count'] ||
		0 === (int) $summary['empty_comment_count'] ||
		(int) $summary['max_comment_bytes'] < 20
	) {
		throw new RuntimeException( 'XML comment inventory benchmark returned unexpected counts.' );
	}

	return (
		(int) $summary['token_count'] +
		(int) $summary['comment_count'] +
		(int) $summary['non_empty_comment_count'] +
		(int) $summary['empty_comment_count'] +
		(int) $summary['total_comment_bytes']
	);
}

/**
 * Benchmark a fused public XML payload inventory summary.
 *
 * @return int Number of inventoried payload tokens and bytes.
 */
function wp_toolkit_native_api_benchmark_xml_payload_inventory_summary() {
	$xml       = wp_toolkit_native_api_benchmark_xml_payload_document();
	$processor = XMLProcessor::create_from_string( $xml );

	if ( false === $processor ) {
		throw new RuntimeException( 'XMLProcessor could not parse benchmark document.' );
	}

	$summary = $processor->summarize_payload_inventory();
	if ( ! is_array( $summary ) || ! isset( $summary['token_count'], $summary['text_token_count'], $summary['total_payload_bytes'] ) ) {
		throw new RuntimeException( 'XML payload inventory benchmark returned an invalid summary.' );
	}

	if (
		0 === (int) $summary['text_token_count'] ||
		0 === (int) $summary['cdata_count'] ||
		0 === (int) $summary['comment_count'] ||
		0 === (int) $summary['processing_instruction_count'] ||
		(int) $summary['max_payload_bytes'] < 20
	) {
		throw new RuntimeException( 'XML payload inventory benchmark returned unexpected counts.' );
	}

	return (
		(int) $summary['token_count'] +
		(int) $summary['text_token_count'] +
		(int) $summary['cdata_count'] +
		(int) $summary['comment_count'] +
		(int) $summary['processing_instruction_count'] +
		(int) $summary['total_payload_bytes']
	);
}

/**
 * Benchmark a fused public XML content inventory summary.
 *
 * @return int Number of inventoried content tokens, attributes, and bytes.
 */
function wp_toolkit_native_api_benchmark_xml_content_inventory_summary() {
	$xml       = wp_toolkit_native_api_benchmark_xml_payload_document();
	$processor = XMLProcessor::create_from_string( $xml );

	if ( false === $processor ) {
		throw new RuntimeException( 'XMLProcessor could not parse benchmark document.' );
	}

	$summary = $processor->summarize_content_inventory();
	if ( ! is_array( $summary ) || ! isset( $summary['token_count'], $summary['attribute_count'], $summary['total_payload_bytes'] ) ) {
		throw new RuntimeException( 'XML content inventory benchmark returned an invalid summary.' );
	}

	if (
		0 === (int) $summary['attribute_count'] ||
		0 === (int) $summary['text_token_count'] ||
		0 === (int) $summary['cdata_count'] ||
		0 === (int) $summary['comment_count'] ||
		0 === (int) $summary['processing_instruction_count'] ||
		(int) $summary['max_attribute_value_bytes'] < 2 ||
		(int) $summary['max_payload_bytes'] < 20
	) {
		throw new RuntimeException( 'XML content inventory benchmark returned unexpected counts.' );
	}

	return (
		(int) $summary['token_count'] +
		(int) $summary['tag_count'] +
		(int) $summary['attribute_count'] +
		(int) $summary['text_token_count'] +
		(int) $summary['cdata_count'] +
		(int) $summary['comment_count'] +
		(int) $summary['processing_instruction_count'] +
		(int) $summary['total_attribute_value_bytes'] +
		(int) $summary['total_payload_bytes']
	);
}

/**
 * Benchmark a fused public XML import inventory summary.
 *
 * @return int Number of inventoried structure, content, and byte counts.
 */
function wp_toolkit_native_api_benchmark_xml_import_inventory_summary() {
	$xml       = wp_toolkit_native_api_benchmark_xml_payload_document();
	$processor = XMLProcessor::create_from_string( $xml );

	if ( false === $processor ) {
		throw new RuntimeException( 'XMLProcessor could not parse benchmark document.' );
	}

	$summary = $processor->summarize_import_inventory();
	if ( ! is_array( $summary ) || ! isset( $summary['token_count'], $summary['attribute_count'], $summary['total_payload_bytes'] ) ) {
		throw new RuntimeException( 'XML import inventory benchmark returned an invalid summary.' );
	}

	if (
		0 === (int) $summary['tag_count'] ||
		0 === (int) $summary['leaf_element_count'] ||
		0 === (int) $summary['attribute_count'] ||
		0 === (int) $summary['text_token_count'] ||
		0 === (int) $summary['cdata_count'] ||
		0 === (int) $summary['comment_count'] ||
		0 === (int) $summary['processing_instruction_count'] ||
		(int) $summary['max_payload_bytes'] < 20
	) {
		throw new RuntimeException( 'XML import inventory benchmark returned unexpected counts.' );
	}

	return (
		(int) $summary['token_count'] +
		(int) $summary['tag_count'] +
		(int) $summary['closing_tag_count'] +
		(int) $summary['unique_tag_name_count'] +
		(int) $summary['leaf_element_count'] +
		(int) $summary['branch_element_count'] +
		(int) $summary['attribute_count'] +
		(int) $summary['text_token_count'] +
		(int) $summary['cdata_count'] +
		(int) $summary['comment_count'] +
		(int) $summary['processing_instruction_count'] +
		(int) $summary['total_attribute_value_bytes'] +
		(int) $summary['total_payload_bytes']
	);
}

/**
 * Benchmark a public XML token summary batch loop.
 *
 * @return int Number of tokens visited plus matching attributes counted.
 */
function wp_toolkit_native_api_benchmark_xml_token_batch() {
	$xml       = wp_toolkit_native_api_benchmark_xml_document();
	$processor = XMLProcessor::create_from_string( $xml );
	$count     = 0;

	if ( false === $processor ) {
		throw new RuntimeException( 'XMLProcessor could not parse benchmark document.' );
	}

	do {
		$batch = $processor->next_token_compact_summary_batch( 256 );
		if ( is_string( $batch ) && '' !== $batch ) {
			foreach ( explode( "\x1e", $batch ) as $row ) {
				$parts = explode( "\x1f", $row, 6 );
				if ( 6 !== count( $parts ) ) {
					throw new RuntimeException( 'XML token batch benchmark returned an invalid summary row.' );
				}

				++$count;
				if ( isset( $parts[5][0] ) && '1' === $parts[5][0] ) {
					++$count;
				}
			}
		}
	} while ( is_string( $batch ) && '' !== $batch );

	return $count;
}

/**
 * Benchmark a fused public XML tag summary.
 *
 * @return int Number of tags visited plus matching attributes counted.
 */
function wp_toolkit_native_api_benchmark_xml_tag_summary() {
	$xml       = wp_toolkit_native_api_benchmark_xml_document();
	$processor = XMLProcessor::create_from_string( $xml );

	if ( false === $processor ) {
		throw new RuntimeException( 'XMLProcessor could not parse benchmark document.' );
	}

	$summary = $processor->summarize_tag_stream( 'id' );
	if ( ! is_array( $summary ) || ! isset( $summary['tag_count'], $summary['attribute_count'] ) ) {
		throw new RuntimeException( 'XML tag summary benchmark returned an invalid summary.' );
	}

	return (int) $summary['tag_count'] + (int) $summary['attribute_count'];
}

/**
 * Benchmark a public XML tag summary batch loop.
 *
 * @return int Number of tags visited plus matching attributes counted.
 */
function wp_toolkit_native_api_benchmark_xml_tag_batch() {
	$xml       = wp_toolkit_native_api_benchmark_xml_document();
	$processor = XMLProcessor::create_from_string( $xml );
	$count     = 0;

	if ( false === $processor ) {
		throw new RuntimeException( 'XMLProcessor could not parse benchmark document.' );
	}

	do {
		$batch = $processor->next_tag_compact_summary_batch( 256, 'id' );
		if ( is_string( $batch ) && '' !== $batch ) {
			$count += wp_toolkit_native_api_benchmark_count_xml_tag_batch( $batch );
		}
	} while ( is_string( $batch ) && '' !== $batch );

	return $count;
}

/**
 * Benchmark a public XML matching tag summary batch loop.
 *
 * @return int Number of matching tags visited plus matching attributes counted.
 */
function wp_toolkit_native_api_benchmark_xml_matching_tag_batch() {
	$xml       = wp_toolkit_native_api_benchmark_xml_document();
	$processor = XMLProcessor::create_from_string( $xml );
	$count     = 0;

	if ( false === $processor ) {
		throw new RuntimeException( 'XMLProcessor could not parse benchmark document.' );
	}

	do {
		$batch = $processor->next_matching_tag_compact_summary_batch( 256, 'https://wordpress.org', 'item', 'id' );
		if ( is_string( $batch ) && '' !== $batch ) {
			$count += wp_toolkit_native_api_benchmark_count_xml_tag_batch( $batch );
		}
	} while ( is_string( $batch ) && '' !== $batch );

	return $count;
}

/**
 * Benchmark a public XML matching tag count batch loop.
 *
 * @return int Number of matching tags visited plus matching attributes counted.
 */
function wp_toolkit_native_api_benchmark_xml_matching_tag_count_batch() {
	$xml       = wp_toolkit_native_api_benchmark_xml_document();
	$processor = XMLProcessor::create_from_string( $xml );
	$count     = 0;

	if ( false === $processor ) {
		throw new RuntimeException( 'XMLProcessor could not parse benchmark document.' );
	}

	do {
		$summary = $processor->next_matching_tag_count_compact_batch( 256, 'https://wordpress.org', 'item', 'id' );
		if ( is_string( $summary ) ) {
			$parts = explode( "\x1f", $summary, 3 );
			if ( 3 !== count( $parts ) ) {
				throw new RuntimeException( 'XML matching tag count batch benchmark returned an invalid summary row.' );
			}

			$count += (int) $parts[1] + (int) $parts[2];
		}
	} while ( is_string( $summary ) );

	return $count;
}

/**
 * Benchmark a document-level XML matching tag summary.
 *
 * @return int Number of matching tags visited plus matching attributes counted.
 */
function wp_toolkit_native_api_benchmark_xml_matching_tag_summary() {
	$xml       = wp_toolkit_native_api_benchmark_xml_document();
	$processor = XMLProcessor::create_from_string( $xml );

	if ( false === $processor ) {
		throw new RuntimeException( 'XMLProcessor could not parse benchmark document.' );
	}

	$summary = $processor->summarize_matching_tag_stream( 'https://wordpress.org', 'item', 'id' );
	if ( ! is_array( $summary ) || ! isset( $summary['tag_count'], $summary['attribute_count'] ) ) {
		throw new RuntimeException( 'XML matching tag summary benchmark returned an invalid summary.' );
	}

	return (int) $summary['tag_count'] + (int) $summary['attribute_count'];
}

/**
 * Benchmark a document-level XML matching tag multi-attribute summary.
 *
 * @return int Number of matching tags visited plus matching attributes counted.
 */
function wp_toolkit_native_api_benchmark_xml_matching_tag_attributes_summary() {
	$xml       = wp_toolkit_native_api_benchmark_xml_matching_attribute_document();
	$processor = XMLProcessor::create_from_string( $xml );

	if ( false === $processor ) {
		throw new RuntimeException( 'XMLProcessor could not parse benchmark document.' );
	}

	$summary = $processor->summarize_matching_tag_attributes_stream(
		'https://wordpress.org',
		'item',
		array( 'id', 'slug', 'status' )
	);
	if ( ! is_array( $summary ) || ! isset( $summary['tag_count'], $summary['attribute_count'] ) ) {
		throw new RuntimeException( 'XML matching tag attributes summary benchmark returned an invalid summary.' );
	}

	return (int) $summary['tag_count'] + (int) $summary['attribute_count'];
}

/**
 * Benchmark a public XML tag count batch loop.
 *
 * @return int Number of tags visited plus matching attributes counted.
 */
function wp_toolkit_native_api_benchmark_xml_tag_count_batch() {
	$xml       = wp_toolkit_native_api_benchmark_xml_document();
	$processor = XMLProcessor::create_from_string( $xml );
	$count     = 0;

	if ( false === $processor ) {
		throw new RuntimeException( 'XMLProcessor could not parse benchmark document.' );
	}

	do {
		$summary = $processor->next_tag_count_compact_batch( 256, 'id' );
		if ( is_string( $summary ) ) {
			$parts = explode( "\x1f", $summary, 3 );
			if ( 3 !== count( $parts ) ) {
				throw new RuntimeException( 'XML tag count batch benchmark returned an invalid summary row.' );
			}

			$count += (int) $parts[1] + (int) $parts[2];
		}
	} while ( is_string( $summary ) );

	return $count;
}

/**
 * Benchmark a document-level XML attribute-prefix summary.
 *
 * @return int Number of tags visited plus matching attributes counted.
 */
function wp_toolkit_native_api_benchmark_xml_prefix_summary() {
	$xml       = wp_toolkit_native_api_benchmark_xml_attribute_document();
	$processor = XMLProcessor::create_from_string( $xml );

	if ( false === $processor ) {
		throw new RuntimeException( 'XMLProcessor could not parse benchmark document.' );
	}

	$summary = $processor->summarize_attribute_names_with_prefix( null, 'data-' );
	if ( ! is_array( $summary ) || ! isset( $summary['tag_count'], $summary['attribute_count'] ) ) {
		throw new RuntimeException( 'XML prefix summary benchmark returned an invalid summary.' );
	}

	return (int) $summary['tag_count'] + (int) $summary['attribute_count'];
}

/**
 * Benchmark document-level XML prefixed-attribute removal.
 *
 * @return int Number of tags visited plus matching attributes removed.
 */
function wp_toolkit_native_api_benchmark_xml_prefix_sanitizer() {
	$xml       = wp_toolkit_native_api_benchmark_xml_attribute_document();
	$processor = XMLProcessor::create_from_string( $xml );

	if ( false === $processor ) {
		throw new RuntimeException( 'XMLProcessor could not parse benchmark document.' );
	}

	$summary = $processor->remove_attributes_with_prefix_from_document( null, 'data-' );
	if ( ! is_array( $summary ) || ! isset( $summary['tag_count'], $summary['removed_count'], $summary['xml'] ) ) {
		throw new RuntimeException( 'XML prefix sanitizer benchmark returned an invalid summary.' );
	}

	if ( false !== strpos( $summary['xml'], ' data-' ) ) {
		throw new RuntimeException( 'XML prefix sanitizer benchmark left data-* attributes in the output.' );
	}

	return (int) $summary['tag_count'] + (int) $summary['removed_count'];
}

/**
 * Benchmark the native XML processor.
 *
 * @return int Number of tokens visited.
 */
function wp_toolkit_native_api_benchmark_native_xml_processor() {
	$xml        = wp_toolkit_native_api_benchmark_xml_document();
	$class_name = 'WordPress\\XML\\NativeXMLProcessor';
	$processor  = $class_name::create_from_string( $xml );
	$count      = 0;

	while ( $processor->next_token() ) {
		++$count;
		$processor->get_token_type();
		$processor->get_token_name();
		$processor->get_attribute( 'id' );
	}

	if ( null !== $processor->get_last_error() ) {
		throw new RuntimeException( 'NativeXMLProcessor reported benchmark parse error: ' . $processor->get_last_error() );
	}

	return $count;
}

/**
 * Benchmark the native fused XML token summary.
 *
 * @return int Number of tokens visited plus matching attributes counted.
 */
function wp_toolkit_native_api_benchmark_native_xml_token_summary() {
	$xml        = wp_toolkit_native_api_benchmark_xml_document();
	$class_name = 'WordPress\\XML\\NativeXMLProcessor';
	$processor  = $class_name::create_from_string( $xml );
	$summary    = $processor->summarize_token_stream( 'id' );

	if ( ! is_string( $summary ) ) {
		throw new RuntimeException( 'Native XML token summary benchmark returned an invalid summary.' );
	}

	$parts = explode( "\x1f", $summary, 3 );
	if ( 3 !== count( $parts ) ) {
		throw new RuntimeException( 'Native XML token summary benchmark returned an invalid summary row.' );
	}

	if ( null !== $processor->get_last_error() ) {
		throw new RuntimeException( 'NativeXMLProcessor reported benchmark parse error: ' . $processor->get_last_error() );
	}

	return (int) $parts[0] + (int) $parts[2];
}

/**
 * Benchmark native XML document inventory summary.
 *
 * @return int Number of inventoried tokens and structural markers.
 */
function wp_toolkit_native_api_benchmark_native_xml_document_inventory_summary() {
	$xml        = wp_toolkit_native_api_benchmark_xml_inventory_document();
	$class_name = 'WordPress\\XML\\NativeXMLProcessor';
	$processor  = $class_name::create_from_string( $xml );
	$summary    = $processor->summarize_document_inventory();

	if ( ! is_string( $summary ) ) {
		throw new RuntimeException( 'Native XML document inventory benchmark returned an invalid summary.' );
	}

	$parts = explode( "\x1f", $summary, 8 );
	if ( 8 !== count( $parts ) ) {
		throw new RuntimeException( 'Native XML document inventory benchmark returned an invalid summary row.' );
	}

	if ( null !== $processor->get_last_error() ) {
		throw new RuntimeException( 'NativeXMLProcessor reported benchmark parse error: ' . $processor->get_last_error() );
	}

	if ( 3 !== (int) $parts[6] || 0 === (int) $parts[4] || 0 === (int) $parts[5] ) {
		throw new RuntimeException( 'Native XML document inventory benchmark returned unexpected counts.' );
	}

	return (
		(int) $parts[0] +
		(int) $parts[1] +
		(int) $parts[2] +
		(int) $parts[3] +
		(int) $parts[4] +
		(int) $parts[5] +
		(int) $parts[7]
	);
}

/**
 * Benchmark native XML element inventory summary.
 *
 * @return int Number of inventoried element-name markers.
 */
function wp_toolkit_native_api_benchmark_native_xml_element_inventory_summary() {
	$xml        = wp_toolkit_native_api_benchmark_xml_element_document();
	$class_name = 'WordPress\\XML\\NativeXMLProcessor';
	$processor  = $class_name::create_from_string( $xml );
	$summary    = $processor->summarize_element_inventory();

	if ( ! is_string( $summary ) ) {
		throw new RuntimeException( 'Native XML element inventory benchmark returned an invalid summary.' );
	}

	$parts = explode( "\x1f", $summary, 7 );
	if ( 7 !== count( $parts ) ) {
		throw new RuntimeException( 'Native XML element inventory benchmark returned an invalid summary row.' );
	}

	if ( null !== $processor->get_last_error() ) {
		throw new RuntimeException( 'NativeXMLProcessor reported benchmark parse error: ' . $processor->get_last_error() );
	}

	if ( 7 !== (int) $parts[3] || 0 === (int) $parts[4] || 0 === (int) $parts[5] || 0 === (int) $parts[6] ) {
		throw new RuntimeException( 'Native XML element inventory benchmark returned unexpected counts.' );
	}

	return (
		(int) $parts[0] +
		(int) $parts[1] +
		(int) $parts[2] +
		(int) $parts[3] +
		(int) $parts[4] +
		(int) $parts[5] +
		(int) $parts[6]
	);
}

/**
 * Benchmark native XML depth inventory summary.
 *
 * @return int Number of inventoried tags and depth markers.
 */
function wp_toolkit_native_api_benchmark_native_xml_depth_inventory_summary() {
	$xml        = wp_toolkit_native_api_benchmark_xml_depth_document();
	$class_name = 'WordPress\\XML\\NativeXMLProcessor';
	$processor  = $class_name::create_from_string( $xml );
	$summary    = $processor->summarize_depth_inventory();

	if ( ! is_string( $summary ) ) {
		throw new RuntimeException( 'Native XML depth inventory benchmark returned an invalid summary.' );
	}

	$parts = explode( "\x1f", $summary, 8 );
	if ( 8 !== count( $parts ) ) {
		throw new RuntimeException( 'Native XML depth inventory benchmark returned an invalid summary row.' );
	}

	if ( null !== $processor->get_last_error() ) {
		throw new RuntimeException( 'NativeXMLProcessor reported benchmark parse error: ' . $processor->get_last_error() );
	}

	if ( (int) $parts[7] < 4 || 0 === (int) $parts[3] || 0 === (int) $parts[5] || 0 === (int) $parts[6] ) {
		throw new RuntimeException( 'Native XML depth inventory benchmark returned unexpected counts.' );
	}

	return (
		(int) $parts[0] +
		(int) $parts[1] +
		(int) $parts[2] +
		(int) $parts[3] +
		(int) $parts[4] +
		(int) $parts[5] +
		(int) $parts[6]
	);
}

/**
 * Benchmark native XML leaf inventory summary.
 *
 * @return int Number of inventoried tags and leaf/branch markers.
 */
function wp_toolkit_native_api_benchmark_native_xml_leaf_inventory_summary() {
	$xml        = wp_toolkit_native_api_benchmark_xml_depth_document();
	$class_name = 'WordPress\\XML\\NativeXMLProcessor';
	$processor  = $class_name::create_from_string( $xml );
	$summary    = $processor->summarize_leaf_inventory();

	if ( ! is_string( $summary ) ) {
		throw new RuntimeException( 'Native XML leaf inventory benchmark returned an invalid summary.' );
	}

	$parts = explode( "\x1f", $summary, 7 );
	if ( 7 !== count( $parts ) ) {
		throw new RuntimeException( 'Native XML leaf inventory benchmark returned an invalid summary row.' );
	}

	if ( null !== $processor->get_last_error() ) {
		throw new RuntimeException( 'NativeXMLProcessor reported benchmark parse error: ' . $processor->get_last_error() );
	}

	if ( 0 === (int) $parts[3] || 0 === (int) $parts[4] || 0 === (int) $parts[5] || (int) $parts[6] < 2 ) {
		throw new RuntimeException( 'Native XML leaf inventory benchmark returned unexpected counts.' );
	}

	return (
		(int) $parts[0] +
		(int) $parts[1] +
		(int) $parts[2] +
		(int) $parts[3] +
		(int) $parts[4] +
		(int) $parts[5] +
		(int) $parts[6]
	);
}

/**
 * Benchmark native XML structural inventory summary.
 *
 * @return int Number of inventoried structural markers.
 */
function wp_toolkit_native_api_benchmark_native_xml_structural_inventory_summary() {
	$xml        = wp_toolkit_native_api_benchmark_xml_depth_document();
	$class_name = 'WordPress\\XML\\NativeXMLProcessor';
	$processor  = $class_name::create_from_string( $xml );
	$summary    = $processor->summarize_structural_inventory();

	if ( ! is_string( $summary ) ) {
		throw new RuntimeException( 'Native XML structural inventory benchmark returned an invalid summary.' );
	}

	$parts = explode( "\x1f", $summary, 14 );
	if ( 14 !== count( $parts ) ) {
		throw new RuntimeException( 'Native XML structural inventory benchmark returned an invalid summary row.' );
	}

	if ( null !== $processor->get_last_error() ) {
		throw new RuntimeException( 'NativeXMLProcessor reported benchmark parse error: ' . $processor->get_last_error() );
	}

	if ( 0 === (int) $parts[6] || 0 === (int) $parts[8] || 0 === (int) $parts[9] || 0 === (int) $parts[11] || 0 === (int) $parts[12] || (int) $parts[13] < 2 ) {
		throw new RuntimeException( 'Native XML structural inventory benchmark returned unexpected counts.' );
	}

	return (
		(int) $parts[0] +
		(int) $parts[1] +
		(int) $parts[2] +
		(int) $parts[3] +
		(int) $parts[4] +
		(int) $parts[5] +
		(int) $parts[6] +
		(int) $parts[7] +
		(int) $parts[8] +
		(int) $parts[9] +
		(int) $parts[11] +
		(int) $parts[12] +
		(int) $parts[13]
	);
}

/**
 * Benchmark native XML attribute inventory summary.
 *
 * @return int Number of inventoried tokens and attributes.
 */
function wp_toolkit_native_api_benchmark_native_xml_attribute_inventory_summary() {
	$xml        = wp_toolkit_native_api_benchmark_xml_attribute_document();
	$class_name = 'WordPress\\XML\\NativeXMLProcessor';
	$processor  = $class_name::create_from_string( $xml );
	$summary    = $processor->summarize_attribute_inventory();

	if ( ! is_string( $summary ) ) {
		throw new RuntimeException( 'Native XML attribute inventory benchmark returned an invalid summary.' );
	}

	$parts = explode( "\x1f", $summary, 6 );
	if ( 6 !== count( $parts ) ) {
		throw new RuntimeException( 'Native XML attribute inventory benchmark returned an invalid summary row.' );
	}

	if ( null !== $processor->get_last_error() ) {
		throw new RuntimeException( 'NativeXMLProcessor reported benchmark parse error: ' . $processor->get_last_error() );
	}

	if ( 0 === (int) $parts[2] || 0 === (int) $parts[3] || 0 === (int) $parts[4] || (int) $parts[5] < 3 ) {
		throw new RuntimeException( 'Native XML attribute inventory benchmark returned unexpected counts.' );
	}

	return (
		(int) $parts[0] +
		(int) $parts[2] +
		(int) $parts[3] +
		(int) $parts[4] +
		(int) $parts[5]
	);
}

/**
 * Benchmark native XML ID inventory summary.
 *
 * @return int Number of inventoried tokens and IDs.
 */
function wp_toolkit_native_api_benchmark_native_xml_id_inventory_summary() {
	$xml        = wp_toolkit_native_api_benchmark_xml_id_document();
	$class_name = 'WordPress\\XML\\NativeXMLProcessor';
	$processor  = $class_name::create_from_string( $xml );
	$summary    = $processor->summarize_id_inventory();

	if ( ! is_string( $summary ) ) {
		throw new RuntimeException( 'Native XML ID inventory benchmark returned an invalid summary.' );
	}

	$parts = explode( "\x1f", $summary, 6 );
	if ( 6 !== count( $parts ) ) {
		throw new RuntimeException( 'Native XML ID inventory benchmark returned an invalid summary row.' );
	}

	if ( null !== $processor->get_last_error() ) {
		throw new RuntimeException( 'NativeXMLProcessor reported benchmark parse error: ' . $processor->get_last_error() );
	}

	if ( 0 === (int) $parts[2] || 0 === (int) $parts[3] || 0 === (int) $parts[4] || 0 === (int) $parts[5] ) {
		throw new RuntimeException( 'Native XML ID inventory benchmark returned unexpected counts.' );
	}

	return (
		(int) $parts[0] +
		(int) $parts[2] +
		(int) $parts[3] +
		(int) $parts[4] +
		(int) $parts[5]
	);
}

/**
 * Benchmark native XML namespace inventory summary.
 *
 * @return int Number of inventoried namespace-related tokens and attributes.
 */
function wp_toolkit_native_api_benchmark_native_xml_namespace_inventory_summary() {
	$xml        = wp_toolkit_native_api_benchmark_xml_namespace_document();
	$class_name = 'WordPress\\XML\\NativeXMLProcessor';
	$processor  = $class_name::create_from_string( $xml );
	$summary    = $processor->summarize_namespace_inventory();

	if ( ! is_string( $summary ) ) {
		throw new RuntimeException( 'Native XML namespace inventory benchmark returned an invalid summary.' );
	}

	$parts = explode( "\x1f", $summary, 6 );
	if ( 6 !== count( $parts ) ) {
		throw new RuntimeException( 'Native XML namespace inventory benchmark returned an invalid summary row.' );
	}

	if ( null !== $processor->get_last_error() ) {
		throw new RuntimeException( 'NativeXMLProcessor reported benchmark parse error: ' . $processor->get_last_error() );
	}

	if ( 0 === (int) $parts[2] || 0 === (int) $parts[4] || (int) $parts[5] < 2 ) {
		throw new RuntimeException( 'Native XML namespace inventory benchmark returned unexpected counts.' );
	}

	return (
		(int) $parts[0] +
		(int) $parts[2] +
		(int) $parts[3] +
		(int) $parts[4] +
		(int) $parts[5]
	);
}

/**
 * Benchmark native XML text inventory summary.
 *
 * @return int Number of inventoried text tokens and bytes.
 */
function wp_toolkit_native_api_benchmark_native_xml_text_inventory_summary() {
	$xml        = wp_toolkit_native_api_benchmark_xml_text_document();
	$class_name = 'WordPress\\XML\\NativeXMLProcessor';
	$processor  = $class_name::create_from_string( $xml );
	$summary    = $processor->summarize_text_inventory();

	if ( ! is_string( $summary ) ) {
		throw new RuntimeException( 'Native XML text inventory benchmark returned an invalid summary.' );
	}

	$parts = explode( "\x1f", $summary, 7 );
	if ( 7 !== count( $parts ) ) {
		throw new RuntimeException( 'Native XML text inventory benchmark returned an invalid summary row.' );
	}

	if ( null !== $processor->get_last_error() ) {
		throw new RuntimeException( 'NativeXMLProcessor reported benchmark parse error: ' . $processor->get_last_error() );
	}

	if ( 0 === (int) $parts[1] || 0 === (int) $parts[2] || 0 === (int) $parts[3] || 0 === (int) $parts[4] || (int) $parts[6] < 10 ) {
		throw new RuntimeException( 'Native XML text inventory benchmark returned unexpected counts.' );
	}

	return (
		(int) $parts[0] +
		(int) $parts[1] +
		(int) $parts[2] +
		(int) $parts[3] +
		(int) $parts[4] +
		(int) $parts[5]
	);
}

/**
 * Benchmark native XML processing instruction inventory summary.
 *
 * @return int Number of inventoried processing instruction tokens and bytes.
 */
function wp_toolkit_native_api_benchmark_native_xml_processing_instruction_inventory_summary() {
	$xml        = wp_toolkit_native_api_benchmark_xml_processing_instruction_document();
	$class_name = 'WordPress\\XML\\NativeXMLProcessor';
	$processor  = $class_name::create_from_string( $xml );
	$summary    = $processor->summarize_processing_instruction_inventory();

	if ( ! is_string( $summary ) ) {
		throw new RuntimeException( 'Native XML processing instruction inventory benchmark returned an invalid summary.' );
	}

	$parts = explode( "\x1f", $summary, 6 );
	if ( 6 !== count( $parts ) ) {
		throw new RuntimeException( 'Native XML processing instruction inventory benchmark returned an invalid summary row.' );
	}

	if ( null !== $processor->get_last_error() ) {
		throw new RuntimeException( 'NativeXMLProcessor reported benchmark parse error: ' . $processor->get_last_error() );
	}

	if ( 0 === (int) $parts[1] || 0 === (int) $parts[2] || 0 === (int) $parts[3] || (int) $parts[5] < 20 ) {
		throw new RuntimeException( 'Native XML processing instruction inventory benchmark returned unexpected counts.' );
	}

	return (
		(int) $parts[0] +
		(int) $parts[1] +
		(int) $parts[2] +
		(int) $parts[3] +
		(int) $parts[4]
	);
}

/**
 * Benchmark native XML comment inventory summary.
 *
 * @return int Number of inventoried comment tokens and bytes.
 */
function wp_toolkit_native_api_benchmark_native_xml_comment_inventory_summary() {
	$xml        = wp_toolkit_native_api_benchmark_xml_comment_document();
	$class_name = 'WordPress\\XML\\NativeXMLProcessor';
	$processor  = $class_name::create_from_string( $xml );
	$summary    = $processor->summarize_comment_inventory();

	if ( ! is_string( $summary ) ) {
		throw new RuntimeException( 'Native XML comment inventory benchmark returned an invalid summary.' );
	}

	$parts = explode( "\x1f", $summary, 6 );
	if ( 6 !== count( $parts ) ) {
		throw new RuntimeException( 'Native XML comment inventory benchmark returned an invalid summary row.' );
	}

	if ( null !== $processor->get_last_error() ) {
		throw new RuntimeException( 'NativeXMLProcessor reported benchmark parse error: ' . $processor->get_last_error() );
	}

	if ( 0 === (int) $parts[1] || 0 === (int) $parts[2] || 0 === (int) $parts[3] || (int) $parts[5] < 20 ) {
		throw new RuntimeException( 'Native XML comment inventory benchmark returned unexpected counts.' );
	}

	return (
		(int) $parts[0] +
		(int) $parts[1] +
		(int) $parts[2] +
		(int) $parts[3] +
		(int) $parts[4]
	);
}

/**
 * Benchmark native XML payload inventory summary.
 *
 * @return int Number of inventoried payload tokens and bytes.
 */
function wp_toolkit_native_api_benchmark_native_xml_payload_inventory_summary() {
	$xml        = wp_toolkit_native_api_benchmark_xml_payload_document();
	$class_name = 'WordPress\\XML\\NativeXMLProcessor';
	$processor  = $class_name::create_from_string( $xml );
	$summary    = $processor->summarize_payload_inventory();

	if ( ! is_string( $summary ) ) {
		throw new RuntimeException( 'Native XML payload inventory benchmark returned an invalid summary.' );
	}

	$parts = explode( "\x1f", $summary, 7 );
	if ( 7 !== count( $parts ) ) {
		throw new RuntimeException( 'Native XML payload inventory benchmark returned an invalid summary row.' );
	}

	if ( null !== $processor->get_last_error() ) {
		throw new RuntimeException( 'NativeXMLProcessor reported benchmark parse error: ' . $processor->get_last_error() );
	}

	if ( 0 === (int) $parts[1] || 0 === (int) $parts[2] || 0 === (int) $parts[3] || 0 === (int) $parts[4] || (int) $parts[6] < 20 ) {
		throw new RuntimeException( 'Native XML payload inventory benchmark returned unexpected counts.' );
	}

	return (
		(int) $parts[0] +
		(int) $parts[1] +
		(int) $parts[2] +
		(int) $parts[3] +
		(int) $parts[4] +
		(int) $parts[5]
	);
}

/**
 * Benchmark native XML content inventory summary.
 *
 * @return int Number of inventoried content tokens, attributes, and bytes.
 */
function wp_toolkit_native_api_benchmark_native_xml_content_inventory_summary() {
	$xml        = wp_toolkit_native_api_benchmark_xml_payload_document();
	$class_name = 'WordPress\\XML\\NativeXMLProcessor';
	$processor  = $class_name::create_from_string( $xml );
	$summary    = $processor->summarize_content_inventory();

	if ( ! is_string( $summary ) ) {
		throw new RuntimeException( 'Native XML content inventory benchmark returned an invalid summary.' );
	}

	$parts = explode( "\x1f", $summary, 11 );
	if ( 11 !== count( $parts ) ) {
		throw new RuntimeException( 'Native XML content inventory benchmark returned an invalid summary row.' );
	}

	if ( null !== $processor->get_last_error() ) {
		throw new RuntimeException( 'NativeXMLProcessor reported benchmark parse error: ' . $processor->get_last_error() );
	}

	if ( 0 === (int) $parts[2] || 0 === (int) $parts[3] || 0 === (int) $parts[4] || 0 === (int) $parts[5] || 0 === (int) $parts[6] || (int) $parts[8] < 2 || (int) $parts[10] < 20 ) {
		throw new RuntimeException( 'Native XML content inventory benchmark returned unexpected counts.' );
	}

	return (
		(int) $parts[0] +
		(int) $parts[1] +
		(int) $parts[2] +
		(int) $parts[3] +
		(int) $parts[4] +
		(int) $parts[5] +
		(int) $parts[6] +
		(int) $parts[7] +
		(int) $parts[9]
	);
}

/**
 * Benchmark native XML import inventory summary.
 *
 * @return int Number of inventoried structure, content, and byte counts.
 */
function wp_toolkit_native_api_benchmark_native_xml_import_inventory_summary() {
	$xml        = wp_toolkit_native_api_benchmark_xml_payload_document();
	$class_name = 'WordPress\\XML\\NativeXMLProcessor';
	$processor  = $class_name::create_from_string( $xml );
	$summary    = $processor->summarize_import_inventory();

	if ( ! is_string( $summary ) ) {
		throw new RuntimeException( 'Native XML import inventory benchmark returned an invalid summary.' );
	}

	$parts = explode( "\x1f", $summary, 23 );
	if ( 23 !== count( $parts ) ) {
		throw new RuntimeException( 'Native XML import inventory benchmark returned an invalid summary row.' );
	}

	if ( null !== $processor->get_last_error() ) {
		throw new RuntimeException( 'NativeXMLProcessor reported benchmark parse error: ' . $processor->get_last_error() );
	}

	if ( 0 === (int) $parts[1] || 0 === (int) $parts[11] || 0 === (int) $parts[14] || 0 === (int) $parts[15] || 0 === (int) $parts[16] || 0 === (int) $parts[17] || 0 === (int) $parts[18] || (int) $parts[22] < 20 ) {
		throw new RuntimeException( 'Native XML import inventory benchmark returned unexpected counts.' );
	}

	return (
		(int) $parts[0] +
		(int) $parts[1] +
		(int) $parts[2] +
		(int) $parts[3] +
		(int) $parts[11] +
		(int) $parts[12] +
		(int) $parts[14] +
		(int) $parts[15] +
		(int) $parts[16] +
		(int) $parts[17] +
		(int) $parts[18] +
		(int) $parts[19] +
		(int) $parts[21]
	);
}

/**
 * Benchmark native XML token summary batches.
 *
 * @return int Number of tokens visited plus matching attributes counted.
 */
function wp_toolkit_native_api_benchmark_native_xml_token_batch() {
	$xml        = wp_toolkit_native_api_benchmark_xml_document();
	$class_name = 'WordPress\\XML\\NativeXMLProcessor';
	$processor  = $class_name::create_from_string( $xml );
	$count      = 0;

	do {
		$batch = $processor->next_token_compact_summary_batch( 256 );
		if ( is_string( $batch ) && '' !== $batch ) {
			foreach ( explode( "\x1e", $batch ) as $row ) {
				$parts = explode( "\x1f", $row, 6 );
				if ( 6 !== count( $parts ) ) {
					throw new RuntimeException( 'Native XML token batch benchmark returned an invalid summary row.' );
				}

				++$count;
				if ( isset( $parts[5][0] ) && '1' === $parts[5][0] ) {
					++$count;
				}
			}
		}
	} while ( is_string( $batch ) && '' !== $batch );

	if ( null !== $processor->get_last_error() ) {
		throw new RuntimeException( 'NativeXMLProcessor reported benchmark parse error: ' . $processor->get_last_error() );
	}

	return $count;
}

/**
 * Benchmark the native fused XML tag summary.
 *
 * @return int Number of tags visited plus matching attributes counted.
 */
function wp_toolkit_native_api_benchmark_native_xml_tag_summary() {
	$xml        = wp_toolkit_native_api_benchmark_xml_document();
	$class_name = 'WordPress\\XML\\NativeXMLProcessor';
	$processor  = $class_name::create_from_string( $xml );
	$summary    = $processor->summarize_tag_stream( 'id' );

	if ( ! is_string( $summary ) ) {
		throw new RuntimeException( 'Native XML tag summary benchmark returned an invalid summary.' );
	}

	$parts = explode( "\x1f", $summary, 3 );
	if ( 3 !== count( $parts ) ) {
		throw new RuntimeException( 'Native XML tag summary benchmark returned an invalid summary row.' );
	}

	if ( null !== $processor->get_last_error() ) {
		throw new RuntimeException( 'NativeXMLProcessor reported benchmark parse error: ' . $processor->get_last_error() );
	}

	return (int) $parts[1] + (int) $parts[2];
}

/**
 * Benchmark native XML tag summary batches.
 *
 * @return int Number of tags visited plus matching attributes counted.
 */
function wp_toolkit_native_api_benchmark_native_xml_tag_batch() {
	$xml        = wp_toolkit_native_api_benchmark_xml_document();
	$class_name = 'WordPress\\XML\\NativeXMLProcessor';
	$processor  = $class_name::create_from_string( $xml );
	$count      = 0;

	do {
		$batch = $processor->next_tag_compact_summary_batch( 256, 'id' );
		if ( is_string( $batch ) && '' !== $batch ) {
			$count += wp_toolkit_native_api_benchmark_count_xml_tag_batch( $batch );
		}
	} while ( is_string( $batch ) && '' !== $batch );

	if ( null !== $processor->get_last_error() ) {
		throw new RuntimeException( 'NativeXMLProcessor reported benchmark parse error: ' . $processor->get_last_error() );
	}

	return $count;
}

/**
 * Benchmark native XML matching tag summary batches.
 *
 * @return int Number of matching tags visited plus matching attributes counted.
 */
function wp_toolkit_native_api_benchmark_native_xml_matching_tag_batch() {
	$xml        = wp_toolkit_native_api_benchmark_xml_document();
	$class_name = 'WordPress\\XML\\NativeXMLProcessor';
	$processor  = $class_name::create_from_string( $xml );
	$count      = 0;

	do {
		$batch = $processor->next_matching_tag_compact_summary_batch( 256, 'https://wordpress.org', 'item', 'id' );
		if ( is_string( $batch ) && '' !== $batch ) {
			$count += wp_toolkit_native_api_benchmark_count_xml_tag_batch( $batch );
		}
	} while ( is_string( $batch ) && '' !== $batch );

	if ( null !== $processor->get_last_error() ) {
		throw new RuntimeException( 'NativeXMLProcessor reported benchmark parse error: ' . $processor->get_last_error() );
	}

	return $count;
}

/**
 * Benchmark native XML matching tag count batches.
 *
 * @return int Number of matching tags visited plus matching attributes counted.
 */
function wp_toolkit_native_api_benchmark_native_xml_matching_tag_count_batch() {
	$xml        = wp_toolkit_native_api_benchmark_xml_document();
	$class_name = 'WordPress\\XML\\NativeXMLProcessor';
	$processor  = $class_name::create_from_string( $xml );
	$count      = 0;

	do {
		$summary = $processor->next_matching_tag_count_batch( 256, 'https://wordpress.org', 'item', 'id' );
		if ( is_string( $summary ) ) {
			$parts = explode( "\x1f", $summary, 3 );
			if ( 3 !== count( $parts ) ) {
				throw new RuntimeException( 'Native XML matching tag count batch benchmark returned an invalid summary row.' );
			}

			$count += (int) $parts[1] + (int) $parts[2];
		}
	} while ( is_string( $summary ) );

	if ( null !== $processor->get_last_error() ) {
		throw new RuntimeException( 'NativeXMLProcessor reported benchmark parse error: ' . $processor->get_last_error() );
	}

	return $count;
}

/**
 * Benchmark the native document-level XML matching tag summary.
 *
 * @return int Number of matching tags visited plus matching attributes counted.
 */
function wp_toolkit_native_api_benchmark_native_xml_matching_tag_summary() {
	$xml        = wp_toolkit_native_api_benchmark_xml_document();
	$class_name = 'WordPress\\XML\\NativeXMLProcessor';
	$processor  = $class_name::create_from_string( $xml );
	$summary    = $processor->summarize_matching_tag_stream( 'https://wordpress.org', 'item', 'id' );

	if ( ! is_string( $summary ) ) {
		throw new RuntimeException( 'Native XML matching tag summary benchmark returned an invalid summary.' );
	}

	$parts = explode( "\x1f", $summary, 3 );
	if ( 3 !== count( $parts ) ) {
		throw new RuntimeException( 'Native XML matching tag summary benchmark returned an invalid summary row.' );
	}

	if ( null !== $processor->get_last_error() ) {
		throw new RuntimeException( 'NativeXMLProcessor reported benchmark parse error: ' . $processor->get_last_error() );
	}

	return (int) $parts[1] + (int) $parts[2];
}

/**
 * Benchmark the native document-level XML matching tag multi-attribute summary.
 *
 * @return int Number of matching tags visited plus matching attributes counted.
 */
function wp_toolkit_native_api_benchmark_native_xml_matching_tag_attributes_summary() {
	$xml        = wp_toolkit_native_api_benchmark_xml_matching_attribute_document();
	$class_name = 'WordPress\\XML\\NativeXMLProcessor';
	$processor  = $class_name::create_from_string( $xml );
	$summary    = $processor->summarize_matching_tag_attributes_stream( 'https://wordpress.org', 'item', "id\x1fslug\x1fstatus" );

	if ( ! is_string( $summary ) ) {
		throw new RuntimeException( 'Native XML matching tag attributes summary benchmark returned an invalid summary.' );
	}

	$parts = explode( "\x1f", $summary, 3 );
	if ( 3 !== count( $parts ) ) {
		throw new RuntimeException( 'Native XML matching tag attributes summary benchmark returned an invalid summary row.' );
	}

	if ( null !== $processor->get_last_error() ) {
		throw new RuntimeException( 'NativeXMLProcessor reported benchmark parse error: ' . $processor->get_last_error() );
	}

	return (int) $parts[1] + (int) $parts[2];
}

/**
 * Benchmark native XML tag count batches.
 *
 * @return int Number of tags visited plus matching attributes counted.
 */
function wp_toolkit_native_api_benchmark_native_xml_tag_count_batch() {
	$xml        = wp_toolkit_native_api_benchmark_xml_document();
	$class_name = 'WordPress\\XML\\NativeXMLProcessor';
	$processor  = $class_name::create_from_string( $xml );
	$count      = 0;

	do {
		$summary = $processor->next_tag_count_batch( 256, 'id' );
		if ( is_string( $summary ) ) {
			$parts = explode( "\x1f", $summary, 3 );
			if ( 3 !== count( $parts ) ) {
				throw new RuntimeException( 'Native XML tag count batch benchmark returned an invalid summary row.' );
			}

			$count += (int) $parts[1] + (int) $parts[2];
		}
	} while ( is_string( $summary ) );

	if ( null !== $processor->get_last_error() ) {
		throw new RuntimeException( 'NativeXMLProcessor reported benchmark parse error: ' . $processor->get_last_error() );
	}

	return $count;
}

/**
 * Counts compact XML tag batch rows without allocating per-row arrays.
 *
 * @param string $batch Compact tag summary batch.
 * @return int Number of tags plus matching attributes.
 */
function wp_toolkit_native_api_benchmark_count_xml_tag_batch( $batch ) {
	$count  = 0;
	$offset = 0;
	$length = strlen( $batch );

	while ( $offset < $length ) {
		$row_end = strpos( $batch, "\x1e", $offset );
		if ( false === $row_end ) {
			$row_end = $length;
		}

		$first = strpos( $batch, "\x1f", $offset );
		if ( false === $first || $first >= $row_end ) {
			throw new RuntimeException( 'XML tag batch benchmark returned an invalid summary row.' );
		}

		$second = strpos( $batch, "\x1f", $first + 1 );
		if ( false === $second || $second >= $row_end ) {
			throw new RuntimeException( 'XML tag batch benchmark returned an invalid summary row.' );
		}

		$third = strpos( $batch, "\x1f", $second + 1 );
		if ( false === $third || $third >= $row_end ) {
			throw new RuntimeException( 'XML tag batch benchmark returned an invalid summary row.' );
		}

		$fourth = strpos( $batch, "\x1f", $third + 1 );
		if ( false === $fourth || $fourth >= $row_end ) {
			throw new RuntimeException( 'XML tag batch benchmark returned an invalid summary row.' );
		}

		$fifth = strpos( $batch, "\x1f", $fourth + 1 );
		if ( false === $fifth || $fifth >= $row_end ) {
			throw new RuntimeException( 'XML tag batch benchmark returned an invalid summary row.' );
		}

		++$count;
		if ( isset( $batch[ $fifth + 1 ] ) && '1' === $batch[ $fifth + 1 ] ) {
			++$count;
		}

		$offset = $row_end + 1;
	}

	return $count;
}

/**
 * Benchmark the native document-level XML attribute-prefix summary.
 *
 * @return int Number of tags visited plus matching attributes counted.
 */
function wp_toolkit_native_api_benchmark_native_xml_prefix_summary() {
	$xml        = wp_toolkit_native_api_benchmark_xml_attribute_document();
	$class_name = 'WordPress\\XML\\NativeXMLProcessor';
	$processor  = $class_name::create_from_string( $xml );
	$summary    = $processor->summarize_attribute_names_with_prefix( null, 'data-' );

	if ( ! is_string( $summary ) ) {
		throw new RuntimeException( 'Native XML prefix summary benchmark returned an invalid summary.' );
	}

	$parts = explode( "\x1f", $summary, 3 );
	if ( 3 !== count( $parts ) ) {
		throw new RuntimeException( 'Native XML prefix summary benchmark returned an invalid summary row.' );
	}

	if ( null !== $processor->get_last_error() ) {
		throw new RuntimeException( 'NativeXMLProcessor reported benchmark parse error: ' . $processor->get_last_error() );
	}

	return (int) $parts[1] + (int) $parts[2];
}

/**
 * Benchmark native document-level XML prefixed-attribute removal.
 *
 * @return int Number of tags visited plus matching attributes removed.
 */
function wp_toolkit_native_api_benchmark_native_xml_prefix_sanitizer() {
	$xml        = wp_toolkit_native_api_benchmark_xml_attribute_document();
	$class_name = 'WordPress\\XML\\NativeXMLProcessor';
	$processor  = $class_name::create_from_string( $xml );
	$summary    = $processor->remove_attributes_with_prefix_from_document( null, 'data-' );

	if ( ! is_string( $summary ) ) {
		throw new RuntimeException( 'Native XML prefix sanitizer benchmark returned an invalid summary.' );
	}

	$parts = explode( "\x1f", $summary, 3 );
	if ( 3 !== count( $parts ) ) {
		throw new RuntimeException( 'Native XML prefix sanitizer benchmark returned an invalid summary row.' );
	}

	if ( false !== strpos( $parts[2], ' data-' ) ) {
		throw new RuntimeException( 'Native XML prefix sanitizer benchmark left data-* attributes in the output.' );
	}

	if ( null !== $processor->get_last_error() ) {
		throw new RuntimeException( 'NativeXMLProcessor reported benchmark parse error: ' . $processor->get_last_error() );
	}

	return (int) $parts[0] + (int) $parts[1];
}

/**
 * Build a representative HTML document.
 *
 * @return string
 */
function wp_toolkit_native_api_benchmark_html_document() {
	$items = array();
	for ( $i = 0; $i < 120; $i++ ) {
		$items[] = sprintf(
			'<article data-id="%1$d" data-kind="post"><h2>Title %1$d</h2><p class="lede">Body <a href="/p/%1$d">link</a></p><img src="/%1$d.png" alt=""></article>',
			$i
		);
	}

	return '<main><section>' . implode( '', $items ) . '</section></main>';
}

/**
 * Build a representative HTML document with ID attributes and duplicates.
 *
 * @return string
 */
function wp_toolkit_native_api_benchmark_html_id_document() {
	$items = array();
	for ( $i = 0; $i < 120; $i++ ) {
		$items[] = sprintf(
			'<article id="post-%1$d"><h2 id="title-%1$d">Title %1$d</h2><p id="post-%1$d">Body <a id="link-%1$d" href="/p/%1$d">link</a></p><span id></span></article>',
			$i
		);
	}

	return '<main id="content"><section>' . implode( '', $items ) . '</section></main>';
}

/**
 * Build a representative HTML document with link attributes for multi-attribute scans.
 *
 * @return string
 */
function wp_toolkit_native_api_benchmark_html_link_document() {
	$items = array();
	for ( $i = 0; $i < 120; $i++ ) {
		$items[] = sprintf(
			'<article data-id="%1$d"><p><a href="/p/%1$d" title="Post %1$d &amp; archive" rel="bookmark">Post %1$d</a></p><p><a href="/feed/%1$d" rel="alternate">Feed %1$d</a></p></article>',
			$i
		);
	}

	return '<main><section>' . implode( '', $items ) . '</section></main>';
}

/**
 * Build a representative HTML document with forms and named controls.
 *
 * @return string
 */
function wp_toolkit_native_api_benchmark_html_form_document() {
	$items = array();
	for ( $i = 0; $i < 120; $i++ ) {
		$items[] = sprintf(
			'<form action="/search/%1$d"><input name="q%1$d" value="term"><input name="page" value="%1$d"><select name="sort"><option>New</option></select><button name="submit">Go</button><input></form>',
			$i
		);
	}

	return '<main><section>' . implode( '', $items ) . '</section></main>';
}

/**
 * Build a representative HTML document with image attributes.
 *
 * @return string
 */
function wp_toolkit_native_api_benchmark_html_image_document() {
	$items = array();
	for ( $i = 0; $i < 120; $i++ ) {
		$items[] = sprintf(
			'<figure><img src="/media/%1$d.jpg" alt="Image %1$d" width="800" height="600"><img src="/thumb/%1$d.jpg" alt=""><figcaption>Image %1$d</figcaption></figure>',
			$i
		);
	}

	return '<main><section>' . implode( '', $items ) . '</section></main>';
}

/**
 * Build a representative HTML document with script attributes and inline scripts.
 *
 * @return string
 */
function wp_toolkit_native_api_benchmark_html_script_document() {
	$items = array();
	for ( $i = 0; $i < 120; $i++ ) {
		$items[] = sprintf(
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Benchmark fixture markup.
			'<article><script src="/assets/app-%1$d.js" type="module" async></script><script>window.wpData%1$d = {"id":%1$d};</script><script defer src="/assets/legacy-%1$d.js"></script></article>',
			$i
		);
	}

	return '<main><section>' . implode( '', $items ) . '</section></main>';
}

/**
 * Build representative plain text containing URL references.
 *
 * @return string
 */
function wp_toolkit_native_api_benchmark_url_in_text_document() {
	$items = array();
	for ( $i = 0; $i < 120; $i++ ) {
		$items[] = sprintf(
			'Post %1$d: https://example.com/posts/%1$d?preview=1 references example.org/docs/%1$d and //cdn.example.org/assets/app-%1$d.js.',
			$i
		);
	}

	return implode( ' ', $items );
}

/**
 * Build a representative XML document.
 *
 * @return string
 */
function wp_toolkit_native_api_benchmark_xml_document() {
	$items = array();
	for ( $i = 0; $i < 120; $i++ ) {
		$items[] = sprintf(
			'<wp:item xmlns:wp="https://wordpress.org" id="%1$d"><wp:title>Title %1$d</wp:title><wp:meta key="slug">post-%1$d</wp:meta></wp:item>',
			$i
		);
	}

	return '<?xml version="1.0" encoding="UTF-8"?><wp:root xmlns:wp="https://wordpress.org">' . implode( '', $items ) . '</wp:root>';
}

/**
 * Build a representative XML document for structure inventory scans.
 *
 * @return string
 */
function wp_toolkit_native_api_benchmark_xml_inventory_document() {
	$items = array();
	for ( $i = 0; $i < 120; $i++ ) {
		$items[] = sprintf(
			'<wp:item xmlns:wp="https://wordpress.org" id="%1$d"><wp:title>Title %1$d</wp:title><!-- item %1$d --><wp:meta key="slug"><![CDATA[post-%1$d]]></wp:meta><wp:empty /></wp:item>',
			$i
		);
	}

	return '<?xml version="1.0" encoding="UTF-8"?><wp:root xmlns:wp="https://wordpress.org">' . implode( '', $items ) . '</wp:root>';
}

/**
 * Build a representative XML document with repeated element names.
 *
 * @return string
 */
function wp_toolkit_native_api_benchmark_xml_element_document() {
	$items = array();
	for ( $i = 0; $i < 120; $i++ ) {
		$items[] = sprintf(
			'<wp:item id="%1$d"><wp:title>Title %1$d</wp:title><media:asset><media:file /><media:size /></media:asset><plain /></wp:item>',
			$i
		);
	}

	return '<?xml version="1.0" encoding="UTF-8"?><wp:root xmlns:wp="https://wordpress.org" xmlns:media="https://example.com/media">' . implode( '', $items ) . '</wp:root>';
}

/**
 * Build a representative XML document with nested importer elements.
 *
 * @return string
 */
function wp_toolkit_native_api_benchmark_xml_depth_document() {
	$items = array();
	for ( $i = 0; $i < 120; $i++ ) {
		$items[] = sprintf(
			'<section id="%1$d"><item><title>Title %1$d</title><meta><key name="slug" /><value>post-%1$d</value></meta></item><attachment><file /><sizes><size /></sizes></attachment></section>',
			$i
		);
	}

	return '<?xml version="1.0" encoding="UTF-8"?><root><batch>' . implode( '', $items ) . '</batch><trailer /></root>';
}

/**
 * Build a representative XML document with repeated matching tag attribute audits.
 *
 * @return string
 */
function wp_toolkit_native_api_benchmark_xml_matching_attribute_document() {
	$items = array();
	for ( $i = 0; $i < 120; $i++ ) {
		$items[] = sprintf(
			'<wp:item xmlns:wp="https://wordpress.org" id="%1$d" slug="post-%1$d" status="publish"><wp:title>Title %1$d</wp:title><wp:meta key="slug">post-%1$d</wp:meta></wp:item>',
			$i
		);
	}

	return '<?xml version="1.0" encoding="UTF-8"?><wp:root xmlns:wp="https://wordpress.org">' . implode( '', $items ) . '</wp:root>';
}

/**
 * Build a representative XML document with repeated prefixed attribute scans.
 *
 * @return string
 */
function wp_toolkit_native_api_benchmark_xml_attribute_document() {
	$items = array();
	for ( $i = 0; $i < 120; $i++ ) {
		$items[] = sprintf(
			'<wp:item xmlns:wp="https://wordpress.org" id="%1$d" wp:kind="post" data-id="%1$d" data-kind="post"><wp:title data-rank="%1$d">Title %1$d</wp:title><wp:meta key="slug" data-slug="post-%1$d">post-%1$d</wp:meta></wp:item>',
			$i
		);
	}

	return '<?xml version="1.0" encoding="UTF-8"?><wp:root xmlns:wp="https://wordpress.org">' . implode( '', $items ) . '</wp:root>';
}

/**
 * Build a representative XML document with ID-heavy importer data.
 *
 * @return string
 */
function wp_toolkit_native_api_benchmark_xml_id_document() {
	$items = array();
	for ( $i = 0; $i < 120; $i++ ) {
		$duplicate_id = $i % 20;
		$items[]      = sprintf(
			'<wp:item xmlns:wp="https://wordpress.org" id="post-%1$d" wp:id="ignored-%1$d"><wp:title id="title-%1$d">Title %1$d</wp:title><wp:meta id="post-%2$d">post-%1$d</wp:meta><wp:empty id="empty-%1$d" /></wp:item>',
			$i,
			$duplicate_id
		);
	}

	return '<?xml version="1.0" encoding="UTF-8"?><wp:root xmlns:wp="https://wordpress.org" id="root">' . implode( '', $items ) . '</wp:root>';
}

/**
 * Build a representative XML document with namespace-heavy importer data.
 *
 * @return string
 */
function wp_toolkit_native_api_benchmark_xml_namespace_document() {
	$items = array();
	for ( $i = 0; $i < 120; $i++ ) {
		$items[] = sprintf(
			'<wp:item id="%1$d" wp:kind="post" media:type="image"><media:title data-rank="%1$d">Title %1$d</media:title><dc:creator dc:id="%1$d">author-%1$d</dc:creator><empty data-id="%1$d" /></wp:item>',
			$i
		);
	}

	return '<?xml version="1.0" encoding="UTF-8"?><wp:root xmlns:wp="https://wordpress.org" xmlns:media="https://example.com/media" xmlns:dc="http://purl.org/dc/elements/1.1/">' . implode( '', $items ) . '</wp:root>';
}

/**
 * Build a representative XML document with text-heavy importer data.
 *
 * @return string
 */
function wp_toolkit_native_api_benchmark_xml_text_document() {
	$items = array();
	for ( $i = 0; $i < 120; $i++ ) {
		$items[] = sprintf(
			'<item id="%1$d">Title %1$d<summary>Excerpt %1$d with &amp; entity text</summary><content><![CDATA[Long body %1$d with <markup> inside]]></content><blank>   </blank></item>',
			$i
		);
	}

	return '<?xml version="1.0" encoding="UTF-8"?><root>' . implode( '', $items ) . '</root>';
}

/**
 * Build a representative XML document with importer processing instructions.
 *
 * @return string
 */
function wp_toolkit_native_api_benchmark_xml_processing_instruction_document() {
	$items = array();
	for ( $i = 0; $i < 120; $i++ ) {
		$items[] = sprintf(
			'<?xml-import source="feed-%1$d" status="queued"?><item id="%1$d">Title %1$d</item><?xml-audit checksum="hash-%1$d"?>',
			$i
		);
	}

	return '<?xml version="1.0" encoding="UTF-8"?><root>' . implode( '', $items ) . '</root><?xml-trailer processed="120"?>';
}

/**
 * Build a representative XML document with importer comments.
 *
 * @return string
 */
function wp_toolkit_native_api_benchmark_xml_comment_document() {
	$items = array();
	for ( $i = 0; $i < 120; $i++ ) {
		$items[] = sprintf(
			'<!-- import item %1$d source feed with checksum hash-%1$d --><item id="%1$d">Title %1$d<!-- --><meta><!-- category post-%1$d --></meta></item>',
			$i
		);
	}

	return '<?xml version="1.0" encoding="UTF-8"?><root><!-- batch import comments -->' . implode( '', $items ) . '<!--   --></root><!-- trailer comment -->';
}

/**
 * Build a representative XML document with mixed payload-bearing tokens.
 *
 * @return string
 */
function wp_toolkit_native_api_benchmark_xml_payload_document() {
	$items = array();
	for ( $i = 0; $i < 120; $i++ ) {
		$items[] = sprintf(
			'<?xml-import source="feed-%1$d"?><!-- import payload %1$d with checksum hash-%1$d --><item id="%1$d">Title %1$d<summary>Excerpt %1$d with &amp; entity text</summary><content><![CDATA[Long body %1$d with <markup> inside]]></content><!-- --></item>',
			$i
		);
	}

	return '<?xml version="1.0" encoding="UTF-8"?><root><!-- mixed payload audit -->' . implode( '', $items ) . '</root><?xml-trailer processed="120"?>';
}
