<?php

namespace WordPress\Merge\Diff;

class LineDiffer implements Differ {
	public function diff( string $old_string, string $new_string ): Diff {
		$old_lines = explode( "\n", $old_string );
		$new_lines = explode( "\n", $new_string );

		$lcs = $this->longestCommonSubsequence( $old_lines, $new_lines );

		$old_index = 0;
		$new_index = 0;
		$diff      = array();

		foreach ( $lcs as $match ) {
			while ( $old_index < $match['old_index'] || $new_index < $match['new_index'] ) {
				if ( $old_index < $match['old_index'] ) {
					$diff[] = array(
						Diff::DIFF_DELETE,
						$old_lines[ $old_index ] . "\n",
					);
					++$old_index;
				}
				if ( $new_index < $match['new_index'] ) {
					$diff[] = array(
						Diff::DIFF_INSERT,
						$new_lines[ $new_index ] . "\n",
					);
					++$new_index;
				}
			}

			// Add matching line as context.
			if ( $old_index < count( $old_lines ) && $new_index < count( $new_lines ) ) {
				$diff[] = array(
					Diff::DIFF_EQUAL,
					$old_lines[ $old_index ] . "\n",
				);
				++$old_index;
				++$new_index;
			}
		}

		// Add remaining lines.
		while ( $old_index < count( $old_lines ) ) {
			$diff[] = array(
				Diff::DIFF_DELETE,
				$old_lines[ $old_index ] . "\n",
			);
			++$old_index;
		}
		while ( $new_index < count( $new_lines ) ) {
			$diff[] = array(
				Diff::DIFF_INSERT,
				$new_lines[ $new_index ] . "\n",
			);
			++$new_index;
		}

		return new Diff( $diff );
	}

	private function longestCommonSubsequence( array $old_lines, array $new_lines ): array {
		$old_len    = count( $old_lines );
		$new_len    = count( $new_lines );
		$lcs_matrix = array_fill( 0, $old_len + 1, array_fill( 0, $new_len + 1, 0 ) );

		// Build the LCS matrix.
		for ( $i = 1; $i <= $old_len; $i++ ) {
			for ( $j = 1; $j <= $new_len; $j++ ) {
				if ( $old_lines[ $i - 1 ] === $new_lines[ $j - 1 ] ) {
					$lcs_matrix[ $i ][ $j ] = $lcs_matrix[ $i - 1 ][ $j - 1 ] + 1;
				} else {
					$lcs_matrix[ $i ][ $j ] = max( $lcs_matrix[ $i - 1 ][ $j ], $lcs_matrix[ $i ][ $j - 1 ] );
				}
			}
		}

		// Backtrack to find the LCS.
		$lcs = array();
		$i   = $old_len;
		$j   = $new_len;
		while ( $i > 0 && $j > 0 ) {
			if ( $old_lines[ $i - 1 ] === $new_lines[ $j - 1 ] ) {
				$lcs[] = array(
					'old_index' => $i - 1,
					'new_index' => $j - 1,
				);
				--$i;
				--$j;
			} elseif ( $lcs_matrix[ $i - 1 ][ $j ] >= $lcs_matrix[ $i ][ $j - 1 ] ) {
				--$i;
			} else {
				--$j;
			}
		}

		return array_reverse( $lcs );
	}
}
