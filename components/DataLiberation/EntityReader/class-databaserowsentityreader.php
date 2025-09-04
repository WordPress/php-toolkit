<?php

namespace WordPress\DataLiberation\EntityReader;

use PDO;
use PDOStatement;
use WordPress\DataLiberation\DataLiberationException;
use WordPress\DataLiberation\ImportEntity;

/**
 * Reads the database rows, one table at a time, from the first row to the last row.
 * Enables efficiently exporting large databases into an SQL file, but it's not useful
 * for content dumps – the latter need to emit resources in a "topological content order,"
 * e.g. post 1, post 1 meta, post 1 categories, post 1 comments, then post 2 (child of 1) etc.
 *
 * Note this is just a reader. It doesn't import any data into WordPress. It
 * only reads rows from the database.
 *
 * @since WP_VERSION
 */
class DatabaseRowsEntityReader implements EntityReader {

	/**
	 * State constants for the finite state machine
	 */
	const STATE_INIT = 'init';
	const STATE_NEXT_ROW = 'next_row';
	const STATE_NEXT_TABLE = 'next_table';
	const STATE_CREATE_TABLE = 'create_table';
	const STATE_FINISHED = 'finished';

	/**
	 * The database connection used to fetch records.
	 *
	 * @since WP_VERSION
	 * @var PDO
	 */
	private $db;

	/**
	 * The current entity being processed.
	 *
	 * @since WP_VERSION
	 * @var ImportEntity|null
	 */
	private $current_entity = null;

	/**
	 * The ID of the last processed record.
	 *
	 * @since WP_VERSION
	 * @var int|null
	 */
	private $last_record_id = 0;

	/**
	 * The number of entities read so far.
	 *
	 * @since WP_VERSION
	 * @var int
	 */
	private $entities_read_so_far = 0;

	/**
	 * The current table being processed.
	 *
	 * @since WP_VERSION
	 * @var string|null
	 */
	private $current_table = null;

	/**
	 * The current query result set.
	 *
	 * @since WP_VERSION
	 * @var PDOStatement|null
	 */
	private $current_result_set = null;

	/**
	 * The list of tables to process.
	 *
	 * @since WP_VERSION
	 * @var array
	 */
	private $tables_to_process;

	/**
	 * Whether to export the CREATE TABLE query for each table.
	 *
	 * @since WP_VERSION
	 * @var bool
	 */
	private $create_table_query;

	/**
	 * The current state of the reader.
	 *
	 * @since WP_VERSION
	 * @var string
	 */
	private $state = self::STATE_INIT;

	/**
	 * The type of the database.
	 *
	 * One of: "sqlite", "mysql"
	 *
	 * @since WP_VERSION
	 * @var string
	 */
	private $db_type;

	public static function create( PDO $db, $options = array() ) {
		return new DatabaseRowsEntityReader( $db, $options );
	}

	/**
	 * Constructor.
	 *
	 * @param  PDO  $db  The database connection to use.
	 * @param  array  $options  The options to configure the reader.
	 *
	 * @since WP_VERSION
	 *
	 */
	public function __construct( PDO $db, $options = array() ) {
		$this->db                 = $db;
		$this->tables_to_process  = $options['tables_to_process'] ?? null;
		$this->create_table_query = $options['create_table_query'] ?? false;
		$this->db_type            = $db->getAttribute( PDO::ATTR_DRIVER_NAME );
		if ( ! in_array( $this->db_type, array( 'sqlite', 'mysql' ) ) ) {
			throw new DataLiberationException( 'Unsupported database type: ' . $this->db_type );
		}
		if ( isset( $options['cursor'] ) ) {
			$this->initialize_from_cursor( $options['cursor'] );
		}
	}

	/**
	 * Gets the data for the current entity.
	 *
	 * @return ImportEntity The entity.
	 * @since WP_VERSION
	 */
	public function get_entity(): ImportEntity {
		return $this->current_entity;
	}

	/**
	 * Gets the ID of the last processed record.
	 *
	 * @return int|null The record ID, or null if no records have been processed.
	 * @since WP_VERSION
	 *
	 */
	public function get_last_record_id() {
		return $this->last_record_id;
	}

	public function is_finished(): bool {
		return $this->state === self::STATE_FINISHED;
	}

