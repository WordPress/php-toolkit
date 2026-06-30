<?php
/**
 * `WP_Exception` is the exception thrown by `wp_trigger_error()` when it is
 * called with `E_USER_ERROR`. It does not exist in WordPress core, so a
 * downstream consumer that boots WordPress will not redeclare it — but we
 * still load this class lazily through Composer's classmap to keep the
 * autoload.files entrypoint free of class declarations and consistent with
 * the WP_Error polyfill alongside it.
 */

// phpcs:disable

if ( ! class_exists( 'WP_Exception' ) ) {
	class WP_Exception extends Exception {
	}
}
