<?php

namespace WordPress\Git\Protocol\Parser;

use HashContext;
use InflateContext;
use WordPress\ByteStream\NotEnoughDataException;
use WordPress\Git\GitException;
use WordPress\Git\Model\Tree;
use WordPress\Git\Parser\Commit;

class PackParser {

	const STATE_PACK_HEADER            = 'STATE_PACK_HEADER';
	const STATE_SCAN_FOR_OBJECT_HEADER = 'STATE_SCAN_FOR_OBJECT_HEADER';
	const STATE_PROCESSING_OBJECT_BODY = 'STATE_PROCESSING_OBJECT_BODY';
	const STATE_OBJECT_BODY_COMPLETE   = 'STATE_OBJECT_BODY_COMPLETE';

	const OBJECT_TYPE_COMMIT    = 1;
	const OBJECT_TYPE_TREE      = 2;
	const OBJECT_TYPE_BLOB      = 3;
	const OBJECT_TYPE_TAG       = 4;
	const OBJECT_TYPE_RESERVED  = 5;
	const OBJECT_TYPE_OFS_DELTA = 6;
	const OBJECT_TYPE_REF_DELTA = 7;

	const OBJECT_NAMES = array(
		self::OBJECT_TYPE_COMMIT    => 'commit',
		self::OBJECT_TYPE_TREE      => 'tree',
		self::OBJECT_TYPE_BLOB      => 'blob',
		self::OBJECT_TYPE_TAG       => 'tag',
		self::OBJECT_TYPE_RESERVED  => 'reserved',
		self::OBJECT_TYPE_OFS_DELTA => 'ofs_delta',
		self::OBJECT_TYPE_REF_DELTA => 'ref_delta',
	);

	/**
	 * Current state of the parser
	 *
	 * @var string
	 */
	protected $parser_state = 'STATE_PACK_HEADER';

	/**
	 * Raw bytes of the PACK file being processed
	 *
	 * @var string
	 */
	protected $pack_data = '';

	/**
	 * Buffered body bytes of the current object.
	 * INTERNAL USE ONLY. Git objects could easily
	 * exceed the entire available memory – let's not
	 * hand such a dangerous API to the consumer.
	 *
	 * @var string
	 */
	protected $body_buffer = '';

	/**
	 * Current offset into pack_data being processed
	 *
	 * @var int
	 */
	protected $bytes_processed = 0;

	/**
	 * Bytes removed from the memory during the processing.
	 *
	 * @var int
	 */
	protected $bytes_already_forgotten = 0;

	/**
	 * Whether we are paused on incomplete input
	 *
	 * @var bool
	 */
	protected $is_paused_on_incomplete_input = false;

	/**
	 * Memory budget for the processed PACK data
	 *
	 * @var int
	 */
	protected $memory_budget = 100 * 1024;

	/**
	 * Current object being processed
	 *
	 * @var array
	 */
	protected $object_header = null;

	/**
	 * Number of bytes processed for current object
	 *
	 * @var int
	 */
	protected $object_bytes_processed = 0;

	/**
	 * Version of the PACK file
	 *
	 * @var int
	 */
	protected $pack_version = null;

	/**
	 * Number of objects in the PACK file
	 *
	 * @var int
	 */
	protected $object_count = null;

	/**
	 * Number of objects already read
	 *
	 * @var int
	 */
	protected $objects_processed = 0;

	/**
	 * Inflate context for decompressing the current object's data
	 *
	 * @var InflateContext
	 */
	protected $inflate_context;

	/**
	 * SHA1 context for hashing the current object's data
	 *
	 * @var HashContext
	 */
	protected $hash_context;

	/**
	 * SHA1 of the current object
	 *
	 * @var string
	 */
	protected $hash;

	/**
	 * Current body chunk being processed
	 *
	 * @var string
	 */
	protected $body_chunk = null;

	/**
	 * Tree parser
	 *
	 * @var TreeParser
	 */
	protected $tree_parser;

