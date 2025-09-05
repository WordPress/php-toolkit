<?php

namespace WordPress\Git\Protocol\Parser;

use WordPress\ByteStream\MemoryPipe;
use WordPress\ByteStream\ReadStream\ByteReadStream;
use WordPress\Git\GitException;
use WordPress\Git\GitRepository;

class GitProtocolDecoder {

	protected $write_to_repository;
	protected $resolve_deltas_from_repository;
	protected $demuxer;
	protected $packet_parser;
	protected $pack_parser;

	protected $packet_type;
	protected $packet_body;
	protected $delta_resolver;
	protected $base_object_reader;
	protected $delta_pipe;
	protected $delta_reference_repository;
	private $will_process_pack;
	private $resolved_deltas = array();

	/**
	 * @var GitObjectEncoder
	 */
	private $new_object_write_stream;

	public function __construct( ByteReadStream $upstream, $options = array() ) {
		$this->write_to_repository            = $options['write_to_repository'] ?? null;
		$this->resolve_deltas_from_repository = $options['resolve_deltas_from_repository'] ?? $options['write_to_repository'] ?? null;
		$this->will_process_pack              = $options['will_process_pack'] ?? true;
		if ( $this->write_to_repository ) {
			if ( ! ( $this->write_to_repository instanceof GitRepository ) ) {
				throw new GitException( 'GitProtocolReader requires a GitRepository constructor argument.' );
			}
		} elseif ( $this->will_process_pack ) {
			throw new GitException(
				'To process PACK packets, GitProtocolReader requires a ' .
				"'write_to_repository' option with a GitRepository instance " .
				'to write the PACKed objects to.'
			);
		}
		$this->demuxer       = new ProtocolDemultiplexer( $upstream );
		$this->packet_parser = new PacketParser();
		$this->pack_parser   = new PackParser();
	}

	public function consume_stream() {
		while ( $this->next_token() ) {
			// ... Twiddle our thumbs as GitProtocolReader indexes the trees and commits ...
		}
	}

	public function get_progress_data() {
		if ( $this->demuxer->get_stream_code() !== ProtocolDemultiplexer::STREAM_CODE_PROGRESS ) {
			return null;
		}

		return $this->demuxer->get_chunk();
	}

	public function get_error_data() {
		if ( $this->demuxer->get_stream_code() !== ProtocolDemultiplexer::STREAM_CODE_FATAL ) {
			return null;
		}

		return $this->demuxer->get_chunk();
	}

	public function get_packet_body() {
		if (
			$this->get_token_type() !== '#packet-body' &&
			$this->get_token_type() !== '#packet-footer'
		) {
			return null;
		}

		return $this->packet_body;
	}

	public function get_token_type() {
		if ( $this->demuxer->get_stream_code() === ProtocolDemultiplexer::STREAM_CODE_PROGRESS ) {
			return '#progress';
		}
		if ( $this->demuxer->get_stream_code() === ProtocolDemultiplexer::STREAM_CODE_FATAL ) {
			return '#error';
		}
		if ( $this->pack_parser->get_token_type() ) {
			return $this->pack_parser->get_token_type();
		}
		if ( $this->packet_parser->is_command() ) {
			return $this->packet_parser->get_packet_type();
		}
		if ( $this->packet_body ) {
			return $this->packet_parser->get_token_type();
		}

		return null;
	}

	public function get_pack_parser() {
		return $this->pack_parser;
	}

