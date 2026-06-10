<?php
declare(strict_types=1);

namespace WordPress\Svn\Tests;

use WordPress\Svn\SvnClient;
use WordPress\Svn\SvnException;

/**
 * The client test scenarios, shared between the transports: the
 * svn:// suite runs them against a local svnserve, the http:// suite
 * against Apache + mod_dav_svn in Docker. Both servers expose the same
 * fixture repositories (see SvnTestServer).
 */
trait SvnClientBehaviorTrait {
	/**
	 * @var string[] Temp directories to remove after each test.
	 */
	private $temp_dirs = array();

	/**
	 * @var string|null URL of this test's private fixture repository.
	 */
	private $repo_base;

	/**
	 * @return SvnTestServer The running server for this suite.
	 */
	abstract protected function server();

	/**
	 * @after
	 */
	public function remove_temp_dirs() {
		foreach ( $this->temp_dirs as $directory ) {
			SvnTestServer::run( 'rm -rf ' . escapeshellarg( $directory ) );
		}
		$this->temp_dirs   = array();
		$this->repo_base   = null;
	}

	/**
	 * @return string URL of a fixture repository private to the running test.
	 */
	protected function repo() {
		if ( null === $this->repo_base ) {
			$this->repo_base = $this->server()->create_repository();
		}

		return $this->repo_base;
	}

	protected function make_temp_path() {
		$path              = sys_get_temp_dir() . '/svn-client-test-' . bin2hex( random_bytes( 6 ) );
		$this->temp_dirs[] = $path;

		return $path;
	}

	protected function client( $with_credentials = false ) {
		$options = array( 'timeout_ms' => 15000 );
		if ( $with_credentials ) {
			$options['username'] = SvnTestServer::USERNAME;
			$options['password'] = SvnTestServer::PASSWORD;
		}

		return new SvnClient( $options );
	}

	public function test_session_primitives() {
		$session = $this->client()->open_session( $this->repo() . '/trunk' );

		$this->assertGreaterThanOrEqual( 2, $session->get_latest_revision() );
		$this->assertSame( 'file', $session->check_path( 'hello.txt' ) );
		$this->assertSame( 'dir', $session->check_path( 'sub' ) );
		$this->assertSame( 'none', $session->check_path( 'no-such-node' ) );

		$names = array();
		foreach ( $session->list_directory( '' ) as $entry ) {
			$names[ $entry['name'] ] = $entry['kind'];
		}
		$this->assertSame( 'file', $names['hello.txt'] );
		$this->assertSame( 'file', $names['multi.txt'] );
		$this->assertSame( 'dir', $names['sub'] );

		$file = $session->get_file( 'hello.txt' );
		$this->assertSame( "hello world\n", $file['contents'] );
		$this->assertSame( md5( $file['contents'] ), $file['checksum'] );

		$properties = $session->get_properties( 'multi.txt' );
		$this->assertSame( 'CRLF', $properties['svn:eol-style'] );

		$session->close();
	}

	public function test_checkout_produces_clean_working_copy() {
		$client = $this->client();
		$path   = $this->make_temp_path();
		$result = $client->checkout( $this->repo() . '/trunk', $path );

		$this->assertGreaterThanOrEqual( 2, $result['revision'] );
		$this->assertSame( "hello world\n", file_get_contents( "$path/hello.txt" ) );
		$this->assertSame( "nested file\n", file_get_contents( "$path/sub/deep/nested.txt" ) );
		// Fixed eol-style files arrive with their style's line endings.
		$this->assertSame( "line1\r\nline2\r\nline3\r\n", file_get_contents( "$path/multi.txt" ) );

		// A fresh checkout must be pristine – especially the CRLF file.
		$this->assertSame( array(), $client->status( $path ) );

		$info = $client->info( $path );
		$this->assertSame( $this->repo() . '/trunk', $info['url'] );
		$this->assertSame( $this->repo(), $info['repository_root'] );
	}

	public function test_checkout_at_old_revision() {
		$client = $this->client();
		$path   = $this->make_temp_path();
		$result = $client->checkout( $this->repo() . '/trunk', $path, array( 'revision' => 1 ) );

		$this->assertSame( 1, $result['revision'] );
		$this->assertSame( 1, $client->info( $path )['revision'] );
		// At r1 the eol-style property did not exist yet.
		$this->assertSame( "line1\nline2\nline3\n", file_get_contents( "$path/multi.txt" ) );
	}

