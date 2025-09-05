<?php

namespace WordPress\Merge\Diff;

class Diff {
	const DIFF_DELETE = - 1;
	const DIFF_INSERT = 1;
	const DIFF_EQUAL  = 0;

	private $changes;

	public function __construct( array $changes ) {
		$this->changes = $changes;
	}

	public function get_changes(): array {
		return $this->changes;
	}

	public function get_old_document(): string {
		$merged = array();
		foreach ( $this->changes as $change ) {
			if ( self::DIFF_EQUAL === $change[0] ) {
				$merged[] = $change[1];
			} elseif ( self::DIFF_DELETE === $change[0] ) {
				$merged[] = $change[1];
			}
		}

		return implode( '', $merged );
	}

	public function get_new_document(): string {
		$merged = array();
		foreach ( $this->changes as $change ) {
			if ( self::DIFF_EQUAL === $change[0] ) {
				$merged[] = $change[1];
			} elseif ( self::DIFF_INSERT === $change[0] ) {
				$merged[] = $change[1];
			}
		}

		return implode( '', $merged );
	}

	public function format_as_delta(): string {
		$delta = array();
		foreach ( $this->changes as $change ) {
			switch ( $change[0] ) {
				case self::DIFF_EQUAL:
					$delta[] = '=' . strlen( $change[1] );
					break;
				case self::DIFF_INSERT:
					$delta[] = '+' . $change[1];
					break;
				case self::DIFF_DELETE:
					$delta[] = '-' . strlen( $change[1] );
			}
		}

		return implode( "\r", $delta );
	}

	public function format_as_git_patch( $options = array() ) {
		$options['contextLines'] = $options['contextLines'] ?? 3;
		$options['a_source']     = $options['a_source'] ?? 'a/string';
		$options['b_source']     = $options['b_source'] ?? 'b/string';

		// Format the diff to Git-style with context.
		$formatted_diff  = 'diff --git ' . $options['a_source'] . ' ' . $options['b_source'] . "\n";
		$formatted_diff .= '--- ' . $options['a_source'] . "\n";
		$formatted_diff .= '+++ ' . $options['b_source'] . "\n";

		$changed_blocks = array();
		$current_block  = array();

		$last_changed_lineno = null;
		foreach ( $this->changes as $lineno => $change ) {
			$type = $change[0];
			if ( self::DIFF_EQUAL === $type ) {
				if ( empty( $current_block ) ) {
					continue;
				}
				if ( $lineno - $last_changed_lineno > $options['contextLines'] ) {
					$changed_blocks[] = $current_block;
					$current_block    = array();
					continue;
				}
			} elseif ( empty( $current_block ) ) {
				$offset        = max( 0, $lineno - $options['contextLines'] - 1 );
				$length        = min( $options['contextLines'] - 1, $lineno - $offset );
				$current_block = array_slice( $this->changes, $offset, $length );
			}

			$current_block[] = $change;

			if ( self::DIFF_EQUAL !== $type ) {
				$last_changed_lineno = $lineno;
			}
		}

		if ( ! empty( $current_block ) ) {
			$changed_blocks[] = $current_block;
		}

		$old_line_cursor = 1;
		$new_line_cursor = 1;
		foreach ( $changed_blocks as $changes ) {
			$block          = '';
			$old_start_line = null;
			$new_start_line = null;

			foreach ( $changes as $change ) {
				if ( self::DIFF_INSERT !== $change[0] ) {
					if ( null === $old_start_line ) {
						$old_start_line = $old_line_cursor;
					}
				}
				if ( self::DIFF_DELETE !== $change[0] ) {
					if ( null === $new_start_line ) {
						$new_start_line = $new_line_cursor;
					}
				}
				$nb_newlines = substr_count( $change[1], "\n" );
				switch ( $change[0] ) {
					case self::DIFF_EQUAL:
						$old_line_cursor += $nb_newlines;
						$new_line_cursor += $nb_newlines;
						break;
					case self::DIFF_DELETE:
						$old_line_cursor += $nb_newlines;
						break;
					case self::DIFF_INSERT:
						$new_line_cursor += $nb_newlines;
						break;
				}
			}
			$old_lines_nb = $old_line_cursor - $old_start_line;
			$new_lines_nb = $new_line_cursor - $new_start_line;

			$block .= sprintf( '@@ -%d,%d +%d,%d @@', $old_start_line, $old_lines_nb, $new_start_line, $new_lines_nb );

			foreach ( $changes as $change ) {
				switch ( $change[0] ) {
					case self::DIFF_EQUAL:
						$symbol = ' ';
						break;
					case self::DIFF_DELETE:
						$symbol = '-';
						break;
					case self::DIFF_INSERT:
						$symbol = '+';
						break;
				}
				$block .= $symbol . ' ' . $change[1];
			}

			$formatted_diff .= $block;
		}

		return $formatted_diff;
	}
}
