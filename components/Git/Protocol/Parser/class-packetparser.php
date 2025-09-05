<?php

namespace WordPress\Git\Protocol\Parser;

use WordPress\ByteStream\NotEnoughDataException;
use WordPress\Git\GitException;

class PacketParser {

	const STATE_SCAN_FOR_EXPECTED_LENGTH = 'scan_for_expected_length';
	const STATE_READ_PACKET_BODY         = 'read_packet_body';
	const STATE_PACKET_FOOTER            = 'packet_footer';

	protected $bytes                         = '';
	protected $bytes_read_so_far             = 0;
	protected $bytes_already_forgotten       = 0;
	protected $is_finished                   = false;
	protected $is_paused_at_incomplete_input = false;
	protected $packet_type                   = null;
	protected $expected_length               = 0;
	protected $packet_bytes_read             = 0;
	protected $body_chunk                    = '';
	protected $state                         = self::STATE_SCAN_FOR_EXPECTED_LENGTH;

	public function next_token() {
		if ( $this->is_paused_at_incomplete_input ) {
			return false;
		}

		if ( $this->is_finished ) {
			return false;
		}

		try {
			while ( true ) {
				switch ( $this->state ) {
					case self::STATE_PACKET_FOOTER:
					case self::STATE_SCAN_FOR_EXPECTED_LENGTH:
						$this->reset_after_packet();
						$this->scan_for_expected_length();
						break;
					case self::STATE_READ_PACKET_BODY:
						if (
							$this->packet_type &&
							'#pack' !== $this->packet_type &&
							$this->packet_bytes_read >= $this->expected_length
						) {
							// We've read the entire packet body.
							// Let's emit the '#packet-footer' token.
							$this->state = self::STATE_PACKET_FOOTER;
						} else {
							$this->next_body_chunk();
						}
						break;
				}

				return true;
			}
		} catch ( NotEnoughDataException $e ) {
			$this->is_paused_at_incomplete_input = true;

			return false;
		}
	}

	public function get_token_type() {
		if ( self::STATE_PACKET_FOOTER === $this->state ) {
			return '#packet-footer';
		} elseif ( $this->packet_type && ! $this->packet_bytes_read ) {
			return '#packet-header';
		} elseif ( $this->packet_type && $this->packet_bytes_read ) {
			return '#packet-body';
		}

		return null;
	}

	private function scan_for_expected_length() {
		$this->reset_after_packet();
		$at = $this->bytes_read_so_far;

		// Need at least 4 bytes for the length hex.
		if ( $at + 4 > strlen( $this->bytes ) ) {
			throw new NotEnoughDataException();
		}

		$length_hex = substr( $this->bytes, $at, 4 );
		if ( 'PACK' === $length_hex ) {
			$this->packet_type       = '#pack';
			$this->body_chunk        = '';
			$this->expected_length   = 0;
			$this->packet_bytes_read = 0;
			$this->state             = self::STATE_READ_PACKET_BODY;

			return;
		} elseif ( ! preg_match( '/^[0-9a-f]{4}$/', $length_hex ) ) {
			throw new GitException( 'Invalid packet length hex "' . $length_hex . '" at offset ' . $this->get_offset_in_stream() );
		}

		$at += 4;
		switch ( $length_hex ) {
			case '0000':
				$this->packet_type       = '#flush';
				$this->bytes_read_so_far = $at;
				$this->expected_length   = 0;
				$this->packet_bytes_read = 0;
				$this->body_chunk        = '';

				return;

			case '0001':
				$this->packet_type       = '#delimiter';
				$this->bytes_read_so_far = $at;
				$this->expected_length   = 0;
				$this->packet_bytes_read = 0;
				$this->body_chunk        = '';

				return;

			case '0002':
				$this->packet_type       = '#response-end';
				$this->bytes_read_so_far = $at;
				$this->is_finished       = true;
				$this->expected_length   = 0;
				$this->packet_bytes_read = 0;
				$this->body_chunk        = '';

				return;
		}

		$length                  = hexdec( $length_hex ) - 4;
		$this->packet_type       = '#packet';
		$this->expected_length   = $length;
		$this->packet_bytes_read = 0;
		$this->body_chunk        = '';
		$this->bytes_read_so_far = $at;
		$this->state             = self::STATE_READ_PACKET_BODY;
	}

	public function is_command() {
		return (
			'#flush' === $this->packet_type ||
			'#delimiter' === $this->packet_type ||
			'#response-end' === $this->packet_type
		);
	}

	private function reset_after_packet() {
		$this->body_chunk        = '';
		$this->packet_type       = null;
		$this->expected_length   = 0;
		$this->packet_bytes_read = 0;
		$this->state             = self::STATE_SCAN_FOR_EXPECTED_LENGTH;
	}

	private function get_offset_in_stream() {
		return $this->bytes_already_forgotten + $this->bytes_read_so_far;
	}

	private function next_body_chunk() {
		if ( '#pack' === $this->packet_type ) {
			$next_chunk = substr( $this->bytes, $this->bytes_read_so_far );
			if ( ! $next_chunk ) {
				throw new NotEnoughDataException();
			}
			$this->body_chunk         = $next_chunk;
			$this->bytes_read_so_far += strlen( $next_chunk );
			$this->packet_bytes_read += strlen( $next_chunk );

			return;
		}

		$remaining  = $this->expected_length - $this->packet_bytes_read;
		$chunk_size = min( 65536, $remaining );
		if ( $this->bytes_read_so_far + $chunk_size > strlen( $this->bytes ) ) {
			throw new NotEnoughDataException();
		}

		$chunk                    = substr( $this->bytes, $this->bytes_read_so_far, $chunk_size );
		$this->bytes_read_so_far += $chunk_size;
		$this->packet_bytes_read += $chunk_size;
		$this->body_chunk         = $chunk;

		if ( $this->packet_bytes_read === $this->expected_length ) {
			if ( 0 === substr_compare( $this->body_chunk, "\n", - strlen( "\n" ) ) ) {
				$this->body_chunk = substr( $this->body_chunk, 0, - 1 );
			}
		}
	}

	public function append_bytes( $bytes ) {
		$this->bytes_already_forgotten      += $this->bytes_read_so_far;
		$this->bytes                         = substr( $this->bytes, $this->bytes_read_so_far );
		$this->bytes_read_so_far             = 0;
		$this->bytes                        .= $bytes;
		$this->is_paused_at_incomplete_input = false;
	}

	public function get_body_chunk() {
		return $this->body_chunk;
	}

	public function get_packet_type() {
		return $this->packet_type;
	}

	public function is_paused_at_incomplete_input(): bool {
		return $this->is_paused_at_incomplete_input;
	}

	public function is_finished(): bool {
		return $this->is_finished;
	}

	public function is_chunk_finished(): bool {
		return $this->packet_bytes_read === $this->expected_length;
	}
}
