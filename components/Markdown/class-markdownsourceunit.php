<?php

namespace WordPress\Markdown;

/**
 * Represents one Markdown source slice mapped to one WordPress block.
 *
 * Source units are the splice points used by MarkdownSourceDocument. Each unit
 * stores the original Markdown bytes, their byte offsets in the full document,
 * the corresponding block markup, and a semantic hash used to recognize
 * unchanged blocks after editing.
 */
class MarkdownSourceUnit {

	private $source;
	private $start_offset;
	private $end_offset;
	private $block_markup;
	private $semantic_hash;

	/**
	 * Creates a mapped Markdown source unit.
	 *
	 * @param string $source        Original Markdown source slice.
	 * @param int    $start_offset  Start byte offset in the full Markdown document.
	 * @param int    $end_offset    End byte offset in the full Markdown document.
	 * @param string $block_markup  WordPress block markup generated from the source slice.
	 * @param string $semantic_hash Hash used to compare this unit with edited blocks.
	 */
	public function __construct( $source, $start_offset, $end_offset, $block_markup, $semantic_hash ) {
		$this->source        = (string) $source;
		$this->start_offset  = (int) $start_offset;
		$this->end_offset    = (int) $end_offset;
		$this->block_markup  = (string) $block_markup;
		$this->semantic_hash = (string) $semantic_hash;
	}

	/**
	 * Returns the original Markdown source slice.
	 *
	 * @return string Original Markdown bytes for this unit.
	 */
	public function get_source() {
		return $this->source;
	}

	/**
	 * Returns whitespace before the first non-whitespace byte in this unit.
	 *
	 * This is reused when a source unit is replaced by changed block markup, so
	 * indentation or blank-line trivia before the edited block is not lost.
	 *
	 * @return string Leading whitespace from the source slice.
	 */
	public function get_leading_trivia() {
		$length = strlen( $this->source );
		for ( $i = 0; $i < $length; $i++ ) {
			if ( ! ctype_space( $this->source[ $i ] ) ) {
				return substr( $this->source, 0, $i );
			}
		}

		return $this->source;
	}

	/**
	 * Returns line-oriented trivia after the final content line in this unit.
	 *
	 * The returned trivia includes the final content line's line ending plus any
	 * following blank lines. This allows changed blocks to preserve LF/CRLF
	 * separators and the absence of a final newline.
	 *
	 * @return string Trailing line ending and blank-line trivia.
	 */
	public function get_trailing_trivia() {
		$lines = self::lines_with_endings( $this->source );
		$trivia = '';

		for ( $index = count( $lines ) - 1; $index >= 0; $index-- ) {
			$line = $lines[ $index ];
			$line_without_ending = self::trim_line_ending( $line );

			if ( self::is_blank( $line_without_ending ) ) {
				$trivia = $line . $trivia;
				continue;
			}

			return self::line_ending( $line ) . $trivia;
		}

		return $trivia;
	}

	/**
	 * Returns the start byte offset of this unit in the original document.
	 *
	 * @return int Start byte offset.
	 */
	public function get_start_offset() {
		return $this->start_offset;
	}

	/**
	 * Returns the end byte offset of this unit in the original document.
	 *
	 * @return int End byte offset.
	 */
	public function get_end_offset() {
		return $this->end_offset;
	}

	/**
	 * Returns the WordPress block markup generated from this unit.
	 *
	 * @return string Block markup for this source unit.
	 */
	public function get_block_markup() {
		return $this->block_markup;
	}

	/**
	 * Returns the semantic hash used to match this unit after edits.
	 *
	 * @return string Semantic block hash.
	 */
	public function get_semantic_hash() {
		return $this->semantic_hash;
	}

	/**
	 * Splits text into lines while retaining each line ending.
	 *
	 * @param string $text Source text.
	 * @return string[] Lines, each including its original line ending.
	 */
	private static function lines_with_endings( $text ) {
		$lines = array();
		$line_start = 0;
		$length = strlen( $text );

		for ( $i = 0; $i < $length; $i++ ) {
			if ( "\n" !== $text[ $i ] && "\r" !== $text[ $i ] ) {
				continue;
			}
			if ( "\r" === $text[ $i ] && $i + 1 < $length && "\n" === $text[ $i + 1 ] ) {
				$i++;
			}
			$lines[] = substr( $text, $line_start, $i - $line_start + 1 );
			$line_start = $i + 1;
		}

		if ( $line_start < $length ) {
			$lines[] = substr( $text, $line_start );
		}

		return $lines;
	}

	/**
	 * Removes one line's trailing CR and LF bytes.
	 *
	 * @param string $line Source line.
	 * @return string Line without its trailing line ending.
	 */
	private static function trim_line_ending( $line ) {
		while ( '' !== $line ) {
			$last = $line[ strlen( $line ) - 1 ];
			if ( "\n" !== $last && "\r" !== $last ) {
				break;
			}
			$line = substr( $line, 0, -1 );
		}

		return $line;
	}

	/**
	 * Returns the CR/LF line ending from a source line.
	 *
	 * @param string $line Source line.
	 * @return string Line ending, or an empty string when none exists.
	 */
	private static function line_ending( $line ) {
		$without_line_ending = self::trim_line_ending( $line );
		return substr( $line, strlen( $without_line_ending ) );
	}

	/**
	 * Indicates whether text contains only whitespace bytes.
	 *
	 * @param string $text Text to inspect.
	 * @return bool True when the text is blank, false otherwise.
	 */
	private static function is_blank( $text ) {
		$length = strlen( $text );
		for ( $i = 0; $i < $length; $i++ ) {
			if ( ! ctype_space( $text[ $i ] ) ) {
				return false;
			}
		}
		return true;
	}
}
