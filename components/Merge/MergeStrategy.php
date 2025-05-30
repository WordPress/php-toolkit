<?php

namespace WordPress\Merge;

use WordPress\Merge\Diff\Differ;
use WordPress\Merge\Merge\MergeConflict;
use WordPress\Merge\Merge\Merger;
use WordPress\Merge\Merge\MergeResult;
use WordPress\Merge\Validate\InvalidMergeException;
use WordPress\Merge\Validate\MergeValidator;

class MergeStrategy {
	private $differ;
	private $merger;
	private $validator;

	public function __construct( Differ $differ, Merger $merger, ?MergeValidator $validator = null ) {
		$this->differ    = $differ;
		$this->merger    = $merger;
		$this->validator = $validator;
	}

	/**
	 * Performs a three-way merge between a common base and two branches.
	 *
	 * @param  string  $base  The common base version
	 * @param  string  $branchA  First branch version
	 * @param  string  $branchB  Second branch version
	 *
	 * @return MergeResult The merged result
	 */
	public function merge( string $base, string $branchA, string $branchB ): MergeResult {
		// Compute diffs between base and each branch
		$diffAB = $this->differ->diff( $base, $branchA );
		$diffAC = $this->differ->diff( $base, $branchB );

		// Perform the merge using the configured merge strategy
		$merge_result = $this->merger->merge( $diffAB, $diffAC );

		if ( ! $this->validator ) {
			return $merge_result;
		}

		try {
			$this->validator->validate( $merge_result->get_merged_content() );
		} catch ( InvalidMergeException $e ) {
			return new MergeResult(
				array(
					new MergeConflict(
						$branchA,
						$branchB,
						array(
							'message' => $e->getMessage(),
						)
					),
				)
			);
		}

		return $merge_result;
	}
}
