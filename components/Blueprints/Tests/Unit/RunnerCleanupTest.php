<?php

namespace WordPress\Blueprints\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPress\Blueprints\DataReference\InlineFile;
use WordPress\Blueprints\Logger\NoopLogger;
use WordPress\Blueprints\Runner;
use WordPress\Blueprints\RunnerConfiguration;

use function WordPress\Filesystem\wp_join_unix_paths;
use function WordPress\Filesystem\wp_unix_sys_get_temp_dir;

class RunnerCleanupTest extends TestCase {
	/**
	 * @var string[]
	 */
	private $paths_to_remove = array();

	/**
	 * @after
	 */
	public function tearDown(): void {
		unset( $GLOBALS['wp_filter']['blueprint.target_resolved'] );

		foreach ( array_reverse( $this->paths_to_remove ) as $path ) {
			$this->remove_directory( $path );
		}
	}

	public function test_removes_temporary_workspace_after_successful_run() {
		$logger = new RecordingLogger();
		$runner = $this->create_runner_for_existing_site( $logger );

		$runner->run();

		$this->assertFalse( is_dir( $runner->runtime->get_temp_root() ) );
		$this->assertSame( array(), $logger->warnings );
	}

	public function test_logs_cleanup_failure_without_failing_successful_run() {
		if ( PHP_OS_FAMILY !== 'Windows' && function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'This test needs filesystem permissions to reject a temporary workspace cleanup.' );
		}

		$logger           = new RecordingLogger();
		$runner           = $this->create_runner_for_existing_site( $logger );
		$temp_root        = null;
		$unremovable_dir  = null;
		$unremovable_file = null;
		$open_handle      = null;

		add_action(
			'blueprint.target_resolved',
			function () use ( $runner, &$temp_root, &$unremovable_dir, &$unremovable_file, &$open_handle ) {
				$temp_root        = $runner->runtime->get_temp_root();
				$unremovable_dir  = wp_join_unix_paths( $temp_root, 'unremovable' );
				$unremovable_file = wp_join_unix_paths( $unremovable_dir, 'locked.txt' );

				mkdir( $unremovable_dir );
				file_put_contents( $unremovable_file, 'locked' );
				$open_handle = fopen( $unremovable_file, 'r' );

				if ( PHP_OS_FAMILY !== 'Windows' ) {
					chmod( $unremovable_dir, 0555 );
				}
			}
		);

		try {
			$runner->run();
		} finally {
			if ( $open_handle ) {
				fclose( $open_handle );
			}
			if ( $unremovable_dir && is_dir( $unremovable_dir ) ) {
				chmod( $unremovable_dir, 0777 );
			}
			if ( $temp_root ) {
				$this->remove_directory( $temp_root );
			}
		}

		$this->assertCount( 1, $logger->warnings );
		$this->assertStringContainsString( 'Failed to remove temporary Blueprint workspace ', $logger->warnings[0] );
		$this->assertStringContainsString( $temp_root, $logger->warnings[0] );
	}

	private function create_runner_for_existing_site( RecordingLogger $logger ) {
		$site_root = wp_join_unix_paths( wp_unix_sys_get_temp_dir(), 'blueprint_cleanup_test_' . uniqid() );
		$this->paths_to_remove[] = $site_root;

		mkdir( wp_join_unix_paths( $site_root, 'wp-content/plugins/sqlite-database-integration' ), 0777, true );
		file_put_contents(
			wp_join_unix_paths( $site_root, 'wp-load.php' ),
			<<<'STUBPHP'
<?php
define( 'WP_CONTENT_DIR', __DIR__ . '/wp-content' );
function is_blog_installed() {
	return true;
}
STUBPHP
		);
		file_put_contents( wp_join_unix_paths( $site_root, 'wp-content/plugins/sqlite-database-integration/load.php' ), '<?php' );
		file_put_contents( wp_join_unix_paths( $site_root, 'wp-content/db.php' ), '<?php' );

		$config = ( new RunnerConfiguration() )
			->set_blueprint( array( 'version' => 2 ) )
			->set_execution_mode( Runner::EXECUTION_MODE_APPLY_TO_EXISTING_SITE )
			->set_target_site_root( $site_root )
			->set_target_site_url( 'http://example.com' )
			->set_database_engine( 'sqlite' )
			->set_logger( $logger )
			->set_wp_cli_reference(
				new InlineFile(
					array(
						'filename' => 'wp-cli.phar',
						'content'  => '',
					)
				)
			);

		return new Runner( $config );
	}

	private function remove_directory( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		chmod( $dir, 0777 );
		$objects = scandir( $dir );
		foreach ( $objects as $object ) {
			if ( '.' === $object || '..' === $object ) {
				continue;
			}

			$path = $dir . DIRECTORY_SEPARATOR . $object;
			if ( is_dir( $path ) ) {
				$this->remove_directory( $path );
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $dir );
	}
}

class RecordingLogger extends NoopLogger {
	/**
	 * @var string[]
	 */
	public $warnings = array();

	public function warning( $message, array $context = array() ): void {
		$this->warnings[] = $message;
	}
}
