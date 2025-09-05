<?php

namespace WordPress\Git\Protocol;

use WordPress\ByteStream\ByteTransformer\ChecksumTransformer;
use WordPress\ByteStream\ReadStream\BaseByteReadStream;
use WordPress\ByteStream\ReadStream\DeflateReadStream;
use WordPress\ByteStream\ReadStream\TransformedReadStream;
use WordPress\Git\GitRepository;
use WordPress\Git\Protocol\Parser\PackParser;

class PackfileEncoderReadStream extends BaseByteReadStream {

	protected $oids;
	protected $objects_source;
	protected $object_reader_base;
	protected $object_reader_deflate;
	protected $objects_written = 0;

	public static function create( GitRepository $objects_source, $oids ) {
		$encoder = new self( $objects_source, $oids );

		return new TransformedReadStream(
			$encoder,
			array(
				'checksum' => new ChecksumTransformer(
					'sha1',
					array(
						'flush_hash'    => true,
						'binary_output' => true,
					)
				),
			)
		);
	}

	private function __construct( GitRepository $objects_source, $oids ) {
		$this->objects_source = $objects_source;
		$this->oids           = $oids;
		$this->buffer         = 'PACK' . pack( 'N', 2 ) . pack( 'N', count( $oids ) );
	}

	public function internal_pull( $n ): string {
		if ( $this->objects_written >= count( $this->oids ) ) {
			$this->close_reading();

			return '';
		}

		if ( ! $this->object_reader_base ) {
			$this->object_reader_base    = $this->objects_source->read_object( $this->oids[ $this->objects_written ] );
			$this->object_reader_deflate = new DeflateReadStream(
				$this->object_reader_base
			);

			return $this->encode_packfile_object_header(
				$this->object_reader_base->get_object_type_name(),
				$this->object_reader_base->get_uncompressed_size()
			);
		}

		$available = $this->object_reader_deflate->pull( 8096 );
		if ( $available ) {
			return $this->object_reader_deflate->consume( $available );
		}

		if ( $this->object_reader_deflate->reached_end_of_data() ) {
			$this->object_reader_deflate->close_reading();
			$this->object_reader_deflate = null;

			$this->object_reader_base->close_reading();
			$this->object_reader_base = null;

			++$this->objects_written;
		}

		return $this->internal_pull( $n );
	}

	private function encode_packfile_object_header( $object_type_name, $uncompressed_size ) {
		$types       = array_flip( PackParser::OBJECT_NAMES );
		$object_type = $types[ $object_type_name ];

		// First byte: type in bits 4-6, size bits 0-3.
		$first_byte  = $uncompressed_size & 0b1111;
		$first_byte |= ( $object_type & 0b111 ) << 4;

		// Continuation bit 7 if needed.
		if ( $uncompressed_size > 15 ) {
			$first_byte |= 0b10000000;
		}

		// Get remaining size bits after first 4 bits.
		$remaining_size = $uncompressed_size >> 4;

		// Build result starting with first byte.
		$result = chr( $first_byte );
		// Add continuation bytes if needed.
		while ( $remaining_size > 0 ) {
			// Set continuation bit if we have more bytes.
			$byte             = $remaining_size & 0b01111111;
			$remaining_size >>= 7;
			if ( $remaining_size > 0 ) {
				$byte |= 0b10000000;
			}

			$result .= chr( $byte );
		}

		return $result;
	}
}
