<?php

namespace WordPress\Merge\Validate;

interface MergeValidator {

	/**
	 * Validates the merge result.
	 *
	 * @param  string  $html  The merged HTML.
	 *
	 * @throws InvalidMergeException if the merge result is invalid.
	 */
	public function validate( $html );
}
