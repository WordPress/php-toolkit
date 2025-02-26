<?php

namespace WordPress\Git\Protocol;

use WordPress\ByteStream\ReadStream\BaseByteReadStream;
use WordPress\ByteStream\ReadStream\TransformedReadStream;
use WordPress\ByteStream\ByteTransformer\ChecksumTransformer;
use WordPress\Git\GitRepository;
use WordPress\Git\Protocol\Parser\PackParser;

class PackfileEncoderReadStream extends BaseByteReadStream {

	protected $context_size_min = 100;
	protected $context_size_max = 100;

	protected $oids;
	protected $objects_source;
	protected $object_reader;
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

		if ( ! $this->object_reader ) {
			$this->object_reader = $this->objects_source->read_object( $this->oids[ $this->objects_written ] );
			$this->object_reader->set_inflate_enabled( false );

			return $this->encode_packfile_object_header(
				$this->object_reader->get_object_type_name(),
				$this->object_reader->get_uncompressed_size()
			);
		}

		$available = $this->object_reader->pull( 8096 );
		if ( $available ) {
			return $this->object_reader->consume( $available );
		}

		if ( $this->object_reader->reached_end_of_data() ) {
			$this->object_reader->close_reading();
			$this->object_reader = null;
			++$this->objects_written;
		}

		return $this->internal_pull( $n );
	}

	private function encode_packfile_object_header( $object_type_name, $uncompressed_size ) {
		$types       = array_flip( PackParser::OBJECT_NAMES );
		$object_type = $types[ $object_type_name ];

		// First byte: type in bits 4-6, size bits 0-3
		$firstByte  = $uncompressed_size & 0b1111;
		$firstByte |= ( $object_type & 0b111 ) << 4;

		// Continuation bit 7 if needed
		if ( $uncompressed_size > 15 ) {
			$firstByte |= 0b10000000;
		}

		// Get remaining size bits after first 4 bits
		$remainingSize = $uncompressed_size >> 4;

		// Build result starting with first byte
		$result = chr( $firstByte );
		// Add continuation bytes if needed
		while ( $remainingSize > 0 ) {
			// Set continuation bit if we have more bytes
			$byte            = $remainingSize & 0b01111111;
			$remainingSize >>= 7;
			if ( $remainingSize > 0 ) {
				$byte |= 0b10000000;
			}

			$result .= chr( $byte );
		}

		return $result;
	}
}
