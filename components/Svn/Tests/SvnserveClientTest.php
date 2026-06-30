<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WordPress\Svn\Tests\SvnClientBehaviorTrait;
use WordPress\Svn\Tests\SvnTestServer;

require_once __DIR__ . '/SvnTestServer.php';
require_once __DIR__ . '/SvnClientBehaviorTrait.php';

/**
 * Runs the client scenarios over the svn:// protocol against a real,
 * locally spawned svnserve. Skipped when the subversion tools are not
 * installed.
 */
class SvnserveClientTest extends TestCase {
	use SvnClientBehaviorTrait;

	/**
	 * @var SvnTestServer|null
	 */
	private static $svnserve;

	/**
	 * @beforeClass
	 */
	public static function start_server() {
		if ( ! SvnTestServer::svn_binaries_available() ) {
			self::markTestSkipped( 'The svnadmin/svnserve/svn binaries are not available.' );
		}
		self::$svnserve = SvnTestServer::start_svnserve();
	}

	/**
	 * @afterClass
	 */
	public static function stop_server() {
		if ( null !== self::$svnserve ) {
			self::$svnserve->stop();
			self::$svnserve = null;
		}
	}

	protected function server() {
		return self::$svnserve;
	}

	public function test_interoperates_with_the_official_client() {
		$repo_url = $this->repo();
		$client   = $this->client();
		$path     = $this->make_temp_path();
		$client->checkout( $repo_url . '/trunk', $path );

		file_put_contents( "$path/hello.txt", "written by php-toolkit\n" );
		file_put_contents( "$path/from-php.txt", "a new file\n" );
		$client->add( $path, 'from-php.txt' );
		$commit_info = $client->commit( $path, 'commit for CLI interop check' );

		// The official client must see our commit…
		$official = $this->make_temp_path();
		SvnTestServer::run(
			'svn checkout -q ' . escapeshellarg( $repo_url . '/trunk' ) . ' ' . escapeshellarg( $official )
		);
		$this->assertSame( "written by php-toolkit\n", file_get_contents( "$official/hello.txt" ) );
		$this->assertSame( "a new file\n", file_get_contents( "$official/from-php.txt" ) );

		// …and apart from the administrative areas the two working
		// copies must be byte-identical.
		SvnTestServer::run(
			'diff -r --exclude=.svn ' . escapeshellarg( $official ) . ' ' . escapeshellarg( $path )
		);

		// The other direction: a CLI commit must reach us via update.
		file_put_contents( "$official/from-cli.txt", "committed by the official client\n" );
		SvnTestServer::run( 'svn add -q from-cli.txt && svn commit -q -m "from the CLI" --username fixture', $official );
		$client->update( $path );
		$this->assertSame( "committed by the official client\n", file_get_contents( "$path/from-cli.txt" ) );

		// And the repository itself must stay healthy.
		$this->assertGreaterThan( 0, $commit_info['revision'] );
	}
}