	/**
	 * Advances to the next entity in the database.
	 *
	 * @return bool Whether another entity was found.
	 * @since WP_VERSION
	 *
	 */
	public function next_entity() {
		if ( $this->is_finished() ) {
			return false;
		}

		if ( $this->state === self::STATE_INIT ) {
			if ( null === $this->tables_to_process ) {
				$this->initialize_tables_to_process();
			}
			$this->state = self::STATE_NEXT_TABLE;
		}

		while ( true ) {
			switch ( $this->state ) {
				case self::STATE_NEXT_TABLE:
					if ( $this->move_to_next_table() ) {
						$this->state = $this->create_table_query ? self::STATE_CREATE_TABLE : self::STATE_NEXT_ROW;
					} else {
						$this->state = self::STATE_FINISHED;

						return false;
					}
					break;

				case self::STATE_CREATE_TABLE:
					$this->export_create_table_query();
					$this->state = self::STATE_NEXT_ROW;

					return true;

				case self::STATE_NEXT_ROW:
					if ( $this->read_next_entity() ) {
						return true;
					}
					$this->state = self::STATE_NEXT_TABLE;

					return $this->next_entity();

				case self::STATE_FINISHED:
					return false;
			}
		}

		return false;
	}

	/**
	 * Advances to the next entity in the current table.
	 *
	 * @return bool Whether another entity was found.
	 * @since WP_VERSION
	 *
	 */
	private function read_next_entity() {
		if ( ! $this->current_result_set ) {
			$this->current_result_set = $this->db->query( "SELECT * FROM {$this->current_table} WHERE ID > {$this->last_record_id}" );
		}

		$record = $this->current_result_set->fetch( PDO::FETCH_ASSOC );
		if ( ! $record ) {
			$this->current_result_set = null;

			return false;
		}

		$this->current_entity = new ImportEntity(
			'database_row',
			array(
				'table'  => $this->current_table,
				'record' => $record,
			)
		);
		$this->last_record_id = $record['ID'] ?? null;
		++ $this->entities_read_so_far;

		return true;
	}

	/**
	 * Moves to the next table in the list of tables to process.
	 *
	 * @return bool Whether there is another table to process.
	 * @since WP_VERSION
	 *
	 */
	private function move_to_next_table() {
		if ( ! $this->current_table ) {
			$this->current_table = reset( $this->tables_to_process );
		} else {
			$this->current_table = next( $this->tables_to_process );
		}
		$this->last_record_id = 0;

		return (bool) $this->current_table;
	}

	/**
	 * Exports the CREATE TABLE query for the current table.
	 *
	 * @since WP_VERSION
	 */
	private function export_create_table_query() {
		switch ( $this->db_type ) {
			case 'sqlite':
				$result = $this->db->query( "SELECT sql FROM sqlite_master WHERE type='table' AND name='{$this->current_table}'" );
				$row    = $result->fetch( PDO::FETCH_ASSOC );
				$sql    = $row ? $row['sql'] . ';' : null;
				break;
			case 'mysql':
				$result = $this->db->query( "SHOW CREATE TABLE {$this->current_table}" );
				$row    = $result->fetch( PDO::FETCH_ASSOC );
				$sql    = $row ? $row['Create Table'] : null;
				break;
		}

		$this->current_entity = new ImportEntity( 'sql_query', $sql );
		++ $this->entities_read_so_far;
	}

	/**
	 * Initializes the list of tables to process by fetching all tables from the database
	 * and sorting them alphabetically.
	 *
	 * @since WP_VERSION
	 */
	private function initialize_tables_to_process() {
		$this->tables_to_process = array();
		$result                  = $this->db->query( 'SHOW TABLES' );
		while ( $row = $result->fetch( PDO::FETCH_NUM ) ) {
			$this->tables_to_process[] = $row[0];
		}
		sort( $this->tables_to_process );
	}

	public function get_reentrancy_cursor() {
		return json_encode(
			array(
				'last_record_id' => $this->last_record_id,
				'current_table'  => $this->current_table,
				'state'          => $this->state,
			)
		);
	}

	/**
	 * Initializes the reader from a cursor.
	 *
	 * @param  string  $cursor  The cursor to initialize from.
	 *
	 * @since WP_VERSION
	 *
	 */
	private function initialize_from_cursor( $cursor ) {
		$cursor_data = json_decode( $cursor, true );
		if ( $cursor_data ) {
			$this->last_record_id = $cursor_data['last_record_id'] ?? null;
			$this->current_table  = $cursor_data['current_table'] ?? null;
			$this->state          = $cursor_data['state'] ?? self::STATE_INIT;
		}
	}
}
