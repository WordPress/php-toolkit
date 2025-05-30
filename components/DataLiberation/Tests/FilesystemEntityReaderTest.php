<?php

use PHPUnit\Framework\TestCase;
use WordPress\DataLiberation\EntityReader\FilesystemEntityReader;
use WordPress\Filesystem\LocalFilesystem;

class FilesystemEntityReaderTest extends TestCase {

	public function test_with_create_index_pages_true() {
		$reader   = new FilesystemEntityReader(
			LocalFilesystem::create( __DIR__ . '/fixtures/filesystem-entity-reader/simple-structure' ),
			array(
				'first_post_id'      => 2,
				'create_index_pages' => true,
				'filter_pattern'     => '#\.html$#',
				'index_file_pattern' => '#root.html#',
				'base_url'           => 'https://example.com',
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

	public function test_uses_root_parent_id_as_top_level_parent() {
		$reader   = new FilesystemEntityReader(
			LocalFilesystem::create( __DIR__ . '/fixtures/filesystem-entity-reader/simple-structure' ),
			array(
				'root_parent_id' => 2,
				'first_post_id'  => 3,
				'base_url'       => 'https://example.com',
			)
		);
		$entities = array();
		while ( $reader->next_entity() ) {
			$page_maybe = $reader->get_entity();
			if ( $page_maybe->get_type() === 'post' ) {
				$entities[] = $page_maybe->get_data();
			}
		}
		$this->assertCount( 3, $entities );
		$this->assertEquals( '/root.html', $entities[0]['local_file_path'] );
		$this->assertEquals( '/nested', $entities[1]['local_file_path'] );
		$this->assertEquals( '/nested/page1.html', $entities[2]['local_file_path'] );
	}

	public function test_preserves_file_extension_in_the_post_name() {
		$reader   = new FilesystemEntityReader(
			LocalFilesystem::create( __DIR__ . '/fixtures/filesystem-entity-reader/simple-structure' ),
			array(
				'first_post_id'      => 2,
				'create_index_pages' => true,
				'filter_pattern'     => '#\.html$#',
				'index_file_pattern' => '#root.html#',
				'base_url'           => 'https://example.com',
			)
		);
		$entities = $this->get_post_entities( $reader );
		$this->assertEquals( 'https://example.com/root.html', $entities[0]['link'] );
		$this->assertEquals( 'https://example.com/nested', $entities[1]['link'] );
		$this->assertEquals( 'https://example.com/nested/page1.html', $entities[2]['link'] );
	}

	public function test_leaves_out_directories_with_no_content() {
		$reader   = new FilesystemEntityReader(
			LocalFilesystem::create( __DIR__ . '/fixtures/filesystem-entity-reader/with-nested-images-directory' ),
			array(
				'first_post_id'      => 2,
				'create_index_pages' => true,
				'filter_pattern'     => '#\.html$#',
				'index_file_pattern' => '#root.html#',
				'base_url'           => 'https://example.com',
			)
		);
		$entities = $this->get_post_entities( $reader );
		$this->assertCount( 3, $entities );
		$this->assertEquals( 'https://example.com/root.html', $entities[0]['link'] );
		$this->assertEquals( 'https://example.com/nested', $entities[1]['link'] );
		$this->assertEquals( 'https://example.com/nested/page1.html', $entities[2]['link'] );
	}

	private function get_post_entities( $reader ) {
		$entities = array();
		while ( $reader->next_entity() ) {
			$page_maybe = $reader->get_entity();
			if ( $page_maybe->get_type() === 'post' ) {
				$entities[] = $page_maybe->get_data();
			}
		}

		return $entities;
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