	/**
	 * The parsed tree object
	 *
	 * @var Tree
	 */
	protected $tree;

	/**
	 * Commit object
	 *
	 * @var Commit
	 */
	protected $commit;

	/**
	 * Maps object offsets in the pack file to their
	 * SHA1 hashes. This is required for resolving
	 * offset deltas.
	 *
	 * @var array
	 */
	protected $offset_hash_map = array();

	public function __construct( $options = array() ) {
		if ( isset( $options['memory_budget'] ) ) {
			$this->memory_budget = $options['memory_budget'];
		}
	}

	/**
	 * Append bytes to be processed
	 *
	 * @param  string $bytes  Raw bytes to process
	 *
	 * @return bool Whether processing can continue
	 */
	public function append_bytes( $bytes ) {
		$this->pack_data                    .= $bytes;
		$this->is_paused_on_incomplete_input = false;

		if ( strlen( $this->pack_data ) > $this->memory_budget ) {
			// Flush processed bytes.
			$this->bytes_already_forgotten += $this->bytes_processed;
			$this->pack_data                = substr( $this->pack_data, $this->bytes_processed );
			$this->bytes_processed          = 0;
		}

		return true;
	}

	/**
	 * Process the next chunk of data
	 *
	 * @return bool|array False if needs more data, array with object data if header parsed
	 */
	public function next_token() {
		while ( true ) {
			if ( $this->is_paused_on_incomplete_input ) {
				return false;
			}
			if ( $this->bytes_processed >= strlen( $this->pack_data ) ) {
				return false;
			}
			if (
				null !== $this->object_count &&
				$this->objects_processed >= $this->object_count
			) {
				return false;
			}

			try {
				if ( self::STATE_PACK_HEADER === $this->parser_state ) {
					$this->process_pack_header();

					return true;
				}

				// Process next object header if we don't have one.
				if ( self::STATE_SCAN_FOR_OBJECT_HEADER === $this->parser_state && ! $this->object_header ) {
					$this->parse_object_header();
					$this->object_bytes_processed = 0;

					return true;
				}

				if ( self::STATE_PROCESSING_OBJECT_BODY === $this->parser_state ) {
					$this->next_body_chunk();

					return true;
				}

				if ( self::STATE_OBJECT_BODY_COMPLETE === $this->parser_state ) {
					$this->reset_after_object();
					continue;
				}
			} catch ( NotEnoughDataException $e ) {
				$this->is_paused_on_incomplete_input = true;

				return false;
			}
		}
	}

	public function get_token_type() {
		if ( $this->hash ) {
			return '#object-hash';
		}
		if ( null !== $this->body_chunk ) {
			return '#object-body';
		}
		if ( $this->object_header ) {
			return '#object-header';
		}
		if ( $this->object_count ) {
			return '#pack-header';
		}

		return null;
	}

	public function parse_body_as_commit() {
		if ( $this->is_object_body_finished() ) {
			return false;
		}
		if ( ! $this->buffer_body_bytes() ) {
			return false;
		}
		$this->commit = CommitParser::parse( $this->body_buffer );

		return true;
	}

	public function get_commit() {
		return $this->commit;
	}

	public function parse_body_as_tree() {
		if ( $this->is_object_body_finished() ) {
			return false;
		}
		if ( ! $this->buffer_body_bytes() ) {
			return false;
		}
		$this->tree = TreeParser::parse_entire_tree( $this->body_buffer );

		return true;
	}

	public function get_tree() {
		return $this->tree;
	}

	private function buffer_body_bytes() {
		while ( true ) {
			if ( strlen( $this->body_buffer ) === $this->object_header['uncompressed_size'] ) {
				return true;
			}
			if ( ! $this->next_body_chunk() ) {
				return false;
			}
			$this->body_buffer .= $this->get_body_chunk();
		}
	}

