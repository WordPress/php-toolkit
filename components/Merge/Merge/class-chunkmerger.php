<?php

namespace WordPress\Merge\Merge;

use WordPress\Merge\Diff\Diff;

use function sort;

class ChunkMerger implements Merger {

	public $chunks_a;
	public $chunks_b;

	public function merge( Diff $diff_ab, Diff $diff_ac ): MergeResult {
		list( $chunks_a, $chunks_b ) = $this->ensureChunks( $diff_ab->get_changes(), $diff_ac->get_changes() );
		$this->chunks_a              = $chunks_a;
		$this->chunks_b              = $chunks_b;

		$results = array();
		$n       = max( count( $chunks_a ), count( $chunks_b ) );
		for ( $i = 0; $i < $n; $i++ ) {
			$chunk_a = $chunks_a[ $i ] ?? array(
				'base'     => null,
				'deleted'  => false,
				'inserted' => '',
			);
			$chunk_b = $chunks_b[ $i ] ?? array(
				'base'     => null,
				'deleted'  => false,
				'inserted' => '',
			);

			if (
				'' !== $chunk_a['inserted'] &&
				'' !== $chunk_b['inserted'] &&
				0 !== strncmp( $chunk_a['inserted'], $chunk_b['inserted'], strlen( $chunk_b['inserted'] ) ) &&
				0 !== strncmp( $chunk_b['inserted'], $chunk_a['inserted'], strlen( $chunk_a['inserted'] ) ) &&
				0 !== substr_compare( $chunk_a['inserted'], $chunk_b['inserted'], - strlen( $chunk_b['inserted'] ) ) &&
				0 !== substr_compare( $chunk_b['inserted'], $chunk_a['inserted'], - strlen( $chunk_a['inserted'] ) )
			) {
				$results[] = new MergeConflict(
					$chunk_a['inserted'],
					$chunk_b['inserted'],
					array(
						'message' => 'Conflicting insertions',
					)
				);
				continue;
			}

			if ( null === $chunk_a['base'] || null === $chunk_b['base'] ) {
				if ( null !== $chunk_a['base'] ) {
					$results[] = $chunk_a['base'] . $chunk_a['inserted'];
				} elseif ( null !== $chunk_b['base'] ) {
					$results[] = $chunk_b['base'] . $chunk_b['inserted'];
				}
				continue;
			}

			if ( $chunk_a['base'] !== $chunk_b['base'] ) {
				$results[] = new MergeConflict(
					$chunk_a['base'],
					$chunk_b['base'],
					array(
						'message' => 'Mismatched base lines',
					)
				);
				continue;
			}

			if ( $chunk_a['deleted'] || $chunk_b['deleted'] ) {
				if ( $chunk_a['inserted'] && $chunk_b['inserted'] && (
						0 === strncmp( $chunk_a['inserted'], $chunk_b['inserted'], strlen( $chunk_b['inserted'] ) ) ||
						0 === strncmp( $chunk_b['inserted'], $chunk_a['inserted'], strlen( $chunk_a['inserted'] ) ) ||
						0 === substr_compare( $chunk_a['inserted'], $chunk_b['inserted'], - strlen( $chunk_b['inserted'] ) ) ||
						0 === substr_compare( $chunk_b['inserted'], $chunk_a['inserted'], - strlen( $chunk_a['inserted'] ) )
					) ) {
					$results[] = strlen( $chunk_a['inserted'] ) > strlen( $chunk_b['inserted'] ) ? $chunk_a['inserted'] : $chunk_b['inserted'];
					continue;
				}
				if ( $chunk_a['deleted'] && $chunk_b['deleted'] ) {
					continue;
				}

				$deletion     = $chunk_a['deleted'] ? $chunk_a : $chunk_b;
				$non_deletion = $chunk_a['deleted'] ? $chunk_b : $chunk_a;

				if ( $deletion['inserted'] ) {
					if ( '' !== $non_deletion['inserted'] ) {
						$results[] = new MergeConflict(
							$deletion['inserted'],
							$non_deletion['inserted'],
							array(
								'message' => 'Deletion with conflicting insertion',
							)
						);
						continue;
					}

					$results[] = $deletion['inserted'];
				} elseif ( '' === trim( $deletion['base'], ' ' ) && '' !== $non_deletion['inserted'] ) {
					// Sometimes branch A is one space short (e.g. due to a trim()) and branch B.
					// adds a meaningful span of text. In this case, we want to ignore the deletion.
					// and keep branch B's text.
					$results[] = $non_deletion['base'];
					$results[] = $non_deletion['inserted'];
				}
				continue;
			}

			$results[]      = $chunk_a['base'];
			$only_insertion = '' !== $chunk_a['inserted'] ? $chunk_a['inserted'] : $chunk_b['inserted'];
			$results[]      = $only_insertion;
		}

		return new MergeResult( $results );
	}

