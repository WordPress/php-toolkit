<?php

namespace WordPress\Encoding;

/*
 * UTF-8 decoding pipeline by Dennis Snell (@dmsnell), originally
 * proposed in https://github.com/WordPress/wordpress-develop/pull/6883.
 *
 * It enables parsing XML documents with incomplete UTF-8 byte sequences
 * without crashing or depending on the mbstring extension.
 */

/**
 * Extract a unicode codepoint from a specific offset in text.
 * Invalid byte sequences count as a single code point, U+FFFD
 * (the Unicode replacement character ``).
 *
 * This function does not permit passing negative indices and will return
 * null if such are provided.
 *
 * @param  string $text  Input text from which to extract.
 * @param  int    $byte_offset  Start at this byte offset in the input text.
 * @param  int    $matched_bytes  How many bytes were matched to produce the codepoint.
 *
 * @return int Unicode codepoint.
 */
function utf8_codepoint_at( string $text, int $byte_offset = 0, &$matched_bytes = 0 ) {
	if ( $byte_offset < 0 ) {
		return null;
	}

	$new_byte_offset = $byte_offset;
	if( 1 !== _wp_scan_utf8( $text, $new_byte_offset, $invalid_length, null, 1 ) ) {
		return utf8_ord( "\u{FFFD}" );
	}

	$matched_bytes = $new_byte_offset - $byte_offset;
	return utf8_ord( substr( $text, $byte_offset, $matched_bytes ) );
}

/**
 * Convert a UTF-8 byte sequence to its Unicode codepoint.
 *
 * @param  string $character  UTF-8 encoded byte sequence representing a single Unicode character.
 *
 * @return int Unicode codepoint.
 */
function utf8_ord( string $character ): int {
	// Convert the byte sequence to its binary representation.
	$bytes = unpack( 'C*', $character );

	// Initialize the codepoint.
	$codepoint = 0;

	// Calculate the codepoint based on the number of bytes.
	if ( 1 === count( $bytes ) ) {
		$codepoint = $bytes[1];
	} elseif ( 2 === count( $bytes ) ) {
		$codepoint = ( ( $bytes[1] & 0x1F ) << 6 ) | ( $bytes[2] & 0x3F );
	} elseif ( 3 === count( $bytes ) ) {
		$codepoint = ( ( $bytes[1] & 0x0F ) << 12 ) | ( ( $bytes[2] & 0x3F ) << 6 ) | ( $bytes[3] & 0x3F );
	} elseif ( 4 === count( $bytes ) ) {
		$codepoint = ( ( $bytes[1] & 0x07 ) << 18 ) | ( ( $bytes[2] & 0x3F ) << 12 ) | ( ( $bytes[3] & 0x3F ) << 6 ) | ( $bytes[4] & 0x3F );
	}

	return $codepoint;
}
