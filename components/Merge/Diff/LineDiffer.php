<?php

namespace WordPress\Merge\Diff;

class LineDiffer implements Differ {
	public function diff( string $oldString, string $newString ): Diff {
		$oldLines = explode( "\n", $oldString );
		$newLines = explode( "\n", $newString );

		$lcs = $this->longestCommonSubsequence( $oldLines, $newLines );

		$oldIndex = 0;
		$newIndex = 0;
		$diff     = array();

		foreach ( $lcs as $match ) {
			while ( $oldIndex < $match['old_index'] || $newIndex < $match['new_index'] ) {
				if ( $oldIndex < $match['old_index'] ) {
					$diff[] = array(
						Diff::DIFF_DELETE,
						$oldLines[ $oldIndex ] . "\n",
					);
					++ $oldIndex;
				}
				if ( $newIndex < $match['new_index'] ) {
					$diff[] = array(
						Diff::DIFF_INSERT,
						$newLines[ $newIndex ] . "\n",
					);
					++ $newIndex;
				}
			}

			// Add matching line as context
			if ( $oldIndex < count( $oldLines ) && $newIndex < count( $newLines ) ) {
				$diff[] = array(
					Diff::DIFF_EQUAL,
					$oldLines[ $oldIndex ] . "\n",
				);
				++ $oldIndex;
				++ $newIndex;
			}
		}

		// Add remaining lines
		while ( $oldIndex < count( $oldLines ) ) {
			$diff[] = array(
				Diff::DIFF_DELETE,
				$oldLines[ $oldIndex ] . "\n",
			);
			++ $oldIndex;
		}
		while ( $newIndex < count( $newLines ) ) {
			$diff[] = array(
				Diff::DIFF_INSERT,
				$newLines[ $newIndex ] . "\n",
			);
			++ $newIndex;
		}

		return new Diff( $diff );
	}

	private function longestCommonSubsequence( array $oldLines, array $newLines ): array {
		$oldLen    = count( $oldLines );
		$newLen    = count( $newLines );
		$lcsMatrix = array_fill( 0, $oldLen + 1, array_fill( 0, $newLen + 1, 0 ) );

		// Build the LCS matrix
		for ( $i = 1; $i <= $oldLen; $i ++ ) {
			for ( $j = 1; $j <= $newLen; $j ++ ) {
				if ( $oldLines[ $i - 1 ] === $newLines[ $j - 1 ] ) {
					$lcsMatrix[ $i ][ $j ] = $lcsMatrix[ $i - 1 ][ $j - 1 ] + 1;
				} else {
					$lcsMatrix[ $i ][ $j ] = max( $lcsMatrix[ $i - 1 ][ $j ], $lcsMatrix[ $i ][ $j - 1 ] );
				}
			}
		}

		// Backtrack to find the LCS
		$lcs = array();
		$i   = $oldLen;
		$j   = $newLen;
		while ( $i > 0 && $j > 0 ) {
			if ( $oldLines[ $i - 1 ] === $newLines[ $j - 1 ] ) {
				$lcs[] = array(
					'old_index' => $i - 1,
					'new_index' => $j - 1,
				);
				-- $i;
				-- $j;
			} elseif ( $lcsMatrix[ $i - 1 ][ $j ] >= $lcsMatrix[ $i ][ $j - 1 ] ) {
				-- $i;
			} else {
				-- $j;
			}
		}

		return array_reverse( $lcs );
	}
}