	public static function ensureChunks( array $diff_ab, array $diff_ac ): array {
		if ( isset( $diff_ab[0]['base'] ) || isset( $diff_ac[0]['base'] ) ) {
			return array( $diff_ab, $diff_ac );
		}

		$boundaries = self::extractBoundaries( $diff_ab, $diff_ac );
		$diff_ab    = self::resliceDiff( $diff_ab, $boundaries );
		$diff_ac    = self::resliceDiff( $diff_ac, $boundaries );

		$chunks_a = self::convertDiffToChunks( $diff_ab );
		$chunks_b = self::convertDiffToChunks( $diff_ac );

		return array( $chunks_a, $chunks_b );
	}

	private static function convertDiffToChunks( array $diff ): array {
		$chunks  = array();
		$current = array(
			'base'     => null,
			'deleted'  => false,
			'inserted' => '',
		);

		foreach ( $diff as $part ) {
			list( $op, $text ) = $part;

			if ( Diff::DIFF_DELETE === $op || Diff::DIFF_EQUAL === $op ) {
				if ( null !== $current['base'] || '' !== $current['inserted'] ) {
					$chunks[] = $current;
					$current  = array(
						'base'     => null,
						'deleted'  => false,
						'inserted' => '',
					);
				}
				$current['base']    = $text;
				$current['deleted'] = ( Diff::DIFF_DELETE === $op );
			} elseif ( Diff::DIFF_INSERT === $op ) {
				$current['inserted'] .= $text;
			}
		}

		if ( null !== $current['base'] || '' !== $current['inserted'] ) {
			$chunks[] = $current;
		}

		return $chunks;
	}

	private static function extractBoundaries( array $diff_a, array $diff_b ): array {
		$boundaries = array();
		foreach ( array( $diff_a, $diff_b ) as $diff ) {
			$offset = 0;
			foreach ( $diff as [$op, $text] ) {
				if ( Diff::DIFF_INSERT === $op ) {
					continue;
				}
				if ( 0 !== $offset ) {
					$boundaries[ $offset ] = true;
				}
				$offset += strlen( $text );
			}
		}
		$boundaries = array_keys( $boundaries );
		sort( $boundaries );

		return $boundaries;
	}

	private static function resliceDiff( array $diff, array $boundaries ): array {
		$boundaries     = array_values( $boundaries );
		$resliced       = array();
		$base_cursor    = 0;
		$boundary_index = 0;

		foreach ( $diff as [$op, $text] ) {
			if ( ! $text ) {
				continue;
			}
			if ( Diff::DIFF_INSERT === $op ) {
				$resliced[] = array( $op, $text );
				continue;
			}

			$text_length  = strlen( $text );
			$start_offset = $base_cursor;

			while (
				$boundary_index < count( $boundaries ) &&
				$boundaries[ $boundary_index ] <= $start_offset
			) {
				++$boundary_index;
			}

			while (
				$boundary_index < count( $boundaries ) &&
				$boundaries[ $boundary_index ] <= $start_offset + $text_length
			) {
				$boundary = $boundaries[ $boundary_index ];

				$slice_length = $boundary - $start_offset;
				if ( $slice_length > 0 && strlen( $text ) > 0 ) {
					$slice_text = substr( $text, 0, $slice_length );
					$resliced[] = array( $op, $slice_text );
				}

				$text          = substr( $text, $slice_length );
				$start_offset += $slice_length;
				++$boundary_index;
				if ( ! $text ) {
					break;
				}
			}

			if ( '' !== $text ) {
				$resliced[] = array( $op, $text );
			}

			$base_cursor += $text_length;
		}

		return $resliced;
	}
}
