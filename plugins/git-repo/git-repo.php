<?php
/**
 * WordPress as a git repository.
 */

require_once __DIR__ . '/../../../wp-load.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use WordPress\DataLiberation\DataFormatConsumer\AnnotatedBlockMarkupConsumer;
use WordPress\DataLiberation\DataFormatConsumer\BlocksWithMetadata;
use WordPress\DataLiberation\DataFormatProducer\AnnotatedBlockMarkupProducer;
use WordPress\Filesystem\Layer\ChrootLayer;
use WordPress\HttpServer\Response\BufferingResponseWriter;
use WordPress\Filesystem\LocalFilesystem;
use WordPress\Filesystem\Visitor\FilesystemVisitor;
use WordPress\Git\GitEndpoint;
use WordPress\Git\GitFilesystem;
use WordPress\Git\GitRepository;

use function WordPress\Filesystem\wp_unix_path_resolve_dots;

$git_repo_path = __DIR__ . '/git-test-server-data';
if ( ! is_dir( $git_repo_path ) ) {
	mkdir( $git_repo_path, 0777, true );
}
$fs         = LocalFilesystem::create( $git_repo_path );
$repository = new GitRepository( $fs );
$git_fs     = GitFilesystem::create( $repository );

$server = new GitEndpoint(
	$repository,
	array(
		'root' => GIT_DIRECTORY_ROOT,
	)
);

$request_bytes = file_get_contents( 'php://input' );
// $response = new StreamingResponseWriter();
$response = new BufferingResponseWriter();

$query_string = $_SERVER['REQUEST_URI'] ?? '';
$request_path = substr( $query_string, strlen( $_SERVER['PHP_SELF'] ) );
if ( '?' === $request_path[0] ) {
	$request_path = substr( $request_path, 1 );
	$request_path = preg_replace( '/&(amp;)?/', '?', $request_path, 1 );
}

// Before handling the request, commit all the pages to the git repo
$synced_post_types = array(
	'page',
	'post',
	'local_file',
);
switch ( $request_path ) {
	// ls refs – protocol discovery
	case '/info/refs?service=git-upload-pack':
		// ls refs or fetch – smart protocol
	case '/git-upload-pack':
		// @TODO: Do streaming and amend the commit every few changes
		// @TODO: Use the streaming exporter instead of the ad-hoc loop below
		$diff = array(
			'updates' => array(),
			'deletes' => array(),
		);
		foreach ( $synced_post_types as $post_type ) {
			$pages = get_posts(
				array(
					'post_type' => $post_type,
					'post_status' => 'publish',
				)
			);
			foreach ( $pages as $page ) {
				$file_path = '/' . ltrim( wp_unix_path_resolve_dots( $post_type . '/' . $page->post_name . '.html' ), '/' );
				$metadata  = array();
				foreach ( array( 'post_date_gmt', 'post_title', 'menu_order' ) as $key ) {
					$metadata[ $key ] = get_post_field( $key, $page->ID );
				}

				$converter    = new AnnotatedBlockMarkupProducer(
					new BlocksWithMetadata( $page->post_content, $metadata )
				);
				$block_markup = $converter->produce();
				if ( ! $block_markup ) {
					throw new Exception( 'Failed to convert the post to HTML' );
				}
				// @TODO: Run the Markdown or block markup exporter
				$diff['updates'][ $file_path ] = $block_markup;
			}
			$visitor = new FilesystemVisitor(
				new ChrootLayer( $git_fs, $post_type )
			);
			while ( $visitor->next() ) {
				$event = $visitor->get_event();
				if ( $event->is_entering() ) {
					foreach ( $event->files as $file_name ) {
						$path = '/' . ltrim( wp_unix_path_resolve_dots( $post_type . '/' . $event->dir . '/' . $file_name ), '/' );
						if ( ! isset( $diff['updates'][ $path ] ) ) {
							$diff['deletes'][] = $path;
						}
					}
				}
			}
		}
		if ( ! $repository->commit( $diff ) ) {
			throw new Exception( 'Failed to commit changes' );
		}
		break;
}

$server->handle_request( $request_path, $request_bytes, $response );

// @TODO: Support the use-case below in the streaming importer
// @TODO: When a page is moved, don't delete the old page and create a new one but
// rather update the existing page.
if ( '/git-receive-pack' === $request_path ) {
	// throw new Exception("test");
	foreach ( $synced_post_types as $post_type ) {
		$updated_ids = array();
		foreach ( $git_fs->ls( $post_type ) as $file_name ) {
			$file_path = $post_type . '/' . $file_name;
			$converter = new AnnotatedBlockMarkupConsumer(
				$git_fs->get_contents( $file_path )
			);
			$result    = $converter->consume();

			$existing_posts = get_posts(
				array(
					'post_type' => $post_type,
					'meta_key' => 'local_file_path',
					'meta_value' => $file_path,
				)
			);

			$filename_without_extension = pathinfo( $file_name, PATHINFO_FILENAME );

			if ( $existing_posts ) {
				$post_id = $existing_posts[0]->ID;
			} else {
				$post_id = wp_insert_post(
					array(
						'post_type' => $post_type,
						'post_status' => 'publish',
						'post_title' => $filename_without_extension,
						'meta_input' => array(
							'local_file_path' => $file_path,
						),
					)
				);
			}
			$updated_ids[] = $post_id;

			$metadata = $result->get_all_metadata( array( 'first_value_only' => true ) );
			$updated  = wp_update_post(
				array(
					'ID' => $post_id,
					'post_name' => $filename_without_extension,
					'post_content' => $result->get_block_markup(),
					'post_title' => $metadata['post_title'] ?? '',
					'post_date_gmt' => $metadata['post_date_gmt'] ?? '',
					'menu_order' => $metadata['menu_order'] ?? '',
					'meta_input' => $metadata,
				)
			);
			if ( is_wp_error( $updated ) ) {
				throw new Exception( 'Failed to update post' );
			}
		}

		// Delete posts that were not updated (i.e. files were deleted)
		$posts_to_delete = get_posts(
			array(
				'post_type' => $post_type,
				'post_status' => 'publish',
				'posts_per_page' => -1,
				'post__not_in' => $updated_ids,
				'fields' => 'ids',
			)
		);

		foreach ( $posts_to_delete as $post_id ) {
			wp_delete_post( $post_id, true );
		}
	}
}
