<?php

namespace WordPress\ByteReader;

/**
 * Experimental interface for streaming, seekable byte readers.
 */
abstract class WP_Byte_Reader {
	abstract public function length();
	abstract public function tell(): int;
	abstract public function seek( int $offset ): bool;
	abstract public function is_finished(): bool;
	abstract public function next_bytes(): bool;
	abstract public function get_bytes(): ?string;
	abstract public function get_last_error(): ?string;
	public function read_all(): string {
		$buffer = '';
		while( $this->next_bytes() ) {
			$buffer .= $this->get_bytes();
		}
		if( $this->get_last_error() ) {
			return false;
		}
		return $buffer;
	}
}
