<?php

namespace WordPress\Merge\Merge;

use WordPress\Merge\Diff\Diff;

interface Merger {
	/**
	 * Performs a three-way merge using two diffs against a common base.
	 *
	 * @param  Diff $diff_ab  The diff between base and branch A
	 * @param  Diff $diff_ac  The diff between base and branch B
	 *
	 * @return MergeResult The merged result
	 */
	public function merge( Diff $diff_ab, Diff $diff_ac ): MergeResult;
}
