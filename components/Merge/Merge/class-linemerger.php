<?php

namespace WordPress\Merge\Merge;

use WordPress\Merge\Diff\Diff;
use WordPress\Merge\MergeConflictException;

class LineMerger implements Merger {

	public function merge( Diff $diffAB, Diff $diffAC ): MergeResult {
		$linesA = $this->to_chunks( $diffAB->get_changes() );
		$linesB = $this->to_chunks( $diffAC->get_changes() );

		$results = array();
		$n       = max( count( $linesA ), count( $linesB ) );

		for ( $i = 0; $i < $n; $i ++ ) {
			$lineA = $linesA[ $i ] ?? array(
				'base'     => null,
				'deleted'  => false,
				'inserted' => '',
			);
			$lineB = $linesB[ $i ] ?? array(
				'base'     => null,
				'deleted'  => false,
				'inserted' => '',
			);

			// Handle conflicting insertions
			if ( $lineA['inserted'] !== '' && $lineB['inserted'] !== '' && $lineA['inserted'] !== $lineB['inserted'] ) {
				$results[] = new MergeConflict(
					$lineA['inserted'],
					$lineB['inserted'],
					array(
						'message' => 'Conflicting insertions',
					)
				);
				continue;
			}

			// Handle base line differences
			if ( $lineA['base'] === null || $lineB['base'] === null ) {
				if ( $lineA['base'] !== null ) {
					$results[] = $lineA['base'] . $lineA['inserted'];
				} elseif ( $lineB['base'] !== null ) {
					$results[] = $lineB['base'] . $lineB['inserted'];
				}
				continue;
			}

			// Conflict if base lines are different
			if ( $lineA['base'] !== $lineB['base'] ) {
				$results[] = new MergeConflict(
					$lineA['base'],
					$lineB['base'],
					array(
						'message' => 'Mismatched base lines',
					)
				);
				continue;
			}

			// Handle deletions
			if ( $lineA['deleted'] || $lineB['deleted'] ) {
				if ( $lineA['deleted'] && $lineB['deleted'] ) {
					continue;
				}

				$deletion    = $lineA['deleted'] ? $lineA : $lineB;
				$nonDeletion = $lineA['deleted'] ? $lineB : $lineA;

				if ( $deletion['inserted'] ) {
					if ( $nonDeletion['inserted'] !== '' ) {
						$results[] = new MergeConflict(
							$deletion['inserted'],
							$nonDeletion['inserted'],
							array(
								'message' => 'Deletion with conflicting insertion',
							)
						);
						continue;
					} else {
						$results[] = $deletion['inserted'];
					}
				}
				continue;
			}

			// Default case: use base line and any insertion
			$results[]     = $lineA['base'];
			$onlyInsertion = $lineA['inserted'] !== '' ? $lineA['inserted'] : $lineB['inserted'];
			$results[]     = $onlyInsertion;
		}

		return new MergeResult( $results );
	}

	/**
	 * Extract lines from diff changes, similar to ChunkMergeStrategy's convertDiffToChunks
	 * but optimized for line-based merging
	 */
	private function to_chunks( array $diff ): array {
		$lines   = array();
		$current = array(
			'base'     => null,
			'deleted'  => false,
			'inserted' => '',
		);

		foreach ( $diff as $part ) {
			list( $op, $text ) = $part;

			if ( $op === Diff::DIFF_DELETE || $op === Diff::DIFF_EQUAL ) {
				if ( $current['base'] !== null || $current['inserted'] !== '' ) {
					$lines[] = $current;
					$current = array(
						'base'     => null,
						'deleted'  => false,
						'inserted' => '',
					);
				}
				$current['base']    = $text;
				$current['deleted'] = ( $op === Diff::DIFF_DELETE );
			} elseif ( $op === Diff::DIFF_INSERT ) {
				$current['inserted'] .= $text;
			}
		}

		if ( $current['base'] !== null || $current['inserted'] !== '' ) {
			$lines[] = $current;
		}

		return $lines;
	}

	/**
	 * Extract lines from diff changes, similar to ChunkMergeStrategy's convertDiffToChunks
	 * but optimized for line-based merging
	 */
	private function to_line_by_line_chunks( array $diff ): array {
		$chunks = array();
		while ( count( $diff ) ) {
			$line      = array_shift( $diff );
			$next_line = $diff[0] ?? null;

			$deletion  = $line[0] == Diff::DIFF_DELETE ? $line : ( $next_line && $next_line[0] == Diff::DIFF_DELETE ? $next_line : null );
			$insertion = $line[0] == Diff::DIFF_INSERT ? $line : ( $next_line && $next_line[0] == Diff::DIFF_INSERT ? $next_line : null );
			if ( $deletion && $insertion ) {
				$chunks[] = array(
					'base'     => $deletion[1],
					'deleted'  => true,
					'inserted' => $insertion[1],
				);
				array_shift( $diff );
				continue;
			}

			switch ( $line[0] ) {
				case Diff::DIFF_EQUAL:
					$chunks[] = array(
						'base'     => $line[1],
						'deleted'  => false,
						'inserted' => '',
					);
					break;
				case Diff::DIFF_INSERT:
					$chunks[] = array(
						'base'     => null,
						'deleted'  => false,
						'inserted' => $line[1],
					);
					break;
				case Diff::DIFF_DELETE:
					$chunks[] = array(
						'base'     => $line[1],
						'deleted'  => true,
						'inserted' => '',
					);
					break;
			}
		}

		return $chunks;
	}
}