	public function test_commit_roundtrip() {
		$client = $this->client();
		$path   = $this->make_temp_path();
		$client->checkout( $this->repo() . '/trunk', $path );

		file_put_contents( "$path/hello.txt", "a change from the test suite\n" );
		mkdir( "$path/lib/nested", 0777, true );
		file_put_contents( "$path/lib/nested/util.php", "<?php // new\n" );
		file_put_contents( "$path/standalone.txt", "standalone\n" );
		$client->add( $path, array( 'lib', 'standalone.txt' ) );
		$client->delete( $path, 'sub/deep/nested.txt' );

		$status = $client->status( $path );
		$this->assertSame( 'modified', $status['hello.txt'] );
		$this->assertSame( 'added', $status['lib'] );
		$this->assertSame( 'added', $status['lib/nested/util.php'] );
		$this->assertSame( 'deleted', $status['sub/deep/nested.txt'] );

		$commit_info = $client->commit( $path, 'roundtrip commit from the test suite' );
		$this->assertGreaterThanOrEqual( 3, $commit_info['revision'] );
		$this->assertSame( array(), $client->status( $path ) );
		$this->assertNull( $client->commit( $path, 'nothing to do' ) );

		// An independent checkout must see exactly what was committed.
		$verify_path = $this->make_temp_path();
		$client->checkout( $this->repo() . '/trunk', $verify_path );
		$this->assertSame( "a change from the test suite\n", file_get_contents( "$verify_path/hello.txt" ) );
		$this->assertSame( "<?php // new\n", file_get_contents( "$verify_path/lib/nested/util.php" ) );
		$this->assertSame( "standalone\n", file_get_contents( "$verify_path/standalone.txt" ) );
		$this->assertFileDoesNotExist( "$verify_path/sub/deep/nested.txt" );
	}

	public function test_commit_does_not_rewrite_eol_styled_files() {
		// Regression test: committing an unrelated change must not sneak
		// in an end-of-line normalization of svn:eol-style files.
		$client = $this->client();
		$path   = $this->make_temp_path();
		$client->checkout( $this->repo() . '/trunk', $path );
		$revision_before = $client->info( $path )['revision'];

		file_put_contents( "$path/unrelated.txt", "unrelated\n" );
		$client->add( $path, 'unrelated.txt' );
		$client->commit( $path, 'unrelated change' );

		$session = $client->open_session( $this->repo() . '/trunk' );
		$file    = $session->get_file( 'multi.txt' );
		$session->close();
		$this->assertSame( "line1\r\nline2\r\nline3\r\n", $file['contents'] );
	}

	public function test_update_pulls_changes() {
		$client = $this->client();
		$writer = $this->make_temp_path();
		$reader = $this->make_temp_path();
		$client->checkout( $this->repo() . '/trunk', $writer );
		$client->checkout( $this->repo() . '/trunk', $reader );

		file_put_contents( "$writer/hello.txt", "updated upstream\n" );
		file_put_contents( "$writer/fresh.txt", "fresh\n" );
		$client->add( $writer, 'fresh.txt' );
		$client->delete( $writer, 'multi.txt' );
		$committed = $client->commit( $writer, 'update test changes' );

		$result = $client->update( $reader );
		$this->assertSame( $committed['revision'], $result['revision'] );
		$this->assertContains( 'fresh.txt', $result['added'] );
		$this->assertContains( 'hello.txt', $result['updated'] );
		$this->assertContains( 'multi.txt', $result['deleted'] );
		$this->assertSame( "updated upstream\n", file_get_contents( "$reader/hello.txt" ) );
		$this->assertSame( "fresh\n", file_get_contents( "$reader/fresh.txt" ) );
		$this->assertFileDoesNotExist( "$reader/multi.txt" );
		$this->assertSame( array(), $client->status( $reader ) );
	}

