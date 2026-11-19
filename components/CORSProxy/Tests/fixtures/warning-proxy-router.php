<?php
/**
 * Router that runs the loopback proxy with a rate-limit hook that raises a
 * PHP warning on every request, so tests can check where warnings go.
 */

function playground_cors_proxy_maybe_rate_limit() {
	trigger_error( 'cors-proxy-test-warning', E_USER_WARNING );
}

require __DIR__ . '/loopback-proxy-router.php';