	public function next_body_chunk() {
		if ( ! $this->object_header ) {
			return false;
		}

		if ( self::STATE_OBJECT_BODY_COMPLETE === $this->parser_state ) {
			return false;
		}

		if ( strlen( $this->pack_data ) <= $this->bytes_processed ) {
			$this->is_paused_on_incomplete_input = true;

			return false;
		}

		if ( ZLIB_STREAM_END === inflate_get_status( $this->inflate_context ) ) {
			$this->hash = hash_final( $this->hash_context );
			++$this->objects_processed;
			$this->offset_hash_map[ $this->object_header['offset'] ] = $this->hash;
			$this->body_chunk                                        = '';
			$this->parser_state                                      = self::STATE_OBJECT_BODY_COMPLETE;

			return true;
		}

		$chunk_size     = 256;
		$deflated_chunk = substr( $this->pack_data, $this->bytes_processed, $chunk_size );

		$res = inflate_add( $this->inflate_context, $deflated_chunk );
		switch ( inflate_get_status( $this->inflate_context ) ) {
			case ZLIB_BUF_ERROR:
			case ZLIB_DATA_ERROR:
			case ZLIB_VERSION_ERROR:
			case ZLIB_MEM_ERROR:
				throw new GitException( 'Inflate error: ' . inflate_get_status( $this->inflate_context ) );
		}
		if ( false === $res ) {
			throw new GitException( 'Inflate error' );
		}
		$bytes_read_for_this_chunk     = inflate_get_read_len( $this->inflate_context ) - $this->object_bytes_processed;
		$this->bytes_processed        += $bytes_read_for_this_chunk;
		$this->object_bytes_processed += $bytes_read_for_this_chunk;
		$this->body_chunk              = $res;
		hash_update( $this->hash_context, $res );

		return true;
	}

	private function reset_after_object() {
		$this->object_header          = null;
		$this->body_chunk             = null;
		$this->object_bytes_processed = 0;
		$this->parser_state           = self::STATE_SCAN_FOR_OBJECT_HEADER;
		$this->inflate_context        = null;
		$this->hash_context           = null;
		$this->hash                   = null;
		$this->tree_parser            = null;
		$this->tree                   = null;
		$this->body_buffer            = '';
	}

	public function get_body_chunk() {
		return $this->body_chunk;
	}

	/**
	 * Get the uncompressed size of the current object
	 *
	 * @return int|null Uncompressed size or null if no object
	 */
	public function get_object_uncompressed_size() {
		if ( ! $this->object_header ) {
			return null;
		}

		return $this->object_header['uncompressed_size'];
	}

	/**
	 * Get SHA1 of the current object – after its entire body has been processed
	 *
	 * @return string|null SHA1 or null if no object
	 */
	public function get_object_hash() {
		return $this->hash;
	}

	/**
	 * Get number of bytes processed for current object
	 *
	 * @return int Number of bytes processed
	 */
	public function is_object_body_finished() {
		return $this->object_header && $this->object_bytes_processed >= $this->object_header['uncompressed_size'];
	}

	/**
	 * Process the PACK file header
	 *
	 * @return bool Whether header was successfully processed
	 */
	private function process_pack_header() {
		$at = $this->bytes_processed;
		if ( $at + 12 >= strlen( $this->pack_data ) ) {
			throw new NotEnoughDataException();
		}
		$header = substr( $this->pack_data, $at, 4 );
		$at    += 4;
		if ( 'PACK' !== $header ) {
			throw new GitException( 'Invalid PACK header' );
		}

		$this->pack_version = unpack( 'N', substr( $this->pack_data, $at, 4 ) )[1];
		$at                += 4;

		$this->object_count = unpack( 'N', substr( $this->pack_data, $at, 4 ) )[1];
		$at                += 4;

		$this->bytes_processed = $at;
		$this->parser_state    = self::STATE_SCAN_FOR_OBJECT_HEADER;
		return true;
	}

	public function get_pack_version() {
		return $this->pack_version;
	}

	public function get_object_count() {
		return $this->object_count;
	}

