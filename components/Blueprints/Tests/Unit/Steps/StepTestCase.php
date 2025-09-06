<?php

namespace WordPress\Blueprints\Tests\Unit\Steps;

use PHPUnit\Framework\TestCase;
use WordPress\Blueprints\DataReference\AbsoluteLocalPath;
use WordPress\Blueprints\Runner;
use WordPress\Blueprints\RunnerConfiguration;
use WordPress\Blueprints\Runtime;
use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\LocalFilesystem;

use function WordPress\Filesystem\wp_join_unix_paths;
use function WordPress\Filesystem\wp_unix_sys_get_temp_dir;

class StepTestCase extends TestCase {
	/**
	 * @var string
	 */
	protected $document_root;

	/**
	 * @var string
	 */
	protected $execution_context_path;

	/**
	 * @var Filesystem
	 */
	protected $execution_context;

	/**
	 * @var Runtime
	 */
	public $runtime;

	/**
	 * @before
	 */
	public function setUp(): void {
		if (PHP_OS_FAMILY === 'Linux' && file_exists('/etc/os-release') && strpos(file_get_contents('/etc/os-release'), 'Ubuntu') !== false) {
			$this->markTestSkipped('Step tests are skipped on Ubuntu. @TODO: Re-enable them. Somehow the WordPress.zip request always times out.');
		}
		$tmp_dir = wp_unix_sys_get_temp_dir();
		$this->document_root          = wp_join_unix_paths( $tmp_dir, 'test_' . uniqid() );
		$this->execution_context_path = wp_join_unix_paths( $tmp_dir, 'test_' . uniqid() );
		$this->execution_context      = LocalFilesystem::create( $this->execution_context_path );

		$base_site_root = wp_join_unix_paths( $tmp_dir, 'blueprint_test_base_site' );
		if ( is_dir( $base_site_root ) && file_exists( wp_join_unix_paths( $base_site_root, 'wp-load.php' ) ) ) {
			LocalFilesystem::create( $tmp_dir )->copy(
				'blueprint_test_base_site',
				basename( $this->document_root ),
				[ 'recursive' => true ]
			);
			$config = ( new RunnerConfiguration() )
				->set_execution_mode( 'apply-to-existing-site' )
				->set_target_site_root( $this->document_root )
			;
		} else {
			$config = ( new RunnerConfiguration() )
				->set_execution_mode( 'create-new-site' )
				->set_target_site_root( $base_site_root )
			;
		}

		file_put_contents(
			wp_join_unix_paths( $this->execution_context_path, 'blueprint.json' ),
			json_encode( [ "version" => 2 ] )
		);

		$config
			->set_blueprint( new AbsoluteLocalPath( wp_join_unix_paths( $this->execution_context_path, 'blueprint.json' ) ) )
			->set_database_engine( 'sqlite' )
			->set_target_site_url( 'http://127.0.0.1:2456' );

		$runner = new Runner( $config );
		try {
			$runner->run();
		} catch ( \WordPress\Filesystem\FilesystemException $e ) {
			// Windows CI won't remove the temp directory, let's ignore that failure.
		}
		$this->runtime = $runner->runtime;
		// Recreate the temp root directory – the runner cleans it up at the
		// end of run().
		@mkdir( $this->runtime->get_temp_root() );
	}

	/**
	 * @after
	 */
	public function tearDown(): void {
		// Don't clean up on Windows – it adds ~20s to each test in GitHub CI!
		if (PHP_OS_FAMILY === 'Windows') {
			return;
		}
		// Clean up temp directory
		if ( is_dir( $this->document_root ) ) {
			$this->removeDirectory( $this->document_root );
		}
		if ( is_dir( $this->runtime->get_temp_root() ) ) {
			$this->removeDirectory( $this->runtime->get_temp_root() );
		}
	}

	private function removeDirectory( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$objects = scandir( $dir );
		foreach ( $objects as $object ) {
			if ( $object == "." || $object == ".." ) {
				continue;
			}

			$path = $dir . DIRECTORY_SEPARATOR . $object;
			if ( is_dir( $path ) ) {
				$this->removeDirectory( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}
}
