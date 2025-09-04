<?php

namespace WordPress\Merge\Merge;

class MergeConflict {

	public $ours;
	public $theirs;
	public $options;

	public function __construct(
		string $ours,
		string $theirs,
		$options = array()
	) {
		$this->ours    = $ours;
		$this->theirs  = $theirs;
		$this->options = $options;
	}

	public function get_message() {
		return $this->options['message'] ?? '';
	}
}