	private function get_offset_in_stream() {
		return $this->bytes_already_forgotten + $this->bytes_processed;
	}

	/**
	 * Process an object header
	 *
	 * @return array Object header data
	 */
	private function parse_object_header() {
		$header_offset = $this->get_offset_in_stream();
		$at            = $this->bytes_processed;

		// Parse object header.
		if ( $at > strlen( $this->pack_data ) ) {
			throw new NotEnoughDataException();
		}
		$byte = ord( $this->pack_data[ $at ] );
		++$at;
		$type = ( $byte >> 4 ) & 0x7;

		// Parse variable length size.
		$size = $byte & 0xf;
		if ( $byte & 0x80 ) {
			$shift = 4;
			do {
				if ( $at >= strlen( $this->pack_data ) ) {
					throw new NotEnoughDataException();
				}
				$byte = ord( $this->pack_data[ $at ] );
				++$at;
				$size  |= ( $byte & 0x7f ) << $shift;
				$shift += 7;
			} while ( $byte & 0x80 );
		}

		$object_header = array(
			'type'              => $type,
			'uncompressed_size' => $size,
			'offset'            => $header_offset,
		);

		// Deltas also have a reference to the original object.
		// before the object body starts.
		if ( self::OBJECT_TYPE_OFS_DELTA === $type ) {
			// Git uses a specific formula: ofs = ((ofs + 1) << 7) + (c & 0x7f).
			// for each continuation byte. The first byte doesn't do the "ofs+1" part.
			// This code matches Git’s logic.
			$offset = 0;

			// Read the first byte.
			if ( $at >= strlen( $this->pack_data ) ) {
				throw new NotEnoughDataException();
			}
			$c = ord( $this->pack_data[ $at ] );
			++$at;
			$offset = ( $c & 0x7F );

			// If bit 7 (0x80) is set, we keep reading.
			while ( $c & 0x80 ) {
				if ( $at >= strlen( $this->pack_data ) ) {
					throw new NotEnoughDataException();
				}
				$c = ord( $this->pack_data[ $at ] );
				++$at;
				$offset = ( ( $offset + 1 ) << 7 ) + ( $c & 0x7F );
			}
			$object_header['reference'] = $this->offset_hash_map[ $header_offset - $offset ];
		} elseif ( self::OBJECT_TYPE_REF_DELTA === $type ) {
			if ( $at + 20 >= strlen( $this->pack_data ) ) {
				throw new NotEnoughDataException();
			}
			$object_header['reference'] = bin2hex( substr( $this->pack_data, $at, 20 ) );
			$at                        += 20;
		}

		$this->bytes_processed = $at;
		$this->object_header   = $object_header;
		$this->parser_state    = self::STATE_PROCESSING_OBJECT_BODY;
		$this->inflate_context = inflate_init( ZLIB_ENCODING_DEFLATE );
		if ( ! $this->inflate_context ) {
			throw new GitException( 'Failed to initialize inflate context' );
		}
		$this->hash_context = hash_init( 'sha1' );
		if ( ! $this->hash_context ) {
			throw new GitException( 'Failed to initialize sha1 context' );
		}
		hash_update(
			$this->hash_context,
			self::OBJECT_NAMES[ $this->object_header['type'] ] .
			' ' .
			$this->object_header['uncompressed_size'] .
			"\x00"
		);

		return true;
	}

	public function get_object_type_name() {
		if ( ! $this->object_header ) {
			return null;
		}

		return self::OBJECT_NAMES[ $this->object_header['type'] ];
	}

	public function get_object_type() {
		if ( ! $this->object_header ) {
			return null;
		}

		return $this->object_header['type'];
	}

	public function get_object_offset() {
		if ( ! $this->object_header ) {
			return null;
		}

		return $this->object_header['offset'];
	}

	public function get_delta_reference() {
		if ( ! $this->object_header ) {
			return null;
		}

		return $this->object_header['reference'];
	}
}
