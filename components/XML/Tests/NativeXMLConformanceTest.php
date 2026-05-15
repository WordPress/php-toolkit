<?php
/**
 * Shared conformance tests for PHP and native XML processors.
 *
 * @package WordPress
 * @subpackage XML-API
 */

use PHPUnit\Framework\TestCase;
use WordPress\XML\XMLProcessor;

/**
 * @group xml-api
 * @group native-api
 */
class NativeXMLConformanceTest extends TestCase {
	/**
	 * Verifies public XML classes can opt in to native delegates when available.
	 */
	public function test_public_xml_class_uses_native_processor_when_enabled() {
		if ( ! class_exists( 'WordPress\\XML\\NativeXMLProcessor', false ) ) {
			$this->markTestSkipped( 'WordPress\\XML\\NativeXMLProcessor is not registered; load the native API extension to run this case.' );
		}

		$previous_enable_xml_defaults = getenv( 'WP_NATIVE_APIS_ENABLE_XML_DEFAULTS' );
		putenv( 'WP_NATIVE_APIS_ENABLE_XML_DEFAULTS=1' );

		try {
			$processor = XMLProcessor::create_from_string( '<root><item id="1" /></root>' );
			$this->assertSame( 'WordPress\\XML\\NativeXMLProcessor', get_class( $this->get_native_delegate( $processor ) ) );
			$this->assertTrue( $processor->next_tag( 'item' ) );
			$this->assertSame( 'item', $processor->get_token_name() );
			$this->assertSame( '1', $processor->get_attribute( '', 'id' ) );
			$this->assertTrue( $processor->is_empty_element() );
			$this->assertFalse( $processor->expects_closer() );
			$this->assertFalse( $processor->is_tag_opener() );
			$this->assertSame(
				array( array( '', 'root' ), array( '', 'item' ) ),
				$processor->get_breadcrumbs()
			);
		} finally {
			if ( false === $previous_enable_xml_defaults ) {
				putenv( 'WP_NATIVE_APIS_ENABLE_XML_DEFAULTS' );
			} else {
				putenv( 'WP_NATIVE_APIS_ENABLE_XML_DEFAULTS=' . $previous_enable_xml_defaults );
			}
		}
	}

	/**
	 * Verifies public read-only native XML scans expose the final complete token.
	 */
	public function test_public_xml_class_exposes_complete_token_with_native_defaults() {
		if ( ! class_exists( 'WordPress\\XML\\NativeXMLProcessor', false ) ) {
			$this->markTestSkipped( 'WordPress\\XML\\NativeXMLProcessor is not registered; load the native API extension to run this case.' );
		}

		$previous_enable_xml_defaults = getenv( 'WP_NATIVE_APIS_ENABLE_XML_DEFAULTS' );
		putenv( 'WP_NATIVE_APIS_ENABLE_XML_DEFAULTS=1' );

		try {
			$processor = XMLProcessor::create_from_string( '<root><item></item></root>' );
			$this->assertSame( 'WordPress\\XML\\NativeXMLProcessor', get_class( $this->get_native_delegate( $processor ) ) );

			$token_types = array();
			while ( $processor->next_token() ) {
				$token_types[] = $processor->get_token_type();
			}

			$this->assertSame( array( '#tag', '#tag', '#tag', '#tag', '#complete' ), $token_types );
			$this->assertTrue( $processor->is_finished() );
			$this->assertSame( '#complete', $processor->get_token_name() );
			$this->assertNull( $processor->get_last_error() );
		} finally {
			if ( false === $previous_enable_xml_defaults ) {
				putenv( 'WP_NATIVE_APIS_ENABLE_XML_DEFAULTS' );
			} else {
				putenv( 'WP_NATIVE_APIS_ENABLE_XML_DEFAULTS=' . $previous_enable_xml_defaults );
			}
		}
	}

	/**
	 * Verifies public native-backed XML processors expose native parse exceptions.
	 */
	public function test_public_xml_class_exposes_native_parse_exceptions_when_enabled() {
		if ( ! class_exists( 'WordPress\\XML\\NativeXMLProcessor', false ) ) {
			$this->markTestSkipped( 'WordPress\\XML\\NativeXMLProcessor is not registered; load the native API extension to run this case.' );
		}

		$previous_enable_xml_defaults = getenv( 'WP_NATIVE_APIS_ENABLE_XML_DEFAULTS' );
		putenv( 'WP_NATIVE_APIS_ENABLE_XML_DEFAULTS=1' );

		try {
			$processor = XMLProcessor::create_from_string( '<root attr></root>' );
			while ( $processor->next_token() ) {
				continue;
			}

			$this->assertSame( XMLProcessor::ERROR_SYNTAX, $processor->get_last_error() );
			$this->assertInstanceOf( 'WordPress\\XML\\XMLUnsupportedException', $processor->get_exception() );
			$this->assertStringContainsString( 'Unquoted attribute value encountered.', $processor->get_exception()->getMessage() );

			$processor = XMLProcessor::create_from_string( '<root xmlns:a=""></root>' );
			$this->assertFalse( $processor->next_token(), 'Expected invalid namespace declarations to stop before exposing a token.' );
			$this->assertSame( XMLProcessor::ERROR_SYNTAX, $processor->get_last_error() );
			$this->assertInstanceOf( 'WordPress\\XML\\XMLUnsupportedException', $processor->get_exception() );
			$this->assertSame( 'Attribute "xmlns:a" has an invalid namespace prefix "xmlns".', $processor->get_exception()->getMessage() );
		} finally {
			if ( false === $previous_enable_xml_defaults ) {
				putenv( 'WP_NATIVE_APIS_ENABLE_XML_DEFAULTS' );
			} else {
				putenv( 'WP_NATIVE_APIS_ENABLE_XML_DEFAULTS=' . $previous_enable_xml_defaults );
			}
		}
	}

	/**
	 * Verifies XML native defaults can be disabled by environment.
	 */
	public function test_public_xml_class_can_disable_native_defaults_by_environment() {
		if ( ! class_exists( 'WordPress\\XML\\NativeXMLProcessor', false ) ) {
			$this->markTestSkipped( 'WordPress\\XML\\NativeXMLProcessor is not registered; load the native API extension to run this case.' );
		}

		$previous_enable_xml_defaults = getenv( 'WP_NATIVE_APIS_ENABLE_XML_DEFAULTS' );
		$disabled_values              = array( '0', 'false', 'FALSE', 'no', 'No', 'off', 'OFF' );

		try {
			foreach ( $disabled_values as $disabled_value ) {
				putenv( 'WP_NATIVE_APIS_ENABLE_XML_DEFAULTS=' . $disabled_value );

				$processor = XMLProcessor::create_from_string( '<root><item id="1" /></root>' );
				$this->assertNull( $this->get_native_delegate( $processor ), "Expected disabled value {$disabled_value} to force PHP-backed XML processing." );
				$this->assertTrue( $processor->next_tag( 'item' ) );
				$this->assertSame( 'item', $processor->get_token_name() );
				$this->assertSame( '1', $processor->get_attribute( '', 'id' ) );
			}
		} finally {
			if ( false === $previous_enable_xml_defaults ) {
				putenv( 'WP_NATIVE_APIS_ENABLE_XML_DEFAULTS' );
			} else {
				putenv( 'WP_NATIVE_APIS_ENABLE_XML_DEFAULTS=' . $previous_enable_xml_defaults );
			}
		}
	}

	/**
	 * Verifies XML native defaults can be explicitly enabled by environment.
	 */
	public function test_public_xml_class_can_enable_native_defaults_by_environment() {
		if ( ! class_exists( 'WordPress\\XML\\NativeXMLProcessor', false ) ) {
			$this->markTestSkipped( 'WordPress\\XML\\NativeXMLProcessor is not registered; load the native API extension to run this case.' );
		}

		if ( defined( 'WP_NATIVE_APIS_DISABLE_DEFAULTS' ) && WP_NATIVE_APIS_DISABLE_DEFAULTS ) {
			$this->markTestSkipped( 'WP_NATIVE_APIS_DISABLE_DEFAULTS is defined; XML native defaults cannot be enabled in this process.' );
		}

		$previous_enable_xml_defaults = getenv( 'WP_NATIVE_APIS_ENABLE_XML_DEFAULTS' );
		$enabled_values               = array( '1', 'true', 'TRUE', 'yes', 'Yes', 'on', 'ON' );

		try {
			foreach ( $enabled_values as $enabled_value ) {
				putenv( 'WP_NATIVE_APIS_ENABLE_XML_DEFAULTS=' . $enabled_value );

				$processor = XMLProcessor::create_from_string( '<root><item id="1" /></root>' );
				$this->assertSame( 'WordPress\\XML\\NativeXMLProcessor', get_class( $this->get_native_delegate( $processor ) ) );
				$this->assertTrue( $processor->next_tag( 'item' ) );
				$this->assertSame( 'item', $processor->get_token_name() );
				$this->assertSame( '1', $processor->get_attribute( '', 'id' ) );
			}
		} finally {
			if ( false === $previous_enable_xml_defaults ) {
				putenv( 'WP_NATIVE_APIS_ENABLE_XML_DEFAULTS' );
			} else {
				putenv( 'WP_NATIVE_APIS_ENABLE_XML_DEFAULTS=' . $previous_enable_xml_defaults );
			}
		}
	}

	/**
	 * Verifies XML native defaults can be disabled by constant.
	 */
	public function test_public_xml_class_can_disable_native_defaults_by_constant() {
		if ( ! class_exists( 'WordPress\\XML\\NativeXMLProcessor', false ) ) {
			$this->markTestSkipped( 'WordPress\\XML\\NativeXMLProcessor is not registered; load the native API extension to run this case.' );
		}

		$this->assertSame(
			array(
				'delegate:php',
				'token:item',
				'id:1',
			),
			$this->run_xml_native_defaults_constant_probe( "define( 'WP_NATIVE_APIS_ENABLE_XML_DEFAULTS', false );" )
		);
	}

	/**
	 * Verifies XML native defaults can be explicitly enabled by constant.
	 */
	public function test_public_xml_class_can_enable_native_defaults_by_constant() {
		if ( ! class_exists( 'WordPress\\XML\\NativeXMLProcessor', false ) ) {
			$this->markTestSkipped( 'WordPress\\XML\\NativeXMLProcessor is not registered; load the native API extension to run this case.' );
		}

		$this->assertSame(
			array(
				'delegate:WordPress\\XML\\NativeXMLProcessor',
				'token:item',
				'id:1',
			),
			$this->run_xml_native_defaults_constant_probe( "define( 'WP_NATIVE_APIS_ENABLE_XML_DEFAULTS', true );" )
		);
	}

	/**
	 * Verifies the global native-default kill switch takes precedence for XML.
	 */
	public function test_global_native_defaults_disable_overrides_xml_enable_constant() {
		if ( ! class_exists( 'WordPress\\XML\\NativeXMLProcessor', false ) ) {
			$this->markTestSkipped( 'WordPress\\XML\\NativeXMLProcessor is not registered; load the native API extension to run this case.' );
		}

		$this->assertSame(
			array(
				'delegate:php',
				'token:item',
				'id:1',
			),
			$this->run_xml_native_defaults_constant_probe(
				"define( 'WP_NATIVE_APIS_DISABLE_DEFAULTS', true );\n" .
				"define( 'WP_NATIVE_APIS_ENABLE_XML_DEFAULTS', true );"
			)
		);
	}

	/**
	 * Verifies public native-backed XML processors preserve tag-token modifiable text after next_tag().
	 */
	public function test_public_xml_tag_modifiable_text_after_next_tag_with_native_defaults() {
		if ( ! class_exists( 'WordPress\\XML\\NativeXMLProcessor', false ) ) {
			$this->markTestSkipped( 'WordPress\\XML\\NativeXMLProcessor is not registered; load the native API extension to run this case.' );
		}

		$previous_enable_xml_defaults = getenv( 'WP_NATIVE_APIS_ENABLE_XML_DEFAULTS' );
		putenv( 'WP_NATIVE_APIS_ENABLE_XML_DEFAULTS=1' );

		try {
			$processor = XMLProcessor::create_from_string( '<root><!--c--><![CDATA[data]]><item id="1">Text &amp; More</item></root>' );
			$this->assertSame( 'WordPress\\XML\\NativeXMLProcessor', get_class( $this->get_native_delegate( $processor ) ) );
			$this->assertTrue( $processor->next_tag( '', 'item' ) );
			$this->assertSame( 'Text & More</', $processor->get_modifiable_text() );
			$this->assertNull( $this->get_native_delegate( $processor ) );
		} finally {
			if ( false === $previous_enable_xml_defaults ) {
				putenv( 'WP_NATIVE_APIS_ENABLE_XML_DEFAULTS' );
			} else {
				putenv( 'WP_NATIVE_APIS_ENABLE_XML_DEFAULTS=' . $previous_enable_xml_defaults );
			}
		}
	}

	/**
	 * Verifies public native-backed XML processors preserve bookmark lifecycle behavior.
	 */
	public function test_public_xml_bookmarks_with_native_defaults() {
		if ( ! class_exists( 'WordPress\\XML\\NativeXMLProcessor', false ) ) {
			$this->markTestSkipped( 'WordPress\\XML\\NativeXMLProcessor is not registered; load the native API extension to run this case.' );
		}

		$previous_enable_xml_defaults = getenv( 'WP_NATIVE_APIS_ENABLE_XML_DEFAULTS' );
		putenv( 'WP_NATIVE_APIS_ENABLE_XML_DEFAULTS=1' );

		try {
			$processor = XMLProcessor::create_from_string( '<root><item id="one" /><item id="two" /></root>' );
			$this->assertSame( 'WordPress\\XML\\NativeXMLProcessor', get_class( $this->get_native_delegate( $processor ) ) );

			$this->assertTrue( $processor->next_tag( 'item' ) );
			$this->assertSame( 'item', $processor->get_token_name() );
			$this->assertSame( 'one', $processor->get_attribute( '', 'id' ) );
			$this->assertTrue( $processor->set_bookmark( 'saved-item' ) );
			$this->assertTrue( $processor->has_bookmark( 'saved-item' ) );

			$this->assertTrue( $processor->next_tag( 'item' ) );
			$this->assertSame( 'item', $processor->get_token_name() );
			$this->assertSame( 'two', $processor->get_attribute( '', 'id' ) );

			$this->assertTrue( $processor->seek( 'saved-item' ) );
			$this->assertSame( 'item', $processor->get_token_name() );
			$this->assertSame( 'one', $processor->get_attribute( '', 'id' ) );
			$this->assertSame(
				array( array( '', 'root' ), array( '', 'item' ) ),
				$processor->get_breadcrumbs()
			);

			$this->assertTrue( $processor->release_bookmark( 'saved-item' ) );
			$this->assertFalse( $processor->has_bookmark( 'saved-item' ) );
		} finally {
			if ( false === $previous_enable_xml_defaults ) {
				putenv( 'WP_NATIVE_APIS_ENABLE_XML_DEFAULTS' );
			} else {
				putenv( 'WP_NATIVE_APIS_ENABLE_XML_DEFAULTS=' . $previous_enable_xml_defaults );
			}
		}
	}

