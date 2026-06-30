<?php

use PHPUnit\Framework\TestCase;
use WordPress\DataLiberation\DataLiberationHTMLProcessor;
use WordPress\DataLiberation\Importer\ImportUtils;

class NativeAwareDataLiberationHTMLProcessor extends DataLiberationHTMLProcessor {
	public function is_native_processor_active() {
		return $this->has_native_processor();
	}
}

class DataLiberationHTMLProcessorTest extends TestCase {

	public function test_native_processor_remains_available_for_inherited_html_cursor_methods() {
		if ( ! class_exists( 'WP_HTML_Native_Processor', false ) ) {
			$this->markTestSkipped( 'The native HTML processor is not loaded.' );
		}

		$processor = NativeAwareDataLiberationHTMLProcessor::create_fragment(
			'<h1>Title</h1><p>Body</p>'
		);

		$this->assertTrue( $processor->is_native_processor_active() );
		$this->assertTrue( $processor->next_tag( 'H1' ) );
		$this->assertSame( 'Title', $processor->get_inner_html() );
		$this->assertTrue( $processor->is_native_processor_active() );
		$this->assertTrue( $processor->next_tag( 'P' ) );
		$this->assertSame( 'P', $processor->get_tag() );
	}

	public function test_import_h1_removal_keeps_native_progressive_upgrade() {
		if ( ! class_exists( 'WP_HTML_Native_Processor', false ) ) {
			$this->markTestSkipped( 'The native HTML processor is not loaded.' );
		}

		$result = ImportUtils::remove_first_h1_block_from_block_markup(
			'<h1>Native Title</h1><!-- wp:paragraph --><p>Body</p>'
		);

		$this->assertSame(
			array(
				'h1_content'     => 'Native Title',
				'remaining_html' => '<p>Body</p>',
			),
			$result
		);
	}
}