	public function next_token() {
		if ( $this->get_token_type() === '#error' ) {
			return false;
		}
		$this->packet_body = '';

		// Process the next multiplexed chunk
		while ( $this->demuxer->next_chunk() ) {
			switch ( $this->demuxer->get_stream_code() ) {
				case ProtocolDemultiplexer::STREAM_CODE_UNKNOWN:
				case ProtocolDemultiplexer::STREAM_CODE_SIDE_BAND:
					$this->packet_parser->append_bytes( $this->demuxer->get_chunk() );
					break;
				case ProtocolDemultiplexer::STREAM_CODE_PROGRESS:
					return true;
				case ProtocolDemultiplexer::STREAM_CODE_FATAL:
					return false;
			}
		}

		// Process the demultiplexed packets. Accumulate the body
		// of all non-PACK packets for simplicity. They're unlikely
		// to be large and it's easier to handle them as fully-loaded
		// strings.
		while ( $this->packet_parser->next_token() ) {
			switch ( $this->packet_parser->get_token_type() ) {
				case '#packet-header':
					if ( $this->packet_parser->is_command() ) {
						return true;
					}
					break;
				case '#packet-body':
					switch ( $this->packet_parser->get_packet_type() ) {
						case '#packet':
							$this->packet_body .= $this->packet_parser->get_body_chunk();
							break;
						case '#pack':
							if ( ! $this->will_process_pack ) {
								throw new GitException( 'GitProtocolReader received a PACK packet but it has no GitRepository to store the objects in. To process PACK packets, pass a GitRepository to the constructor.' );
							}
							$this->pack_parser->append_bytes( $this->packet_parser->get_body_chunk() );
							break;
						default:
							throw new GitException( 'Invalid packet type: ' . $this->packet_parser->get_packet_type() );
					}
					break;
				case '#packet-footer':
					return true;
				default:
					throw new GitException( 'Invalid token type: ' . $this->packet_parser->get_token_type() );
			}
		}

		while ( $this->pack_parser->next_token() ) {
			$is_delta = (
				$this->pack_parser->get_object_type() === PackParser::OBJECT_TYPE_REF_DELTA ||
				$this->pack_parser->get_object_type() === PackParser::OBJECT_TYPE_OFS_DELTA
			);

			if ( $is_delta ) {
				switch ( $this->pack_parser->get_token_type() ) {
					case '#object-header':
						$target_oid = $this->pack_parser->get_delta_reference();
						if ( isset( $this->resolved_deltas[ $target_oid ] ) ) {
							$target_oid = $this->resolved_deltas[ $target_oid ];
						}
						$delta_repository = null;
						if ( $this->write_to_repository->has_object( $target_oid ) ) {
							$delta_repository = $this->write_to_repository;
						} elseif ( $this->resolve_deltas_from_repository->has_object( $target_oid ) ) {
							$delta_repository = $this->resolve_deltas_from_repository;
						} else {
							throw new GitException(
								sprintf(
									'Delta target hash=%s not found in repository',
									$target_oid
								)
							);
						}
						$this->base_object_reader         = $delta_repository->read_object( $target_oid );
						$delta_uncompressed_size          = $this->pack_parser->get_object_uncompressed_size();
						$this->delta_pipe                 = new MemoryPipe( null, $delta_uncompressed_size );
						$this->delta_resolver             = new DeltaResolver(
							$this->base_object_reader,
							$this->delta_pipe
						);
						$this->delta_reference_repository = $delta_repository;
						break;
					case '#object-body':
						$this->delta_pipe->append_bytes(
							$this->pack_parser->get_body_chunk()
						);

						if ( $this->delta_resolver->resolve_buffers_lengths() ) {
							$this->new_object_write_stream = $this->write_to_repository->new_object_open_write_stream(
								$this->delta_resolver->get_base_reader()->get_object_type_name(),
								$this->delta_resolver->get_expected_target_length()
							);
						}

						while ( $this->delta_resolver->resolve_next_chunk() ) {
							$this->new_object_write_stream->append_bytes(
								$this->delta_resolver->get_resolved_chunk()
							);
						}
						break;
					case '#object-hash':
						$this->delta_pipe->close_writing();
						while ( $this->delta_resolver->resolve_next_chunk() ) {
							$this->new_object_write_stream->append_bytes(
								$this->delta_resolver->get_resolved_chunk()
							);
						}

						if ( $this->delta_resolver->is_paused_on_incomplete_input() ) {
							throw new GitException(
								sprintf(
									'Incomplete input while resolving delta. Target hash=%s, target type=%s, delta hash=%s',
									$this->pack_parser->get_delta_reference(),
									$this->delta_reference_repository->read_object( $this->pack_parser->get_delta_reference() )->get_object_type_name(),
									$this->pack_parser->get_object_hash()
								)
							);
						}

						$delta_oid = $this->pack_parser->get_object_hash();

						$this->new_object_write_stream->close_writing();
						$new_oid                             = $this->new_object_write_stream->get_hash();
						$this->resolved_deltas[ $delta_oid ] = $new_oid;

						$this->base_object_reader->close_reading();
						$this->delta_resolver     = null;
						$this->base_object_reader = null;

						return true;
				}
			} else {
				switch ( $this->pack_parser->get_token_type() ) {
					case '#object-header':
						$this->new_object_write_stream = $this->write_to_repository->new_object_open_write_stream(
							$this->pack_parser->get_object_type_name(),
							$this->pack_parser->get_object_uncompressed_size()
						);
						break;
					case '#object-body':
						$this->new_object_write_stream->append_bytes(
							$this->pack_parser->get_body_chunk()
						);
						break;
					case '#object-hash':
						$this->new_object_write_stream->close_writing();

						return true;
				}
			}
		}

		// Neither parser yielded a valid token – let's bale and wait for more data.
		return false;
	}

	protected function process_pack_object() {
		$this->pack_parser->next_token();
	}
}
