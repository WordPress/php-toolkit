<?php

use PHPUnit\Framework\TestCase;
use WordPress\DataLiberation\DataFormatConsumer\MarkupProcessorConsumer;
use WordPress\DataLiberation\EntityReader\HTMLEntityReader;

class HTMLEntityReaderTest extends TestCase {

	public function test_entity_reader() {
		$html             = <<<HTML
<meta name=post_title content="WordPress 6.8 was released">
<meta name=post_date content="2024-12-16">
<meta name=custom_post_meta content="custom_post_meta_value">
<meta name=color_palette content="use_that_pretty_one">
<h1>It is our pleasure to announce that WordPress 6.8 was released</h1>
<p>Last week, WordPress 6.8 was released.</p>
HTML;
		$html_processor   = WP_HTML_Processor::create_fragment( $html );
		$producer         = new MarkupProcessorConsumer( $html_processor );
		$blocks_with_meta = $producer->consume();

		$reader   = new HTMLEntityReader( $blocks_with_meta, 1 );
		$entities = array();
		while ( $reader->next_entity() ) {
			$data = $reader->get_entity()->get_data();
			if ( isset( $data['post_content'] ) ) {
				$data['post_content'] = $this->normalize_markup( $data['post_content'] );
			}
			$entities[] = array(
				'type' => $reader->get_entity()->get_type(),
				'data' => $data,
			);
		}
		$expected_entities = array(
			array(
				'type' => 'post',
				'data' => array(
					'post_title' => 'WordPress 6.8 was released',
					'post_date' => '2024-12-16',
					'post_id' => 1,
					'post_content' => $this->normalize_markup(
						<<<HTML
<!-- wp:heading {"level":1} -->
<h1>It is our pleasure to announce that WordPress 6.8 was released </h1><!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Last week, WordPress 6.8 was released. </p><!-- /wp:paragraph -->

HTML
					),
				),
			),
			array(
				'type' => 'post_meta',
				'data' => array(
					'post_id' => 1,
					'meta_key' => 'custom_post_meta',
					'meta_value' => 'custom_post_meta_value',
				),
			),
			array(
				'type' => 'post_meta',
				'data' => array(
					'post_id' => 1,
					'meta_key' => 'color_palette',
					'meta_value' => 'use_that_pretty_one',
				),
			),
		);
		$this->assertEquals( $expected_entities, $entities );
	}

	private function normalize_markup( $markup ) {
		$processor  = WP_HTML_Processor::create_fragment( $markup );
		$serialized = $processor->serialize();
		return $serialized;
	}
}
