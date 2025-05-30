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
		$runtime->evalPhpCodeInSubProcess(
			<<<'PHP'
<?php
require_once getenv('DOCROOT') . '/wp-load.php';
require_once getenv('DOCROOT') . '/wp-admin/includes/admin.php';

kses_remove_filters();
$admin_id = get_users(array('role' => 'Administrator') )[0]->ID;
wp_set_current_user( $admin_id );

wp_set_current_user( $admin_id );
$importer = new WXR_Importer( array(
'fetch_attachments' => true,
// @TODO: Support custom author
'default_author' => $admin_id
) );
$logger = new WP_Importer_Logger_CLI();
$importer->set_logger( $logger );
// Slashes from the imported content are lost if we don't call wp_slash here.
add_action( 'wp_insert_post_data', function( $data ) {
return wp_slash($data);
});

// Ensure that Site Editor templates are associated with the correct taxonomy.
add_filter( 'wp_import_post_terms', function ( $terms, $post_id ) {
foreach ( $terms as $post_term ) {
if ( 'wp_theme' !== $term['taxonomy'] ) {continue;}
$post_term = get_term_by('slug', $term['slug'], $term['taxonomy'] );
if ( ! $post_term ) {
$post_term = wp_insert_term(
$term['slug'],
$term['taxonomy']
);
$term_id = $post_term['term_id'];
} else {
$term_id = $post_term->term_id;
}
wp_set_object_terms( $post_id, $term_id, $term['taxonomy']) ;
}
return $terms;
}, 10, 2 );
$result = $importer->import( getenv('WXR_PATH') );
PHP
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
