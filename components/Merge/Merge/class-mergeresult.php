<?php

namespace WordPress\Merge\Merge;

class MergeResult {
	private $results;

	public function __construct( array $results ) {
		$this->results = $results;
	}

	public function get_merged_content(): string {
		$mergedContent = '';
		foreach ( $this->results as $result ) {
			if ( $result instanceof MergeConflict ) {
				$mergedContent .= "\n<<<<<<< HEAD\n";
				$mergedContent .= $result->ours . "\n";
				$mergedContent .= "=======\n";
				$mergedContent .= $result->theirs . "\n";
				$mergedContent .= ">>>>>>> incoming \n";
			} else {
				$mergedContent .= $result;
			}
		}

		return $mergedContent;
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
