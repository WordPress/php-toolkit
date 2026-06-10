<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WordPress\Svn\SvnClient;

/**
 * The full proof: checks out the entire wordpress-develop trunk –
 * thousands of files, hundreds of megabytes – over HTTPS, with every
 * file checksum-verified during the editor drive, then updates it
 * across 80 revisions of real history.
 *
 * This downloads a lot of data, so it requires a double opt-in:
 *
 *     SVN_TESTS_ONLINE=1 SVN_TESTS_HUGE=1 \
 *         vendor/bin/phpunit components/Svn/Tests/WordPressDevelopCheckoutTest.php
 */
class WordPressDevelopCheckoutTest extends TestCase {
	/**
	 * @var string|null
	 */
	private $checkout_path;

	/**
	 * @before
	 */
	public function require_opt_in() {
		if ( ! getenv( 'SVN_TESTS_ONLINE' ) || ! getenv( 'SVN_TESTS_HUGE' ) ) {
			$this->markTestSkipped( 'Set SVN_TESTS_ONLINE=1 and SVN_TESTS_HUGE=1 to run the full wordpress-develop checkout.' );
		}
		$this->checkout_path = sys_get_temp_dir() . '/svn-wpdevelop-test-' . bin2hex( random_bytes( 6 ) );
	}

	/**
	 * @after
	 */
	public function remove_checkout() {
		if ( null !== $this->checkout_path ) {
			exec( 'rm -rf ' . escapeshellarg( $this->checkout_path ) );
			$this->checkout_path = null;
		}
	}

	public function test_full_wordpress_develop_checkout_and_update() {
		$client = new SvnClient( array( 'timeout_ms' => 600000 ) );

		// Pin two known revisions so the assertions stay meaningful.
		$old_revision = 62400;
		$new_revision = 62480;

		$result = $client->checkout(
			'https://develop.svn.wordpress.org/trunk',
			$this->checkout_path,
			array( 'revision' => $old_revision )
		);
		$this->assertSame( $old_revision, $result['revision'] );
		$this->assertGreaterThan( 6000, count( $result['added'] ) );

		// The tree must contain the well-known landmarks…
		$this->assertFileExists( "{$this->checkout_path}/src/wp-includes/version.php" );
		$this->assertFileExists( "{$this->checkout_path}/src/wp-settings.php" );
		$this->assertFileExists( "{$this->checkout_path}/tests/phpunit/includes/bootstrap.php" );
		$this->assertStringContainsString(
			'$wp_version',
			file_get_contents( "{$this->checkout_path}/src/wp-includes/version.php" )
		);

		// …including wordpress-develop's real svn:externals definition,
		// which points at a different WordPress.org server entirely.
		$importer = "{$this->checkout_path}/tests/phpunit/data/plugins/wordpress-importer";
		$this->assertFileExists( "$importer/class-wp-import.php" );
		$this->assertSame(
			'https://plugins.svn.wordpress.org/wordpress-importer/trunk',
			$client->info( $importer )['url']
		);

		// Every file was checksum-verified during the drive; the status
		// walk re-verifies all of them against the pristine store. Only
		// the external may be flagged.
		$status = $client->status( $this->checkout_path );
		unset( $status['tests/phpunit/data/plugins/wordpress-importer'] );
		$this->assertSame( array(), $status );

		// Now replay 80 revisions of real WordPress history.
		$update = $client->update( $this->checkout_path, array( 'revision' => $new_revision ) );
		$this->assertSame( $new_revision, $update['revision'] );
		$this->assertGreaterThan( 100, count( $update['updated'] ) );
		$this->assertSame( array(), $update['conflicted'] );
		$this->assertSame( $new_revision, $client->info( $this->checkout_path )['revision'] );
	}
}
