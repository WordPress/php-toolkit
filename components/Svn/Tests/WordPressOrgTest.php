<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WordPress\Svn\SvnClient;

/**
 * Tests against the real WordPress.org Subversion servers:
 * develop.svn.wordpress.org (WordPress core development) and
 * plugins.svn.wordpress.org (the plugin directory).
 *
 * These need the network, so they only run when opted in:
 *
 *     SVN_TESTS_ONLINE=1 vendor/bin/phpunit components/Svn/Tests/WordPressOrgTest.php
 */
class WordPressOrgTest extends TestCase {
	const WORDPRESS_DEVELOP = 'https://develop.svn.wordpress.org';
	const PLUGINS           = 'https://plugins.svn.wordpress.org';

	/**
	 * @var string[]
	 */
	private $temp_dirs = array();

	/**
	 * @before
	 */
	public function require_opt_in() {
		if ( ! getenv( 'SVN_TESTS_ONLINE' ) ) {
			$this->markTestSkipped( 'Set SVN_TESTS_ONLINE=1 to run the WordPress.org network tests.' );
		}
	}

	/**
	 * @after
	 */
	public function remove_temp_dirs() {
		foreach ( $this->temp_dirs as $directory ) {
			exec( 'rm -rf ' . escapeshellarg( $directory ) );
		}
		$this->temp_dirs = array();
	}

	private function make_temp_path() {
		$path              = sys_get_temp_dir() . '/svn-wporg-test-' . bin2hex( random_bytes( 6 ) );
		$this->temp_dirs[] = $path;

		return $path;
	}

	private function client() {
		return new SvnClient( array( 'timeout_ms' => 120000 ) );
	}

	public function test_wordpress_develop_basics() {
		$client = $this->client();

		$latest = $client->latest_revision( self::WORDPRESS_DEVELOP );
		// WordPress development passed r62000 in 2025.
		$this->assertGreaterThan( 62000, $latest );

		$session = $client->open_session( self::WORDPRESS_DEVELOP . '/trunk' );
		$this->assertSame( 'https://develop.svn.wordpress.org', $session->get_repository_root() );
		$this->assertSame( '602fd350-edb4-49c9-b593-d223f7449a82', $session->get_uuid() );

		$names = array();
		foreach ( $session->list_directory( '' ) as $entry ) {
			$names[ $entry['name'] ] = $entry['kind'];
		}
		$this->assertSame( 'dir', $names['src'] );
		$this->assertSame( 'dir', $names['tests'] );
		$this->assertSame( 'file', $names['package.json'] );

		$file = $session->get_file( 'wp-config-sample.php' );
		$this->assertSame( md5( $file['contents'] ), $file['checksum'] );
		$this->assertStringContainsString( 'DB_NAME', $file['contents'] );
		$this->assertSame( 'CRLF', $file['properties']['svn:eol-style'] );

		$session->close();
	}

	public function test_checkout_a_wordpress_develop_subtree() {
		$client = $this->client();
		$path   = $this->make_temp_path();

		$result = $client->checkout( self::WORDPRESS_DEVELOP . '/trunk/src/wp-admin/css/colors', $path );

		$this->assertGreaterThan( 62000, $result['revision'] );
		$this->assertFileExists( "$path/blue/colors.scss" );
		$this->assertFileExists( "$path/midnight/colors.scss" );
		$this->assertStringContainsString( '$scheme-name', file_get_contents( "$path/blue/colors.scss" ) );

		// A fresh checkout must be clean.
		$this->assertSame( array(), $client->status( $path ) );
	}

	public function test_checkout_wordpress_develop_root_with_limited_depth() {
		$client = $this->client();
		$path   = $this->make_temp_path();

		$result = $client->checkout( self::WORDPRESS_DEVELOP . '/trunk', $path, array( 'depth' => 'files' ) );

		$this->assertGreaterThan( 62000, $result['revision'] );
		$this->assertFileExists( "$path/wp-config-sample.php" );
		$this->assertFileExists( "$path/package.json" );
		$this->assertFileDoesNotExist( "$path/src" );

		// wp-config-sample.php carries svn:eol-style CRLF.
		$this->assertStringContainsString( "\r\n", file_get_contents( "$path/wp-config-sample.php" ) );
		$this->assertSame( array(), $client->status( $path ) );
	}

	public function test_plugins_repository_read() {
		$client  = $this->client();
		$session = $client->open_session( self::PLUGINS . '/hello-dolly/trunk' );

		$file = $session->get_file( 'hello.php' );
		$this->assertSame( md5( $file['contents'] ), $file['checksum'] );
		$this->assertStringContainsString( 'Hello Dolly', $file['contents'] );

		$session->close();

		$path = $this->make_temp_path();
		$client->checkout( self::PLUGINS . '/hello-dolly/trunk', $path );
		$this->assertFileExists( "$path/hello.php" );
		$this->assertSame( array(), $client->status( $path ) );
	}
}
