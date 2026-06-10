<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WordPress\Svn\Tests\SvnClientBehaviorTrait;
use WordPress\Svn\Tests\SvnTestServer;

require_once __DIR__ . '/SvnTestServer.php';
require_once __DIR__ . '/SvnClientBehaviorTrait.php';

/**
 * Runs the client scenarios over http:// against a real Apache +
 * mod_dav_svn server in a Docker container (see Tests/http-server/).
 * Skipped when no Docker daemon is available.
 */
class DavClientTest extends TestCase {
	use SvnClientBehaviorTrait;

	/**
	 * @var SvnTestServer|null
	 */
	private static $dav_server;

	/**
	 * @beforeClass
	 */
	public static function start_server() {
		if ( ! SvnTestServer::docker_available() ) {
			self::markTestSkipped( 'Docker is not available; skipping the mod_dav_svn integration tests.' );
		}
		self::$dav_server = SvnTestServer::start_dav();
	}

	/**
	 * @afterClass
	 */
	public static function stop_server() {
		if ( null !== self::$dav_server ) {
			self::$dav_server->stop();
			self::$dav_server = null;
		}
	}

	protected function server() {
		return self::$dav_server;
	}
}
