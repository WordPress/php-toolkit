<?php

namespace WordPress\Blueprints\Tests\Unit\Steps;

use PHPUnit\Framework\TestCase;
use WordPress\Blueprints\DataReference\AbsoluteLocalPath;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runner;
use WordPress\Blueprints\RunnerConfiguration;
use WordPress\Blueprints\Runtime;
use WordPress\Blueprints\Steps\StepInterface;
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

		$blueprint = array(
			'version'   => 2,
			'constants' => array(
				'SQLITE_JOURNAL_MODE'                           => 'DELETE',
				'WP_SQLITE_UNSAFE_ENABLE_UNSUPPORTED_VERSIONS' => true,
			),
		);
		if ( PHP_VERSION_ID < 70400 ) {
			$blueprint['wordpressVersion'] = '6.6.2';
		}

		file_put_contents(
			wp_join_unix_paths( $this->execution_context_path, 'blueprint.json' ),
			json_encode( $blueprint )
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
		// Cleanup is slow on Windows, but retaining every copied WordPress site
		// exhausts the temporary drive during the full test suite.
		// Clean up temp directory
		if ( is_dir( $this->document_root ) ) {
			$this->removeDirectory( $this->document_root );
		}
		if ( is_dir( $this->runtime->get_temp_root() ) ) {
			$this->removeDirectory( $this->runtime->get_temp_root() );
		}
	}

	protected function assert_install_stages_package_under_working_directory( StepInterface $step, $slug ) {
		$filesystem = $this->runtime->get_target_filesystem();
		$filesystem->mkdir( 'wp-content/mu-plugins', array( 'recursive' => true ) );
		// Record the final source before WordPress moves the files and cleans up staging.
		$filesystem->put_contents(
			'wp-content/mu-plugins/record-upgrader-source.php',
			<<<'PHP'
<?php
add_filter(
	'upgrader_source_selection',
	static function ( $source, $working_dir ) {
		if ( ! is_wp_error( $source ) ) {
			file_put_contents(
				__DIR__ . '/upgrader-source.json',
				json_encode( array( realpath( $source ), realpath( $working_dir ) ) )
			);
		}
		return $source;
	},
	PHP_INT_MAX,
	2
);
PHP
		);

		$step->run( $this->runtime, new Tracker() );

		$record = 'wp-content/mu-plugins/upgrader-source.json';
		$this->assertTrue( $filesystem->exists( $record ), 'WordPress should select a source for the package.' );
		list( $source, $working_dir ) = json_decode( $filesystem->get_contents( $record ), true );

		$this->assertIsString( $source, 'The selected package source should exist.' );
		$this->assertIsString( $working_dir, 'The original working directory should still exist.' );

		$source      = str_replace( '\\', '/', $source );
		$working_dir = str_replace( '\\', '/', $working_dir );
		$this->assertSame( $slug, basename( $source ) );
		if ( $slug === basename( $working_dir ) ) {
			// Older WordPress versions already use the package basename.
			$this->assertSame( $working_dir, $source );
		} else {
			$this->assertStringStartsWith( $working_dir . '/', $source );
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
