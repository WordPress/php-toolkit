<?php

namespace WordPress\Merge\Merge;

use WordPress\Merge\Diff\Diff;
use function str_starts_with;
use function str_ends_with;

class ChunkMerger implements Merger {

	public $chunksA;
	public $chunksB;

	public function merge( Diff $diffAB, Diff $diffAC ): MergeResult {
		list( $chunksA, $chunksB ) = $this->ensureChunks( $diffAB->get_changes(), $diffAC->get_changes() );
		$this->chunksA             = $chunksA;
		$this->chunksB             = $chunksB;

		$results = array();
		$n       = max( count( $chunksA ), count( $chunksB ) );
		for ( $i = 0; $i < $n; $i++ ) {
			$chunkA = $chunksA[ $i ] ?? array(
				'base' => null,
				'deleted' => false,
				'inserted' => '',
			);
			$chunkB = $chunksB[ $i ] ?? array(
				'base' => null,
				'deleted' => false,
				'inserted' => '',
			);

			if (
				$chunkA['inserted'] !== '' &&
				$chunkB['inserted'] !== '' &&
				! str_starts_with( $chunkA['inserted'], $chunkB['inserted'] ) &&
				! str_starts_with( $chunkB['inserted'], $chunkA['inserted'] ) &&
				! str_ends_with( $chunkA['inserted'], $chunkB['inserted'] ) &&
				! str_ends_with( $chunkB['inserted'], $chunkA['inserted'] )
			) {
				$results[] = new MergeConflict(
					$chunkA['inserted'],
					$chunkB['inserted'],
					array(
						'message' => 'Conflicting insertions',
					)
				);
				continue;
			}

			if ( $chunkA['base'] === null || $chunkB['base'] === null ) {
				if ( $chunkA['base'] !== null ) {
					$results[] = $chunkA['base'] . $chunkA['inserted'];
				} elseif ( $chunkB['base'] !== null ) {
					$results[] = $chunkB['base'] . $chunkB['inserted'];
				}
				continue;
			}

			if ( $chunkA['base'] !== $chunkB['base'] ) {
				$results[] = new MergeConflict(
					$chunkA['base'],
					$chunkB['base'],
					array(
						'message' => 'Mismatched base lines',
					)
				);
				continue;
			}

			if ( $chunkA['deleted'] || $chunkB['deleted'] ) {
				if ( $chunkA['inserted'] && $chunkB['inserted'] && (
					str_starts_with( $chunkA['inserted'], $chunkB['inserted'] ) ||
					str_starts_with( $chunkB['inserted'], $chunkA['inserted'] ) ||
					str_ends_with( $chunkA['inserted'], $chunkB['inserted'] ) ||
					str_ends_with( $chunkB['inserted'], $chunkA['inserted'] )
				) ) {
					$results[] = strlen( $chunkA['inserted'] ) > strlen( $chunkB['inserted'] ) ? $chunkA['inserted'] : $chunkB['inserted'];
					continue;
				}
				if ( $chunkA['deleted'] && $chunkB['deleted'] ) {
					continue;
				}

				$deletion    = $chunkA['deleted'] ? $chunkA : $chunkB;
				$nonDeletion = $chunkA['deleted'] ? $chunkB : $chunkA;

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
					}

					$results[] = $deletion['inserted'];
				} elseif ( trim( $deletion['base'], ' ' ) === '' && $nonDeletion['inserted'] !== '' ) {
					// Sometimes branch A is one space short (e.g. due to a trim()) and branch B
					// adds a meaningful span of text. In this case, we want to ignore the deletion
					// and keep branch B's text.
					$results[] = $nonDeletion['base'];
					$results[] = $nonDeletion['inserted'];
				}
				continue;
			}

			$results[]     = $chunkA['base'];
			$onlyInsertion = $chunkA['inserted'] !== '' ? $chunkA['inserted'] : $chunkB['inserted'];
			$results[]     = $onlyInsertion;
		}

		return new MergeResult( $results );
	}

	public static function ensureChunks( array $diffAB, array $diffAC ): array {
		if ( isset( $diffAB[0]['base'] ) || isset( $diffAC[0]['base'] ) ) {
			return array( $diffAB, $diffAC );
		}

		$boundaries = self::extractBoundaries( $diffAB, $diffAC );
		$diffAB     = self::resliceDiff( $diffAB, $boundaries );
		$diffAC     = self::resliceDiff( $diffAC, $boundaries );

		$chunksA = self::convertDiffToChunks( $diffAB );
		$chunksB = self::convertDiffToChunks( $diffAC );

		return array( $chunksA, $chunksB );
	}

	private static function convertDiffToChunks( array $diff ): array {
		$chunks  = array();
		$current = array(
			'base' => null,
			'deleted' => false,
			'inserted' => '',
		);

		foreach ( $diff as $part ) {
			list( $op, $text ) = $part;

			if ( $op === Diff::DIFF_DELETE || $op === Diff::DIFF_EQUAL ) {
				if ( $current['base'] !== null || $current['inserted'] !== '' ) {
					$chunks[] = $current;
					$current  = array(
						'base' => null,
						'deleted' => false,
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
			$chunks[] = $current;
		}

		return $chunks;
	}

	private static function extractBoundaries( array $diffA, array $diffB ): array {
		$boundaries = array();
		foreach ( array( $diffA, $diffB ) as $diff ) {
			$offset = 0;
			foreach ( $diff as [$op, $text] ) {
				if ( $op === Diff::DIFF_INSERT ) {
					continue;
				}
				if ( $offset !== 0 ) {
					$boundaries[ $offset ] = true;
				}
				$offset += strlen( $text );
			}
		}
		$boundaries = array_keys( $boundaries );
		\sort( $boundaries );

		return $boundaries;
	}

	private static function resliceDiff( array $diff, array $boundaries ): array {
		$boundaries    = array_values( $boundaries );
		$resliced      = array();
		$baseCursor    = 0;
		$boundaryIndex = 0;

		foreach ( $diff as [$op, $text] ) {
			if ( ! $text ) {
				continue;
			}
			if ( $op === Diff::DIFF_INSERT ) {
				$resliced[] = array( $op, $text );
				continue;
			}

			$textLength  = strlen( $text );
			$startOffset = $baseCursor;

			while (
				$boundaryIndex < count( $boundaries ) &&
				$boundaries[ $boundaryIndex ] <= $startOffset
			) {
				++$boundaryIndex;
			}

			while (
				$boundaryIndex < count( $boundaries ) &&
				$boundaries[ $boundaryIndex ] <= $startOffset + $textLength
			) {
				$boundary = $boundaries[ $boundaryIndex ];

				$sliceLength = $boundary - $startOffset;
				if ( $sliceLength > 0 && strlen( $text ) > 0 ) {
					$sliceText  = substr( $text, 0, $sliceLength );
					$resliced[] = array( $op, $sliceText );
				}

				$text         = substr( $text, $sliceLength );
				$startOffset += $sliceLength;
				++$boundaryIndex;
				if ( ! $text ) {
					break;
				}
			}

			if ( $text !== '' ) {
				$resliced[] = array( $op, $text );
			}

			$baseCursor += $textLength;
		}

		return $resliced;
	}
}
