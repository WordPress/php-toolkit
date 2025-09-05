<?php

namespace WordPress\Merge\Merge;

use WordPress\Merge\Diff\Diff;
use WordPress\Merge\MergeConflictException;

class LineMerger implements Merger {

	public function merge( Diff $diff_ab, Diff $diff_ac ): MergeResult {
		$lines_a = $this->to_chunks( $diff_ab->get_changes() );
		$lines_b = $this->to_chunks( $diff_ac->get_changes() );

		$results = array();
		$n       = max( count( $lines_a ), count( $lines_b ) );

		for ( $i = 0; $i < $n; $i ++ ) {
			$line_a = $lines_a[ $i ] ?? array(
				'base'     => null,
				'deleted'  => false,
				'inserted' => '',
			);
			$line_b = $lines_b[ $i ] ?? array(
				'base'     => null,
				'deleted'  => false,
				'inserted' => '',
			);

			// Handle conflicting insertions
			if ( $line_a['inserted'] !== '' && $line_b['inserted'] !== '' && $line_a['inserted'] !== $line_b['inserted'] ) {
				$results[] = new MergeConflict(
					$line_a['inserted'],
					$line_b['inserted'],
					array(
						'message' => 'Conflicting insertions',
					)
				);
				continue;
			}

			// Handle base line differences
			if ( $line_a['base'] === null || $line_b['base'] === null ) {
				if ( $line_a['base'] !== null ) {
					$results[] = $line_a['base'] . $line_a['inserted'];
				} elseif ( $line_b['base'] !== null ) {
					$results[] = $line_b['base'] . $line_b['inserted'];
				}
				continue;
			}

			// Conflict if base lines are different
			if ( $line_a['base'] !== $line_b['base'] ) {
				$results[] = new MergeConflict(
					$line_a['base'],
					$line_b['base'],
					array(
						'message' => 'Mismatched base lines',
					)
				);
				continue;
			}

			// Handle deletions
			if ( $line_a['deleted'] || $line_b['deleted'] ) {
				if ( $line_a['deleted'] && $line_b['deleted'] ) {
					continue;
				}

				$deletion    = $line_a['deleted'] ? $line_a : $line_b;
				$non_deletion = $line_a['deleted'] ? $line_b : $line_a;

				if ( $deletion['inserted'] ) {
					if ( $non_deletion['inserted'] !== '' ) {
						$results[] = new MergeConflict(
							$deletion['inserted'],
							$non_deletion['inserted'],
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
			$results[]     = $line_a['base'];
			$only_insertion = $line_a['inserted'] !== '' ? $line_a['inserted'] : $line_b['inserted'];
			$results[]     = $only_insertion;
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
