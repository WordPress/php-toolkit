<?php

namespace WordPress\Merge\Merge;

class Chunk {

	public $base;
	public $inserted;
	public $deleted;

	public function __construct(
		string $base,
		string $inserted = '',
		bool $deleted = false,
	) {
		$this->base     = $base;
		$this->inserted = $inserted;
		$this->deleted  = $deleted;
	}
}
