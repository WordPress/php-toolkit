<?php

// @TODO: Likely remove this file.

namespace WordPress\Merge;

use DiffMatchPatch\Diff;
use DiffMatchPatch\DiffMatchPatch;
use WordPress\Merge\MergeException;

class DiffMatchPatchMergeDriver {
	// implements MergeDriver {

	private $dmp;

	public function __construct() {
		$this->dmp = new DiffMatchPatch();
	}

	/**
	 *
	 */
	public function three_way_merge( $common_parent, $branch_a, $branch_b, $options = array() ) {
		$diff_a = $this->dmp->diff_main( $common_parent, $branch_a );
		$this->dmp->diff_cleanupSemantic( $diff_a );
		// $this->dmp->diff_cleanupEfficiency($diff_a);

		$diff_b = $this->dmp->diff_main( $common_parent, $branch_b );
		// $this->dmp->diff_cleanupSemantic($diff_b);
		// $this->dmp->diff_cleanupEfficiency($diff_b);

		$patch_a                      = $this->dmp->patch_make( $common_parent, $diff_a );
		list( $merged_a, $applied_a ) = $this->dmp->patch_apply( $patch_a, $common_parent );
		if ( ! $applied_a ) {
			throw new MergeException( 'Diff failed to apply cleanly onto common parent' );
		}

		$mode = $options['mode'] ?? 'fallback';
		if ( $mode === 'dmp' ) {
			$patch_b  = $this->dmp->patch_make( $common_parent, $diff_b );
			$merged_b = $this->apply_patch( $merged_a, $patch_b );
		} else {
			try {
				// Always try rebasing the patch
				$diff_b   = $this->rebase_diff( $diff_a, $diff_b, $merged_a );
				$patch_b  = $this->dmp->patch_make( $merged_a, $diff_b );
				$merged_b = $this->apply_patch( $merged_a, $patch_b );
			} catch ( MergeConflictException $e ) {
				if ( $mode === 'rebase' ) {
					throw $e;
				}
				// If the rebasing failed, fall back to fuzzy diff-match-patch merging
				$patch_b  = $this->dmp->patch_make( $common_parent, $diff_b );
				$merged_b = $this->apply_patch( $merged_a, $patch_b );
			}
		}

		return $merged_b;
	}

	private function apply_patch( $text, $patch ) {
		list( $merged, $changes_applied ) = $this->dmp->patch_apply( $patch, $text );
		// @TODO: Reason about $changes_applied. Sometimes it contains
		// false entries when the $merged value looks great.
		return $merged;
	}

	public function apply_diff( $text, $diff ) {
		$patch = $this->dmp->patch_make( $text, $diff );

		return $this->dmp->patch_apply( $patch, $text );
	}

	public function diff( $old_string, $new_string ) {
		return $this->dmp->diff_main( $old_string, $new_string );
	}

