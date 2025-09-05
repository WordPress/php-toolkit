<?php

namespace WordPress\Merge\Diff;

use DiffMatchPatch\DiffMatchPatch;

class MyersDiffer implements Differ {
	private $dmp;

	public function __construct() {
		$this->dmp = new DiffMatchPatch();
	}

	public function diff( string $old_string, string $new_string ): Diff {
		$diff = $this->dmp->diff_main( $old_string, $new_string, false );
		$this->dmp->diff_cleanupSemantic( $diff );
		$this->dmp->diff_cleanupEfficiency( $diff );

		return new Diff( $diff );
	}
}