	/**
	 * Verifies native-backed XML bookmark seeks preserve PHP mutation handoff state.
	 */
	public function test_public_xml_bookmark_seek_then_mutation_with_native_defaults() {
		if ( ! class_exists( 'WordPress\\XML\\NativeXMLProcessor', false ) ) {
			$this->markTestSkipped( 'WordPress\\XML\\NativeXMLProcessor is not registered; load the native API extension to run this case.' );
		}

		$previous_enable_xml_defaults = getenv( 'WP_NATIVE_APIS_ENABLE_XML_DEFAULTS' );
		putenv( 'WP_NATIVE_APIS_ENABLE_XML_DEFAULTS=1' );

		try {
			$processor = XMLProcessor::create_from_string( '<root xmlns:x="urn:x"><item id="1" data-one="a">One</item><x:item x:id="2">Two</x:item></root>' );
			$this->assertSame( 'WordPress\\XML\\NativeXMLProcessor', get_class( $this->get_native_delegate( $processor ) ) );

			$this->assertTrue( $processor->next_tag( '', 'item' ) );
			$this->assertTrue( $processor->set_bookmark( 'first-item' ) );
			$this->assertTrue( $processor->next_tag( 'urn:x', 'item' ) );
			$this->assertTrue( $processor->seek( 'first-item' ) );
			$this->assertSame( '1', $processor->get_attribute( '', 'id' ) );
			$this->assertNull( $processor->get_attribute( 'urn:x', 'id' ) );

			$this->assertTrue( $processor->set_attribute( '', 'data-two', 'b' ) );
			$this->assertTrue( $processor->remove_attribute( '', 'data-one' ) );
			$this->assertSame(
				'<root xmlns:x="urn:x"><item data-two="b" id="1" >One</item><x:item x:id="2">Two</x:item></root>',
				$processor->get_updated_xml()
			);

			$processor = XMLProcessor::create_from_string( '<root><item>One</item><item>Two</item></root>' );
			$this->assertSame( 'WordPress\\XML\\NativeXMLProcessor', get_class( $this->get_native_delegate( $processor ) ) );
			$this->assertTrue( $processor->next_tag( 'item' ) );
			$this->assertTrue( $processor->next_token() );
			$this->assertSame( 'One', $processor->get_modifiable_text() );
			$this->assertTrue( $processor->set_bookmark( 'first-text' ) );
			$this->assertTrue( $processor->next_tag( 'item' ) );
			$this->assertTrue( $processor->seek( 'first-text' ) );
			$this->assertTrue( $processor->set_modifiable_text( 'Changed & <ok>' ) );
			$this->assertSame(
				'<root><item>Changed &amp; &lt;ok&gt;</item><item>Two</item></root>',
				$processor->get_updated_xml()
			);

			$processor = XMLProcessor::create_from_string( '<root><item id="1">One</item><item id="2">Two</item></root>' );
			$this->assertSame( 'WordPress\\XML\\NativeXMLProcessor', get_class( $this->get_native_delegate( $processor ) ) );
			$this->assertTrue( $processor->next_tag( 'root' ) );
			$this->assertTrue( $processor->set_bookmark( 'root' ) );
			$this->assertTrue( $processor->next_tag( array( 'breadcrumbs' => array( 'root', 'item' ), 'match_offset' => 2 ) ) );
			$this->assertSame( '2', $processor->get_attribute( '', 'id' ) );
			$this->assertTrue( $processor->set_bookmark( 'second-item' ) );
			$this->assertTrue( $processor->seek( 'root' ) );
			$this->assertTrue( $processor->set_attribute( '', 'class', 'top' ) );
			$this->assertNull( $this->get_native_delegate( $processor ) );
			$this->assertTrue( $processor->seek( 'second-item' ) );
			$this->assertSame( 'item', $processor->get_token_name() );
			$this->assertSame( '2', $processor->get_attribute( '', 'id' ) );
			$this->assertSame(
				'<root class="top"><item id="1">One</item><item id="2">Two</item></root>',
				$processor->get_updated_xml()
			);
		} finally {
			if ( false === $previous_enable_xml_defaults ) {
				putenv( 'WP_NATIVE_APIS_ENABLE_XML_DEFAULTS' );
			} else {
				putenv( 'WP_NATIVE_APIS_ENABLE_XML_DEFAULTS=' . $previous_enable_xml_defaults );
			}
		}
	}

	/**
	 * Verifies public XML inventory summaries complete native-default processors.
	 */
	public function test_public_xml_metadata_inventory_summaries_complete_native_default_processors() {
		if ( ! class_exists( 'WordPress\\XML\\NativeXMLProcessor', false ) ) {
			$this->markTestSkipped( 'WordPress\\XML\\NativeXMLProcessor is not registered; load the native API extension to run this case.' );
		}

		$methods = array(
			'summarize_attribute_inventory',
			'summarize_id_inventory',
			'summarize_namespace_inventory',
			'summarize_text_inventory',
			'summarize_processing_instruction_inventory',
			'summarize_comment_inventory',
			'summarize_payload_inventory',
		);
		$xml     = '<?xml version="1.0"?><wp:root xmlns:wp="https://wordpress.org" id="r"><wp:item wp:slug="first">Text<!-- note --><![CDATA[x]]><?xml audit data?></wp:item></wp:root>';

		foreach ( $methods as $method ) {
			$processor = XMLProcessor::create_from_string( $xml );
			$this->assertSame( 'WordPress\\XML\\NativeXMLProcessor', get_class( $this->get_native_delegate( $processor ) ) );

			$processor->$method();
			$this->assertTrue( $processor->is_finished(), $method . ' should finish a fresh native-default processor.' );

			$processor = XMLProcessor::create_from_string( $xml );
			$this->assertSame( 'WordPress\\XML\\NativeXMLProcessor', get_class( $this->get_native_delegate( $processor ) ) );
			$this->assertTrue( $processor->next_tag() );

			$processor->$method();
			$this->assertTrue( $processor->is_finished(), $method . ' should finish a partially advanced native-default processor.' );
		}
	}

	/**
	 * Verifies public XML aggregate summaries complete native-default processors.
	 */
	public function test_public_xml_aggregate_summaries_complete_native_default_processors() {
		if ( ! class_exists( 'WordPress\\XML\\NativeXMLProcessor', false ) ) {
			$this->markTestSkipped( 'WordPress\\XML\\NativeXMLProcessor is not registered; load the native API extension to run this case.' );
		}

		$cases = array(
			array( 'summarize_attribute_names_with_prefix', array( null, 'data-' ) ),
			array( 'summarize_token_stream', array( 'id' ) ),
			array( 'summarize_matching_tag_stream', array( 'https://wordpress.org', 'item', 'id' ) ),
			array( 'summarize_matching_tag_attributes_stream', array( 'https://wordpress.org', 'item', array( 'id', 'data-kind' ) ) ),
			array( 'summarize_tag_stream', array( 'id' ) ),
		);
		$xml   = '<?xml version="1.0"?><wp:root xmlns:wp="https://wordpress.org" id="r"><wp:item id="1" data-kind="post">Text</wp:item><item data-kind="plain" /></wp:root>';

		foreach ( $cases as $case ) {
			$processor = XMLProcessor::create_from_string( $xml );
			$this->assertSame( 'WordPress\\XML\\NativeXMLProcessor', get_class( $this->get_native_delegate( $processor ) ) );

			call_user_func_array( array( $processor, $case[0] ), $case[1] );
			$this->assertTrue( $processor->is_finished(), $case[0] . ' should finish a fresh native-default processor.' );

			$processor = XMLProcessor::create_from_string( $xml );
			$this->assertSame( 'WordPress\\XML\\NativeXMLProcessor', get_class( $this->get_native_delegate( $processor ) ) );
			$this->assertTrue( $processor->next_tag() );

			call_user_func_array( array( $processor, $case[0] ), $case[1] );
			$this->assertTrue( $processor->is_finished(), $case[0] . ' should finish a partially advanced native-default processor.' );
		}

		$processor = XMLProcessor::create_from_string( $xml );
		$this->assertSame( 'WordPress\\XML\\NativeXMLProcessor', get_class( $this->get_native_delegate( $processor ) ) );

		$summary = $processor->remove_attributes_with_prefix_from_document( null, 'data-' );
		$this->assertSame( 2, $summary['removed_count'] );
		$this->assertTrue( $processor->is_finished(), 'remove_attributes_with_prefix_from_document should finish a fresh native-default processor.' );

		$processor = XMLProcessor::create_from_string( $xml );
		$this->assertSame( 'WordPress\\XML\\NativeXMLProcessor', get_class( $this->get_native_delegate( $processor ) ) );
		$this->assertTrue( $processor->next_tag() );

		$summary = $processor->remove_attributes_with_prefix_from_document( null, 'data-' );
		$this->assertSame( 2, $summary['removed_count'] );
		$this->assertTrue( $processor->is_finished(), 'remove_attributes_with_prefix_from_document should finish a partially advanced native-default processor.' );
	}

	/**
	 * Verifies public XML batch scans complete native-default processors.
	 */
	public function test_public_xml_batches_complete_native_default_processors() {
		if ( ! class_exists( 'WordPress\\XML\\NativeXMLProcessor', false ) ) {
			$this->markTestSkipped( 'WordPress\\XML\\NativeXMLProcessor is not registered; load the native API extension to run this case.' );
		}

		$xml = '<?xml version="1.0"?><root id="root"><item id="1">Text</item><empty id="2" /></root>';

		$processor = XMLProcessor::create_from_string( $xml );
		$this->assertSame( 'WordPress\\XML\\NativeXMLProcessor', get_class( $this->get_native_delegate( $processor ) ) );
		do {
			$batch = $processor->next_token_summary_batch( 2 );
		} while ( ! empty( $batch ) );
		$this->assertTrue( $processor->is_finished(), 'next_token_summary_batch should finish after exhaustion.' );

		$processor = XMLProcessor::create_from_string( $xml );
		$this->assertSame( 'WordPress\\XML\\NativeXMLProcessor', get_class( $this->get_native_delegate( $processor ) ) );
		do {
			$batch = $processor->next_tag_summary_batch( 2, 'id' );
		} while ( ! empty( $batch ) );
		$this->assertTrue( $processor->is_finished(), 'next_tag_summary_batch should finish after exhaustion.' );

		$processor = XMLProcessor::create_from_string( $xml );
		$this->assertSame( 'WordPress\\XML\\NativeXMLProcessor', get_class( $this->get_native_delegate( $processor ) ) );
		do {
			$summary = $processor->next_tag_count_batch( 2, 'id' );
		} while ( $summary['token_count'] > 0 );
		$this->assertTrue( $processor->is_finished(), 'next_tag_count_batch should finish after exhaustion.' );

		$processor = XMLProcessor::create_from_string( $xml );
		$this->assertSame( 'WordPress\\XML\\NativeXMLProcessor', get_class( $this->get_native_delegate( $processor ) ) );
		do {
			$batch = $processor->next_matching_tag_summary_batch( 1, '', 'item', 'id' );
		} while ( ! empty( $batch ) );
		$this->assertTrue( $processor->is_finished(), 'next_matching_tag_summary_batch should finish after exhaustion.' );

		$processor = XMLProcessor::create_from_string( $xml );
		$this->assertSame( 'WordPress\\XML\\NativeXMLProcessor', get_class( $this->get_native_delegate( $processor ) ) );
		do {
			$summary = $processor->next_matching_tag_count_batch( 1, '', 'item', 'id' );
		} while ( $summary['token_count'] > 0 );
		$this->assertTrue( $processor->is_finished(), 'next_matching_tag_count_batch should finish after exhaustion.' );
	}

	/**
	 * Verifies public XML compact batch scans complete native-default processors.
	 */
	public function test_public_xml_compact_batches_complete_native_default_processors() {
		if ( ! class_exists( 'WordPress\\XML\\NativeXMLProcessor', false ) ) {
			$this->markTestSkipped( 'WordPress\\XML\\NativeXMLProcessor is not registered; load the native API extension to run this case.' );
		}

		$xml = '<?xml version="1.0"?><root id="root"><item id="1">Text</item><empty id="2" /></root>';

		$processor = XMLProcessor::create_from_string( $xml );
		$this->assertSame( 'WordPress\\XML\\NativeXMLProcessor', get_class( $this->get_native_delegate( $processor ) ) );
		do {
			$batch = $processor->next_token_compact_summary_batch( 2 );
		} while ( is_string( $batch ) && '' !== $batch );
		$this->assertTrue( $processor->is_finished(), 'next_token_compact_summary_batch should finish after exhaustion.' );

		$processor = XMLProcessor::create_from_string( $xml );
		$this->assertSame( 'WordPress\\XML\\NativeXMLProcessor', get_class( $this->get_native_delegate( $processor ) ) );
		do {
			$batch = $processor->next_tag_compact_summary_batch( 2, 'id' );
		} while ( is_string( $batch ) && '' !== $batch );
		$this->assertTrue( $processor->is_finished(), 'next_tag_compact_summary_batch should finish after exhaustion.' );

		$processor = XMLProcessor::create_from_string( $xml );
		$this->assertSame( 'WordPress\\XML\\NativeXMLProcessor', get_class( $this->get_native_delegate( $processor ) ) );
		do {
			$summary = $processor->next_tag_count_compact_batch( 2, 'id' );
			$parts   = explode( "\x1f", is_string( $summary ) ? $summary : '' );
		} while ( isset( $parts[0] ) && (int) $parts[0] > 0 );
		$this->assertTrue( $processor->is_finished(), 'next_tag_count_compact_batch should finish after exhaustion.' );

		$processor = XMLProcessor::create_from_string( $xml );
		$this->assertSame( 'WordPress\\XML\\NativeXMLProcessor', get_class( $this->get_native_delegate( $processor ) ) );
		do {
			$batch = $processor->next_matching_tag_compact_summary_batch( 1, '', 'item', 'id' );
		} while ( is_string( $batch ) && '' !== $batch );
		$this->assertTrue( $processor->is_finished(), 'next_matching_tag_compact_summary_batch should finish after exhaustion.' );

		$processor = XMLProcessor::create_from_string( $xml );
		$this->assertSame( 'WordPress\\XML\\NativeXMLProcessor', get_class( $this->get_native_delegate( $processor ) ) );
		do {
			$summary = $processor->next_matching_tag_count_compact_batch( 1, '', 'item', 'id' );
			$parts   = explode( "\x1f", is_string( $summary ) ? $summary : '' );
		} while ( isset( $parts[0] ) && (int) $parts[0] > 0 );
		$this->assertTrue( $processor->is_finished(), 'next_matching_tag_count_compact_batch should finish after exhaustion.' );
	}

