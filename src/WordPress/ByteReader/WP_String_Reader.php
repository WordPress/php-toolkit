<?php

namespace WordPress\ByteReader;

use WordPress\Error\WordPressException;

class WP_String_Reader extends WP_Byte_Reader {

	const STATE_STREAMING = '#streaming';
	const STATE_FINISHED  = '#finished';

	protected $string;
	protected $chunk_size;
	protected $offset = 0;
	protected $output_bytes = '';
	protected $last_chunk_size = 0;
	protected $state = self::STATE_STREAMING;

	static public function create($string, $chunk_size = 8096) {
		if (!is_string($string)) {
			throw new WordPressException('Input must be a string');
		}
		return new self($string, $chunk_size);
	}

	private function __construct($string, $chunk_size) {
		$this->string = $string;
		$this->chunk_size = $chunk_size;
	}

	public function length(): ?int {
		return strlen($this->string);
	}

	public function tell(): int {
		return $this->offset - $this->last_chunk_size;
	}

	public function seek($offset): bool {
		if (!is_int($offset)) {
			throw new WordPressException('Cannot set cursor to a non-integer offset.');
		}
		if ($offset < 0 || $offset > strlen($this->string)) {
			throw new WordPressException('Cannot set cursor to an offset outside the string bounds.');
		}
		$this->offset = $offset;
		$this->last_chunk_size = 0;
		$this->output_bytes = '';
		return true;
	}

	public function close(): bool {
		$this->state = static::STATE_FINISHED;
		return true;
	}

	public function is_finished(): bool {
		return !$this->output_bytes && $this->state === static::STATE_FINISHED;
	}

	public function get_bytes(): string {
		return $this->output_bytes;
	}

	public function next_bytes(): bool {
		$this->output_bytes = '';
		$this->last_chunk_size = 0;

		if ($this->is_finished()) {
			return false;
		}

		if ($this->offset >= strlen($this->string)) {
			$this->state = static::STATE_FINISHED;
			return false;
		}

		$bytes = substr($this->string, $this->offset, $this->chunk_size);
		$this->last_chunk_size = strlen($bytes);
		$this->offset += $this->last_chunk_size;
		$this->output_bytes = $bytes;

		return true;
	}
}
