<?php

use PHPUnit\Framework\TestCase;
use WordPress\DataLiberation\EntityReader\FilesystemEntityReader;
use WordPress\Filesystem\LocalFilesystem;

class FilesystemEntityReaderTest extends TestCase {

	public function test_with_create_index_pages_true() {
		$reader   = new FilesystemEntityReader(
			LocalFilesystem::create( __DIR__ . '/fixtures/filesystem-entity-reader' ),
			array(
				'first_post_id' => 2,
				'create_index_pages' => true,
				'filter_pattern' => '#\.html$#',
				'index_file_pattern' => '#root.html#',
			)
		);
		$entities = array();
		while ( $reader->next_entity() ) {
			$entities[] = $reader->get_entity();
		}
		$this->assertCount( 6, $entities );

		// The root index page
		// Root index page
		$entity_data = $entities[0]->get_data();
		$this->assertEquals( 2, $entity_data['post_id'] );
		$this->assertNull( $entity_data['post_parent'] );
		$this->assertEquals( 'Root', $entity_data['post_title'] );
		$this->assertEquals( 'publish', $entity_data['post_status'] );
		$this->assertEquals( 'page', $entity_data['post_type'] );
		$this->assertEquals( '/root.html', $entity_data['guid'] );
		$this->assertMarkupMatches(
			$entity_data['post_content'],
			'<!-- wp:paragraph --> <p>This is the root page. </p><!-- /wp:paragraph -->'
		);

		$entity_data = $entities[1]->get_data();
		$this->assertEquals( 2, $entity_data['post_id'] );
		$this->assertEquals( 'local_file_path', $entity_data['meta_key'] );
		$this->assertEquals( '/root.html', $entity_data['meta_value'] );

		$entity_data = $entities[2]->get_data();
		$this->assertEquals( 3, $entity_data['post_id'] );
		$this->assertEquals( 2, $entity_data['post_parent'] );
		$this->assertEquals( 'Nested', $entity_data['post_title'] );
		$this->assertEquals( 'publish', $entity_data['post_status'] );
		$this->assertEquals( 'page', $entity_data['post_type'] );
		$this->assertEquals( '/nested', $entity_data['guid'] );
		$this->assertMarkupMatches(
			$entity_data['post_content'],
			''
		);

		$entity_data = $entities[3]->get_data();
		$this->assertEquals( 3, $entity_data['post_id'] );
		$this->assertEquals( 'local_file_path', $entity_data['meta_key'] );
		$this->assertEquals( '/nested', $entity_data['meta_value'] );

		$entity_data = $entities[4]->get_data();
		$this->assertEquals( 4, $entity_data['post_id'] );
		$this->assertEquals( 'Page 1', $entity_data['post_title'] );
		$this->assertEquals( 'publish', $entity_data['post_status'] );
		$this->assertEquals( 'page', $entity_data['post_type'] );
		$this->assertEquals( '/nested/page1.html', $entity_data['guid'] );
		$this->assertMarkupMatches(
			$entity_data['post_content'],
			'<!-- wp:paragraph --> <p>This is page 1. </p><!-- /wp:paragraph -->'
		);

		$entity_data = $entities[5]->get_data();
		$this->assertEquals( 4, $entity_data['post_id'] );
		$this->assertEquals( 'local_file_path', $entity_data['meta_key'] );
		$this->assertEquals( '/nested/page1.html', $entity_data['meta_value'] );
	}

	private function assertMarkupMatches( $markup, $expected ) {
		$this->assertEquals(
			$this->normalize_markup( $expected ),
			$this->normalize_markup( $markup )
		);
	}

	private function normalize_markup( $markup ) {
		return WP_HTML_Processor::create_fragment(
			preg_replace( '/\s+/', ' ', trim( $markup ) )
		)->serialize();
	}
}
