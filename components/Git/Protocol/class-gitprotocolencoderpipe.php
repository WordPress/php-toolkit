<?php

namespace WordPress\Git\Protocol;

use WordPress\ByteStream\ReadStream\BaseByteReadStream;
use WordPress\Git\Model\Tree;

class GitProtocolEncoderPipe extends BaseByteReadStream {

	private $packfile_pipe;
	private $operation_queue = array();
	private $operation;

	public static function encode_packet_lines( array $payloads, $channel_code = '' ): string {
		$lines = array();
		foreach ( $payloads as $payload ) {
			$lines[] = self::encode_packet_line( $payload, $channel_code );
		}

		return implode( '', $lines );
	}

	public static function encode_packet_line( string $payload, $channel_code = '' ): string {
		if ( '0000' === $payload || '0001' === $payload || '0002' === $payload ) {
			if ( ! $channel_code ) {
				return $payload;
			}
		}

		if ( '' !== $channel_code ) {
			$payload = $channel_code . $payload;
		}
		$length = sprintf( '%04x', strlen( $payload ) + 4 );

		return $length . $payload;
	}

	public static function encode_variable_length( $number ) {
		$result = '';
		do {
			$byte     = $number & 0x7F;
			$number >>= 7;
			if ( $number > 0 ) {
				$byte |= 0x80;
			}
			$result .= chr( $byte );
		} while ( $number > 0 );

		return $result;
	}

	public static function encode_tree_bytes( Tree $tree ) {
		$tree_bytes = '';
		foreach ( $tree->entries as $entry ) {
			$tree_bytes .= $entry->mode . ' ' . $entry->name . "\0" . hex2bin( $entry->hash );
		}

		return $tree_bytes;
	}

	protected function internal_pull( $n ): string {
		if ( null === $this->operation ) {
			$this->operation = array_shift( $this->operation_queue );
		}

		$operation = $this->operation;
		switch ( $operation['type'] ) {
			case 'raw-bytes':
				$this->operation = null;

				return $operation['bytes'];

			case 'packet-line':
				$this->operation = null;

				return self::encode_packet_line( $operation['chunk'], $operation['channel_code'] );

			case 'packet-lines':
				$this->operation = null;

				return self::encode_packet_lines( $operation['chunk'], $operation['channel_code'] );

			case 'packfile':
				if ( ! $this->packfile_pipe ) {
					$this->packfile_pipe = PackfileEncoderReadStream::create( $operation['repository'], $operation['pack_objects'] );
				} elseif ( $this->packfile_pipe->reached_end_of_data() ) {
					$this->packfile_pipe->close_reading();
					$this->packfile_pipe = null;
					$this->operation     = null;

					return '';
				}
				$available = $this->packfile_pipe->pull( 8096 );
				$chunk     = $this->packfile_pipe->consume( $available );
				if ( $operation['multiplex'] ) {
					return self::encode_packet_line( $chunk, "\x01" );
				}

				return $chunk;
			default:
				return '';
		}
	}

	public function reached_end_of_data(): bool {
		return empty( $this->operation_queue ) && ! $this->operation;
	}

	public function close_writing(): void {
	}

	public function append_sideband_bytes( $bytes ): void {
		$this->operation_queue[] = array(
			'type'  => 'raw-bytes',
			'chunk' => self::encode_packet_lines( $bytes, "\x01" ),
		);
	}

	public function append_progress_packet_line( $chunk ): void {
		$this->operation_queue[] = array(
			'type'         => 'packet-line',
			'chunk'        => $chunk,
			'channel_code' => "\x02",
		);
	}

	public function append_error_packet_line( $chunk ): void {
		$this->operation_queue[] = array(
			'type'         => 'packet-line',
			'chunk'        => $chunk,
			'channel_code' => "\x03",
		);
	}

	public function append_sideband_packet_line( $packet_line ): void {
		$this->operation_queue[] = array(
			'type'         => 'packet-line',
			'chunk'        => self::encode_packet_line( $packet_line ),
			'channel_code' => "\x01",
		);
	}

	public function append_packet_line( $line, $channel_code = '' ): void {
		$this->operation_queue[] = array(
			'type'         => 'packet-line',
			'chunk'        => $line,
			'channel_code' => $channel_code,
		);
	}

	public function append_raw_bytes( $bytes ): void {
		$this->operation_queue[] = array(
			'type'  => 'raw-bytes',
			'bytes' => $bytes,
		);
	}

	public function append_packet_lines( $lines, $channel_code = '' ): void {
		$this->operation_queue[] = array(
			'type'         => 'packet-lines',
			'chunk'        => $lines,
			'channel_code' => $channel_code,
		);
	}

	public function append_packfile( $repository, $pack_objects, $multiplex = false ): void {
		$this->operation_queue[] = array(
			'type'         => 'packfile',
			'repository'   => $repository,
			'pack_objects' => $pack_objects,
			'object_index' => 0,
			'multiplex'    => $multiplex,
		);
	}
}