	public function rebase_diff( $base_diff, $diff_to_rebase, $document_after_base_diff ) {
		// Convert the diffs to format that makes rebasing easier
		$diff_a = self::dmp_diff_to_annotated_diff( $base_diff );
		$diff_b = self::dmp_diff_to_annotated_diff( $diff_to_rebase );
		// print_r([
		// 'diff_a' => $diff_a,
		// 'diff_b' => $diff_b,
		// 'delta_a' => $this->diff_as_delta($base_diff),
		// 'delta_b' => $this->diff_as_delta($diff_to_rebase),
		// ]);

		// Do the rebase
		$i_a = 0;
		$i_b = 0;

		$rebased_diff      = array();
		$accumulated_shift = 0;
		while ( $i_b < count( $diff_b ) ) {
			$change_b           = $diff_b[ $i_b ];
			$change_b['start'] += $accumulated_shift;
			if ( ! isset( $diff_a[ $i_a ] ) ) {
				$rebased_diff[] = $change_b;
				++$i_b;
				continue;
			}

			$change_a = $diff_a[ $i_a ];

			if ( $change_a['start'] === $change_b['start'] ) {
				/**
				 * version a: {"level": 1}
				 * version b: {"level": 20}
				 * patch b:   =10\t-1\t+20
				 *
				 * version c: {"level": 3}
				 * patch c:   =10\t-1\t+3
				 *
				 * If we apply insertions from both patches, we'll get {"level": 320}
				 * which is not what we want. Let's throw and fall back to the fuzzy
				 * merging from diff-match-patch.
				 */
				if ( $change_a['type'] === Diff::INSERT && $change_b['type'] === Diff::INSERT ) {
					throw new MergeConflictException( 'Two insertions at the same start position' );
				}
			}

			if ( $change_b['start'] < $change_a['start'] ) {
				$rebased_diff[] = $change_b;
				++$i_b;
			} else {
				switch ( $change_a['type'] ) {
					case Diff::INSERT:
						$accumulated_shift += $change_a['length'];
						break;
					case Diff::DELETE:
						if ( $change_a['start'] + $change_a['length'] > $change_b['start'] ) {
							switch ( $change_b['type'] ) {
								case Diff::INSERT:
									if ( $change_a['start'] !== $change_b['start'] ) {
										throw new MergeConflictException( 'Deletion in A intersects with an insertion in B' );
									}
									break;
								case Diff::DELETE:
									if ( $change_b['start'] + $change_b['length'] <= $change_a['start'] + $change_a['length'] ) {
										// If deletion B is contained within deletion A, we can just ignore it
										++$i_b;
										var_dump(
											array(
												'change_a' => $change_a,
												'change_b' => $change_b,
											)
										);
										// if($change_b['start'] === $change_a['start']) {
										// Diff b already accounts for the shift from this change, let's add it to
										// the accumulator to make sure we won't count it twice.
										$accumulated_shift += $change_b['length'];
										// }
										continue 3;
									} else {
										// Otherwise we can merge the two deletions
										$merged_deletion = array(
											'type'   => Diff::DELETE,
											'start'  => $change_a['start'],
											'length' => $change_b['length'] + ( $change_b['start'] - $change_b['start'] ),
										);
										// Store the deleted substring for debugging
										$merged_deletion['string'] = mb_substr(
											$document_after_base_diff,
											$merged_deletion['start'],
											$merged_deletion['length']
										);
										$rebased_diff[]            = $merged_deletion;
										// Move past both deletions
										++$i_b;
										++$i_a;
									}
									break;
							}
						}

						// Shift by the number of characters that are being deleted, but
						// only up to the point where deletion A starts.
						$accumulated_shift -= min(
							$change_a['length'],
							$change_b['start'] - $change_a['start']
						);
						break;
				}
				++$i_a;
			}
		}

		// Convert the rebased diff back to DMP format
		$cursor                = 0;
		$dmp_diff              = array();
		$final_document_length = strlen( $document_after_base_diff ) + $accumulated_shift;

		foreach ( $rebased_diff as $change ) {
			if ( $change['start'] > $cursor ) {
				$dmp_diff[] = array(
					Diff::EQUAL,
					substr( $document_after_base_diff, $cursor, $change['start'] - $cursor ),
				);
			}
			switch ( $change['type'] ) {
				case Diff::INSERT:
					$dmp_diff[] = array(
						Diff::INSERT,
						$change['string'],
					);
					break;
				case Diff::DELETE:
					$dmp_diff[] = array(
						Diff::DELETE,
						substr( $document_after_base_diff, $change['start'], $change['length'] ),
					);
					break;
			}
			$cursor = $change['start'] + $change['length'];
		}

		if ( $cursor < $final_document_length ) {
			$dmp_diff[] = array(
				Diff::EQUAL,
				substr( $document_after_base_diff, $cursor, $final_document_length - $cursor ),
			);
		}

		// print_r([
		// 'diff' => $base_diff,
		// 'diff_a' => $this->diff_as_delta($base_diff),
		// 'diff_b' => $this->diff_as_delta($diff_to_rebase),
		// 'diff_r' => $this->diff_as_delta($dmp_diff),
		// ]);
		return $dmp_diff;
	}


	private static function dmp_diff_to_annotated_diff( $diff ) {
		$cursor         = 0;
		$annotated_diff = array();
		foreach ( $diff as $change ) {
			switch ( $change[0] ) {
				case Diff::EQUAL:
					$cursor += mb_strlen( $change[1] );
					break;
				case Diff::INSERT:
					$annotated_change = array(
						'type'   => Diff::INSERT,
						'start'  => $cursor,
						'length' => mb_strlen( $change[1] ),
						'string' => $change[1],
					);
					$cursor          += $annotated_change['length'];
					$annotated_diff[] = $annotated_change;
					break;
				case Diff::DELETE:
					$annotated_change = array(
						'type'   => Diff::DELETE,
						'start'  => $cursor,
						'length' => mb_strlen( $change[1] ),
						'string' => $change[1],
					);
					$cursor          += $annotated_change['length'];
					$annotated_diff[] = $annotated_change;
					break;
			}
		}

		return $annotated_diff;
	}
}