	public function test_update_preserves_unrelated_local_modifications() {
		$client = $this->client();
		$writer = $this->make_temp_path();
		$reader = $this->make_temp_path();
		$client->checkout( $this->repo() . '/trunk', $writer );
		$client->checkout( $this->repo() . '/trunk', $reader );

		file_put_contents( "$writer/hello.txt", "upstream edit\n" );
		$client->commit( $writer, 'edit hello upstream' );

		file_put_contents( "$reader/multi.txt", "local edit\r\n" );
		$client->update( $reader );

		$this->assertSame( "upstream edit\n", file_get_contents( "$reader/hello.txt" ) );
		$this->assertSame( "local edit\r\n", file_get_contents( "$reader/multi.txt" ) );
		$this->assertSame( array( 'multi.txt' => 'modified' ), $client->status( $reader ) );
	}

	public function test_conflicting_update_and_resolution() {
		$client = $this->client();
		$writer = $this->make_temp_path();
		$reader = $this->make_temp_path();
		$client->checkout( $this->repo() . '/trunk', $writer );
		$client->checkout( $this->repo() . '/trunk', $reader );

		file_put_contents( "$writer/hello.txt", "the writer's version\n" );
		$committed = $client->commit( $writer, 'writer changes hello' );

		file_put_contents( "$reader/hello.txt", "the reader's version\n" );
		$result = $client->update( $reader );

		$this->assertSame( array( 'hello.txt' ), $result['conflicted'] );
		$this->assertSame( 'conflicted', $client->status( $reader )['hello.txt'] );
		// The local version stays in place, the incoming version lands
		// next to it as <name>.r<revision>.
		$this->assertSame( "the reader's version\n", file_get_contents( "$reader/hello.txt" ) );
		$incoming = "$reader/hello.txt.r{$committed['revision']}";
		$this->assertSame( "the writer's version\n", file_get_contents( $incoming ) );

		try {
			$client->commit( $reader, 'must be blocked' );
			$this->fail( 'Committing a conflicted working copy must throw.' );
		} catch ( SvnException $exception ) {
			$this->assertStringContainsString( 'conflict', $exception->getMessage() );
		}

		$client->resolved( $reader, 'hello.txt' );
		$this->assertFileDoesNotExist( $incoming );
		$resolution = $client->commit( $reader, 'the reader wins' );
		$this->assertGreaterThan( $committed['revision'], $resolution['revision'] );
	}

	public function test_revert_restores_pristine_state() {
		$client = $this->client();
		$path   = $this->make_temp_path();
		$client->checkout( $this->repo() . '/trunk', $path );

		file_put_contents( "$path/hello.txt", "scribbles\n" );
		file_put_contents( "$path/new-file.txt", "new\n" );
		$client->add( $path, 'new-file.txt' );
		$client->delete( $path, 'multi.txt' );

		$client->revert( $path, array( 'hello.txt', 'new-file.txt', 'multi.txt' ) );

		$this->assertSame( "hello world\n", file_get_contents( "$path/hello.txt" ) );
		$this->assertSame( "line1\r\nline2\r\nline3\r\n", file_get_contents( "$path/multi.txt" ) );
		// Reverting an addition keeps the file on disk, unversioned.
		$this->assertSame( array( 'new-file.txt' => 'unversioned' ), $client->status( $path ) );
	}

	public function test_delete_refuses_modified_files_without_force() {
		$client = $this->client();
		$path   = $this->make_temp_path();
		$client->checkout( $this->repo() . '/trunk', $path );
		file_put_contents( "$path/hello.txt", "precious local edit\n" );

		try {
			$client->delete( $path, 'hello.txt' );
			$this->fail( 'Deleting a modified file without force must throw.' );
		} catch ( SvnException $exception ) {
			$this->assertStringContainsString( 'local modifications', $exception->getMessage() );
		}
		$this->assertFileExists( "$path/hello.txt" );

		$client->delete( $path, 'hello.txt', array( 'force' => true ) );
		$this->assertFileDoesNotExist( "$path/hello.txt" );
		$this->assertSame( 'deleted', $client->status( $path )['hello.txt'] );
	}

