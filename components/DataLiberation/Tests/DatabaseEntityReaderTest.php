<?php

use PHPUnit\Framework\TestCase;
use WordPress\DataLiberation\EntityReader\DatabaseRowsEntityReader;
use WordPress\DataLiberation\ImportEntity;

class DatabaseEntityReaderTest extends TestCase {

	private $db;

	protected function setUp(): void {
		$this->db = new PDO( 'sqlite::memory:' );
		$this->db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->createTestTables();
	}

	private function createTestTables() {
		$this->db->exec( 'CREATE TABLE posts (ID INTEGER PRIMARY KEY, post_title TEXT)' );
		$this->db->exec( "INSERT INTO posts (post_title) VALUES ('First Post')" );
		$this->db->exec( "INSERT INTO posts (post_title) VALUES ('Second Post')" );
	}

	public function testGetEntity() {
		$reader = DatabaseRowsEntityReader::create( $this->db, array( 'tables_to_process' => array( 'posts' ) ) );
		$reader->next_entity();
		$entity = $reader->get_entity();
		$this->assertInstanceOf( ImportEntity::class, $entity );
		$this->assertEquals( 'posts', $entity->get_data()['table'] );
		$this->assertEquals( 'First Post', $entity->get_data()['record']['post_title'] );
	}

	public function testNextEntity() {
		$reader = DatabaseRowsEntityReader::create( $this->db, array( 'tables_to_process' => array( 'posts' ) ) );
		$this->assertTrue( $reader->next_entity() );
		$this->assertTrue( $reader->next_entity() );
		$this->assertFalse( $reader->next_entity() );
	}

	public function testGetLastRecordId() {
		$reader = DatabaseRowsEntityReader::create( $this->db, array( 'tables_to_process' => array( 'posts' ) ) );
		$reader->next_entity();
		$this->assertEquals( 1, $reader->get_last_record_id() );
		$reader->next_entity();
		$this->assertEquals( 2, $reader->get_last_record_id() );
	}

	public function testIsFinished() {
		$reader = DatabaseRowsEntityReader::create( $this->db, array( 'tables_to_process' => array( 'posts' ) ) );
		$this->assertFalse( $reader->is_finished() );
		$this->assertTrue( $reader->next_entity() );

		$this->assertTrue( $reader->next_entity() );
		$this->assertFalse( $reader->is_finished() );

		$this->assertFalse( $reader->next_entity() );
		$this->assertTrue( $reader->is_finished() );
	}
}
