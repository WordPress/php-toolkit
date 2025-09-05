<?php

namespace WordPress\Merge\Diff;

interface Differ {
	/**
	 * Computes the difference between two strings.
	 *
	 * @param  string $old_string  The original string
	 * @param  string $new_string  The new string to compare against
	 *
	 * @return Diff The computed differences
	 */
	public function diff( string $old_string, string $new_string ): Diff;
}
