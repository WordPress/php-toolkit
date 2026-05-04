<?php
/**
 * Polyfill for the WordPress core WP_Error class.
 *
 * Loaded lazily through Composer's classmap so that simply requiring
 * `vendor/autoload.php` does not eagerly declare `WP_Error`. Eager
 * declaration would fatal as soon as WordPress core loads its own copy,
 * which is the regression caught by bin/check-wp-coexistence.php.
 */

// phpcs:disable

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public $data;

		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( empty( $code ) ) {
				return;
			}
			$this->code = $code;
			$this->message = $message;
			$this->data = $data;
		}
	}
}
