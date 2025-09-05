<?php

namespace WordPress\Merge;

function print_diff_chunks( array $chunks_a, array $chunks_b ): void {
	$width      = (int) shell_exec( 'tput cols' ) - 20;
	$half_width = (int) ( $width / 2 );
	$empty_line = str_repeat( ' ', $half_width );

	echo "\n";
	$header_a = str_pad( 'Version A', $half_width, ' ', STR_PAD_BOTH );
	$header_b = str_pad( 'Version B', $half_width, ' ', STR_PAD_BOTH );
	echo "     \033[1m" . $header_a . ' | ' . $header_b . "\033[0m\n";
	echo str_repeat( '-', $width ) . "\n";

	$n = max( count( $chunks_a ), count( $chunks_b ) );
	for ( $i = 0; $i < $n; $i ++ ) {
		$chunk_a = $chunks_a[ $i ];
		$chunk_b = $chunks_b[ $i ];

		$left_lines  = explode( "\n", format_chunk_side( $chunk_a, $half_width ) );
		$right_lines = explode( "\n", format_chunk_side( $chunk_b, $half_width ) );

		$max_lines = max( count( $left_lines ), count( $right_lines ) );
		for ( $j = 0; $j < $max_lines; $j ++ ) {
			printf(
				"%3d: %s | %s\n",
				$i,
				$left_lines[ $j ] ?? $empty_line,
				$right_lines[ $j ] ?? $empty_line
			);
		}
	}
}

function mb_wordwrap( string $text, int $width, string $break = "\n", bool $cut = true, string $encoding = 'UTF-8' ): array {
	// Split text into words while keeping unprintable characters
	$words = preg_split( '/(\s+)/u', $text, - 1, PREG_SPLIT_DELIM_CAPTURE );

	$lines        = array();
	$current_line = '';

	for ( $i = 0; $i < count( $words ); $i ++ ) {
		$word = $words[ $i ];
		if ( strpos( $word, "\n" ) !== false ) {
			$offset = strpos( $word, "\n" );
			// Slice until the newline character while keeping the number of
			// characters the same.
			$before = substr( $word, 0, $offset ) . ' ';
			$after  = substr( $word, $offset + 1 );
			array_splice( $words, $i, 1, array( $before, $after ) );
			-- $i;
			continue;
		}
		// Strip unprintable characters for length calculation
		$length = mb_strlen( $word, $encoding );

		// Handle cases where a single word is longer than the width
		if ( $cut && $length > $width ) {
			if ( ! empty( $current_line ) ) {
				$lines[]      = $current_line;
				$current_line = '';
			}
			while ( $length > $width ) {
				$chunk   = mb_substr( $word, 0, $width, $encoding );
				$word    = mb_substr( $word, $width, null, $encoding );
				$length  = mb_strlen( $word, $encoding );
				$lines[] = $chunk;
			}
			if ( $length > 0 ) {
				$current_line = $word;
			}
			continue;
		}

		// Check if adding the next word exceeds the width
		$current_length = mb_strlen( $current_line, $encoding );

		if ( $current_length + $length >= $width ) {
			$lines[]      = rtrim( $current_line, "\n" );
			$current_line = $word;
		} else {
			$current_line .= $word;
		}
	}

	if ( ! empty( $current_line ) ) {
		$lines[] = rtrim( $current_line, "\n" );
	}

	return $lines;
}

function format_chunk_side( array $chunk, $width ): string {
	$text          = $chunk['base'] . $chunk['inserted'];
	$ansi_segments = array(
		array(
			'color' => $chunk['deleted'] ? "\033[101m" : "\033[37m",
			'start' => 0,
			'end'   => mb_strlen( $chunk['base'] ),
		),
		array(
			'color' => $chunk['inserted'] ? "\033[102m" : '',
			'start' => mb_strlen( $chunk['base'] ),
			'end'   => mb_strlen( $chunk['base'] ) + mb_strlen( $chunk['inserted'] ),
		),
	);

	$cursor            = 0;
	$wrapped           = mb_wordwrap( $text, $width );
	$next_ansi_segment = array_shift( $ansi_segments );
	foreach ( $wrapped as $k => $line ) {
		$line_start     = $cursor;
		$line_end       = $line_start + mb_strlen( $line );
		$line_shift     = 0;
		$padding_length = $width - mb_strlen( $line );
		while ( $next_ansi_segment && ! ( $next_ansi_segment['end'] < $line_start || $next_ansi_segment['start'] >= $line_end ) ) {
			$start_offset  = max( 0, $next_ansi_segment['start'] - $cursor ) + $line_shift;
			$end_offset    = min( $line_end, $next_ansi_segment['end'] ) + $line_shift;
			$wrapped[ $k ] = (
				mb_substr(
					$wrapped[ $k ],
					0,
					$start_offset
				) .
				$next_ansi_segment['color'] .
				mb_substr(
					$wrapped[ $k ],
					$start_offset,
					$end_offset - $start_offset
				) .
				"\033[0m" .
				mb_substr(
					$wrapped[ $k ],
					$end_offset
				)
			);
			$line_shift    = mb_strlen( $next_ansi_segment['color'] . "\033[0m" );
			if ( $next_ansi_segment['end'] <= $line_end ) {
				do {
					$next_ansi_segment = array_shift( $ansi_segments );
				} while ( $next_ansi_segment && $next_ansi_segment['start'] === $next_ansi_segment['end'] );
			} else {
				break;
			}
		}
		$cursor = $line_end + 1;

		if ( $padding_length > 0 ) {
			// Tab characters have variable length in terminal which breaks the side-by-side formatting.
			// We cannot easily preserve them and display nice diff columns. At the same time, removing
			// them in favor of spaces may confuse the viewer – "why are spaces replaced with spaces here?"
			//
			// @TODO: Investigate how other diff tools solve that problem and find a useful and established
			// pattern. Perhaps display UTF-8 arrows instead of tabs and dots instead of spaces?
			$wrapped[ $k ] = str_replace( "\t", ' ', $wrapped[ $k ] );
			$wrapped[ $k ] = trim( $wrapped[ $k ] ) . str_repeat( ' ', $padding_length );
		}
	}

	return implode( "\n", $wrapped );
}
