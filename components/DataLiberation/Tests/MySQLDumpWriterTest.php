<?php

use PHPUnit\Framework\TestCase;
use WordPress\ByteStream\MemoryPipe;
use WordPress\DataLiberation\DataLiberationException;
use WordPress\DataLiberation\EntityWriter\MySQLDumpWriter;
use WordPress\DataLiberation\ImportEntity;

class MySQLDumpWriterTest extends TestCase {

	private $writer;
	private $memory_pipe;

	protected function setUp(): void {
		$this->memory_pipe = new MemoryPipe();
		$this->writer      = new MySQLDumpWriter( $this->memory_pipe );
	}

	public function testAppendEntity() {
		$entity = new ImportEntity(
			'database_row',
			array(
				'table'  => 'posts',
				'record' => array(
					'ID'           => 1,
					'post_title'   => 'First Post',
					'post_content' => 'Hello World',
				),
			)
		);

		$this->writer->append_entity( $entity );
		$this->writer->close_writing();

		$expected = "INSERT INTO posts (ID, post_title, post_content) VALUES (1, 'First Post', 'Hello World');\n";
		$this->assertEquals( $expected, $this->memory_pipe->consume_all() );
	}

	public function testAppendEntityWithNullValue() {
		$entity = new ImportEntity(
			'database_row',
			array(
				'table'  => 'posts',
				'record' => array(
					'ID'         => 1,
					'post_title' => null,
				),
			)
		);

		$this->writer->append_entity( $entity );
		$this->writer->close_writing();

		$expected = "INSERT INTO posts (ID, post_title) VALUES (1, NULL);\n";
		$this->assertEquals( $expected, $this->memory_pipe->consume_all() );
	}

	public function testAppendEntityWithSpecialChars() {
		$entity = new ImportEntity(
			'database_row',
			array(
				'table'  => 'posts',
				'record' => array(
					'post_title' => "It's a \"quote\" with \\ backslash",
				),
			)
		);

		$this->writer->append_entity( $entity );
		$this->writer->close_writing();

		$expected = "INSERT INTO posts (post_title) VALUES ('It\\'s a \"quote\" with \\\\ backslash');\n";
		$this->assertEquals( $expected, $this->memory_pipe->consume_all() );
	}

	public function testAppendEntityWithControlChars() {
		$entity = new ImportEntity(
			'database_row',
			array(
				'table'  => 'posts',
				'record' => array(
					'post_title' => "Line1\nLine2\tTabbed\0NullByte",
				),
			)
		);

		$this->writer->append_entity( $entity );
		$this->writer->close_writing();

		$expected = "INSERT INTO posts (post_title) VALUES ('Line1\\x0aLine2\\x09Tabbed\\x00NullByte');\n";
		$this->assertEquals( $expected, $this->memory_pipe->consume_all() );
	}

	public function testCloseWriting() {
		$this->writer->close_writing();

		$this->expectException( DataLiberationException::class );
		$this->writer->append_entity(
			new ImportEntity(
				'database_row',
				array(
					'table'  => 'posts',
					'record' => array(),
				)
			)
		);
	}

	public function testGetReentrancyCursor() {
		$this->assertEquals( '', $this->writer->get_reentrancy_cursor() );
	}
}
