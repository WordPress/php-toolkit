<?php

namespace WordPress\Blueprints\Steps;

use RuntimeException;
use WordPress\Blueprints\DataReference\File;
use WordPress\Blueprints\Exception\BlueprintExecutionException;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;

/**
 * Represents the 'importContent' step.
 * @TODO: Ditch the WXR_Importer plugin and adapt Data Liberation importer here.
 *        CLI importing logic is fleshed out in import-markdown-directory.php. This
 *        step could run a similar script as a subprocess and report the progress back
 *        using $progress tracker.
 */
class ImportContentStep implements StepInterface {
	/**
	 * @var mixed[]
	 */
	private $content;

	public function __construct( array $content ) {
		$this->content = $content;
	}

	public function run( Runtime $runtime, Tracker $progress ) {
		$progress->setCaption( 'Importing content' );

		$total_files = count( $this->content );
		if ( $total_files === 0 ) {
			$progress->finish();

			return true;
		}

		$progress->split( $total_files );

		foreach ( $this->content as $i => $content_definition ) {
			if ( $content_definition['type'] === 'wxr' ) {
				// @TODO: More useful captions – include the url
				$progress[ $i ]->setCaption( 'Importing WXR file ' );
				$this->importWxr( $runtime, $content_definition );
			} elseif ( $content_definition['type'] === 'posts' ) {
				$progress[ $i ]->setCaption( 'Importing a post ' );
				$this->importPosts( $runtime, $content_definition );
			} else {
				throw new RuntimeException( 'Unsupported content type: ' . $content_definition['type'] );
			}

			$progress[ $i ]->finish();
		}

		$progress->finish();
	}

	private function importWxr( Runtime $runtime, array $content_definition ): void {
		$resolved = $runtime->resolve( $content_definition['source'] );
		if ( ! $resolved instanceof File ) {
			throw new BlueprintExecutionException( sprintf(
				'Imported content reference must be a file, but %s was a Directory.',
				$content_definition['source']->get_human_readable_name()
			) );
		}

		$wxrPath = $runtime->saveToTemporaryFile( $resolved );
		// @TODO: Make it work when Blueprints are running as phar archive
		$import_script_path = __DIR__ . '/scripts/import-content.php';
		if ( ! file_exists( $import_script_path ) ) {
			throw new BlueprintExecutionException( sprintf(
				'Import script %s does not exist.',
				$import_script_path
			) );
		}

		$importer_script = file_get_contents( $import_script_path );
		$runtime->evalPhpCodeInSubProcess(
			<<<'PHP'
<?php
// @TODO: Just call a function here, do not go through CLI arguments.
// @TODO: Establish a communication channel between the main process and the subprocess
//        to report progress and errors.
// @TODO: Enforce chrooting of the imported static files.
$_SERVER['argv'] = [
	'import-wxr.php',
	'wxr',
	getenv('WXR_PATH'),
	'--media-url',
	'https://pd.w.org/'
];
?>
PHP
			. $importer_script
			,
			[
				'WXR_PATH' => $wxrPath,
			]
		);
	}

	private function importPosts( Runtime $runtime, array $content_definition ): void {
		$posts = $content_definition['source'];
		if ( ! is_array( $posts ) ) {
			throw new RuntimeException( 'Invalid posts data.' );
		}

		$runtime->evalPhpCodeInSubProcess(
			<<<'PHP'
<?php
require_once getenv('DOCROOT') . '/wp-load.php';
foreach (json_decode(getenv('POSTS'), true) as $post) {
wp_insert_post(wp_slash($post));
}
PHP
			,
			[
				'POSTS' => json_encode( $posts ),
			]
		);
	}
}
