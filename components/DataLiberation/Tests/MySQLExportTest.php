<?php

use PHPUnit\Framework\TestCase;
use WordPress\DataLiberation\EntityReader\DatabaseRowsEntityReader;
use WordPress\DataLiberation\EntityWriter\MySQLDumpWriter;
use WordPress\ByteStream\MemoryPipe;

class MySQLExportTest extends TestCase {

	private $db;
	private $memory_pipe;
	private $writer;
	private $reader;

	protected function setUp(): void {
		// Set up test database
		$this->db = new PDO( 'sqlite::memory:' );
		$this->db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->createTestTables();

		// Set up export components
		$this->memory_pipe = new MemoryPipe();
		$this->writer      = new MySQLDumpWriter( $this->memory_pipe );
		$this->reader      = DatabaseRowsEntityReader::create(
			$this->db,
			array(
				'tables_to_process' => array( 'posts' ),
			)
		);
	}

	private function createTestTables(): void {
		$this->db->exec( 'CREATE TABLE posts (ID INTEGER PRIMARY KEY, post_title TEXT)' );
		$this->db->exec( "INSERT INTO posts (post_title) VALUES ('First Post')" );
		$this->db->exec( "INSERT INTO posts (post_title) VALUES ('Second Post')" );
	}

	public function testExportFromDatabaseToSQL(): void {
		// Export all entities
		while ( $this->reader->next_entity() ) {
			$entity = $this->reader->get_entity();
			$this->writer->append_entity( $entity );
		}
		$this->writer->close_writing();

		// Verify exported SQL
		/**
		 * Older PDO return all database data as strings.
		 *
		 * @see https://github.com/laravel/framework/issues/3548
		 */
		if ( version_compare( PHP_VERSION, '8.0', '<=' ) ) {
			$expected = "INSERT INTO posts (ID, post_title) VALUES ('1', 'First Post');\n" .
						"INSERT INTO posts (ID, post_title) VALUES ('2', 'Second Post');\n";
		} else {
			$expected = "INSERT INTO posts (ID, post_title) VALUES (1, 'First Post');\n" .
						"INSERT INTO posts (ID, post_title) VALUES (2, 'Second Post');\n";
		}

		$this->assertEquals( $expected, $this->memory_pipe->consume_all() );
	}

	public function testExportEmptyTable(): void {
		// Clear the table
		$this->db->exec( 'DELETE FROM posts' );

		// Try export
		while ( $this->reader->next_entity() ) {
			$entity = $this->reader->get_entity();
			$this->writer->append_entity( $entity );
		}
		$this->writer->close_writing();

		// Should produce empty output
		$this->assertEquals( '', $this->memory_pipe->consume_all() );
	}

	public function testExportWithSpecialCharacters(): void {
		// Add a row with special characters
		$this->db->exec( "INSERT INTO posts (post_title) VALUES ('It''s a \"special\" \\ post')" );

		// Export all entities
		while ( $this->reader->next_entity() ) {
			$entity = $this->reader->get_entity();
			$this->writer->append_entity( $entity );
		}
		$this->writer->close_writing();

		// Verify the output contains properly escaped special characters
		$output = $this->memory_pipe->consume_all();
		$this->assertStringContainsString( "'It\\'s a \"special\" \\\\ post'", $output );
	}

	public function testExportWithCreateTableStatement(): void {
		// Set up reader with create_table_query option
		$this->memory_pipe = new MemoryPipe();
		$this->writer      = new MySQLDumpWriter( $this->memory_pipe );
		$this->reader      = DatabaseRowsEntityReader::create(
			$this->db,
			array(
				'tables_to_process' => array( 'posts' ),
				'create_table_query' => true,
			)
		);

		// Export all entities
		while ( $this->reader->next_entity() ) {
			$entity = $this->reader->get_entity();
			$this->writer->append_entity( $entity );
		}
		$this->writer->close_writing();

		$output = $this->memory_pipe->consume_all();
		/**
		 * Older PDO return all database data as strings.
		 *
		 * @see https://github.com/laravel/framework/issues/3548
		 */
		if ( version_compare( PHP_VERSION, '8.0', '<=' ) ) {
			$expected = <<<SQL
CREATE TABLE posts (ID INTEGER PRIMARY KEY, post_title TEXT);
INSERT INTO posts (ID, post_title) VALUES ('1', 'First Post');
INSERT INTO posts (ID, post_title) VALUES ('2', 'Second Post');

SQL;
		} else {
			$expected = <<<SQL
CREATE TABLE posts (ID INTEGER PRIMARY KEY, post_title TEXT);
INSERT INTO posts (ID, post_title) VALUES (1, 'First Post');
INSERT INTO posts (ID, post_title) VALUES (2, 'Second Post');

SQL;
		}

		$this->assertEquals(
			$expected,
			$output
		);
	}

	public function testExportWithCursor(): void {
		// Start initial export and process first record
		$this->reader->next_entity();
		$entity = $this->reader->get_entity();
		$this->writer->append_entity( $entity );

		// Get cursor state after first record
		$cursor = $this->reader->get_reentrancy_cursor();

		// Create new reader initialized from cursor
		$resumed_reader = DatabaseRowsEntityReader::create(
			$this->db,
			array(
				'tables_to_process' => array( 'posts' ),
				'cursor' => $cursor,
			)
		);

		// Continue export with resumed reader
		$resumed_reader->next_entity();
		$entity = $resumed_reader->get_entity();
		$this->writer->append_entity( $entity );

		$this->writer->close_writing();

		/**
		 * Older PDO return all database data as strings.
		 *
		 * @see https://github.com/laravel/framework/issues/3548
		 */
		if ( version_compare( PHP_VERSION, '8.0', '<=' ) ) {
			$expected = "INSERT INTO posts (ID, post_title) VALUES ('1', 'First Post');\n" .
						"INSERT INTO posts (ID, post_title) VALUES ('2', 'Second Post');\n";
		} else {
			$expected = "INSERT INTO posts (ID, post_title) VALUES (1, 'First Post');\n" .
						"INSERT INTO posts (ID, post_title) VALUES (2, 'Second Post');\n";
		}

		$this->assertEquals( $expected, $this->memory_pipe->consume_all() );
	}
}
