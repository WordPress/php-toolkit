<?php

/**
 * @TODO Improve the error messages, e.g  implement
 * `throw new AsyncResourceException($resource)`
 * that would report which URL failed to download.
 */

namespace WordPress\AsyncHttp;

function fread_guarantee_n_bytes( $stream, $length ) {
	$buffer = '';
	while ( strlen( $buffer ) < $length ) {
		$next_bytes = fread( $stream, $length - strlen( $buffer ) );
		if ( false === $next_bytes ) {
			return false;
		}
		$buffer .= $next_bytes;
	}

	return $buffer;
}


