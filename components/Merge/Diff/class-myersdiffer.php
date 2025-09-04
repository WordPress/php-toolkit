<?php

namespace WordPress\Merge\Diff;

use DiffMatchPatch\DiffMatchPatch;

class MyersDiffer implements Differ {
	private $dmp;

	public function __construct() {
		$this->dmp = new DiffMatchPatch();
	}

	public function diff( string $oldString, string $newString ): Diff {
		$diff = $this->dmp->diff_main( $oldString, $newString, false );
		$this->dmp->diff_cleanupSemantic( $diff );
		$this->dmp->diff_cleanupEfficiency( $diff );

		return new Diff( $diff );
	}
}