	public function test_authentication() {
		$auth_url = $this->server()->create_repository( true ) . '/trunk';

		try {
			$this->client()->checkout( $auth_url, $this->make_temp_path() );
			$this->fail( 'Anonymous access to the authenticated repository must throw.' );
		} catch ( SvnException $exception ) {
			// Expected: the server demands credentials.
			$this->assertNotSame( '', $exception->getMessage() );
		}

		$wrong_password = new \WordPress\Svn\SvnClient(
			array(
				'username'   => SvnTestServer::USERNAME,
				'password'   => 'wrong-password',
				'timeout_ms' => 15000,
			)
		);
		try {
			$wrong_password->checkout( $auth_url, $this->make_temp_path() );
			$this->fail( 'A wrong password must throw.' );
		} catch ( SvnException $exception ) {
			$this->assertNotSame( '', $exception->getMessage() );
		}

		$client = $this->client( true );
		$path   = $this->make_temp_path();
		$client->checkout( $auth_url, $path );
		$this->assertSame( "hello world\n", file_get_contents( "$path/hello.txt" ) );

		file_put_contents( "$path/by-alice.txt", "authenticated write\n" );
		$client->add( $path, 'by-alice.txt' );
		$commit_info = $client->commit( $path, 'authenticated commit' );
		$this->assertSame( SvnTestServer::USERNAME, $commit_info['author'] );
	}

	public function test_externals_are_checked_out_as_nested_working_copies() {
		$client = $this->client();

		// Define an external pointing at ^/trunk/sub – exercises both the
		// property commit and the repository-root-relative URL syntax.
		$session = $client->open_session( $this->repo() );
		$session->commit(
			'define externals',
			array(
				array(
					'op'            => 'modify-properties',
					'path'          => 'trunk',
					'kind'          => 'dir',
					'base_revision' => $session->get_latest_revision(),
					'properties'    => array( 'svn:externals' => "^/trunk/sub vendor/sub-external\n" ),
				),
			)
		);
		$session->close();

		$path   = $this->make_temp_path();
		$result = $client->checkout( $this->repo() . '/trunk', $path );
		$this->assertArrayHasKey( 'vendor/sub-external', $result['externals'] );
		$this->assertSame( "nested file\n", file_get_contents( "$path/vendor/sub-external/deep/nested.txt" ) );

		// The external is its own working copy of the referenced URL.
		$external_info = $client->info( "$path/vendor/sub-external" );
		$this->assertSame( $this->repo() . '/trunk/sub', $external_info['url'] );

		// Status reports the external but does not descend into it.
		$status = $client->status( $path );
		$this->assertSame( 'external', $status['vendor/sub-external'] );
		$this->assertArrayNotHasKey( 'vendor/sub-external/deep/nested.txt', $status );

		// Updating keeps the external in sync.
		$update_result = $client->update( $path );
		$this->assertArrayHasKey( 'vendor/sub-external', $update_result['externals'] );

		// ignore_externals skips the nested checkout entirely.
		$bare_path = $this->make_temp_path();
		$client->checkout( $this->repo() . '/trunk', $bare_path, array( 'ignore_externals' => true ) );
		$this->assertFileDoesNotExist( "$bare_path/vendor/sub-external" );
	}

	public function test_checkout_with_limited_depth() {
		$client = $this->client();
		$path   = $this->make_temp_path();
		$client->checkout( $this->repo() . '/trunk', $path, array( 'depth' => 'files' ) );

		$this->assertFileExists( "$path/hello.txt" );
		$this->assertFileExists( "$path/multi.txt" );
		$this->assertFileDoesNotExist( "$path/sub" );
	}

	public function test_out_of_date_commit_is_rejected() {
		$client = $this->client();
		$first  = $this->make_temp_path();
		$second = $this->make_temp_path();
		$client->checkout( $this->repo() . '/trunk', $first );
		$client->checkout( $this->repo() . '/trunk', $second );

		file_put_contents( "$first/hello.txt", "first writer\n" );
		$client->commit( $first, 'first writer wins' );

		file_put_contents( "$second/hello.txt", "second writer\n" );
		try {
			$client->commit( $second, 'should be out of date' );
			$this->fail( 'Committing against an out-of-date base must throw.' );
		} catch ( SvnException $exception ) {
			$this->assertNotSame( '', $exception->getMessage() );
		}
	}
}
