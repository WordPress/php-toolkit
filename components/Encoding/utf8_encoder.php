<?php

namespace WordPress\Encoding;

/**
 * UTF-8 encoding pipeline by Dennis Snell (@dmsnell).
 *
 * It enables parsing XML documents with incomplete UTF-8 byte sequences
 * without crashing or depending on the mbstring extension.
 */

/**
 * Encode a code point number into the UTF-8 encoding.
 *
 * This encoder implements the UTF-8 encoding algorithm for converting
 * a code point into a byte sequence. If it receives an invalid code
 * point it will return the Unicode Replacement Character U+FFFD `�`.
 *
 * Example:
 *
 *     '🅰' === WP_HTML_Decoder::code_point_to_utf8_bytes( 0x1f170 );
 *
 *     // Half of a surrogate pair is an invalid code point.
 *     '�' === WP_HTML_Decoder::code_point_to_utf8_bytes( 0xd83c );
 *
 * @since 6.6.0
 *
 * @see https://www.rfc-editor.org/rfc/rfc3629 For the UTF-8 standard.
 *
 * @param int $code_point Which code point to convert.
 * @return string Converted code point, or `�` if invalid.
 */
function code_point_to_utf8_bytes( $code_point ) {
	// Pre-check to ensure a valid code point.
	if (
		$code_point <= 0 ||
		( $code_point >= 0xD800 && $code_point <= 0xDFFF ) ||
		$code_point > 0x10FFFF
	) {
		return '�';
	}

	if ( $code_point <= 0x7F ) {
		return chr( $code_point );
	}

	if ( $code_point <= 0x7FF ) {
		$byte1 = chr( ( $code_point >> 6 ) | 0xC0 );
		$byte2 = chr( $code_point & 0x3F | 0x80 );

		return "{$byte1}{$byte2}";
	}

	if ( $code_point <= 0xFFFF ) {
		$byte1 = chr( ( $code_point >> 12 ) | 0xE0 );
		$byte2 = chr( ( $code_point >> 6 ) & 0x3F | 0x80 );
		$byte3 = chr( $code_point & 0x3F | 0x80 );

		return "{$byte1}{$byte2}{$byte3}";
	}

	// Any values above U+10FFFF are eliminated above in the pre-check.
	$byte1 = chr( ( $code_point >> 18 ) | 0xF0 );
	$byte2 = chr( ( $code_point >> 12 ) & 0x3F | 0x80 );
	$byte3 = chr( ( $code_point >> 6 ) & 0x3F | 0x80 );
	$byte4 = chr( $code_point & 0x3F | 0x80 );

	return "{$byte1}{$byte2}{$byte3}{$byte4}";
}
