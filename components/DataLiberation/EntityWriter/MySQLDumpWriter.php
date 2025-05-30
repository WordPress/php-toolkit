<?php

namespace WordPress\DataLiberation\EntityWriter;

use WordPress\ByteStream\WriteStream\ByteWriteStream;
use WordPress\DataLiberation\DataLiberationException;
use WordPress\DataLiberation\ImportEntity;

class MySQLDumpWriter implements EntityWriter {

	private $write_stream;
	private $is_closed = false;

	public function __construct( ByteWriteStream $write_stream ) {
		$this->write_stream = $write_stream;
	}

	public function append_entity( ImportEntity $entity ) {
		if ( $this->is_closed ) {
			throw new DataLiberationException( 'Cannot write to a closed writer' );
		}

		switch ( $entity->get_type() ) {
			case 'sql_query':
				$this->write_stream->append_bytes( $entity->get_data() . "\n" );
				break;
			case 'database_row':
				$data   = $entity->get_data();
				$table  = $data['table'];
				$record = $data['record'];

				$columns = implode( ', ', array_keys( $record ) );
				$values  = implode( ', ', array_map( array( $this, 'quote_value' ), array_values( $record ) ) );

				$this->write_stream->append_bytes( "INSERT INTO $table ($columns) VALUES ($values);\n" );
				break;
		}
	}

	private function quote_value( $value ) {
		if ( is_null( $value ) ) {
			return 'NULL';
		}
		if ( is_string( $value ) ) {
			return "'" . $this->escape_string( $value ) . "'";
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		return $value;
	}

	/**
	 * @TODO: This is AI-generated. It's okay for the demo, but before
	 *        any production application we should review this with @dmsnell
	 *        and reach mysql_real_escape_string() parity without any server
	 *        round-trips.
	 *
	 * Escapes special characters in a string for use in a MySQL query.
	 *
	 * Only escapes characters that need escaping when using single quotes:
	 * - Backslash (\) -> \\
	 * - Single quote (') -> \'
	 * - Control chars like null byte, newline etc are escaped as hex
	 */
	private function escape_string( $value ) {
		$result = '';
		$len    = strlen( $value );

		for ( $i = 0; $i < $len; $i ++ ) {
			$char = $value[ $i ];
			$ord  = ord( $char );

			// Control characters need hex escaping
			if ( $ord < 32 || $ord === 0x7F ) {
				$result .= sprintf( '\\x%02x', $ord );
				continue;
			}

			// Only need to escape backslash and single quote
			switch ( $char ) {
				case '\\':
					$result .= '\\\\';
					break;
				case "'":
					$result .= "\\'";
					break;
				default:
					$result .= $char;
			}
		}

		return $result;
	}

	public function close_writing() {
		if ( ! $this->is_closed ) {
			$this->write_stream->close_writing();
			$this->is_closed = true;
		}
	}

	public function get_reentrancy_cursor(): string {
		return ''; // Not needed for this writer
	}
}
