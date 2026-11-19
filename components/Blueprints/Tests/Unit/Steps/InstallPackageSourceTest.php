<?php

namespace WordPress\Blueprints\Tests\Unit\Steps;

use function WordPress\Filesystem\wp_join_unix_paths;

require_once __DIR__ . '/StepTestCase.php';

class InstallPackageSourceTest extends StepTestCase {
	/**
	 * @dataProvider working_directory_provider
	 */
	public function test_flat_package_source_respects_wordpress_working_directory( $working_directory_name, $randomized ) {
		$working_dir = wp_join_unix_paths( $this->runtime->get_temp_root(), $working_directory_name );
		mkdir( $working_dir );
		file_put_contents( $working_dir . '/package.php', '<?php // Package file.' );

		// Supply both layouts directly so the installed WordPress version does not determine coverage.
		$source = $this->runtime->eval_php_code_in_subprocess(
			<<<'PHP'
<?php
require_once getenv( 'WP_CORE_DIR' ) . '/wp-load.php';
require_once getenv( 'WP_CORE_DIR' ) . '/wp-admin/includes/file.php';
require_once getenv( 'SOURCE_MODULE' );

WP_Filesystem();
$working_dir = getenv( 'WORKING_DIR' );
$source      = WordPress\Blueprints\Steps\stabilize_package_source( $working_dir, $working_dir, 'test-package.zip' );
if ( is_wp_error( $source ) ) {
	throw new RuntimeException( $source->get_error_message() );
}
append_output( $source );
PHP
			,
			array(
				'SOURCE_MODULE' => dirname( __DIR__, 3 ) . '/Steps/install-package-source.php',
				'WORKING_DIR'   => $working_dir,
			)
		)->output_file_content;

		$this->assertFileExists( rtrim( $source, '/' ) . '/package.php' );
		$this->assertSame( 'test-package', basename( rtrim( $source, '/' ) ) );
		if ( $randomized ) {
			$working_path = str_replace( '\\', '/', realpath( $working_dir ) );
			$source_path  = str_replace( '\\', '/', realpath( $source ) );
			$this->assertStringStartsWith( $working_path . '/', $source_path );
		} else {
			$this->assertSame( $working_dir, $source );
		}
	}

	public static function working_directory_provider() {
		return array(
			'randomized directory' => array( 'test-package-a1b2c3d4', true ),
			'package basename'     => array( 'test-package', false ),
		);
	}
}
