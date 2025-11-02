<?php

namespace WordPress\Encoding;

use function WordPress\Encoding\compat\_wp_scan_utf8;

/*
 * UTF-8 decoding pipeline by Dennis Snell (@dmsnell), originally
 * proposed in https://github.com/WordPress/wordpress-develop/pull/6883.
 *
 * It enables parsing XML documents with incomplete UTF-8 byte sequences
 * without crashing or depending on the mbstring extension.
 */

if ( ! defined( 'UTF8_DECODER_ACCEPT' ) ) {
	define( 'UTF8_DECODER_ACCEPT', 0 );
}

if ( ! defined( 'UTF8_DECODER_REJECT' ) ) {
	define( 'UTF8_DECODER_REJECT', 1 );
}

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
	$position_in_input = $byte_offset;
	$codepoint_at      = $byte_offset;
	$end_byte          = strlen( $text );
	$codepoint         = null;
	$decoder_state     = UTF8_DECODER_ACCEPT;

	// Get to the start of the string.
	while ( $position_in_input < $end_byte ) {
		$decoder_state = utf8_decoder_apply_byte( $text[ $position_in_input ], $decoder_state );

		if ( UTF8_DECODER_ACCEPT === $decoder_state ) {
			++$position_in_input;
			$codepoint = utf8_ord( substr( $text, $codepoint_at, $position_in_input - $codepoint_at ) );
			break;
		} elseif ( UTF8_DECODER_REJECT === $decoder_state ) {
			// "\u{FFFD}" is not supported in PHP 5.6.
			$codepoint = utf8_ord( "\xEF\xBF\xBD" );
			break;
		} else {
			++$position_in_input;
		}
	}

	$matched_bytes = $position_in_input - $byte_offset;

	return $codepoint;
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


/**
 * Inner loop for a number of UTF-8 decoding-related functions.
 *
 * You probably don't need this! This is highly-specific and optimized
 * code for UTF-8 operations used in other functions.
 *
 * @see http://bjoern.hoehrmann.de/utf-8/decoder/dfa/
 *
 * @since {WP_VERSION}
 *
 * @access private
 *
 * @param  string   $byte  Next byte to be applied in UTF-8 decoding or validation.
 * @param  int      $state  UTF-8 decoding state, one of the following values:<br><ul>
 *                                 <li>`UTF8_DECODER_ACCEPT`: Decoder is ready for a new code point.<br>
 *                                 <li>`UTF8_DECODER_REJECT`: An error has occurred.<br>
 *                                 Any other positive value: Decoder is waiting for additional bytes.
 * @param  int|null $codepoint  Optional. If provided, will accumulate the decoded code point as
 *                            each byte is processed. If not provided or unable to decode, will
 *                            not be set, or will be set to invalid and unusable data.
 *
 * @return int Next decoder state after processing the current byte.
 */
function utf8_decoder_apply_byte( string $byte, int $state, int &$codepoint = 0 ): int {
	/**
	 * State classification and transition table for UTF-8 validation.
	 *
	 * > The first part of the table maps bytes to character classes that
	 * > to reduce the size of the transition table and create bitmasks.
	 * >
	 * > The second part is a transition table that maps a combination
	 * > of a state of the automaton and a character class to a state.
	 *
	 * @see http://bjoern.hoehrmann.de/utf-8/decoder/dfa/
	 */
	static $state_table = (
		"\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00" .
		"\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00" .
		"\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00" .
		"\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00" .
		"\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x09\x09\x09\x09\x09\x09\x09\x09\x09\x09\x09\x09\x09\x09\x09\x09" .
		"\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07" .
		"\x08\x08\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02" .
		"\x10\x03\x03\x03\x03\x03\x03\x03\x03\x03\x03\x03\x03\x04\x03\x03" .
		"\x11\x06\x06\x06\x05\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08" .
		"\x00\x01\x02\x03\x05\x08\x07\x01\x01\x01\x04\x06\x01\x01\x01\x01" .
		"\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x00\x01\x01\x01\x01\x01\x00\x01\x00\x01\x01\x01\x01\x01\x01" .
		"\x01\x02\x01\x01\x01\x01\x01\x02\x01\x02\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x02\x01\x01\x01\x01\x01\x01\x01\x01" .
		"\x01\x02\x01\x01\x01\x01\x01\x01\x01\x02\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x03\x01\x03\x01\x01\x01\x01\x01\x01" .
		"\x01\x03\x01\x01\x01\x01\x01\x03\x01\x03\x01\x01\x01\x01\x01\x01\x01\x03\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01"
	);

	$byte      = ord( $byte );
	$type      = ord( $state_table[ $byte ] );
	$codepoint = ( UTF8_DECODER_ACCEPT === $state )
		? ( ( 0xFF >> $type ) & $byte )
		: ( ( $byte & 0x3F ) | ( $codepoint << 6 ) );

	return ord( $state_table[ 256 + ( $state * 16 ) + $type ] );
}