	/**
	 * Verifies complete-input processors reject appended bytes.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_rejects_append_bytes_for_complete_input( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$processor = $this->create_xml_processor(
			$implementation,
			'<root><item /></root>'
		);

		$this->assertFalse( $processor->append_bytes( '<extra />' ) );
		$this->assertFalse( $processor->is_expecting_more_input() );
		$this->assertFalse( $processor->is_paused_at_incomplete_input() );
	}

	/**
	 * Verifies streaming processors can resume after incomplete input.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_streaming_appends_after_incomplete_input( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$processor = $this->create_xml_streaming_processor( $implementation, '<root' );

		$this->assertFalse( $processor->next_token(), 'Expected incomplete opening tag to pause token scanning.' );
		$this->assertNull( $processor->get_last_error() );
		$this->assertTrue( $processor->is_paused_at_incomplete_input() );
		$this->assertTrue( $processor->is_expecting_more_input() );
		$this->assertTrue( $processor->append_bytes( '><item id="1" /></root>' ) );

		$this->assertTrue( $processor->next_token(), 'Expected root tag after appending bytes.' );
		$this->assertSame( '#tag', $processor->get_token_type() );
		$this->assertSame( 'root', $processor->get_token_name() );
		$this->assertTrue( $processor->next_token(), 'Expected item tag after appending bytes.' );
		$this->assertSame( 'item', $processor->get_token_name() );
		$this->assertSame( '1', $this->get_xml_attribute( $processor, $implementation, 'id' ) );
		$this->assertTrue( $processor->next_token(), 'Expected root closer after appending bytes.' );
		$this->assertSame( 'root', $processor->get_token_name() );
		$this->assertTrue( $processor->is_tag_closer() );
	}

	/**
	 * Verifies streaming processors resume after an incomplete token following prior tokens.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_streaming_appends_after_prior_token_incomplete_input( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$processor = $this->create_xml_streaming_processor( $implementation, '<root><item' );

		$this->assertTrue( $processor->next_token(), 'Expected root tag before incomplete child.' );
		$this->assertSame( 'root', $processor->get_token_name() );
		$this->assertFalse( $processor->next_token(), 'Expected incomplete child tag to pause token scanning.' );
		$this->assertNull( $processor->get_last_error() );
		$this->assertTrue( $processor->is_paused_at_incomplete_input() );
		$this->assertTrue( $processor->is_expecting_more_input() );
		$this->assertTrue( $processor->append_bytes( ' id="1" /></root>' ) );

		$this->assertTrue( $processor->next_token(), 'Expected child tag after appending bytes.' );
		$this->assertSame( '#tag', $processor->get_token_type() );
		$this->assertSame( 'item', $processor->get_token_name() );
		$this->assertSame( '1', $this->get_xml_attribute( $processor, $implementation, 'id' ) );
		$this->assertTrue( $processor->next_token(), 'Expected root closer after appended child.' );
		$this->assertSame( 'root', $processor->get_token_name() );
		$this->assertTrue( $processor->is_tag_closer() );
	}

	/**
	 * Verifies streaming cursors preserve context for siblings but not parent closers.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_streaming_cursor_preserves_sibling_context( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$xml       = '<wp:root xmlns:wp="https://wordpress.org"><wp:item id="7">Text</wp:item><wp:tail /></wp:root>';
		$processor = $this->create_xml_streaming_processor( $implementation, $xml );

		$this->assertTrue( $processor->next_tag(), 'Expected root tag before cursor capture.' );
		$this->assertTrue( $processor->next_tag(), 'Expected item tag before cursor capture.' );
		$this->assertSame( 'item', $processor->get_tag_local_name() );
		$this->assertSame( 'https://wordpress.org', $processor->get_tag_namespace() );

		$offset  = $processor->get_token_byte_offset_in_the_input_stream();
		$cursor  = $processor->get_reentrancy_cursor();
		$resumed = $this->create_xml_streaming_processor_from_cursor( $implementation, substr( $xml, $offset ), $cursor );

		$this->assertTrue( $resumed->next_tag(), 'Expected resumed processor to expose the item tag.' );
		$this->assertSame( 'item', $resumed->get_tag_local_name() );
		$this->assertSame( 'https://wordpress.org', $resumed->get_tag_namespace() );
		$this->assertSame( '7', $this->get_xml_attribute( $resumed, $implementation, 'id' ) );
		$this->assertTrue( $resumed->next_token(), 'Expected resumed processor to expose item text.' );
		$this->assertSame( 'Text', $resumed->get_modifiable_text() );
		$this->assertTrue( $resumed->next_token(), 'Expected resumed processor to expose item closer.' );
		$this->assertSame( 'item', $resumed->get_tag_local_name() );
		$this->assertTrue( $resumed->is_tag_closer() );
		$this->assertTrue( $resumed->next_token(), 'Expected resumed processor to expose sibling tail tag.' );
		$this->assertSame( 'tail', $resumed->get_tag_local_name() );
		$this->assertSame( 'https://wordpress.org', $resumed->get_tag_namespace() );
		$this->assertTrue( $resumed->is_empty_element() );
		$this->assertFalse( $resumed->next_token(), 'Expected resumed processor to reject the pre-cursor parent closer.' );
		$this->assertNotNull( $resumed->get_last_error() );
	}

	/**
	 * Verifies streaming batch scans pause at incomplete input and resume after append.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_streaming_batches_pause_at_incomplete_input( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$cases = array(
			'token_compact',
			'tag_compact',
			'tag_count',
			'matching_tag_compact',
			'matching_tag_count',
		);

		foreach ( $cases as $case ) {
			$processor = $this->create_xml_streaming_processor( $implementation, '<root><item' );
			$result    = $this->run_xml_streaming_batch_case( $processor, $implementation, $case );

			if ( 'matching_tag_compact' === $case ) {
				$this->assertNull( $result, 'Expected no matching compact row before the incomplete child is complete.' );
			} else {
				$this->assertNotEmpty( $result, 'Expected batch case ' . $case . ' to expose prior complete tokens.' );
			}

			$this->assertNull( $processor->get_last_error(), 'Expected batch case ' . $case . ' not to report an error before input is finished.' );
			$this->assertTrue( $processor->is_paused_at_incomplete_input(), 'Expected batch case ' . $case . ' to pause at incomplete input.' );
			$this->assertTrue( $processor->is_expecting_more_input(), 'Expected batch case ' . $case . ' to keep expecting input.' );
			$this->assertTrue( $processor->append_bytes( ' id="1" /></root>' ), 'Expected batch case ' . $case . ' to accept appended bytes.' );
			$this->assertTrue( $processor->next_token(), 'Expected batch case ' . $case . ' to resume at the incomplete child tag.' );
			$this->assertSame( 'item', $processor->get_token_name() );
			$this->assertSame( '1', $this->get_xml_attribute( $processor, $implementation, 'id' ) );
		}
	}

	/**
	 * Verifies finishing incomplete streaming input reports a syntax error on the next scan.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_streaming_input_finished_reports_incomplete_input_error( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$processor = $this->create_xml_streaming_processor( $implementation, '<root><item' );

		$this->assertTrue( $processor->next_token(), 'Expected root tag before incomplete child.' );
		$this->assertSame( 'root', $processor->get_token_name() );
		$this->assertFalse( $processor->next_token(), 'Expected incomplete child tag to pause token scanning.' );
		$this->assertNull( $processor->get_last_error() );
		$this->assertTrue( $processor->is_paused_at_incomplete_input() );
		$this->assertTrue( $processor->is_expecting_more_input() );

		$processor->input_finished();

		$this->assertNull( $processor->get_last_error(), 'Expected finished input to defer the syntax error until the next scan.' );
		$this->assertFalse( $processor->is_paused_at_incomplete_input() );
		$this->assertFalse( $processor->is_expecting_more_input() );
		$this->assertFalse( $processor->is_finished() );
		$this->assertFalse( $processor->next_token(), 'Expected finished incomplete input to report a syntax error.' );
		$this->assertNotNull( $processor->get_last_error() );
		$this->assertFalse( $processor->is_finished() );
	}

	/**
	 * Verifies XML documents without a document element report a syntax error.
	 *
	 * @dataProvider data_xml_without_document_element
	 *
	 * @param string $xml            XML input.
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_requires_document_element( $xml, $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$processor = $this->create_xml_processor( $implementation, $xml );

		while ( $processor->next_token() ) {
			continue;
		}

		$this->assertNotNull( $processor->get_last_error() );
	}

	/**
	 * Verifies leading whitespace outside the document element is skipped.
	 *
	 * @dataProvider data_xml_with_leading_misc_whitespace
	 *
	 * @param string $xml            XML input.
	 * @param array  $token_names    Expected token names.
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_skips_leading_misc_whitespace( $xml, $token_names, $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$processor = $this->create_xml_processor( $implementation, $xml );

		foreach ( $token_names as $token_name ) {
			$this->assertTrue( $processor->next_token() );
			$this->assertSame( $token_name, $processor->get_token_name() );
		}
	}

	/**
	 * Verifies streaming processors pause and resume for incomplete token kinds.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_streaming_pauses_for_incomplete_token_kinds( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$cases = array(
			array( '<root><!--note', '--></root>' ),
			array( '<root><![CDATA[x', ']]></root>' ),
			array( '<!DOCTYPE root', '><root />' ),
			array( '<root><?xml-stylesheet href="x"', '?></root>' ),
			array( '<', 'root />' ),
			array( '<root a="x', '" />' ),
			array( '<root a', '="x" />' ),
			array( '<root a=', '"x" />' ),
		);

		foreach ( $cases as $case ) {
			$processor = $this->create_xml_streaming_processor( $implementation, $case[0] );
			while ( $processor->next_token() ) {
				// Advance to the incomplete token.
			}

			$this->assertTrue( $processor->is_paused_at_incomplete_input(), 'Expected incomplete input to pause for: ' . $case[0] );
			$this->assertTrue( $processor->is_expecting_more_input(), 'Expected processor to keep expecting input for: ' . $case[0] );
			$this->assertTrue( $processor->append_bytes( $case[1] ), 'Expected processor to accept appended bytes for: ' . $case[0] );
			$this->assertTrue( $processor->next_token(), 'Expected processor to resume after appended bytes for: ' . $case[0] );
		}
	}

	/**
	 * Verifies the first native slice matches the PHP XML processor token stream.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_reads_tags_and_attributes( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$processor = $this->create_xml_processor(
			$implementation,
			'<root><item id="7" data-kind="post" /></root>'
		);

		$this->assertTrue( $processor->next_token(), 'Expected the root token.' );
		$this->assertSame( '#tag', $processor->get_token_type() );
		$this->assertSame( 'root', $processor->get_token_name() );
		$this->assertSame( '', $processor->get_tag_namespace() );

		$this->assertTrue( $processor->next_tag(), 'Expected the item tag.' );
		$this->assertSame( '#tag', $processor->get_token_type() );
		$this->assertSame( 'item', $processor->get_token_name() );
		$this->assertSame( '', $processor->get_tag_namespace() );
		$this->assertSame( '7', $this->get_xml_attribute( $processor, $implementation, 'id' ) );
		$this->assertSame( 'post', $this->get_xml_attribute( $processor, $implementation, 'data-kind' ) );
		$this->assertSame(
			array( array( '', 'data-kind' ) ),
			$processor->get_attribute_names_with_prefix( null, 'data-' )
		);
		$this->assertNull( $processor->get_last_error() );
	}

	/**
	 * Verifies attribute-prefix reads preserve XML input order.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_preserves_attribute_prefix_order( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$processor = $this->create_xml_processor(
			$implementation,
			'<content xmlns:wp="http://wordpress.org/export/1.2/" wp:data-foo="bar" wp:data-bar="baz" data-foo="no-ns" />'
		);

		$this->assertTrue( $processor->next_tag(), 'Expected the content tag.' );
		$this->assertSame(
			array(
				array( 'http://wordpress.org/export/1.2/', 'data-foo' ),
				array( 'http://wordpress.org/export/1.2/', 'data-bar' ),
			),
			$processor->get_attribute_names_with_prefix( 'http://wordpress.org/export/1.2/', 'data-' )
		);
		$this->assertSame(
			array( array( '', 'data-foo' ) ),
			$processor->get_attribute_names_with_prefix( null, 'data-' )
		);
	}

	/**
	 * Verifies document-level attribute-prefix summaries match repeated scans.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_summarizes_attribute_prefixes( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$processor = $this->create_xml_processor(
			$implementation,
			'<root xmlns:wp="http://wordpress.org/export/1.2/"><wp:item wp:data-id="7" data-kind="post" /><item data-id="8" /></root>'
		);

		$this->assertSame(
			array(
				'tag_count'       => 3,
				'attribute_count' => 2,
			),
			$this->summarize_xml_attribute_names_with_prefix( $processor, $implementation, null, 'data-' )
		);

		$processor = $this->create_xml_processor(
			$implementation,
			'<root xmlns:wp="http://wordpress.org/export/1.2/"><wp:item wp:data-id="7" data-kind="post" /><item data-id="8" /></root>'
		);

		$this->assertTrue( $processor->next_tag(), 'Expected the root tag before summarizing remaining tags.' );
		$this->assertSame(
			array(
				'tag_count'       => 2,
				'attribute_count' => 1,
			),
			$this->summarize_xml_attribute_names_with_prefix( $processor, $implementation, 'http://wordpress.org/export/1.2/', 'data-' )
		);
	}

	/**
	 * Verifies document-level token stream summaries match repeated token scans.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_summarizes_token_streams( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$processor = $this->create_xml_processor(
			$implementation,
			'<?xml version="1.0" encoding="UTF-8"?><root id="root"><item id="7">Text</item><empty></empty></root>'
		);

		$this->assertSame(
			array(
				'token_count'     => 8,
				'tag_count'       => 3,
				'attribute_count' => 2,
			),
			$this->summarize_xml_token_stream( $processor, $implementation, 'id' )
		);

		$processor = $this->create_xml_processor(
			$implementation,
			'<?xml version="1.0" encoding="UTF-8"?><root id="root"><item id="7">Text</item><empty></empty></root>'
		);

		$this->assertTrue( $processor->next_token(), 'Expected the XML declaration before summarizing remaining tokens.' );
		$this->assertSame(
			array(
				'token_count'     => 7,
				'tag_count'       => 3,
				'attribute_count' => 2,
			),
			$this->summarize_xml_token_stream( $processor, $implementation, 'id' )
		);
	}

	/**
	 * Verifies document-level tag stream summaries match repeated tag scans.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_summarizes_tag_streams( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$processor = $this->create_xml_processor(
			$implementation,
			'<?xml version="1.0" encoding="UTF-8"?><root id="root"><item id="7">Text</item><empty></empty></root>'
		);

		$this->assertSame(
			array(
				'tag_count'       => 3,
				'attribute_count' => 2,
			),
			$this->summarize_xml_tag_stream( $processor, $implementation, 'id' )
		);

		$processor = $this->create_xml_processor(
			$implementation,
			'<?xml version="1.0" encoding="UTF-8"?><root id="root"><item id="7">Text</item><empty></empty></root>'
		);

		$this->assertTrue( $processor->next_tag(), 'Expected the root tag before summarizing remaining tags.' );
		$this->assertSame(
			array(
				'tag_count'       => 2,
				'attribute_count' => 1,
			),
			$this->summarize_xml_tag_stream( $processor, $implementation, 'id' )
		);
	}

	/**
	 * Verifies incremental tag count batches match repeated tag scans.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_counts_tag_batches( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$processor = $this->create_xml_processor(
			$implementation,
			'<?xml version="1.0" encoding="UTF-8"?><root id="root"><item id="7">Text</item><empty></empty></root>'
		);

		$this->assertSame(
			array(
				'token_count'     => 3,
				'tag_count'       => 2,
				'attribute_count' => 2,
			),
			$this->next_xml_tag_count_batch( $processor, $implementation, 2, 'id' )
		);
		$this->assertSame(
			array(
				'token_count'     => 5,
				'tag_count'       => 1,
				'attribute_count' => 0,
			),
			$this->next_xml_tag_count_batch( $processor, $implementation, 2, 'id' )
		);
		$this->assertSame(
			array(
				'token_count'     => 0,
				'tag_count'       => 0,
				'attribute_count' => 0,
			),
			$this->next_xml_tag_count_batch( $processor, $implementation, 2, 'id' )
		);
	}

	/**
	 * Verifies document-level prefixed attribute removals match repeated scans.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_removes_attribute_prefixes_from_document( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$xml       = '<root xmlns:wp="http://wordpress.org/export/1.2/" data-root="1"><wp:item wp:data-id="7" data-kind="post" keep="x" /><item data-id="8" /></root>';
		$processor = $this->create_xml_processor( $implementation, $xml );

		$this->assertSame(
			array(
				'tag_count'     => 3,
				'removed_count' => 3,
				'xml'           => '<root xmlns:wp="http://wordpress.org/export/1.2/" ><wp:item wp:data-id="7"  keep="x" /><item  /></root>',
			),
			$this->remove_xml_attribute_names_with_prefix_from_document( $processor, $implementation, null, 'data-' )
		);

		$processor = $this->create_xml_processor( $implementation, $xml );
		$this->assertSame(
			array(
				'tag_count'     => 3,
				'removed_count' => 1,
				'xml'           => '<root xmlns:wp="http://wordpress.org/export/1.2/" data-root="1"><wp:item  data-kind="post" keep="x" /><item data-id="8" /></root>',
			),
			$this->remove_xml_attribute_names_with_prefix_from_document( $processor, $implementation, 'http://wordpress.org/export/1.2/', 'data-' )
		);
	}

	/**
	 * Verifies empty-element markers are exposed consistently.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_reports_empty_elements( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$processor = $this->create_xml_processor(
			$implementation,
			'<root><empty /><parent></parent></root>'
		);

		$this->assertTrue( $processor->next_tag(), 'Expected the root token.' );
		$this->assertSame( 'root', $processor->get_token_name() );
		$this->assertFalse( $processor->is_empty_element() );

		$this->assertTrue( $processor->next_tag(), 'Expected the empty element token.' );
		$this->assertSame( 'empty', $processor->get_token_name() );
		$this->assertTrue( $processor->is_empty_element() );

		$this->assertTrue( $processor->next_tag(), 'Expected the non-empty parent token.' );
		$this->assertSame( 'parent', $processor->get_token_name() );
		$this->assertFalse( $processor->is_empty_element() );
	}

	/**
	 * Verifies XML character references are decoded in text and attributes.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_decodes_character_references( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$processor = $this->create_xml_processor(
			$implementation,
			'<root a="&amp; &amp;amp; &lt; &gt; &quot; &apos;&#65;&#x42; &#0; &unknown;">&amp; &amp;amp; &lt; &gt; &quot; &apos;&#67;&#x44; &#xD800; &unknown;</root>'
		);

		$this->assertTrue( $processor->next_token(), 'Expected the root token.' );
		$this->assertSame( '& &amp; < > " \'AB &#0; &unknown;', $this->get_xml_attribute( $processor, $implementation, 'a' ) );

		$this->assertTrue( $processor->next_token(), 'Expected the text token.' );
		$this->assertSame( '& &amp; < > " \'CD &#xD800; &unknown;', $processor->get_modifiable_text() );
		$this->assertNull( $processor->get_last_error() );
	}

	/**
	 * Verifies malformed documents are observable through the shared error API.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_reports_syntax_errors( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$processor = $this->create_xml_processor(
			$implementation,
			'<root><item id=7 /></root>'
		);

		while ( $processor->next_token() ) {
			continue;
		}

		$this->assertNotNull( $processor->get_last_error() );
	}

	/**
	 * Verifies malformed closing tags stop scanning after prior valid tokens.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_rejects_attributes_in_closing_tags( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$processor = $this->create_xml_processor(
			$implementation,
			'<content>Test</content post-type="test">'
		);

		$this->assertTrue( $processor->next_token(), 'Expected the opening tag before the invalid closer.' );
		$this->assertSame( 'content', $processor->get_token_name() );
		$this->assertTrue( $processor->next_token(), 'Expected the text token before the invalid closer.' );
		$this->assertSame( 'Test', $processor->get_modifiable_text() );
		$this->assertFalse( $processor->next_token(), 'Expected the invalid closing tag to stop token scanning.' );
		$this->assertNotNull( $processor->get_last_error() );
	}

	/**
	 * Verifies malformed attributes reject the current token.
	 *
	 * @dataProvider data_malformed_attribute_xml_processor_implementations
	 *
	 * @param string $xml            XML input.
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_rejects_malformed_attributes( $xml, $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$processor = $this->create_xml_processor( $implementation, $xml );

		$this->assertFalse( $processor->next_tag(), 'Expected malformed attributes to stop tag scanning.' );
		$this->assertNotNull( $processor->get_last_error() );
		$this->assertNotNull( $processor->get_exception() );
	}

	/**
	 * Verifies unsupported processing instructions are observable as errors.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_reports_processing_instruction_errors( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$processor = $this->create_xml_processor(
			$implementation,
			'<root><?pi data?><child /></root>'
		);

		while ( $processor->next_token() ) {
			continue;
		}

		$this->assertNotNull( $processor->get_last_error() );
	}

	/**
	 * Verifies namespace-local tag reads and namespaced attributes stay aligned.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_reads_namespace_qualified_tags_and_attributes( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$processor = $this->create_xml_processor(
			$implementation,
			'<root xmlns:wp="https://wordpress.org"><wp:item wp:id="7" plain="yes" /></root>'
		);

		$this->assertTrue( $processor->next_tag(), 'Expected the root tag.' );
		$this->assertSame( 'root', $processor->get_token_name() );
		$this->assertSame( 'root', $processor->get_tag_local_name() );
		$this->assertSame( 'root', $processor->get_tag_namespace_and_local_name() );

		$this->assertTrue( $processor->next_tag(), 'Expected the namespaced item tag.' );
		$this->assertSame( 'item', $processor->get_token_name() );
		$this->assertSame( 'item', $processor->get_tag_local_name() );
		$this->assertSame( 'https://wordpress.org', $processor->get_tag_namespace() );
		$this->assertSame( '{https://wordpress.org}item', $processor->get_tag_namespace_and_local_name() );
		$this->assertSame( '7', $this->get_xml_attribute( $processor, $implementation, 'id', 'https://wordpress.org' ) );
		$this->assertSame( 'yes', $this->get_xml_attribute( $processor, $implementation, 'plain' ) );
	}

	/**
	 * Verifies breadcrumbs and current depth stay aligned while skipping closing tags.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_reports_breadcrumbs_and_current_depth( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$processor = $this->create_xml_processor(
			$implementation,
			'<root xmlns:wp="w.org"><wp:text><post /></wp:text><image /></root>'
		);

		$this->assertSame( 0, $processor->get_current_depth() );

		$this->assertTrue( $processor->next_tag(), 'Expected the root tag.' );
		$this->assertSame( 1, $processor->get_current_depth() );
		$this->assertSame(
			array( array( '', 'root' ) ),
			$processor->get_breadcrumbs()
		);

		$this->assertTrue( $processor->next_tag(), 'Expected the namespaced text tag.' );
		$this->assertSame( 2, $processor->get_current_depth() );
		$this->assertSame(
			array( array( '', 'root' ), array( 'w.org', 'text' ) ),
			$processor->get_breadcrumbs()
		);

		$this->assertTrue( $processor->next_tag(), 'Expected the post tag.' );
		$this->assertSame( 3, $processor->get_current_depth() );
		$this->assertSame(
			array( array( '', 'root' ), array( 'w.org', 'text' ), array( '', 'post' ) ),
			$processor->get_breadcrumbs()
		);

		$this->assertTrue( $processor->next_tag(), 'Expected next_tag() to skip closing tags and find image.' );
		$this->assertSame( 'image', $processor->get_token_name() );
		$this->assertSame( 2, $processor->get_current_depth() );
		$this->assertSame(
			array( array( '', 'root' ), array( '', 'image' ) ),
			$processor->get_breadcrumbs()
		);
	}

	/**
	 * Verifies text and comment tokens stay aligned with the PHP XML processor.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_reads_text_and_comment_tokens( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$processor = $this->create_xml_processor(
			$implementation,
			'<root xmlns:wp="w.org"><wp:text>Hello<!--note--><post />World</wp:text></root>'
		);

		$this->assertTrue( $processor->next_token(), 'Expected the root tag.' );
		$this->assertSame( '#tag', $processor->get_token_type() );
		$this->assertSame( 'root', $processor->get_token_name() );

		$this->assertTrue( $processor->next_token(), 'Expected the namespaced text tag.' );
		$this->assertSame( '#tag', $processor->get_token_type() );
		$this->assertSame( 'text', $processor->get_token_name() );

		$this->assertTrue( $processor->next_token(), 'Expected the first text token.' );
		$this->assertSame( '#text', $processor->get_token_type() );
		$this->assertSame( '#text', $processor->get_token_name() );
		$this->assertSame( 'Hello', $processor->get_modifiable_text() );
		$this->assertSame( 2, $processor->get_current_depth() );
		$this->assertSame(
			array( array( '', 'root' ), array( 'w.org', 'text' ) ),
			$processor->get_breadcrumbs()
		);

		$this->assertTrue( $processor->next_token(), 'Expected the comment token.' );
		$this->assertSame( '#comment', $processor->get_token_type() );
		$this->assertSame( '#comment', $processor->get_token_name() );
		$this->assertSame( 'note', $processor->get_modifiable_text() );
		$this->assertSame( 2, $processor->get_current_depth() );
		$this->assertSame(
			array( array( '', 'root' ), array( 'w.org', 'text' ) ),
			$processor->get_breadcrumbs()
		);

		$this->assertTrue( $processor->next_tag(), 'Expected next_tag() to skip text and find the post tag.' );
		$this->assertSame( '#tag', $processor->get_token_type() );
		$this->assertSame( 'post', $processor->get_token_name() );

		$this->assertTrue( $processor->next_token(), 'Expected the second text token.' );
		$this->assertSame( '#text', $processor->get_token_type() );
		$this->assertSame( 'World', $processor->get_modifiable_text() );
	}

	/**
	 * Verifies opening tag modifiable text stays aligned with the PHP XML processor.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_reads_opening_tag_modifiable_text( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$processor = $this->create_xml_processor(
			$implementation,
			'<root><item id="1">Text &amp; More</item></root>'
		);

		$this->assertTrue( $processor->next_token(), 'Expected the root tag.' );
		$this->assertSame( 'root', $processor->get_token_name() );
		$this->assertSame( '<item id="1">Text & More</item></', $processor->get_modifiable_text() );

		$this->assertTrue( $processor->next_token(), 'Expected the item tag.' );
		$this->assertSame( 'item', $processor->get_token_name() );
		$this->assertSame( 'Text & More</', $processor->get_modifiable_text() );

		$this->assertTrue( $processor->next_token(), 'Expected the text token.' );
		$this->assertSame( '#text', $processor->get_token_name() );

		$this->assertTrue( $processor->next_token(), 'Expected the item closer.' );
		$this->assertSame( 'item', $processor->get_token_name() );

		$processor = $this->create_xml_processor( $implementation, '<root><empty /></root>' );
		$this->assertTrue( $processor->next_token(), 'Expected the root tag before the empty element.' );
		$this->assertTrue( $processor->next_token(), 'Expected the empty element.' );
		$this->assertSame( 'empty', $processor->get_token_name() );
		$this->assertSame( '', $processor->get_modifiable_text() );
	}

	/**
	 * Verifies CDATA sections stay aligned with the PHP XML processor.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_reads_cdata_section_tokens( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$processor = $this->create_xml_processor(
			$implementation,
			'<root xmlns:wp="w.org"><wp:text>before<![CDATA[<b>&c]]>after</wp:text></root>'
		);

		$this->assertTrue( $processor->next_token(), 'Expected the root tag.' );
		$this->assertTrue( $processor->next_token(), 'Expected the namespaced text tag.' );

		$this->assertTrue( $processor->next_token(), 'Expected the text token before CDATA.' );
		$this->assertSame( '#text', $processor->get_token_type() );
		$this->assertSame( 'before', $processor->get_modifiable_text() );

		$this->assertTrue( $processor->next_token(), 'Expected the CDATA token.' );
		$this->assertSame( '#cdata-section', $processor->get_token_type() );
		$this->assertSame( '#cdata-section', $processor->get_token_name() );
		$this->assertSame( '<b>&c', $processor->get_modifiable_text() );
		$this->assertSame( 2, $processor->get_current_depth() );
		$this->assertSame(
			array( array( '', 'root' ), array( 'w.org', 'text' ) ),
			$processor->get_breadcrumbs()
		);

		$this->assertTrue( $processor->next_token(), 'Expected the text token after CDATA.' );
		$this->assertSame( '#text', $processor->get_token_type() );
		$this->assertSame( 'after', $processor->get_modifiable_text() );
	}

	/**
	 * Verifies modifiable text updates stay aligned with the PHP XML processor.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_updates_modifiable_text_tokens( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$processor = $this->create_xml_processor(
			$implementation,
			'<root>One<!--note--><![CDATA[raw]]><tail /></root>'
		);

		$this->assertTrue( $processor->next_token(), 'Expected the root tag.' );
		$this->assertFalse( $processor->set_modifiable_text( 'nope' ), 'Expected tag text mutation to be rejected.' );

		$this->assertTrue( $processor->next_token(), 'Expected the text token.' );
		$this->assertTrue( $processor->set_modifiable_text( 'Two & <three>' ), 'Expected text mutation to succeed.' );

		$this->assertTrue( $processor->next_token(), 'Expected the comment token.' );
		$this->assertTrue( $processor->set_modifiable_text( 'comment & <tag>' ), 'Expected comment mutation to succeed.' );

		$this->assertTrue( $processor->next_token(), 'Expected the CDATA token.' );
		$this->assertTrue( $processor->set_modifiable_text( 'inside ]]> cdata' ), 'Expected CDATA mutation to succeed.' );

		$this->assertSame(
			'<root>Two &amp; &lt;three&gt;<!--comment &amp; &lt;tag&gt;--><![CDATA[inside ]]&gt; cdata]]><tail /></root>',
			$processor->get_updated_xml()
		);
		$this->assertTrue( $processor->next_tag(), 'Expected processor to continue after text mutations.' );
		$this->assertSame( 'tail', $processor->get_token_name() );
	}

	/**
	 * Verifies prolog declaration tokens stay aligned with the PHP XML processor.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_reads_xml_declaration_and_doctype_tokens( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$processor = $this->create_xml_processor(
			$implementation,
			'<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE root><root />'
		);

		$this->assertTrue( $processor->next_token(), 'Expected the XML declaration token.' );
		$this->assertSame( '#xml-declaration', $processor->get_token_type() );
		$this->assertSame( '#xml-declaration', $processor->get_token_name() );
		$this->assertSame( 'xml version="1.0" encoding="UTF-8"', $processor->get_modifiable_text() );
		$this->assertSame( 0, $processor->get_current_depth() );
		$this->assertSame( array(), $processor->get_breadcrumbs() );
		$this->assertSame( '1.0', $this->get_xml_attribute( $processor, $implementation, 'version' ) );
		$this->assertSame( 'UTF-8', $this->get_xml_attribute( $processor, $implementation, 'encoding' ) );

		$this->assertTrue( $processor->next_token(), 'Expected the DOCTYPE token.' );
		$this->assertSame( '#doctype', $processor->get_token_type() );
		$this->assertSame( '#doctype', $processor->get_token_name() );
		$this->assertSame( '', $processor->get_modifiable_text() );
		$this->assertSame( 0, $processor->get_current_depth() );
		$this->assertSame( array(), $processor->get_breadcrumbs() );

		$this->assertTrue( $processor->next_tag(), 'Expected next_tag() to skip prolog tokens and find the root tag.' );
		$this->assertSame( '#tag', $processor->get_token_type() );
		$this->assertSame( 'root', $processor->get_token_name() );
	}

	/**
	 * Verifies chunked token summaries stay aligned with token-by-token reads.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_reads_token_summary_batches( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$processor = $this->create_xml_processor(
			$implementation,
			'<?xml version="1.0"?><root id="root"><item id="7">Text</item><empty /></root>'
		);
		$rows      = array();

		do {
			$batch = $this->next_xml_token_summary_batch( $processor, $implementation, 2 );
			$rows  = array_merge( $rows, $batch );
		} while ( ! empty( $batch ) );

		$this->assertCount( 7, $rows );
		$this->assertSame(
			array( '#xml-declaration', '#tag', '#tag', '#text', '#tag', '#tag', '#tag' ),
			array_column( $rows, 'token_type' )
		);
		$this->assertSame(
			array( null, 'root', '7', null, null, null, null ),
			array_column( $rows, 'id' )
		);
		$this->assertSame( 'root', $rows[1]['tag_local_name'] );
		$this->assertSame( 1, $rows[1]['current_depth'] );
		$this->assertSame( 'item', $rows[2]['tag_local_name'] );
		$this->assertSame( 2, $rows[2]['current_depth'] );
		$this->assertSame( true, $rows[4]['is_tag_closer'] );
		$this->assertSame( true, $rows[5]['is_empty_element'] );
		$this->assertSame( 0, $rows[6]['current_depth'] );
	}

	/**
	 * Verifies document-level inventory summaries match token-by-token reads.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_summarizes_document_inventory( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$processor = $this->create_xml_processor(
			$implementation,
			'<?xml version="1.0"?><root><!-- note --><item>Text</item><empty /><data><![CDATA[x]]></data></root>'
		);

		$this->assertSame(
			array(
				'token_count'         => 11,
				'tag_count'           => 4,
				'closing_tag_count'   => 3,
				'text_token_count'    => 1,
				'comment_count'       => 1,
				'cdata_count'         => 1,
				'max_depth'           => 2,
				'empty_element_count' => 1,
			),
			$this->summarize_xml_document_inventory( $processor, $implementation )
		);
		$this->assertTrue( $processor->is_finished() );

		$processor = $this->create_xml_processor(
			$implementation,
			'<?xml version="1.0"?><root><!-- note --><item>Text</item><empty /><data><![CDATA[x]]></data></root>'
		);
		$this->assertTrue( $processor->next_tag() );
		$this->assertSame(
			array(
				'token_count'         => 9,
				'tag_count'           => 3,
				'closing_tag_count'   => 3,
				'text_token_count'    => 1,
				'comment_count'       => 1,
				'cdata_count'         => 1,
				'max_depth'           => 2,
				'empty_element_count' => 1,
			),
			$this->summarize_xml_document_inventory( $processor, $implementation )
		);
		$this->assertTrue( $processor->is_finished() );
	}

	/**
	 * Verifies element-name inventories match repeated token scans.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_summarizes_element_inventory( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$xml       = '<?xml version="1.0"?><wp:root xmlns:wp="https://wordpress.org"><wp:item /><wp:item><plain /></wp:item><plain /></wp:root>';
		$processor = $this->create_xml_processor( $implementation, $xml );

		$this->assertSame(
			array(
				'token_count'              => 8,
				'tag_count'                => 5,
				'closing_tag_count'        => 2,
				'unique_tag_name_count'    => 3,
				'duplicate_tag_name_count' => 2,
				'namespaced_tag_count'     => 3,
				'empty_element_count'      => 3,
			),
			$this->summarize_xml_element_inventory( $processor, $implementation )
		);
		$this->assertTrue( $processor->is_finished() );

		$processor = $this->create_xml_processor( $implementation, $xml );
		$this->assertTrue( $processor->next_tag() );
		$this->assertSame(
			array(
				'token_count'              => 6,
				'tag_count'                => 4,
				'closing_tag_count'        => 2,
				'unique_tag_name_count'    => 2,
				'duplicate_tag_name_count' => 2,
				'namespaced_tag_count'     => 2,
				'empty_element_count'      => 3,
			),
			$this->summarize_xml_element_inventory( $processor, $implementation )
		);
		$this->assertTrue( $processor->is_finished() );
	}

	/**
	 * Verifies document-level depth inventories match repeated token scans.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_summarizes_depth_inventory( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$xml       = '<?xml version="1.0"?><root><section><item /><item><child /></item></section><single /></root>';
		$processor = $this->create_xml_processor( $implementation, $xml );

		$this->assertSame(
			array(
				'token_count'          => 10,
				'tag_count'            => 6,
				'closing_tag_count'    => 3,
				'empty_element_count'  => 3,
				'root_level_tag_count' => 1,
				'nested_tag_count'     => 3,
				'total_tag_depth'      => 15,
				'max_depth'            => 4,
			),
			$this->summarize_xml_depth_inventory( $processor, $implementation )
		);
		$this->assertTrue( $processor->is_finished() );

		$processor = $this->create_xml_processor( $implementation, $xml );
		$this->assertTrue( $processor->next_tag() );
		$this->assertSame(
			array(
				'token_count'          => 8,
				'tag_count'            => 5,
				'closing_tag_count'    => 3,
				'empty_element_count'  => 3,
				'root_level_tag_count' => 0,
				'nested_tag_count'     => 3,
				'total_tag_depth'      => 14,
				'max_depth'            => 4,
			),
			$this->summarize_xml_depth_inventory( $processor, $implementation )
		);
		$this->assertTrue( $processor->is_finished() );
	}

	/**
	 * Verifies leaf and branch element inventories match repeated token scans.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_summarizes_leaf_inventory( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$xml       = '<?xml version="1.0"?><root><section><item /><item><child /></item></section><single /></root>';
		$processor = $this->create_xml_processor( $implementation, $xml );

		$this->assertSame(
			array(
				'token_count'             => 10,
				'tag_count'               => 6,
				'closing_tag_count'       => 3,
				'empty_element_count'     => 3,
				'leaf_element_count'      => 3,
				'branch_element_count'    => 3,
				'max_child_element_count' => 2,
			),
			$this->summarize_xml_leaf_inventory( $processor, $implementation )
		);
		$this->assertTrue( $processor->is_finished() );

		$processor = $this->create_xml_processor( $implementation, $xml );
		$this->assertTrue( $processor->next_tag() );
		$this->assertSame(
			array(
				'token_count'             => 8,
				'tag_count'               => 5,
				'closing_tag_count'       => 3,
				'empty_element_count'     => 3,
				'leaf_element_count'      => 3,
				'branch_element_count'    => 2,
				'max_child_element_count' => 2,
			),
			$this->summarize_xml_leaf_inventory( $processor, $implementation )
		);
		$this->assertTrue( $processor->is_finished() );
	}

	/**
	 * Verifies structural inventories match repeated token scans.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_summarizes_structural_inventory( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$xml       = '<?xml version="1.0"?><wp:root xmlns:wp="https://wordpress.org"><wp:section><wp:item /><wp:item><child /></wp:item></wp:section><single /></wp:root>';
		$processor = $this->create_xml_processor( $implementation, $xml );

		$this->assertSame(
			array(
				'token_count'              => 10,
				'tag_count'                => 6,
				'closing_tag_count'        => 3,
				'unique_tag_name_count'    => 5,
				'duplicate_tag_name_count' => 1,
				'namespaced_tag_count'     => 4,
				'empty_element_count'      => 3,
				'root_level_tag_count'     => 1,
				'nested_tag_count'         => 3,
				'total_tag_depth'          => 15,
				'max_depth'                => 4,
				'leaf_element_count'       => 3,
				'branch_element_count'     => 3,
				'max_child_element_count'  => 2,
			),
			$this->summarize_xml_structural_inventory( $processor, $implementation )
		);
		$this->assertTrue( $processor->is_finished() );

		$processor = $this->create_xml_processor( $implementation, $xml );
		$this->assertTrue( $processor->next_tag() );
		$this->assertSame(
			array(
				'token_count'              => 8,
				'tag_count'                => 5,
				'closing_tag_count'        => 3,
				'unique_tag_name_count'    => 4,
				'duplicate_tag_name_count' => 1,
				'namespaced_tag_count'     => 3,
				'empty_element_count'      => 3,
				'root_level_tag_count'     => 0,
				'nested_tag_count'         => 3,
				'total_tag_depth'          => 14,
				'max_depth'                => 4,
				'leaf_element_count'       => 3,
				'branch_element_count'     => 2,
				'max_child_element_count'  => 2,
			),
			$this->summarize_xml_structural_inventory( $processor, $implementation )
		);
		$this->assertTrue( $processor->is_finished() );
	}

	/**
	 * Verifies document-level attribute inventories match repeated token scans.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_summarizes_attribute_inventory( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$xml       = '<?xml version="1.0"?><wp:root xmlns:wp="https://wordpress.org" id="root"><wp:item id="7" wp:slug="first"><wp:title>Title</wp:title><empty data-id="x" /></wp:item></wp:root>';
		$processor = $this->create_xml_processor( $implementation, $xml );

		$this->assertSame(
			array(
				'token_count'                  => 9,
				'tag_count'                    => 4,
				'attribute_count'              => 4,
				'namespaced_attribute_count'   => 1,
				'tags_with_attributes_count'   => 3,
				'max_attribute_count'          => 2,
			),
			$this->summarize_xml_attribute_inventory( $processor, $implementation )
		);

		$processor = $this->create_xml_processor( $implementation, $xml );
		$this->assertTrue( $processor->next_tag() );
		$this->assertSame(
			array(
				'token_count'                  => 7,
				'tag_count'                    => 3,
				'attribute_count'              => 3,
				'namespaced_attribute_count'   => 1,
				'tags_with_attributes_count'   => 2,
				'max_attribute_count'          => 2,
			),
			$this->summarize_xml_attribute_inventory( $processor, $implementation )
		);
	}

	/**
	 * Verifies document-level ID inventories match repeated token scans.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_summarizes_id_inventory( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$xml       = '<?xml version="1.0"?><wp:root xmlns:wp="https://wordpress.org" id="root"><wp:item id="7" wp:id="ignored"><wp:title id="title">Title</wp:title><empty id="7" /><plain /></wp:item></wp:root>';
		$processor = $this->create_xml_processor( $implementation, $xml );

		$this->assertSame(
			array(
				'token_count'        => 10,
				'tag_count'          => 5,
				'id_attribute_count' => 4,
				'unique_id_count'    => 3,
				'duplicate_id_count' => 1,
				'id_value_bytes'     => 11,
			),
			$this->summarize_xml_id_inventory( $processor, $implementation )
		);

		$processor = $this->create_xml_processor( $implementation, $xml );
		$this->assertTrue( $processor->next_tag() );
		$this->assertSame(
			array(
				'token_count'        => 8,
				'tag_count'          => 4,
				'id_attribute_count' => 3,
				'unique_id_count'    => 2,
				'duplicate_id_count' => 1,
				'id_value_bytes'     => 7,
			),
			$this->summarize_xml_id_inventory( $processor, $implementation )
		);
	}

	/**
	 * Verifies document-level namespace inventories match repeated token scans.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_summarizes_namespace_inventory( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$xml       = '<?xml version="1.0"?><wp:root xmlns:wp="https://wordpress.org" xmlns:media="https://example.com/media" id="root"><wp:item id="7" wp:slug="first" media:type="image"><media:title>Title</media:title><empty data-id="x" /></wp:item></wp:root>';
		$processor = $this->create_xml_processor( $implementation, $xml );

		$this->assertSame(
			array(
				'token_count'                  => 9,
				'tag_count'                    => 4,
				'namespaced_tag_count'         => 3,
				'attribute_count'              => 5,
				'namespaced_attribute_count'   => 2,
				'unique_namespace_count'       => 2,
			),
			$this->summarize_xml_namespace_inventory( $processor, $implementation )
		);

		$processor = $this->create_xml_processor( $implementation, $xml );
		$this->assertTrue( $processor->next_tag() );
		$this->assertSame(
			array(
				'token_count'                  => 7,
				'tag_count'                    => 3,
				'namespaced_tag_count'         => 2,
				'attribute_count'              => 4,
				'namespaced_attribute_count'   => 2,
				'unique_namespace_count'       => 2,
			),
			$this->summarize_xml_namespace_inventory( $processor, $implementation )
		);
	}

	/**
	 * Verifies document-level text inventories match repeated token scans.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_summarizes_text_inventory( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$xml       = '<?xml version="1.0"?><root> Alpha <item>Two</item><data><![CDATA[raw]]></data><space>   </space></root>';
		$processor = $this->create_xml_processor( $implementation, $xml );

		$this->assertSame(
			array(
				'token_count'           => 13,
				'text_token_count'      => 3,
				'cdata_count'           => 1,
				'non_empty_text_count'  => 3,
				'whitespace_text_count' => 1,
				'total_text_bytes'      => 16,
				'max_text_bytes'        => 7,
			),
			$this->summarize_xml_text_inventory( $processor, $implementation )
		);

		$processor = $this->create_xml_processor( $implementation, $xml );
		$this->assertTrue( $processor->next_tag() );
		$this->assertSame(
			array(
				'token_count'           => 11,
				'text_token_count'      => 3,
				'cdata_count'           => 1,
				'non_empty_text_count'  => 3,
				'whitespace_text_count' => 1,
				'total_text_bytes'      => 16,
				'max_text_bytes'        => 7,
			),
			$this->summarize_xml_text_inventory( $processor, $implementation )
		);
	}

	/**
	 * Verifies document-level processing instruction inventories match repeated token scans.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_summarizes_processing_instruction_inventory( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$xml       = '<?xml version="1.0"?><root><?xml-stylesheet type="text/xsl"?><?xml audit data?><item /></root><?xml trailing?>';
		$processor = $this->create_xml_processor( $implementation, $xml );

		$this->assertSame(
			array(
				'token_count'                    => 7,
				'processing_instruction_count'   => 3,
				'xml_declaration_count'          => 1,
				'non_empty_instruction_count'    => 4,
				'total_instruction_bytes'        => 64,
				'max_instruction_bytes'          => 27,
			),
			$this->summarize_xml_processing_instruction_inventory( $processor, $implementation )
		);

		$processor = $this->create_xml_processor( $implementation, $xml );
		$this->assertTrue( $processor->next_tag() );
		$this->assertSame(
			array(
				'token_count'                    => 5,
				'processing_instruction_count'   => 3,
				'xml_declaration_count'          => 0,
				'non_empty_instruction_count'    => 3,
				'total_instruction_bytes'        => 47,
				'max_instruction_bytes'          => 27,
			),
			$this->summarize_xml_processing_instruction_inventory( $processor, $implementation )
		);
	}

	/**
	 * Verifies document-level comment inventories match repeated token scans.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_summarizes_comment_inventory( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$xml       = '<?xml version="1.0"?><root><!-- lead --><item><!-- --></item><empty><!--x--></empty><!--   --></root><!--trailer-->';
		$processor = $this->create_xml_processor( $implementation, $xml );

		$this->assertSame(
			array(
				'token_count'             => 12,
				'comment_count'           => 5,
				'non_empty_comment_count' => 3,
				'empty_comment_count'     => 2,
				'total_comment_bytes'     => 18,
				'max_comment_bytes'       => 7,
			),
			$this->summarize_xml_comment_inventory( $processor, $implementation )
		);

		$processor = $this->create_xml_processor( $implementation, $xml );
		$this->assertTrue( $processor->next_tag() );
		$this->assertSame(
			array(
				'token_count'             => 10,
				'comment_count'           => 5,
				'non_empty_comment_count' => 3,
				'empty_comment_count'     => 2,
				'total_comment_bytes'     => 18,
				'max_comment_bytes'       => 7,
			),
			$this->summarize_xml_comment_inventory( $processor, $implementation )
		);
	}

	/**
	 * Verifies document-level payload inventories match repeated token scans.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_summarizes_payload_inventory( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$xml       = '<?xml version="1.0"?><root>Alpha<!-- note --><item><![CDATA[raw]]><?xml audit data?></item><space>   </space></root><?xml trailing?>';
		$processor = $this->create_xml_processor( $implementation, $xml );

		$this->assertSame(
			array(
				'token_count'                  => 13,
				'text_token_count'             => 2,
				'cdata_count'                  => 1,
				'comment_count'                => 1,
				'processing_instruction_count' => 2,
				'total_payload_bytes'          => 37,
				'max_payload_bytes'            => 11,
			),
			$this->summarize_xml_payload_inventory( $processor, $implementation )
		);

		$processor = $this->create_xml_processor( $implementation, $xml );
		$this->assertTrue( $processor->next_tag() );
		$this->assertSame(
			array(
				'token_count'                  => 11,
				'text_token_count'             => 2,
				'cdata_count'                  => 1,
				'comment_count'                => 1,
				'processing_instruction_count' => 2,
				'total_payload_bytes'          => 37,
				'max_payload_bytes'            => 11,
			),
			$this->summarize_xml_payload_inventory( $processor, $implementation )
		);
	}

	/**
	 * Verifies document-level content inventories match repeated token scans.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_summarizes_content_inventory( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$xml       = '<?xml version="1.0"?><root id="root"><item data-kind="post">Title<!-- note --><![CDATA[raw]]><?xml audit data?></item><empty data-id="x" /></root><?xml trailing?>';
		$processor = $this->create_xml_processor( $implementation, $xml );

		$this->assertSame(
			array(
				'token_count'                  => 11,
				'tag_count'                    => 3,
				'attribute_count'              => 3,
				'text_token_count'             => 1,
				'cdata_count'                  => 1,
				'comment_count'                => 1,
				'processing_instruction_count' => 2,
				'total_attribute_value_bytes'  => 9,
				'max_attribute_value_bytes'    => 4,
				'total_payload_bytes'          => 34,
				'max_payload_bytes'            => 11,
			),
			$this->summarize_xml_content_inventory( $processor, $implementation )
		);
		$this->assertTrue( $processor->is_finished() );

		$processor = $this->create_xml_processor( $implementation, $xml );
		$this->assertTrue( $processor->next_tag() );
		$this->assertSame(
			array(
				'token_count'                  => 9,
				'tag_count'                    => 2,
				'attribute_count'              => 2,
				'text_token_count'             => 1,
				'cdata_count'                  => 1,
				'comment_count'                => 1,
				'processing_instruction_count' => 2,
				'total_attribute_value_bytes'  => 5,
				'max_attribute_value_bytes'    => 4,
				'total_payload_bytes'          => 34,
				'max_payload_bytes'            => 11,
			),
			$this->summarize_xml_content_inventory( $processor, $implementation )
		);
		$this->assertTrue( $processor->is_finished() );
	}

	/**
	 * Verifies importer-facing inventories match repeated token scans.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_summarizes_import_inventory( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$xml       = '<?xml version="1.0"?><root id="root"><item data-kind="post">Title<!-- note --><![CDATA[raw]]><?xml audit data?></item><empty data-id="x" /></root><?xml trailing?>';
		$processor = $this->create_xml_processor( $implementation, $xml );

		$this->assertSame(
			array(
				'token_count'                  => 11,
				'tag_count'                    => 3,
				'closing_tag_count'            => 2,
				'unique_tag_name_count'        => 3,
				'duplicate_tag_name_count'     => 0,
				'namespaced_tag_count'         => 0,
				'empty_element_count'          => 1,
				'root_level_tag_count'         => 1,
				'nested_tag_count'             => 0,
				'total_tag_depth'              => 5,
				'max_depth'                    => 2,
				'leaf_element_count'           => 2,
				'branch_element_count'         => 1,
				'max_child_element_count'      => 2,
				'attribute_count'              => 3,
				'text_token_count'             => 1,
				'cdata_count'                  => 1,
				'comment_count'                => 1,
				'processing_instruction_count' => 2,
				'total_attribute_value_bytes'  => 9,
				'max_attribute_value_bytes'    => 4,
				'total_payload_bytes'          => 34,
				'max_payload_bytes'            => 11,
			),
			$this->summarize_xml_import_inventory( $processor, $implementation )
		);
		$this->assertTrue( $processor->is_finished() );

		$processor = $this->create_xml_processor( $implementation, $xml );
		$this->assertTrue( $processor->next_tag() );
		$this->assertSame(
			array(
				'token_count'                  => 9,
				'tag_count'                    => 2,
				'closing_tag_count'            => 2,
				'unique_tag_name_count'        => 2,
				'duplicate_tag_name_count'     => 0,
				'namespaced_tag_count'         => 0,
				'empty_element_count'          => 1,
				'root_level_tag_count'         => 0,
				'nested_tag_count'             => 0,
				'total_tag_depth'              => 4,
				'max_depth'                    => 2,
				'leaf_element_count'           => 2,
				'branch_element_count'         => 0,
				'max_child_element_count'      => 0,
				'attribute_count'              => 2,
				'text_token_count'             => 1,
				'cdata_count'                  => 1,
				'comment_count'                => 1,
				'processing_instruction_count' => 2,
				'total_attribute_value_bytes'  => 5,
				'max_attribute_value_bytes'    => 4,
				'total_payload_bytes'          => 34,
				'max_payload_bytes'            => 11,
			),
			$this->summarize_xml_import_inventory( $processor, $implementation )
		);
		$this->assertTrue( $processor->is_finished() );
	}

	/**
	 * Verifies chunked tag summaries stay aligned with tag-by-tag reads.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_reads_tag_summary_batches( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$processor = $this->create_xml_processor(
			$implementation,
			'<?xml version="1.0"?><root id="root"><item id="7">Text</item><empty /></root>'
		);
		$rows      = array();

		do {
			$batch = $this->next_xml_tag_summary_batch( $processor, $implementation, 2, 'id' );
			$rows  = array_merge( $rows, $batch );
		} while ( ! empty( $batch ) );

		$this->assertCount( 3, $rows );
		$this->assertSame(
			array( 'root', 'item', 'empty' ),
			array_column( $rows, 'tag_local_name' )
		);
		$this->assertSame(
			array( 'root', '7', null ),
			array_column( $rows, 'id' )
		);
		$this->assertSame( 1, $rows[0]['current_depth'] );
		$this->assertSame( 2, $rows[1]['current_depth'] );
		$this->assertTrue( $rows[2]['is_empty_element'] );
	}

	/**
	 * Verifies matching tag summaries skip non-matching tags while preserving metadata.
	 *
	 * @dataProvider data_xml_processor_implementations
	 *
	 * @param string $implementation Implementation identifier.
	 */
	public function test_xml_processor_reads_matching_tag_summary_batches( $implementation ) {
		$this->skip_if_native_is_unavailable( $implementation );

		$processor = $this->create_xml_processor(
			$implementation,
			'<?xml version="1.0"?><wp:root xmlns:wp="https://wordpress.org"><wp:item id="7"><wp:title>Title</wp:title></wp:item><item id="plain" /><wp:item id="8" /></wp:root>'
		);
		$rows      = array();

		do {
			$batch = $this->next_xml_matching_tag_summary_batch( $processor, $implementation, 1, 'https://wordpress.org', 'item', 'id' );
			$rows  = array_merge( $rows, $batch );
		} while ( ! empty( $batch ) );

		$this->assertCount( 2, $rows );
		$this->assertSame(
			array( 'item', 'item' ),
			array_column( $rows, 'tag_local_name' )
		);
		$this->assertSame(
			array( 'https://wordpress.org', 'https://wordpress.org' ),
			array_column( $rows, 'tag_namespace' )
		);
		$this->assertSame(
			array( '7', '8' ),
			array_column( $rows, 'id' )
		);
		$this->assertSame( 2, $rows[0]['current_depth'] );
		$this->assertSame( 2, $rows[1]['current_depth'] );
		$this->assertFalse( $rows[0]['is_empty_element'] );
		$this->assertTrue( $rows[1]['is_empty_element'] );

		$this->assertSame(
			array(),
			$this->next_xml_matching_tag_summary_batch( $processor, $implementation, 1, 'https://wordpress.org', 'missing', 'id' )
		);

		$processor = $this->create_xml_processor(
			$implementation,
			'<?xml version="1.0"?><wp:root xmlns:wp="https://wordpress.org"><wp:item id="7"><wp:title>Title</wp:title></wp:item><item id="plain" /><wp:item id="8" /></wp:root>'
		);

		$this->assertSame(
			array(
				'token_count'     => 3,
				'tag_count'       => 1,
				'attribute_count' => 1,
			),
			$this->next_xml_matching_tag_count_batch( $processor, $implementation, 1, 'https://wordpress.org', 'item', 'id' )
		);
		$this->assertSame(
			array(
				'token_count'     => 6,
				'tag_count'       => 1,
				'attribute_count' => 1,
			),
			$this->next_xml_matching_tag_count_batch( $processor, $implementation, 1, 'https://wordpress.org', 'item', 'id' )
		);
		$this->assertSame(
			array(
				'token_count'     => 1,
				'tag_count'       => 0,
				'attribute_count' => 0,
			),
			$this->next_xml_matching_tag_count_batch( $processor, $implementation, 1, 'https://wordpress.org', 'item', 'id' )
		);

		$processor = $this->create_xml_processor(
			$implementation,
			'<?xml version="1.0"?><wp:root xmlns:wp="https://wordpress.org"><wp:item id="7"><wp:title>Title</wp:title></wp:item><item id="plain" /><wp:item id="8" /></wp:root>'
		);

		$this->assertSame(
			array(
				'token_count'     => 10,
				'tag_count'       => 2,
				'attribute_count' => 2,
			),
			$this->summarize_xml_matching_tag_stream( $processor, $implementation, 'https://wordpress.org', 'item', 'id' )
		);

		$processor = $this->create_xml_processor(
			$implementation,
			'<?xml version="1.0"?><wp:root xmlns:wp="https://wordpress.org"><wp:item id="7"><wp:title>Title</wp:title></wp:item><item id="plain" /><wp:item id="8" /></wp:root>'
		);
		$this->assertTrue( $processor->next_tag() );
		$this->assertSame(
			array(
				'token_count'     => 8,
				'tag_count'       => 2,
				'attribute_count' => 2,
			),
			$this->summarize_xml_matching_tag_stream( $processor, $implementation, 'https://wordpress.org', 'item', 'id' )
		);

		$processor = $this->create_xml_processor(
			$implementation,
			'<?xml version="1.0"?><wp:root xmlns:wp="https://wordpress.org"><wp:item id="7" slug="first"><wp:title>Title</wp:title></wp:item><item id="plain" slug="skip" /><wp:item id="8" status="draft" /></wp:root>'
		);

		$this->assertSame(
			array(
				'token_count'     => 10,
				'tag_count'       => 2,
				'attribute_count' => 4,
			),
			$this->summarize_xml_matching_tag_attributes_stream(
				$processor,
				$implementation,
				'https://wordpress.org',
				'item',
				array( 'id', 'slug', 'status', 'missing' )
			)
		);
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_xml_processor_implementations() {
		return array(
			'php-xml-processor'    => array( 'php-xml-processor' ),
			'native-xml-processor' => array( 'native-xml-processor' ),
		);
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_xml_without_document_element() {
		return array(
			'php-empty'              => array( '', 'php-xml-processor' ),
			'native-empty'           => array( '', 'native-xml-processor' ),
			'php-whitespace'         => array( " \n\t", 'php-xml-processor' ),
			'native-whitespace'      => array( " \n\t", 'native-xml-processor' ),
			'php-comment-only'       => array( '<!--c-->', 'php-xml-processor' ),
			'native-comment-only'    => array( '<!--c-->', 'native-xml-processor' ),
			'php-declaration-only'   => array( '<?xml version="1.0"?>', 'php-xml-processor' ),
			'native-declaration-only' => array( '<?xml version="1.0"?>', 'native-xml-processor' ),
		);
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_xml_with_leading_misc_whitespace() {
		return array(
			'php-whitespace-root'              => array( " \n\t<root />", array( 'root' ), 'php-xml-processor' ),
			'native-whitespace-root'           => array( " \n\t<root />", array( 'root' ), 'native-xml-processor' ),
			'php-comment-whitespace-root'      => array( "<!--c-->\n<root />", array( '#comment', 'root' ), 'php-xml-processor' ),
			'native-comment-whitespace-root'   => array( "<!--c-->\n<root />", array( '#comment', 'root' ), 'native-xml-processor' ),
			'php-declaration-whitespace-root'  => array( "<?xml version=\"1.0\"?>\n<root />", array( '#xml-declaration', 'root' ), 'php-xml-processor' ),
			'native-declaration-whitespace-root' => array( "<?xml version=\"1.0\"?>\n<root />", array( '#xml-declaration', 'root' ), 'native-xml-processor' ),
		);
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_malformed_attribute_xml_processor_implementations() {
		return array(
			'php-lt-in-attribute-value'       => array( '<root enabled="I love <3 this" />', 'php-xml-processor' ),
			'native-lt-in-attribute-value'    => array( '<root enabled="I love <3 this" />', 'native-xml-processor' ),
			'php-duplicate-attribute'         => array( '<root id="first" id="second" />', 'php-xml-processor' ),
			'native-duplicate-attribute'      => array( '<root id="first" id="second" />', 'native-xml-processor' ),
			'php-empty-prefixed-namespace'    => array( '<root xmlns:a="" />', 'php-xml-processor' ),
			'native-empty-prefixed-namespace' => array( '<root xmlns:a="" />', 'native-xml-processor' ),
		);
	}

	/**
	 * Creates an XML processor for a specific implementation.
	 *
	 * @param string $implementation Implementation identifier.
	 * @param string $xml            XML input.
	 * @return object Processor instance.
	 */
	private function create_xml_processor( $implementation, $xml ) {
		if ( 'native-xml-processor' === $implementation ) {
			$class_name = 'WordPress\\XML\\NativeXMLProcessor';

			return $class_name::create_from_string( $xml );
		}

		$processor = XMLProcessor::create_from_string( $xml );
		$this->disable_native_delegate( $processor );

		return $processor;
	}

	/**
	 * Creates a streaming XML processor for a specific implementation.
	 *
	 * @param string $implementation Implementation identifier.
	 * @param string $xml            XML input.
	 * @return object Processor instance.
	 */
	private function create_xml_streaming_processor( $implementation, $xml ) {
		if ( 'native-xml-processor' === $implementation ) {
			$class_name = 'WordPress\\XML\\NativeXMLProcessor';

			return $class_name::create_for_streaming( $xml, null, 'UTF-8', array() );
		}

		$processor = XMLProcessor::create_for_streaming( $xml );
		$this->disable_native_delegate( $processor );

		return $processor;
	}

	/**
	 * Creates a streaming XML processor from a reentrancy cursor for a specific implementation.
	 *
	 * @param string $implementation Implementation identifier.
	 * @param string $xml            XML input.
	 * @param string $cursor         Reentrancy cursor.
	 * @return object Processor instance.
	 */
	private function create_xml_streaming_processor_from_cursor( $implementation, $xml, $cursor ) {
		if ( 'native-xml-processor' === $implementation ) {
			$class_name = 'WordPress\\XML\\NativeXMLProcessor';

			return $class_name::create_for_streaming( $xml, $cursor, 'UTF-8', array() );
		}

		$processor = XMLProcessor::create_for_streaming( $xml, $cursor );
		$this->disable_native_delegate( $processor );

		return $processor;
	}

	/**
	 * Disables the native delegate so PHP implementation rows still cover PHP.
	 *
	 * @param object $processor Processor instance.
	 */
	private function disable_native_delegate( $processor ) {
		$property = new ReflectionProperty( XMLProcessor::class, 'native_processor' );
		$property->setAccessible( true );
		$property->setValue( $processor, null );
	}

	/**
	 * Returns the native delegate for public-class default checks.
	 *
	 * @param object $processor Processor instance.
	 * @return object|null Native delegate.
	 */
	private function get_native_delegate( $processor ) {
		$property = new ReflectionProperty( XMLProcessor::class, 'native_processor' );
		$property->setAccessible( true );

		return $property->getValue( $processor );
	}

	/**
	 * Runs a fresh PHP process that defines native-default constants before bootstrap.
	 *
	 * @param string $constant_definitions PHP source defining constants.
	 * @return string[] Probe output lines.
	 */
	private function run_xml_native_defaults_constant_probe( $constant_definitions ) {
		$root      = dirname( __DIR__, 3 );
		$extension = $root . '/extensions/native-apis/target/release/libwp_native_apis.so';

		if ( ! file_exists( $extension ) ) {
			$this->markTestSkipped( 'Native API extension binary is not built.' );
		}

		$code = $constant_definitions . "\n" .
			'require ' . var_export( $root . '/bootstrap.php', true ) . ";\n" .
			'$class_name = \'WordPress\\\\XML\\\\XMLProcessor\';' . "\n" .
			'$property = new ReflectionProperty( $class_name, \'native_processor\' );' . "\n" .
			'$property->setAccessible( true );' . "\n" .
			'$processor = $class_name::create_from_string( \'<root><item id="1" /></root>\' );' . "\n" .
			'$delegate = $property->getValue( $processor );' . "\n" .
			'echo \'delegate:\' . ( null === $delegate ? \'php\' : get_class( $delegate ) ) . "\n";' . "\n" .
			'$processor->next_tag( \'item\' );' . "\n" .
			'echo \'token:\' . $processor->get_token_name() . "\n";' . "\n" .
			'echo \'id:\' . $processor->get_attribute( \'\', \'id\' ) . "\n";';

		$command = escapeshellarg( PHP_BINARY ) . ' -d extension=' . escapeshellarg( $extension ) . ' -r ' . escapeshellarg( $code );
		$output  = array();
		$status  = 0;
		exec( $command . ' 2>&1', $output, $status );

		$this->assertSame( 0, $status, implode( "\n", $output ) );

		return $output;
	}

	/**
	 * Reads an XML attribute through the implementation-specific signature.
	 *
	 * @param object $processor      Processor instance.
	 * @param string $implementation Implementation identifier.
	 * @param string $local_name     Attribute local name.
	 * @return string|true|null Attribute value.
	 */
	private function get_xml_attribute( $processor, $implementation, $local_name, $namespace = '' ) {
		return $processor->get_attribute( $namespace, $local_name );
	}

	/**
	 * Runs the implementation-specific XML attribute-prefix summary API.
	 *
	 * @param object      $processor             Processor instance.
	 * @param string      $implementation        Implementation identifier.
	 * @param string|null $full_namespace_prefix Namespace prefix to match.
	 * @param string      $local_name_prefix     Local name prefix to match.
	 * @return array Summary with `tag_count` and `attribute_count`.
	 */
	private function summarize_xml_attribute_names_with_prefix( $processor, $implementation, $full_namespace_prefix, $local_name_prefix ) {
		$summary = $processor->summarize_attribute_names_with_prefix( $full_namespace_prefix, $local_name_prefix );

		if ( 'native-xml-processor' !== $implementation ) {
			return $summary;
		}

		$parts = explode( "\x1f", $summary, 3 );

		return array(
			'tag_count'       => (int) $parts[1],
			'attribute_count' => (int) $parts[2],
		);
	}

	/**
	 * Runs the implementation-specific XML token stream summary API.
	 *
	 * @param object $processor      Processor instance.
	 * @param string $implementation Implementation identifier.
	 * @param string $attribute_name Attribute name to count.
	 * @return array Summary with `token_count`, `tag_count`, and `attribute_count`.
	 */
	private function summarize_xml_token_stream( $processor, $implementation, $attribute_name ) {
		$summary = $processor->summarize_token_stream( $attribute_name );

		if ( 'native-xml-processor' !== $implementation ) {
			return $summary;
		}

		$parts = explode( "\x1f", $summary, 3 );

		return array(
			'token_count'     => (int) $parts[0],
			'tag_count'       => (int) $parts[1],
			'attribute_count' => (int) $parts[2],
		);
	}

	/**
	 * Runs the implementation-specific XML document inventory summary API.
	 *
	 * @param object $processor      Processor instance.
	 * @param string $implementation Implementation identifier.
	 * @return array Summary with token category counts and maximum depth.
	 */
	private function summarize_xml_document_inventory( $processor, $implementation ) {
		$summary = $processor->summarize_document_inventory();

		if ( 'native-xml-processor' !== $implementation ) {
			return $summary;
		}

		$parts = explode( "\x1f", $summary, 8 );
		$this->assertCount( 8, $parts, 'Expected compact XML document inventory summary.' );

		return array(
			'token_count'         => (int) $parts[0],
			'tag_count'           => (int) $parts[1],
			'closing_tag_count'   => (int) $parts[2],
			'text_token_count'    => (int) $parts[3],
			'comment_count'       => (int) $parts[4],
			'cdata_count'         => (int) $parts[5],
			'max_depth'           => (int) $parts[6],
			'empty_element_count' => (int) $parts[7],
		);
	}

	/**
	 * Runs the implementation-specific XML element inventory summary API.
	 *
	 * @param object $processor      Processor instance.
	 * @param string $implementation Implementation identifier.
	 * @return array Summary with element-name inventory counts.
	 */
	private function summarize_xml_element_inventory( $processor, $implementation ) {
		$summary = $processor->summarize_element_inventory();

		if ( 'native-xml-processor' !== $implementation ) {
			return $summary;
		}

		$parts = explode( "\x1f", $summary, 7 );
		$this->assertCount( 7, $parts, 'Expected compact XML element inventory summary.' );

		return array(
			'token_count'              => (int) $parts[0],
			'tag_count'                => (int) $parts[1],
			'closing_tag_count'        => (int) $parts[2],
			'unique_tag_name_count'    => (int) $parts[3],
			'duplicate_tag_name_count' => (int) $parts[4],
			'namespaced_tag_count'     => (int) $parts[5],
			'empty_element_count'      => (int) $parts[6],
		);
	}

	/**
	 * Runs the implementation-specific XML depth inventory summary API.
	 *
	 * @param object $processor      Processor instance.
	 * @param string $implementation Implementation identifier.
	 * @return array Summary with depth distribution counts.
	 */
	private function summarize_xml_depth_inventory( $processor, $implementation ) {
		$summary = $processor->summarize_depth_inventory();

		if ( 'native-xml-processor' !== $implementation ) {
			return $summary;
		}

		$parts = explode( "\x1f", $summary, 8 );
		$this->assertCount( 8, $parts, 'Expected compact XML depth inventory summary.' );

		return array(
			'token_count'          => (int) $parts[0],
			'tag_count'            => (int) $parts[1],
			'closing_tag_count'    => (int) $parts[2],
			'empty_element_count'  => (int) $parts[3],
			'root_level_tag_count' => (int) $parts[4],
			'nested_tag_count'     => (int) $parts[5],
			'total_tag_depth'      => (int) $parts[6],
			'max_depth'            => (int) $parts[7],
		);
	}

	/**
	 * Runs the implementation-specific XML leaf inventory summary API.
	 *
	 * @param object $processor      Processor instance.
	 * @param string $implementation Implementation identifier.
	 * @return array Summary with leaf and branch element counts.
	 */
	private function summarize_xml_leaf_inventory( $processor, $implementation ) {
		$summary = $processor->summarize_leaf_inventory();

		if ( 'native-xml-processor' !== $implementation ) {
			return $summary;
		}

		$parts = explode( "\x1f", $summary, 7 );
		$this->assertCount( 7, $parts, 'Expected compact XML leaf inventory summary.' );

		return array(
			'token_count'             => (int) $parts[0],
			'tag_count'               => (int) $parts[1],
			'closing_tag_count'       => (int) $parts[2],
			'empty_element_count'     => (int) $parts[3],
			'leaf_element_count'      => (int) $parts[4],
			'branch_element_count'    => (int) $parts[5],
			'max_child_element_count' => (int) $parts[6],
		);
	}

	/**
	 * Runs the implementation-specific XML structural inventory summary API.
	 *
	 * @param object $processor      Processor instance.
	 * @param string $implementation Implementation identifier.
	 * @return array Summary with structural element counts.
	 */
	private function summarize_xml_structural_inventory( $processor, $implementation ) {
		$summary = $processor->summarize_structural_inventory();

		if ( 'native-xml-processor' !== $implementation ) {
			return $summary;
		}

		$parts = explode( "\x1f", $summary, 14 );
		$this->assertCount( 14, $parts, 'Expected compact XML structural inventory summary.' );

		return array(
			'token_count'              => (int) $parts[0],
			'tag_count'                => (int) $parts[1],
			'closing_tag_count'        => (int) $parts[2],
			'unique_tag_name_count'    => (int) $parts[3],
			'duplicate_tag_name_count' => (int) $parts[4],
			'namespaced_tag_count'     => (int) $parts[5],
			'empty_element_count'      => (int) $parts[6],
			'root_level_tag_count'     => (int) $parts[7],
			'nested_tag_count'         => (int) $parts[8],
			'total_tag_depth'          => (int) $parts[9],
			'max_depth'                => (int) $parts[10],
			'leaf_element_count'       => (int) $parts[11],
			'branch_element_count'     => (int) $parts[12],
			'max_child_element_count'  => (int) $parts[13],
		);
	}

	/**
	 * Runs the implementation-specific XML attribute inventory summary API.
	 *
	 * @param object $processor      Processor instance.
	 * @param string $implementation Implementation identifier.
	 * @return array Summary with attribute inventory counts.
	 */
	private function summarize_xml_attribute_inventory( $processor, $implementation ) {
		$summary = $processor->summarize_attribute_inventory();

		if ( 'native-xml-processor' !== $implementation ) {
			return $summary;
		}

		$parts = explode( "\x1f", $summary, 6 );
		$this->assertCount( 6, $parts, 'Expected compact XML attribute inventory summary.' );

		return array(
			'token_count'                  => (int) $parts[0],
			'tag_count'                    => (int) $parts[1],
			'attribute_count'              => (int) $parts[2],
			'namespaced_attribute_count'   => (int) $parts[3],
			'tags_with_attributes_count'   => (int) $parts[4],
			'max_attribute_count'          => (int) $parts[5],
		);
	}

	/**
	 * Runs the implementation-specific XML ID inventory summary API.
	 *
	 * @param object $processor      Processor instance.
	 * @param string $implementation Implementation identifier.
	 * @return array Summary with ID inventory counts.
	 */
	private function summarize_xml_id_inventory( $processor, $implementation ) {
		$summary = $processor->summarize_id_inventory();

		if ( 'native-xml-processor' !== $implementation ) {
			return $summary;
		}

		$parts = explode( "\x1f", $summary, 6 );
		$this->assertCount( 6, $parts, 'Expected compact XML ID inventory summary.' );

		return array(
			'token_count'        => (int) $parts[0],
			'tag_count'          => (int) $parts[1],
			'id_attribute_count' => (int) $parts[2],
			'unique_id_count'    => (int) $parts[3],
			'duplicate_id_count' => (int) $parts[4],
			'id_value_bytes'     => (int) $parts[5],
		);
	}

	/**
	 * Runs the implementation-specific XML namespace inventory summary API.
	 *
	 * @param object $processor      Processor instance.
	 * @param string $implementation Implementation identifier.
	 * @return array Summary with namespace inventory counts.
	 */
	private function summarize_xml_namespace_inventory( $processor, $implementation ) {
		$summary = $processor->summarize_namespace_inventory();

		if ( 'native-xml-processor' !== $implementation ) {
			return $summary;
		}

		$parts = explode( "\x1f", $summary, 6 );
		$this->assertCount( 6, $parts, 'Expected compact XML namespace inventory summary.' );

		return array(
			'token_count'                  => (int) $parts[0],
			'tag_count'                    => (int) $parts[1],
			'namespaced_tag_count'         => (int) $parts[2],
			'attribute_count'              => (int) $parts[3],
			'namespaced_attribute_count'   => (int) $parts[4],
			'unique_namespace_count'       => (int) $parts[5],
		);
	}

	/**
	 * Runs the implementation-specific XML text inventory summary API.
	 *
	 * @param object $processor      Processor instance.
	 * @param string $implementation Implementation identifier.
	 * @return array Summary with text and CDATA inventory counts.
	 */
	private function summarize_xml_text_inventory( $processor, $implementation ) {
		$summary = $processor->summarize_text_inventory();

		if ( 'native-xml-processor' !== $implementation ) {
			return $summary;
		}

		$parts = explode( "\x1f", $summary, 7 );
		$this->assertCount( 7, $parts, 'Expected compact XML text inventory summary.' );

		return array(
			'token_count'           => (int) $parts[0],
			'text_token_count'      => (int) $parts[1],
			'cdata_count'           => (int) $parts[2],
			'non_empty_text_count'  => (int) $parts[3],
			'whitespace_text_count' => (int) $parts[4],
			'total_text_bytes'      => (int) $parts[5],
			'max_text_bytes'        => (int) $parts[6],
		);
	}

	/**
	 * Runs the implementation-specific XML processing instruction inventory API.
	 *
	 * @param object $processor      Processor instance.
	 * @param string $implementation Implementation identifier.
	 * @return array Summary with XML declaration and processing instruction counts.
	 */
	private function summarize_xml_processing_instruction_inventory( $processor, $implementation ) {
		$summary = $processor->summarize_processing_instruction_inventory();

		if ( 'native-xml-processor' !== $implementation ) {
			return $summary;
		}

		$parts = explode( "\x1f", $summary, 6 );
		$this->assertCount( 6, $parts, 'Expected compact XML processing instruction inventory summary.' );

		return array(
			'token_count'                    => (int) $parts[0],
			'processing_instruction_count'   => (int) $parts[1],
			'xml_declaration_count'          => (int) $parts[2],
			'non_empty_instruction_count'    => (int) $parts[3],
			'total_instruction_bytes'        => (int) $parts[4],
			'max_instruction_bytes'          => (int) $parts[5],
		);
	}

	/**
	 * Runs the implementation-specific XML comment inventory API.
	 *
	 * @param object $processor      Processor instance.
	 * @param string $implementation Implementation identifier.
	 * @return array Summary with XML comment counts and byte totals.
	 */
	private function summarize_xml_comment_inventory( $processor, $implementation ) {
		$summary = $processor->summarize_comment_inventory();

		if ( 'native-xml-processor' !== $implementation ) {
			return $summary;
		}

		$parts = explode( "\x1f", $summary, 6 );
		$this->assertCount( 6, $parts, 'Expected compact XML comment inventory summary.' );

		return array(
			'token_count'             => (int) $parts[0],
			'comment_count'           => (int) $parts[1],
			'non_empty_comment_count' => (int) $parts[2],
			'empty_comment_count'     => (int) $parts[3],
			'total_comment_bytes'     => (int) $parts[4],
			'max_comment_bytes'       => (int) $parts[5],
		);
	}

	/**
	 * Runs the implementation-specific XML payload inventory API.
	 *
	 * @param object $processor      Processor instance.
	 * @param string $implementation Implementation identifier.
	 * @return array Summary with XML payload counts and byte totals.
	 */
	private function summarize_xml_payload_inventory( $processor, $implementation ) {
		$summary = $processor->summarize_payload_inventory();

		if ( 'native-xml-processor' !== $implementation ) {
			return $summary;
		}

		$parts = explode( "\x1f", $summary, 7 );
		$this->assertCount( 7, $parts, 'Expected compact XML payload inventory summary.' );

		return array(
			'token_count'                  => (int) $parts[0],
			'text_token_count'             => (int) $parts[1],
			'cdata_count'                  => (int) $parts[2],
			'comment_count'                => (int) $parts[3],
			'processing_instruction_count' => (int) $parts[4],
			'total_payload_bytes'          => (int) $parts[5],
			'max_payload_bytes'            => (int) $parts[6],
		);
	}

	/**
	 * Runs the implementation-specific XML content inventory API.
	 *
	 * @param object $processor      Processor instance.
	 * @param string $implementation Implementation identifier.
	 * @return array Summary with XML content and metadata byte totals.
	 */
	private function summarize_xml_content_inventory( $processor, $implementation ) {
		$summary = $processor->summarize_content_inventory();

		if ( 'native-xml-processor' !== $implementation ) {
			return $summary;
		}

		$parts = explode( "\x1f", $summary, 11 );
		$this->assertCount( 11, $parts, 'Expected compact XML content inventory summary.' );

		return array(
			'token_count'                  => (int) $parts[0],
			'tag_count'                    => (int) $parts[1],
			'attribute_count'              => (int) $parts[2],
			'text_token_count'             => (int) $parts[3],
			'cdata_count'                  => (int) $parts[4],
			'comment_count'                => (int) $parts[5],
			'processing_instruction_count' => (int) $parts[6],
			'total_attribute_value_bytes'  => (int) $parts[7],
			'max_attribute_value_bytes'    => (int) $parts[8],
			'total_payload_bytes'          => (int) $parts[9],
			'max_payload_bytes'            => (int) $parts[10],
		);
	}

	/**
	 * Runs the implementation-specific XML import inventory API.
	 *
	 * @param object $processor      Processor instance.
	 * @param string $implementation Implementation identifier.
	 * @return array Summary with importer-facing XML structure and content counts.
	 */
	private function summarize_xml_import_inventory( $processor, $implementation ) {
		$summary = $processor->summarize_import_inventory();

		if ( 'native-xml-processor' !== $implementation ) {
			return $summary;
		}

		$parts = explode( "\x1f", $summary, 23 );
		$this->assertCount( 23, $parts, 'Expected compact XML import inventory summary.' );

		return array(
			'token_count'                  => (int) $parts[0],
			'tag_count'                    => (int) $parts[1],
			'closing_tag_count'            => (int) $parts[2],
			'unique_tag_name_count'        => (int) $parts[3],
			'duplicate_tag_name_count'     => (int) $parts[4],
			'namespaced_tag_count'         => (int) $parts[5],
			'empty_element_count'          => (int) $parts[6],
			'root_level_tag_count'         => (int) $parts[7],
			'nested_tag_count'             => (int) $parts[8],
			'total_tag_depth'              => (int) $parts[9],
			'max_depth'                    => (int) $parts[10],
			'leaf_element_count'           => (int) $parts[11],
			'branch_element_count'         => (int) $parts[12],
			'max_child_element_count'      => (int) $parts[13],
			'attribute_count'              => (int) $parts[14],
			'text_token_count'             => (int) $parts[15],
			'cdata_count'                  => (int) $parts[16],
			'comment_count'                => (int) $parts[17],
			'processing_instruction_count' => (int) $parts[18],
			'total_attribute_value_bytes'  => (int) $parts[19],
			'max_attribute_value_bytes'    => (int) $parts[20],
			'total_payload_bytes'          => (int) $parts[21],
			'max_payload_bytes'            => (int) $parts[22],
		);
	}

	/**
	 * Runs the implementation-specific XML tag stream summary API.
	 *
	 * @param object $processor      Processor instance.
	 * @param string $implementation Implementation identifier.
	 * @param string $attribute_name Attribute name to count.
	 * @return array Summary with `tag_count` and `attribute_count`.
	 */
	private function summarize_xml_tag_stream( $processor, $implementation, $attribute_name ) {
		$summary = $processor->summarize_tag_stream( $attribute_name );

		if ( 'native-xml-processor' !== $implementation ) {
			return $summary;
		}

		$parts = explode( "\x1f", $summary, 3 );

		return array(
			'tag_count'       => (int) $parts[1],
			'attribute_count' => (int) $parts[2],
		);
	}

	/**
	 * Runs the implementation-specific XML token summary batch API.
	 *
	 * @param object $processor      Processor instance.
	 * @param string $implementation Implementation identifier.
	 * @param int    $max_tokens     Maximum number of tokens to fetch.
	 * @return array[] Token summary rows.
	 */
	private function next_xml_token_summary_batch( $processor, $implementation, $max_tokens ) {
		return $processor->next_token_summary_batch( $max_tokens );
	}

	/**
	 * Runs the implementation-specific XML tag summary batch API.
	 *
	 * @param object $processor      Processor instance.
	 * @param string $implementation Implementation identifier.
	 * @param int    $max_tags       Maximum number of tags to fetch.
	 * @param string $attribute_name Attribute name to include.
	 * @return array[] Tag summary rows.
	 */
	private function next_xml_tag_summary_batch( $processor, $implementation, $max_tags, $attribute_name ) {
		return $processor->next_tag_summary_batch( $max_tags, $attribute_name );
	}

	/**
	 * Runs the implementation-specific XML matching tag summary batch API.
	 *
	 * @param object $processor      Processor instance.
	 * @param string $implementation Implementation identifier.
	 * @param int    $max_tags       Maximum number of matching tags to fetch.
	 * @param string $tag_namespace  Namespace URI to match.
	 * @param string $tag_local_name Local tag name to match.
	 * @param string $attribute_name Attribute name to include.
	 * @return array[] Tag summary rows.
	 */
	private function next_xml_matching_tag_summary_batch( $processor, $implementation, $max_tags, $tag_namespace, $tag_local_name, $attribute_name ) {
		return $processor->next_matching_tag_summary_batch( $max_tags, $tag_namespace, $tag_local_name, $attribute_name );
	}

	/**
	 * Runs the implementation-specific XML matching tag count batch API.
	 *
	 * @param object $processor      Processor instance.
	 * @param string $implementation Implementation identifier.
	 * @param int    $max_tags       Maximum number of matching tags to count.
	 * @param string $tag_namespace  Namespace URI to match.
	 * @param string $tag_local_name Local tag name to match.
	 * @param string $attribute_name Attribute name to count.
	 * @return array Summary with `token_count`, `tag_count`, and `attribute_count`.
	 */
	private function next_xml_matching_tag_count_batch( $processor, $implementation, $max_tags, $tag_namespace, $tag_local_name, $attribute_name ) {
		if ( 'native-xml-processor' !== $implementation ) {
			return $processor->next_matching_tag_count_batch( $max_tags, $tag_namespace, $tag_local_name, $attribute_name );
		}

		$summary = $processor->next_matching_tag_count_batch( $max_tags, $tag_namespace, $tag_local_name, $attribute_name );
		if ( ! is_string( $summary ) ) {
			return array(
				'token_count'     => 0,
				'tag_count'       => 0,
				'attribute_count' => 0,
			);
		}

		$parts = explode( "\x1f", $summary, 3 );
		$this->assertCount( 3, $parts, 'Expected compact XML matching tag count batch summary.' );

		return array(
			'token_count'     => (int) $parts[0],
			'tag_count'       => (int) $parts[1],
			'attribute_count' => (int) $parts[2],
		);
	}

	/**
	 * Runs the implementation-specific XML matching tag summary API.
	 *
	 * @param object $processor      Processor instance.
	 * @param string $implementation Implementation identifier.
	 * @param string $tag_namespace  Namespace URI to match.
	 * @param string $tag_local_name Local tag name to match.
	 * @param string $attribute_name Attribute name to count.
	 * @return array Summary with `token_count`, `tag_count`, and `attribute_count`.
	 */
	private function summarize_xml_matching_tag_stream( $processor, $implementation, $tag_namespace, $tag_local_name, $attribute_name ) {
		if ( 'native-xml-processor' !== $implementation ) {
			return $processor->summarize_matching_tag_stream( $tag_namespace, $tag_local_name, $attribute_name );
		}

		$summary = $processor->summarize_matching_tag_stream( $tag_namespace, $tag_local_name, $attribute_name );
		$this->assertIsString( $summary );

		$parts = explode( "\x1f", $summary, 3 );
		$this->assertCount( 3, $parts, 'Expected compact XML matching tag stream summary.' );

		return array(
			'token_count'     => (int) $parts[0],
			'tag_count'       => (int) $parts[1],
			'attribute_count' => (int) $parts[2],
		);
	}

	/**
	 * Runs the implementation-specific XML matching tag attributes summary API.
	 *
	 * @param object $processor       Processor instance.
	 * @param string $implementation  Implementation identifier.
	 * @param string $tag_namespace   Namespace URI to match.
	 * @param string $tag_local_name  Local tag name to match.
	 * @param array  $attribute_names Attribute names to count.
	 * @return array Summary with `token_count`, `tag_count`, and `attribute_count`.
	 */
	private function summarize_xml_matching_tag_attributes_stream( $processor, $implementation, $tag_namespace, $tag_local_name, $attribute_names ) {
		if ( 'native-xml-processor' !== $implementation ) {
			return $processor->summarize_matching_tag_attributes_stream( $tag_namespace, $tag_local_name, $attribute_names );
		}

		$summary = $processor->summarize_matching_tag_attributes_stream(
			$tag_namespace,
			$tag_local_name,
			implode( "\x1f", $attribute_names )
		);
		$this->assertIsString( $summary );

		$parts = explode( "\x1f", $summary, 3 );
		$this->assertCount( 3, $parts, 'Expected compact XML matching tag attributes stream summary.' );

		return array(
			'token_count'     => (int) $parts[0],
			'tag_count'       => (int) $parts[1],
			'attribute_count' => (int) $parts[2],
		);
	}

	/**
	 * Runs the implementation-specific XML tag count batch API.
	 *
	 * @param object $processor      Processor instance.
	 * @param string $implementation Implementation identifier.
	 * @param int    $max_tags       Maximum number of tags to count.
	 * @param string $attribute_name Attribute name to count.
	 * @return array Summary with `token_count`, `tag_count`, and `attribute_count`.
	 */
	private function next_xml_tag_count_batch( $processor, $implementation, $max_tags, $attribute_name ) {
		if ( 'native-xml-processor' !== $implementation ) {
			return $processor->next_tag_count_batch( $max_tags, $attribute_name );
		}

		$summary = $processor->next_tag_count_batch( $max_tags, $attribute_name );
		if ( ! is_string( $summary ) ) {
			return array(
				'token_count'     => 0,
				'tag_count'       => 0,
				'attribute_count' => 0,
			);
		}

		$parts = explode( "\x1f", $summary, 3 );
		$this->assertCount( 3, $parts, 'Expected compact XML tag count batch summary.' );

		return array(
			'token_count'     => (int) $parts[0],
			'tag_count'       => (int) $parts[1],
			'attribute_count' => (int) $parts[2],
		);
	}

	/**
	 * Runs a streaming XML batch case.
	 *
	 * @param object $processor      Processor instance.
	 * @param string $implementation Implementation identifier.
	 * @param string $case           Batch case identifier.
	 * @return mixed Batch result.
	 */
	private function run_xml_streaming_batch_case( $processor, $implementation, $case ) {
		switch ( $case ) {
			case 'token_compact':
				return $processor->next_token_compact_summary_batch( 10 );

			case 'tag_compact':
				return $processor->next_tag_compact_summary_batch( 10, 'id' );

			case 'tag_count':
				return $this->next_xml_tag_count_batch( $processor, $implementation, 10, 'id' );

			case 'matching_tag_compact':
				return $processor->next_matching_tag_compact_summary_batch( 10, '', 'item', 'id' );

			case 'matching_tag_count':
				return $this->next_xml_matching_tag_count_batch( $processor, $implementation, 10, '', 'item', 'id' );
		}

		$this->fail( 'Unknown XML streaming batch case: ' . $case );
	}

	/**
	 * Parses a native compact XML token summary row.
	 *
	 * @param string $metadata Native compact metadata row.
	 * @return array Token summary row.
	 */
	private function parse_native_compact_token_summary( $metadata ) {
		$parts = explode( "\x1f", $metadata, 6 );
		$this->assertCount( 6, $parts, 'Expected compact XML token summary row.' );

		switch ( $parts[0] ) {
			case 't':
				$token_type = '#tag';
				$token_name = $parts[1];
				break;
			case 'x':
				$token_type = '#xml-declaration';
				$token_name = '#xml-declaration';
				break;
			case 'd':
				$token_type = '#doctype';
				$token_name = '#doctype';
				break;
			case 'p':
				$token_type = '#processing-instructions';
				$token_name = '#processing-instructions';
				break;
			case 'c':
				$token_type = '#comment';
				$token_name = '#comment';
				break;
			case 'a':
				$token_type = '#cdata-section';
				$token_name = '#cdata-section';
				break;
			default:
				$token_type = '#text';
				$token_name = '#text';
				break;
		}

		$id_found = isset( $parts[5][0] ) && '1' === $parts[5][0];

		return array(
			'token_type'                   => $token_type,
			'token_name'                   => $token_name,
			'tag_local_name'               => '#tag' === $token_type ? $parts[1] : '',
			'tag_namespace'                => '#tag' === $token_type ? $parts[2] : '',
			'tag_namespace_and_local_name' => '#tag' === $token_type && '' !== $parts[2] ? '{' . $parts[2] . '}' . $parts[1] : ( '#tag' === $token_type ? $parts[1] : '' ),
			'is_tag_closer'                => isset( $parts[3][0] ) && '1' === $parts[3][0],
			'is_empty_element'             => isset( $parts[3][1] ) && '1' === $parts[3][1],
			'current_depth'                => (int) $parts[4],
			'id'                           => $id_found ? substr( $parts[5], 1 ) : null,
		);
	}

	/**
	 * Parses a native compact XML tag summary row.
	 *
	 * @param string $metadata       Native compact metadata row.
	 * @param string $attribute_name Attribute name included in the row.
	 * @return array Tag summary row.
	 */
	private function parse_native_compact_tag_summary( $metadata, $attribute_name ) {
		$parts = explode( "\x1f", $metadata, 6 );
		$this->assertCount( 6, $parts, 'Expected compact XML tag summary row.' );
		$this->assertGreaterThan( 0, (int) $parts[0], 'Expected compact XML tag summary to include consumed token count.' );

		$attribute_found = isset( $parts[5][0] ) && '1' === $parts[5][0];

		return array(
			'tag_local_name'               => $parts[1],
			'tag_namespace'                => $parts[2],
			'tag_namespace_and_local_name' => '' === $parts[2] ? $parts[1] : '{' . $parts[2] . '}' . $parts[1],
			'is_empty_element'             => isset( $parts[3][0] ) && '1' === $parts[3][0],
			'current_depth'                => (int) $parts[4],
			$attribute_name                => $attribute_found ? substr( $parts[5], 1 ) : null,
		);
	}

	/**
	 * Runs the implementation-specific XML attribute-prefix document removal API.
	 *
	 * @param object      $processor             Processor instance.
	 * @param string      $implementation        Implementation identifier.
	 * @param string|null $full_namespace_prefix Namespace prefix to match.
	 * @param string      $local_name_prefix     Local name prefix to match.
	 * @return array Summary with `tag_count`, `removed_count`, and `xml`.
	 */
	private function remove_xml_attribute_names_with_prefix_from_document( $processor, $implementation, $full_namespace_prefix, $local_name_prefix ) {
		$summary = $processor->remove_attributes_with_prefix_from_document( $full_namespace_prefix, $local_name_prefix );

		if ( 'native-xml-processor' !== $implementation ) {
			return $summary;
		}

		$parts = explode( "\x1f", $summary, 3 );

		return array(
			'tag_count'     => (int) $parts[0],
			'removed_count' => (int) $parts[1],
			'xml'           => $parts[2],
		);
	}

	/**
	 * Skips native implementation cases when the extension is unavailable.
	 *
	 * @param string $implementation Implementation identifier.
	 */
	private function skip_if_native_is_unavailable( $implementation ) {
		if ( 'native-xml-processor' === $implementation && ! class_exists( 'WordPress\\XML\\NativeXMLProcessor', false ) ) {
			$this->markTestSkipped( 'WordPress\\XML\\NativeXMLProcessor is not registered; load the native API extension to run this case.' );
		}
	}
}
