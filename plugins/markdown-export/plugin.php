<?php
/**
 * Plugin Name: Markdown Export
 * Description: Adds the ability to respond with markdown version of posts and pages if the requested path ends with .md
 */

use WordPress\DataLiberation\DataFormatConsumer\BlocksWithMetadata;
use WordPress\Markdown\MarkdownProducer;

if ( file_exists( __DIR__ . '/php-toolkit.phar' ) ) {
	// Production – built and installed plugin
	require_once __DIR__ . '/php-toolkit.phar';
} else {
	// Development – plugin mounted in WordPress via Playground CLI mounts
	require_once __DIR__ . '/../../vendor/autoload.php';
}

/**
 * Handle requests for markdown versions of posts and pages.
 *
 * This function intercepts requests where the URL path ends with `.md`
 * and returns the corresponding post/page content in Markdown format.
 */
add_action(
	'template_redirect',
	function () {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		// Check if the request ends with .md
		if ( ! preg_match( '/\.md$/i', $request_uri ) ) {
			return;
		}

		// Remove the .md extension to get the actual post slug/path
		$path = preg_replace( '/\.md$/i', '', $request_uri );
		$path = strtok( $path, '?' ); // Remove query string if present

		// Try to find the post by path
		$post = markdown_export_get_post_by_path( $path );

		if ( ! $post ) {
			status_header( 404 );
			echo 'Post not found';
			exit;
		}

		// Convert the post content to markdown
		$markdown = markdown_export_convert_post_to_markdown( $post );

		// Set appropriate headers
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( $post->post_name ) . '.md"' );

		echo $markdown;
		exit;
	}
);

/**
 * Get a post by its path/slug.
 *
 * @param string $path The URL path to find the post for.
 * @return WP_Post|null The post object or null if not found.
 */
function markdown_export_get_post_by_path( $path ) {
	// Clean up the path
	$path = trim( $path, '/' );

	if ( empty( $path ) ) {
		return null;
	}

	// Try to get by path as a page (hierarchical)
	$page = get_page_by_path( $path, OBJECT, array( 'page', 'post' ) );
	if ( $page ) {
		return $page;
	}

	// Try to find by slug for non-hierarchical post types
	$posts = get_posts(
		array(
			'name'           => basename( $path ),
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => 1,
		)
	);

	if ( ! empty( $posts ) ) {
		return $posts[0];
	}

	return null;
}

/**
 * Convert a post to Markdown format using MarkdownProducer.
 *
 * @param WP_Post $post The post to convert.
 * @return string The markdown representation of the post.
 */
function markdown_export_convert_post_to_markdown( $post ) {
	// Prepare metadata for the markdown frontmatter
	$metadata = array(
		'post_title' => array( $post->post_title ),
	);

	// Add optional metadata if available
	if ( ! empty( $post->post_date ) ) {
		$metadata['post_date'] = array( $post->post_date );
	}

	if ( ! empty( $post->post_excerpt ) ) {
		$metadata['post_excerpt'] = array( $post->post_excerpt );
	}

	// Create BlocksWithMetadata and convert to markdown
	$blocks_with_metadata = new BlocksWithMetadata(
		$post->post_content,
		$metadata
	);

	$producer = new MarkdownProducer( $blocks_with_metadata );

	return $producer->produce();
}
