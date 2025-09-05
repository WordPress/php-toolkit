<?php

namespace WordPress\Merge\Merge;

class MergeResult {
	private $results;

	public function __construct( array $results ) {
		$this->results = $results;
	}

	public function get_merged_content(): string {
		$merged_content = '';
		foreach ( $this->results as $result ) {
			if ( $result instanceof MergeConflict ) {
				$merged_content .= "\n<<<<<<< HEAD\n";
				$merged_content .= $result->ours . "\n";
				$merged_content .= "=======\n";
				$merged_content .= $result->theirs . "\n";
				$merged_content .= ">>>>>>> incoming \n";
			} else {
				$merged_content .= $result;
			}
		}

		return $merged_content;
	}

	public function has_conflicts(): bool {
		foreach ( $this->results as $result ) {
			if ( $result instanceof MergeConflict ) {
				return true;
			}
		}

		return false;
	}

	public function get_conflicts(): array {
		$conflicts = array();
		foreach ( $this->results as $result ) {
			if ( $result instanceof MergeConflict ) {
				$conflicts[] = $result;
			}
		}

		return $conflicts;
	}
}
