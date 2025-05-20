<?php

use PHPUnit\Framework\TestCase;
use WordPress\DataLiberation\EntityReader\DatabaseContentEntityReader;

class DatabaseContentEntityReaderTest extends TestCase {

	private $db;
	private $reader;

	protected function setUp(): void {
		// Set up test database
		$this->db = new PDO( 'sqlite::memory:' );
		$this->db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->createTestTables();
		$this->populateTestTables();

		// Set up reader
		$this->reader = DatabaseContentEntityReader::create(
			$this->db,
			array(
				'table_prefix' => 'wp_',
			)
		);
	}

	private function createTestTables(): void {
		$this->db->exec( 'CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_parent INTEGER, post_title TEXT)' );
		$this->db->exec( 'CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY, post_id INTEGER, meta_key TEXT, meta_value TEXT)' );
		$this->db->exec( 'CREATE TABLE wp_terms (term_id INTEGER PRIMARY KEY, name TEXT)' );
		$this->db->exec( 'CREATE TABLE wp_term_taxonomy (term_taxonomy_id INTEGER PRIMARY KEY, term_id INTEGER, taxonomy TEXT)' );
		$this->db->exec( 'CREATE TABLE wp_term_relationships (object_id INTEGER, term_taxonomy_id INTEGER)' );
		$this->db->exec( 'CREATE TABLE wp_comments (comment_ID INTEGER PRIMARY KEY, comment_post_ID INTEGER, comment_content TEXT)' );
	}

	private function populateTestTables(): void {
		// Insert parent posts
		for ( $i = 1; $i <= 10; $i ++ ) {
			$this->db->exec( "INSERT INTO wp_posts (ID, post_parent, post_title) VALUES ($i, 0, 'Parent Post $i')" );
			$this->db->exec( "INSERT INTO wp_postmeta (meta_id, post_id, meta_key, meta_value) VALUES ($i, $i, 'meta_key_$i', 'meta_value_$i')" );
			$this->db->exec( "INSERT INTO wp_terms (term_id, name) VALUES ($i, 'Term $i')" );
			$this->db->exec( "INSERT INTO wp_term_taxonomy (term_taxonomy_id, term_id, taxonomy) VALUES ($i, $i, 'category')" );
			$this->db->exec( "INSERT INTO wp_term_relationships (object_id, term_taxonomy_id) VALUES ($i, $i)" );
			$this->db->exec( "INSERT INTO wp_comments (comment_ID, comment_post_ID, comment_content) VALUES ($i, $i, 'Comment $i')" );
		}
		// Insert child posts
		for ( $i = 11; $i <= 20; $i ++ ) {
			$parent_id = $i - 10;
			$this->db->exec( "INSERT INTO wp_posts (ID, post_parent, post_title) VALUES ($i, $parent_id, 'Child Post $i')" );
			$this->db->exec( "INSERT INTO wp_postmeta (meta_id, post_id, meta_key, meta_value) VALUES ($i, $i, 'meta_key_$i', 'meta_value_$i')" );
			$this->db->exec( "INSERT INTO wp_terms (term_id, name) VALUES ($i, 'Term $i')" );
			$this->db->exec( "INSERT INTO wp_term_taxonomy (term_taxonomy_id, term_id, taxonomy) VALUES ($i, $i, 'category')" );
			$this->db->exec( "INSERT INTO wp_term_relationships (object_id, term_taxonomy_id) VALUES ($i, $i)" );
			$this->db->exec( "INSERT INTO wp_comments (comment_ID, comment_post_ID, comment_content) VALUES ($i, $i, 'Comment $i')" );
		}
	}

	public function testReadPosts(): void {
		$postCount = 0;
		while ( $this->reader->next_entity() ) {
			$entity = $this->reader->get_entity();
			if ( $entity->get_type() === 'post' ) {
				++ $postCount;
			}
		}
		$this->assertEquals( 20, $postCount );
	}

	public function testReadPostMeta(): void {
		$metaCount = 0;
		while ( $this->reader->next_entity() ) {
			$entity = $this->reader->get_entity();
			if ( $entity->get_type() === 'post_meta' ) {
				++ $metaCount;
			}
		}
		$this->assertEquals( 20, $metaCount );
	}

	public function testReadTerms(): void {
		$termCount = 0;
		while ( $this->reader->next_entity() ) {
			$entity = $this->reader->get_entity();
			if ( $entity->get_type() === 'term' ) {
				++ $termCount;
			}
		}
		$this->assertEquals( 20, $termCount );
	}

	public function testReadComments(): void {
		$commentCount = 0;
		while ( $this->reader->next_entity() ) {
			$entity = $this->reader->get_entity();
			if ( $entity->get_type() === 'comment' ) {
				++ $commentCount;
			}
		}
		$this->assertEquals( 20, $commentCount );
	}

	public function testExportOrder(): void {
		$expectedOrder = array(
			'Parent Post 1',
			'Child Post 11',
			'Parent Post 2',
			'Child Post 12',
			'Parent Post 3',
			'Child Post 13',
			'Parent Post 4',
			'Child Post 14',
			'Parent Post 5',
			'Child Post 15',
			'Parent Post 6',
			'Child Post 16',
			'Parent Post 7',
			'Child Post 17',
			'Parent Post 8',
			'Child Post 18',
			'Parent Post 9',
			'Child Post 19',
			'Parent Post 10',
			'Child Post 20',
		);

		$actualOrder = array();
		while ( $this->reader->next_entity() ) {
			$entity = $this->reader->get_entity();
			if ( $entity->get_type() === 'post' ) {
				$actualOrder[] = $entity->get_data()['post_title'];
			}
		}

		$this->assertEquals( $expectedOrder, $actualOrder );
	}
}
