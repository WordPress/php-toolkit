<?php

if ( ! function_exists( 'str_starts_with' ) ) {
	function str_starts_with( $haystack, $needle ) {
		return substr( $haystack, 0, strlen( $needle ) ) === $needle;
	}
}

if ( ! function_exists( 'str_ends_with' ) ) {
	function str_ends_with( $haystack, $needle ) {
		return substr( $haystack, -strlen( $needle ) ) === $needle;
	}
}

if ( ! function_exists( 'str_contains' ) ) {
	function str_contains( $haystack, $needle ) {
		return $needle === '' || strpos( $haystack, $needle ) !== false;
	}
}

if ( ! function_exists( 'array_key_first' ) ) {
	function array_key_first( array $array ) {
		foreach ( $array as $key => $value ) {
			return $key;
		}
		return null;
	}
}
