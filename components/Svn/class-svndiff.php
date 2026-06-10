<?php

namespace WordPress\Svn;

/**
 * Encodes full-text data as svndiff0 deltas.
 *
 * Subversion exchanges file contents as binary deltas in the "svndiff"
 * format. When the entire file is sent (as opposed to a delta against
 * an earlier version), the delta consists of windows that only use
 * "copy from new data" instructions.
 *
 * See the streaming decoder in SvnDiffApplier for the format details.
 */
class SvnDiff {
	const HEADER_V0 = "SVN\x00";

	/**
	 * Maximum number of target bytes encoded in a single svndiff window.
	 *
	 * 100 KB mirrors SVN_DELTA_WINDOW_SIZE in Subversion itself.
	 */
	const WINDOW_SIZE = 102400;

	/**
	 * Encodes a string as a self-contained svndiff0 document that ignores
	 * the source text entirely – every window emits raw "new data".
	 *
	 * @param  string $contents  The full text to encode.
	 * @return string The svndiff0 representation of $contents.
	 */
	public static function encode_fulltext( $contents ) {
		$svndiff = self::HEADER_V0;
		$offset  = 0;
		$length  = strlen( $contents );
		do {
			$chunk    = substr( $contents, $offset, self::WINDOW_SIZE );
			$svndiff .= self::encode_fulltext_window( $chunk );
			$offset  += strlen( $chunk );
		} while ( $offset < $length );

		return $svndiff;
	}

	/**
	 * Encodes a single svndiff0 window holding raw new data.
	 *
	 * @param  string $chunk  Up to WINDOW_SIZE bytes of target data.
	 * @return string The encoded window.
	 */
	private static function encode_fulltext_window( $chunk ) {
		$chunk_length = strlen( $chunk );
		$instruction  = self::encode_copy_from_new_data_instruction( $chunk_length );

		return self::encode_varint( 0 ) . // Source view offset.
			self::encode_varint( 0 ) . // Source view length.
			self::encode_varint( $chunk_length ) . // Target view length.
			self::encode_varint( strlen( $instruction ) ) . // Instructions length.
			self::encode_varint( $chunk_length ) . // New data length.
			$instruction .
			$chunk;
	}

	/**
	 * Encodes a "copy from new data" instruction.
	 *
	 * The first byte holds the opcode in its two high bits (0b10 for
	 * new data) and the length in the low six bits. A length of zero
	 * means the real length follows as a varint.
	 *
	 * @param  int $length  Number of new data bytes the instruction covers.
	 * @return string The encoded instruction.
	 */
	private static function encode_copy_from_new_data_instruction( $length ) {
		if ( $length > 0 && $length < 64 ) {
			return chr( 0x80 | $length );
		}

		return chr( 0x80 ) . self::encode_varint( $length );
	}

	/**
	 * Encodes a non-negative integer in the variable-length format used
	 * throughout svndiff: base-128, most significant group first, with
	 * the high bit set on every byte except the last one.
	 *
	 * @param  int $number  The non-negative integer to encode.
	 * @return string The encoded varint.
	 */
	public static function encode_varint( $number ) {
		$bytes    = chr( $number & 0x7f );
		$number >>= 7;
		while ( $number > 0 ) {
			$bytes    = chr( 0x80 | ( $number & 0x7f ) ) . $bytes;
			$number >>= 7;
		}

		return $bytes;
	}
}
