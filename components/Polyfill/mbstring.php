<?php

if ( ! function_exists( 'mbstring_binary_safe_encoding' ) ) {
	function mbstring_binary_safe_encoding( $reset = false ) {
		static $encodings  = array();
		static $overloaded = null;

		if ( is_null( $overloaded ) ) {
			if ( function_exists( 'mb_internal_encoding' )
				&& ( (int) ini_get( 'mbstring.func_overload' ) & 2 ) // phpcs:ignore PHPCompatibility.IniDirectives.RemovedIniDirectives.mbstring_func_overloadDeprecated
			) {
				$overloaded = true;
			} else {
				$overloaded = false;
			}
		}

		if ( false === $overloaded ) {
			return;
		}

		if ( ! $reset ) {
			$encoding = mb_internal_encoding();
			array_push( $encodings, $encoding );
			mb_internal_encoding( 'ISO-8859-1' );
		}

		if ( $reset && $encodings ) {
			$encoding = array_pop( $encodings );
			mb_internal_encoding( $encoding );
		}
	}
}

if ( ! function_exists( 'reset_mbstring_encoding' ) ) {
	function reset_mbstring_encoding() {
		mbstring_binary_safe_encoding( true );
	}
}

if ( ! function_exists( 'mb_str_split' ) ) {
	function mb_str_split( $string, $split_length = 1, $encoding = null ) {
		if ( null !== $encoding ) {
			$old_encoding = mb_internal_encoding();
			mb_internal_encoding( $encoding );
		}

		$result = array();
		$length = mb_strlen( $string );
		for ( $i = 0; $i < $length; $i += $split_length ) {
			$result[] = mb_substr( $string, $i, $split_length );
		}

		if ( null !== $encoding ) {
			mb_internal_encoding( $old_encoding );
		}

		return $result;
	}
}
