<?php

namespace WordPress\Git\Protocol\Parser;

use Exception;
use WordPress\ByteStream\NotEnoughDataException;
use WordPress\Git\Model\Tree;
use WordPress\Git\Model\TreeEntry;

class TreeParser {

	const STATE_READING_MODE            = 'STATE_READING_MODE';
	const STATE_READING_NAME            = 'STATE_READING_NAME';
	const STATE_READING_SHA1            = 'STATE_READING_SHA1';
	const STATE_SCANNING_FOR_NEXT_ENTRY = 'STATE_SCANNING_FOR_NEXT_ENTRY';

	/**
	 * Current state of the parser
	 *
	 * @var string
	 */
	protected $parser_state = self::STATE_SCANNING_FOR_NEXT_ENTRY;

	/**
	 * Raw bytes of the tree data being processed
	 *
	 * @var string
	 */
	protected $tree_data = '';

	/**
	 * Current offset into tree_data being processed
	 *
	 * @var int
	 */
	protected $bytes_processed = 0;

	/**
	 * Expected length of the tree data
	 *
	 * @var int
	 */
	protected $expected_bytes = null;

	/**
	 * Current tree entry
	 *
	 * @var TreeEntry
	 */
	protected $tree_entry = null;

	/**
	 * Whether we are paused on incomplete input
	 *
	 * @var bool
	 */
	protected $is_paused_on_incomplete_input = false;

	public static function parse_entire_tree( $tree_data ) {
		$tree   = new Tree();
		$parser = new TreeParser(
			array(
				'expected_bytes' => strlen( $tree_data ),
			)
		);
		$parser->append_bytes( $tree_data );
		while ( $parser->next() ) {
			$tree->add_entry( $parser->get_tree_entry() );
		}

		return $tree;
	}

	public function __construct( $options = array() ) {
		if ( ! isset( $options['expected_bytes'] ) ) {
			throw new Exception( 'The expected_bytes option is required' );
		}
		$this->expected_bytes = $options['expected_bytes'];
	}

	/**
	 * Append bytes to be processed
	 *
	 * @param  string $bytes  Raw bytes to process
	 *
	 * @return bool Whether processing can continue
	 */
	public function append_bytes( $bytes ) {
		$this->tree_data                    .= $bytes;
		$this->is_paused_on_incomplete_input = false;

		// Flush processed bytes.
		$this->tree_data       = substr( $this->tree_data, $this->bytes_processed );
		$this->bytes_processed = 0;

		return true;
	}

	/**
	 * Process the next tree entry
	 *
	 * @return bool Whether the entry was successfully parsed
	 */
	public function next() {
		if ( $this->is_paused_on_incomplete_input ) {
			return false;
		}

		if ( $this->bytes_processed >= $this->expected_bytes ) {
			return false;
		}

		try {
			while ( true ) {
				if ( self::STATE_SCANNING_FOR_NEXT_ENTRY === $this->parser_state ) {
					$this->tree_entry   = new TreeEntry();
					$this->parser_state = self::STATE_READING_MODE;
				}
				if ( self::STATE_READING_MODE === $this->parser_state ) {
					$this->read_mode();
				}
				if ( self::STATE_READING_NAME === $this->parser_state ) {
					$this->read_name();
				}
				if ( self::STATE_READING_SHA1 === $this->parser_state ) {
					$this->tree_entry->hash = bin2hex( $this->consume_bytes( 20 ) );
					$this->parser_state     = self::STATE_SCANNING_FOR_NEXT_ENTRY;

					// Once we have the sha1, we can return the entry.
					return true;
				}
			}
		} catch ( NotEnoughDataException $e ) {
			$this->is_paused_on_incomplete_input = true;

			return false;
		}
	}

	private function read_mode() {
		while ( true ) {
			$next_byte = $this->consume_bytes( 1 );
			if ( ' ' === $next_byte ) {
				break;
			}
			$this->tree_entry->mode .= $next_byte;
		}
		$this->parser_state = self::STATE_READING_NAME;
	}

	private function read_name() {
		while ( true ) {
			$next_byte = $this->consume_bytes( 1 );
			if ( "\0" === $next_byte ) {
				break;
			}
			$this->tree_entry->name .= $next_byte;
		}
		$this->parser_state = self::STATE_READING_SHA1;
	}

	/**
	 * Read bytes from current position
	 */
	private function consume_bytes( $length ) {
		if ( $this->bytes_processed + $length > strlen( $this->tree_data ) ) {
			throw new NotEnoughDataException();
		}
		$bytes                  = substr( $this->tree_data, $this->bytes_processed, $length );
		$this->bytes_processed += $length;

		return $bytes;
	}

	public function is_paused_on_incomplete_input() {
		return $this->is_paused_on_incomplete_input;
	}

	public function get_tree_entry(): ?TreeEntry {
		return $this->tree_entry;
	}
}
