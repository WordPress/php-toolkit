<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WordPress\Svn\Protocol\RaSvnConnection;
use WordPress\Svn\Protocol\RaSvnItem;
use WordPress\Svn\SvnException;

class RaSvnConnectionTest extends TestCase {
	/**
	 * @param  string $wire  Raw protocol bytes.
	 * @return RaSvnConnection A connection reading from the given bytes.
	 */
	private function connection_for( $wire ) {
		$stream = fopen( 'php://memory', 'r+b' );
		fwrite( $stream, $wire );
		rewind( $stream );

		return new RaSvnConnection( $stream );
	}

	public function test_reads_words() {
		$item = $this->connection_for( 'success ' )->read_item();
		$this->assertSame( RaSvnItem::TYPE_WORD, $item->type );
		$this->assertSame( 'success', $item->get_word() );
		$this->assertTrue( $item->is_word( 'success' ) );
		$this->assertFalse( $item->is_word( 'failure' ) );
	}

	public function test_reads_numbers() {
		$this->assertSame( 42, $this->connection_for( '42 ' )->read_item()->get_number() );
		$this->assertSame( 0, $this->connection_for( '0 ' )->read_item()->get_number() );
	}

	public function test_reads_strings() {
		$this->assertSame( 'hello', $this->connection_for( '5:hello ' )->read_item()->get_string() );
		$this->assertSame( '', $this->connection_for( '0: ' )->read_item()->get_string() );
	}

	public function test_reads_binary_strings() {
		$binary = "\x00\x01\xff( ) 7:trap";
		$item   = $this->connection_for( strlen( $binary ) . ':' . $binary . ' ' )->read_item();
		$this->assertSame( $binary, $item->get_string() );
	}

	public function test_reads_nested_lists() {
		// A real svnserve greeting.
		$connection = $this->connection_for( '( success ( 2 2 ( ) ( edit-pipeline svndiff1 ) ) ) ' );
		$tuple      = $connection->read_item()->get_list();
		$this->assertSame( 'success', $tuple[0]->get_word() );
		$params = $tuple[1]->get_list();
		$this->assertSame( 2, $params[0]->get_number() );
		$this->assertSame( 2, $params[1]->get_number() );
		$this->assertSame( array(), $params[2]->get_list() );
		$capabilities = $params[3]->get_list();
		$this->assertSame( 'edit-pipeline', $capabilities[0]->get_word() );
		$this->assertSame( 'svndiff1', $capabilities[1]->get_word() );
	}

	public function test_reads_consecutive_items() {
		$connection = $this->connection_for( '( success ( ) ) ( failure ( ) ) 12 ' );
		$this->assertSame( 'success', $connection->read_item()->get_list()[0]->get_word() );
		$this->assertSame( 'failure', $connection->read_item()->get_list()[0]->get_word() );
		$this->assertSame( 12, $connection->read_item()->get_number() );
	}

	public function test_tolerates_extra_whitespace() {
		$item = $this->connection_for( "  (  success \n (  1  )  )  " )->read_item();
		$this->assertSame( 'success', $item->get_list()[0]->get_word() );
		$this->assertSame( 1, $item->get_list()[1]->get_list()[0]->get_number() );
	}

	public function test_throws_on_connection_end() {
		$this->expectException( SvnException::class );
		$this->connection_for( '( success ( ' )->read_item();
	}

	public function test_optional_value_helpers() {
		$connection = $this->connection_for( '( ) ( 5 ) ' );
		$this->assertNull( $connection->read_item()->get_optional() );
		$this->assertSame( 5, $connection->read_item()->get_optional()->get_number() );
	}

	public function test_type_mismatch_throws() {
		$this->expectException( SvnException::class );
		$this->connection_for( '42 ' )->read_item()->get_string();
	}

	public function test_boolean_words() {
		$connection = $this->connection_for( 'true false ' );
		$this->assertTrue( $connection->read_item()->get_boolean() );
		$this->assertFalse( $connection->read_item()->get_boolean() );
	}

	public function test_encode_string() {
		$this->assertSame( '5:hello', RaSvnConnection::encode_string( 'hello' ) );
		$this->assertSame( '0:', RaSvnConnection::encode_string( '' ) );
	}

	public function test_encode_boolean() {
		$this->assertSame( 'true', RaSvnConnection::encode_boolean( true ) );
		$this->assertSame( 'false', RaSvnConnection::encode_boolean( false ) );
	}

	public function test_write_appends_to_stream() {
		$stream     = fopen( 'php://memory', 'r+b' );
		$connection = new RaSvnConnection( $stream );
		$connection->write( '( get-latest-rev ( ) ) ' );
		rewind( $stream );
		$this->assertSame( '( get-latest-rev ( ) ) ', stream_get_contents( $stream ) );
	}
}
