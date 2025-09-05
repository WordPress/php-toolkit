<?php
/**
 * Plugin Name: Data Liberation – WordPress Static files editor
 * Requires Plugins: data-liberation
 *
 * @TODO: Page metadata editor in Gutenberg
 * @TODO: HTML, XHTML, and Blocks renderers
 * @TODO: Integrity check – is the database still in sync with the files?
 *        If not, what should we do?
 *        * Overwrite the database with the local files? This is a local files editor after all.
 *        * Display a warning in wp-admin and let the user decide what to do?
 * @TODO: Consider tricky scenarios – moving a parent to trash and then restoring it.
 * @TODO: Call resize_to_max_dimensions_if_files_is_an_image for the images dragged directly
 *        into the file picker
 */

use WordPress\DataLiberation\BlockMarkup\BlockMarkupUrlProcessor;
use WordPress\DataLiberation\DataFormatConsumer\AnnotatedBlockMarkupConsumer;
use WordPress\DataLiberation\DataFormatConsumer\BlocksWithMetadata;
use WordPress\DataLiberation\DataFormatProducer\AnnotatedBlockMarkupProducer;
use WordPress\DataLiberation\EntityReader\FilesystemEntityReader;
use WordPress\DataLiberation\Importer\ImportSession;
use WordPress\DataLiberation\Importer\StreamImporter;
use WordPress\DataLiberation\URL\WPURL;
use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Filesystem\LocalFilesystem;
use WordPress\Filesystem\UploadedFilesystem;
use WordPress\Filesystem\Visitor\FilesystemVisitor;
use WordPress\Git\GitFilesystem;
use WordPress\Git\GitRemote;
use WordPress\Git\GitRepository;
use WordPress\Markdown\MarkdownConsumer;
use WordPress\Markdown\MarkdownProducer;
use WordPress\Merge\Diff\MyersDiffer;
use WordPress\Merge\Merge\ChunkMerger;
use WordPress\Merge\MergeStrategy;
use WordPress\Merge\Validate\BlockMarkupMergeValidator;
use WordPress\XML\XMLProcessor;

use function WordPress\DataLiberation\URL\is_child_url_of;
use function WordPress\Filesystem\copy_between_filesystems;
use function WordPress\Filesystem\ls_recursive;
use function WordPress\Filesystem\wp_join_unix_paths;
use function WordPress\Filesystem\wp_unix_path_resolve_dots;

if ( ! defined( 'WP_STATIC_PAGES_DIR' ) ) {
	define( 'WP_STATIC_PAGES_DIR', WP_CONTENT_DIR . '/uploads/my-static-pages' );
}

if ( ! defined( 'WP_STATIC_MEDIA_DIR' ) ) {
	define( 'WP_STATIC_MEDIA_DIR', 'media' );
}

if ( ! defined( 'WP_LOCAL_FILE_POST_TYPE' ) ) {
	define( 'WP_LOCAL_FILE_POST_TYPE', 'local_file' );
}

if ( ! defined( 'WP_AUTOSAVES_DIRECTORY' ) ) {
	define( 'WP_AUTOSAVES_DIRECTORY', '.autosaves' );
}

if ( ! defined( 'WP_STATIC_FILES_EDITOR_IMAGE_MAX_DIMENSION' ) ) {
	define( 'WP_STATIC_FILES_EDITOR_IMAGE_MAX_DIMENSION', 1280 );
}

if ( isset( $_GET['dump'] ) ) {
	add_action(
		'init',
		function () {
			WP_Static_Files_Editor_Plugin::import_static_pages();
		}
	);
}

require_once __DIR__ . '/data-source-page.php';
require_once __DIR__ . '/DataSource.php';

class WP_Static_Files_Editor_Plugin {

	/**
	 * @var DataSource
	 */
	private static $data_source;

	private static $is_running_wp_insert_post_data = false;

	public static function is_data_source_configured() {
		$config = static::get_settings();

		if ( ! isset( $config['dataSourceType'] ) ) {
			return false;
		}

		switch ( $config['dataSourceType'] ) {
			case 'local_directory':
				return ! empty( $config['localDirectory'] );
			case 'git_repo':
				return ! empty( $config['gitRepo'] ) && ! empty( $config['selectedBranch'] );
			case 'github_repository':
				$github_token = get_option( 'msf_github_token', '' );
				return ! empty( $github_token ) && ! empty( $config['gitRepo'] ) && ! empty( $config['selectedBranch'] );
			default:
				return false;
		}
	}

	public static function get_data_source() {
		if ( ! self::$data_source ) {
			if ( ! self::is_data_source_configured() ) {
				throw new RuntimeException( 'No data source configured' );
			}
			$settings = static::get_settings();
			switch ( $settings['dataSourceType'] ) {
				case 'local_directory':
					self::$data_source = new LocalDirectoryDataSource(
						LocalFilesystem::create( $settings['localDirectory'] )
					);
					break;
				case 'git_repo':
					self::$data_source = GitDataSource::create( $settings );
					break;
				case 'github_repository':
					$settings['gitRepo'] = self::get_git_remote_url(
						$settings['gitRepo'],
						array(
							'provider' => 'github',
							'token' => get_option( 'msf_github_token', '' ),
						)
					);
					self::$data_source   = GitDataSource::create( $settings );
					break;
			}

			// Synchronize the data with the remote data source once every 10 minutes
			$last_sync_time = self::get_sync_info()['lastSyncTime'];
			if ( time() - $last_sync_time > 10 * MINUTE_IN_SECONDS ) {
				try {
					self::sync_data_source();
				} catch ( Exception $e ) {
					// Tolerate the failure during the periodic sync. We may just be offline,
					// it's not a big deal – we'll just sync later on when the connection is
					// restored.
				}
			}
		}

		return self::$data_source;
	}

	public static function menu_item_callback() {
		if ( ! self::is_data_source_configured() ) {
			wp_redirect( admin_url( 'admin.php?page=static_files_editor-data-source&error=no_data_source' ) );
			exit( 'Please configure a data source in the settings page before continuing.' );
		}

		// Get first post or create new one
		$posts = get_posts(
			array(
				'post_type'      => WP_LOCAL_FILE_POST_TYPE,
				'posts_per_page' => 2,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		if ( empty( $posts ) ) {
			try {
				self::sync_data_source();
				$data_source = self::get_data_source();
				$fs          = $data_source->get_filesystem();
				foreach ( $fs->ls( '/' ) as $entry ) {
					if ( ! $fs->is_file( $entry ) ) {
						continue;
					}
					$extension = pathinfo( $entry, PATHINFO_EXTENSION );
					if ( ! in_array( $extension, array( 'md', 'html' ) ) ) {
						continue;
					}
					self::get_or_create_post_for_file( $entry );
				}

				$posts = get_posts(
					array(
						'post_type'      => WP_LOCAL_FILE_POST_TYPE,
						'posts_per_page' => 2,
						'orderby'        => 'ID',
						'order'          => 'ASC',
					)
				);
				if ( empty( $posts ) ) {
					wp_redirect( admin_url( 'post-new.php?post_type=' . WP_LOCAL_FILE_POST_TYPE ) );
					exit;
				}
			} catch ( Exception $e ) {
				// There are more ways to get here than just the new Exception above.
				wp_redirect( admin_url( 'admin.php?page=static_files_editor-data-source&error=no_data_source' ) );
				exit( 'Please configure a data source in the settings page before continuing.' );
			}
		}

		// Look for the first post that's not the default "my-first-note.md"
		$post_id = null;
		foreach ( $posts as $post ) {
			$path = get_post_meta( $post->ID, 'local_file_path', true );
			if ( '/my-first-note.md' !== $path ) {
				$post_id = $post->ID;
				break;
			}
		}
		// Fallback to first post if no other found
		if ( null === $post_id ) {
			$post_id = $posts[0]->ID;
		}

		$edit_url = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
		wp_redirect( $edit_url );
		exit;
	}

	public static function initialize() {
		// Register hooks
		register_activation_hook(
			__FILE__,
			function () {
				if ( self::is_data_source_configured() ) {
					self::import_static_pages();
				}
			}
		);
		register_activation_hook(
			__FILE__,
			function () {
				update_option( 'wp_page_for_privacy_policy', 0 );
				update_option( 'show_on_front', 'posts' );
				update_option( 'wp_editor_fullscreen_default', true );
				update_option( 'site_editor_fullscreen_default', true );
			}
		);

		add_action(
			'init',
			function () {
				self::register_post_type();
				// Redirect menu page to custom route
				global $pagenow;
				if ( 'admin.php' === $pagenow && isset( $_GET['page'] ) && 'static_files_editor' === $_GET['page'] ) {
					self::menu_item_callback();
				}
			}
		);

		add_action(
			'wp',
			function () {
				// Redirect homepage to static files editor
				if ( is_home() ) {
					wp_redirect( admin_url( 'admin.php?page=static_files_editor' ) );
					exit;
				}
			}
		);

		add_filter(
			'big_image_size_threshold',
			function ( $threshold ) {
				return WP_STATIC_FILES_EDITOR_IMAGE_MAX_DIMENSION;
			}
		);

		// Handle media uploads
		add_filter(
			'wp_generate_attachment_metadata',
			function ( $metadata, $attachment_id ) {
				try {
					if ( ! self::acquire_synchronization_lock() ) {
						return $metadata;
					}

					// Don't process thumbnails, only original images
					$file_path = wp_get_original_image_path( $attachment_id );
					if ( ! $file_path ) {
						return $metadata;
					}

					// Skip if the file was already processed
					$local_file_path = get_post_meta( $attachment_id, 'local_file_path', true );
					if ( $local_file_path ) {
						return $metadata;
					}

					$file_path = self::resize_to_max_dimensions_if_files_is_an_image( $file_path );

					$local_fs = LocalFilesystem::create( dirname( $file_path ) );

					$file_name   = basename( $file_path );
					$target_path = wp_join_unix_paths( WP_STATIC_MEDIA_DIR, $file_name );

					// Skip if the file was already processed
					$main_fs = self::get_data_source()->get_filesystem();
					if ( $main_fs->is_file( $target_path ) ) {
						return $metadata;
					}

					// Set local_file_path metadata for the attachment
					update_post_meta( $attachment_id, 'local_file_path', $target_path );

					// Copy the file to the static media directory
					copy_between_filesystems(
						array(
							'source_filesystem' => $local_fs,
							'source_path'       => $file_name,
							'target_filesystem' => $main_fs,
							'target_path'       => $target_path,
						)
					);

					return $metadata;
				} finally {
					self::release_synchronization_lock();
				}
			},
			10,
			2
		);

		// Handle attachment updates (e.g. image rotations)
		add_action(
			'wp_update_attachment_metadata',
			function ( $metadata, $attachment_id ) {
				try {
					if ( ! self::acquire_synchronization_lock() ) {
						return $metadata;
					}

					// Don't process thumbnails, only original images
					$file_path = wp_get_original_image_path( $attachment_id );
					if ( ! $file_path ) {
						return $metadata;
					}

					// Skip if the file isn't synchronized with the local filesystem
					$local_file_path = get_post_meta( $attachment_id, 'local_file_path', true );
					if ( ! $local_file_path ) {
						return $metadata;
					}

					$file_path = self::resize_to_max_dimensions_if_files_is_an_image( $file_path );

					$local_fs = LocalFilesystem::create( dirname( $file_path ) );

					$file_name   = basename( $file_path );
					$target_path = wp_join_unix_paths( WP_STATIC_MEDIA_DIR, $file_name );

					// Skip if the file was already processed
					$main_fs = self::get_data_source()->get_filesystem();
					if ( $main_fs->is_file( $target_path ) ) {
						return $metadata;
					}

					// Copy the updated file to the static media directory
					copy_between_filesystems(
						array(
							'source_filesystem' => $local_fs,
							'source_path'       => $file_name,
							'target_filesystem' => $main_fs,
							'target_path'       => $target_path,
						)
					);

					return $metadata;
				} finally {
					self::release_synchronization_lock();
				}
			},
			10,
			2
		);

		// Disable thumbnail generation for local file attachments
		add_filter(
			'intermediate_image_sizes_advanced',
			function ( $sizes, $metadata ) {
				return array();
			},
			10,
			2
		);

		// Rewrite attachment URLs to use the static files download endpoint
		add_filter(
			'wp_get_attachment_url',
			function ( $url, $attachment_id ) {
				$local_file_path = get_post_meta( $attachment_id, 'local_file_path', true );
				if ( $local_file_path ) {
					return rest_url( 'static-files-editor/v1/download-file?path=' . urlencode( $local_file_path ) );
				}

				return $url;
			},
			10,
			2
		);

		add_action(
			'admin_enqueue_scripts',
			function ( $hook ) {
				wp_register_script(
					'static-files-editor',
					plugins_url( 'build/index.js', __FILE__ ),
					array( 'wp-element', 'wp-components', 'wp-block-editor', 'wp-edit-post', 'wp-plugins', 'wp-editor', 'wp-api-fetch' ),
					'1.0.0',
					true
				);

				wp_add_inline_script(
					'static-files-editor',
					'window.WP_LOCAL_FILE_POST_TYPE = ' . json_encode( WP_LOCAL_FILE_POST_TYPE ) . ';',
					'before'
				);

				wp_register_style(
					'static-files-editor',
					plugins_url( 'build/style-index.css', __FILE__ ),
					array( 'wp-components', 'wp-block-editor', 'wp-edit-post' ),
					'1.0.0'
				);

				$screen         = get_current_screen();
				$enqueue_script = $screen && 'post' === $screen->base && WP_LOCAL_FILE_POST_TYPE === $screen->post_type;
				if ( ! $enqueue_script ) {
					return;
				}

				add_filter( 'show_admin_bar', '__return_false' );

				wp_enqueue_script( 'static-files-editor' );
				wp_enqueue_style( 'static-files-editor' );

				// Preload the initial files tree
				wp_add_inline_script(
					'wp-api-fetch',
					'wp.apiFetch.use(wp.apiFetch.createPreloadingMiddleware({
                "/static-files-editor/v1/files?per_page=-1": {
                    body: ' . json_encode( WP_Static_Files_Editor_Plugin::get_files_list_endpoint() ) . ',
                },
                "/static-files-editor/v1/data-source/sync-info": {
                    body: ' . json_encode( WP_Static_Files_Editor_Plugin::get_sync_info() ) . ',
                },
            }));',
					'after'
				);
			}
		);

		add_action(
			'rest_api_init',
			function () {
				register_rest_route(
					'static-files-editor/v1',
					'/data-source/branches',
					array(
						'methods'             => 'POST',
						'callback'            => array( self::class, 'get_git_branches_endpoint' ),
						'permission_callback' => function () {
							return current_user_can( 'edit_posts' );
						},
					)
				);

				register_rest_route(
					'static-files-editor/v1',
					'/data-source/files',
					array(
						'methods'             => 'POST',
						'callback'            => array( self::class, 'get_git_files_endpoint' ),
						'permission_callback' => function () {
							return current_user_can( 'edit_posts' );
						},
					)
				);

				register_rest_route(
					'static-files-editor/v1',
					'/data-source/sync',
					array(
						'methods'             => 'POST',
						'callback'            => array( self::class, 'data_source_sync_endpoint' ),
						'permission_callback' => function () {
							return current_user_can( 'edit_posts' );
						},
					)
				);

				register_rest_route(
					'static-files-editor/v1',
					'/data-source/sync-info',
					array(
						'methods'             => 'GET',
						'callback'            => array( self::class, 'get_sync_info' ),
						'permission_callback' => function () {
							return current_user_can( 'edit_posts' );
						},
					)
				);

				register_rest_route(
					'static-files-editor/v1',
					'/get-or-create-post-for-file',
					array(
						'methods'             => 'POST',
						'callback'            => array( self::class, 'get_or_create_post_for_file_endpoint' ),
						'permission_callback' => function () {
							return current_user_can( 'edit_posts' );
						},
					)
				);

				register_rest_route(
					'static-files-editor/v1',
					'/files',
					array(
						'methods'             => 'GET',
						'callback'            => array( self::class, 'get_files_list_endpoint' ),
						'permission_callback' => function () {
							return current_user_can( 'edit_posts' );
						},
					)
				);

				register_rest_route(
					'static-files-editor/v1',
					'/files/(?P<id>.*)',
					array(
						'methods'             => 'PUT',
						'callback'            => array( self::class, 'update_file_endpoint' ),
						'permission_callback' => function () {
							return current_user_can( 'edit_posts' );
						},
					)
				);

				register_rest_route(
					'static-files-editor/v1',
					'/files/batch',
					array(
						'methods'             => 'POST',
						'callback'            => array( self::class, 'create_files_batch_endpoint' ),
						'permission_callback' => function () {
							return current_user_can( 'edit_posts' );
						},
					)
				);
				register_rest_route(
					'static-files-editor/v1',
					'/files',
					array(
						'methods'             => 'POST',
						'callback'            => array( self::class, 'create_file_endpoint' ),
						'permission_callback' => function () {
							return current_user_can( 'edit_posts' );
						},
					)
				);

				register_rest_route(
					'static-files-editor/v1',
					'/files/(?P<id>.*)',
					array(
						'methods'             => 'DELETE',
						'callback'            => array( self::class, 'delete_file_endpoint' ),
						'permission_callback' => function () {
							return current_user_can( 'edit_posts' );
						},
					)
				);

				register_rest_route(
					'static-files-editor/v1',
					'/download-file',
					array(
						'methods'             => 'GET',
						'callback'            => array( self::class, 'download_file_endpoint' ),
						'permission_callback' => function () {
							// @TODO: Restrict access to this endpoint to editors, but
							// don't require a nonce. Nonces are troublesome for
							// static assets that don't have a dynamic URL.
							// return current_user_can('edit_posts');
							return true;
						},
						'args'                => array(
							'path' => array(
								'required'          => true,
								'type'              => 'string',
								'sanitize_callback' => function ( $param ) {
									return '/' . ltrim( $param, '/' );
								},
							),
						),
					)
				);

				register_rest_route(
					'static-files-editor/v1',
					'/save-settings',
					array(
						'methods'             => 'POST',
						'callback'            => array( self::class, 'save_settings_endpoint' ),
						'permission_callback' => function () {
							return current_user_can( 'manage_options' );
						},
					)
				);

				// Endpoint to get GitHub repositories
				register_rest_route(
					'static-files-editor/v1',
					'/github/repos',
					array(
						'methods'             => 'GET',
						'callback'            => 'WP_Static_Files_Editor_Plugin::get_github_repos_endpoint',
						'permission_callback' => function () {
							return current_user_can( 'edit_posts' );
						},
					)
				);

				// Endpoint to store GitHub token
				register_rest_route(
					'static-files-editor/v1',
					'/github/store-token',
					array(
						'methods'             => 'POST',
						'callback'            => 'WP_Static_Files_Editor_Plugin::store_github_token_endpoint',
						'permission_callback' => function () {
							return current_user_can( 'edit_posts' );
						},
					)
				);

				// Endpoint to clear GitHub token
				register_rest_route(
					'static-files-editor/v1',
					'/github/clear-token',
					array(
						'methods'             => 'POST',
						'callback'            => 'WP_Static_Files_Editor_Plugin::clear_github_token_endpoint',
						'permission_callback' => function () {
							return current_user_can( 'edit_posts' );
						},
					)
				);
			}
		);

		/**
		 * Shorten the autosave interval to 100 days to remove the dichotomy
		 * between posts with edits and autosaves. These are two different
		 * entity types and reconciling changes is much more difficult when
		 * they're both involved. We'll rely on regular saves instead.
		 *
		 * @param array $settings
		 * @return array
		 */
		add_filter(
			'block_editor_settings_all',
			function ( $settings, $context ) {
				if ( isset( $context->post ) && WP_LOCAL_FILE_POST_TYPE === $context->post->post_type ) {
					$settings['autosaveInterval'] = 86400 * 100;
				}
				return $settings;
			},
			10,
			2
		);

		// Add filter for REST API responses
		add_filter(
			'rest_prepare_' . WP_LOCAL_FILE_POST_TYPE,
			function ( $response, $post, $request ) {
				// Short-circuit on non-GET requests to avoid messing with
				// POST requests.
				if ( 'GET' !== $request->get_method() ) {
					return $response;
				}

				$new_content = self::refresh_post_from_local_file( $post );
				if ( ! is_wp_error( $new_content ) ) {
					$response->data['content']['raw']      = $new_content;
					$response->data['content']['rendered'] = '';
				}

				return $response;
			},
			10,
			3
		);

		/**
		 * Merge the proposed post content with the local file content.
		 *
		 * This is used to reconcile changes made in the block editor with
		 * changes made in the local file.
		 */
		add_action(
			'wp_insert_post_data',
			function ( $processed_post, $unprocessed_post, $unsanitized_postarr, $update ) use ( &$is_running_wp_insert_post_data ) {
				/**
				 * Make sure we don't run this action recursively as one of the outcomes
				 * is creating a new post revision.
				 */
				if ( self::$is_running_wp_insert_post_data ) {
					return $processed_post;
				}

				$creating_revision = false;
				if ( 'revision' === $processed_post['post_type'] ) {
					$parent_post = get_post( $processed_post['post_parent'] );
					if ( WP_LOCAL_FILE_POST_TYPE === $parent_post->post_type ) {
						$creating_revision = true;
					}
				}

				$updating_post = WP_LOCAL_FILE_POST_TYPE === $processed_post['post_type'] && $update;
				$should_run    = $updating_post || $creating_revision;
				if ( ! $should_run ) {
					return $processed_post;
				}

				/**
				 * Update the local files if we're using a remote datasource.
				 *
				 * @TODO: Introduce an "eager" mode where this check runs.
				 *        It is expensive and running it every 5 seconds seems excessive –
				 *        How often would another person actually edit the note while we are editing it?
				 *        How often would it matter for us to see their changes live?
				 *        Most of the time we don't care. We could do it during a purposeful collaborative
				 *        editing session, but running it implicitly on the 5-second autosave interval
				 *        is just wasteful
				 */
				// self::get_data_source()->pull_updates();

				$post_id = $creating_revision ? $processed_post['post_parent'] : $unprocessed_post['ID'];

				$is_running_wp_insert_post_data = true;
				try {
					$last_autosave = wp_get_post_autosave( $post_id, get_current_user_id() );
					$db_post       = get_post( $post_id );
					if ( $last_autosave && new DateTime( $last_autosave->post_date ) > new DateTime( $db_post->post_date ) ) {
						$db_post = $last_autosave;
					}
					$path   = get_post_meta( $post_id, 'local_file_path', true );
					$format = pathinfo( $path, PATHINFO_EXTENSION );
					if ( ! $format ) {
						$format = 'html';
					}

					$fs_post = self::local_file_to_post_entity( $path );
					if ( ! $fs_post ) {
						return $processed_post;
					}

					$blocks_with_metadata = self::post_entity_to_blocks_with_metadata( wp_unslash( (array) $unprocessed_post ) );

					/**
					 * Merge if we have a local file and it's different from the database content.
					 */
					$mergable_db_post = self::post_to_mergable_string( (array) $db_post, $format );
					$mergable_fs_post = self::post_to_mergable_string( $fs_post, $format );
					if ( $fs_post['post_content'] && $mergable_fs_post !== $mergable_db_post ) {
						/**
						 * Uh-oh, the database content is different from the local file content!
						 * Let's perform a three-way merge.
						 */
						$merge_strategy = new MergeStrategy(
							new MyersDiffer(),
							new ChunkMerger(),
							new BlockMarkupMergeValidator()
						);

						/**
						 * Three-way merge the post entities in an annotated block markup
						 * format that includes all the relevant metadata.
						 */
						$mergable_unprocessed_post = self::post_to_mergable_string( wp_unslash( (array) $unprocessed_post ), $format );
						$merge_result              = $merge_strategy->merge(
							$mergable_db_post,
							$mergable_unprocessed_post,
							$mergable_fs_post,
						);

						if ( $merge_result->has_conflicts() ) {
							// @TODO: Log all the the merge conflicts for inspection later.

							/**
							 * We could not resolve the conflicts.
							 *
							 * Let's overwrite the post content with the block editor contents and
							 * ignore the local file content.
							 *
							 * However, to avoid data loss, let's create a post revision with the
							 * filesystem content. It can be recovered as long as the app is running.
							 *
							 * @TODO: Add a standardized "overwrite_on_conflict" mechanics with
							 *        custom logic for each data source. In git, it would be a commit.
							 *        In a local filesystem, it would be a file in the "conflicts" directory.
							 */
							$revision_data = array(
								'post_content' => $fs_post['post_content'],
								'post_title'   => $fs_post['post_title'],
								'post_parent'  => $post_id,
								'post_type'    => 'revision',
								'post_status'  => 'inherit',
								'post_author'  => get_current_user_id(),
								// @TODO: Also store $fs_post metadata in the revision.
							);
							wp_insert_post( $revision_data );
						} else {
							$blocks_with_metadata = self::annotated_block_markup_to_blocks_with_metadata( $merge_result->get_merged_content() );
							$delta_post           = array_merge(
								array( 'post_content' => $blocks_with_metadata->get_block_markup() ),
								$blocks_with_metadata->get_all_metadata( array( 'first_value_only' => true ) ),
							);
							/**
							 * The merge was successful.
							 *
							 * Let's store the merged content in the database (and use it in the REST
							 * API response so the client can live update the post content).
							 *
							 * @TODO: A generic way of merging post entities. This one is naive and won't
							 *        remove fields $processed_post that were deleted in $merged_post_entity.
							 */
							$processed_post   = array_merge( $processed_post, wp_slash( $delta_post ) );
							$unprocessed_post = array_merge( $unprocessed_post, $delta_post );
						}
					}

					$new_static_file_content = self::convert_post_data_to_string(
						$blocks_with_metadata,
						$format
					);

					$fs = self::get_data_source()->get_filesystem();
					$fs->put_contents(
						$path,
						$new_static_file_content,
						array(
							'message' => 'User saved ' . basename( $path ),
							'amend' => $creating_revision,
						)
					);
					return $processed_post;
				} finally {
					self::$is_running_wp_insert_post_data = false;
				}
			},
			10,
			4
		);
	}

	private static function post_entity_to_blocks_with_metadata( $post_entity ) {
		return new BlocksWithMetadata(
			$post_entity['post_content'],
			array(
				'post_title' => array( $post_entity['post_title'] ),
				// 'post_date_gmt' => array( $post_entity['post_date_gmt'] ),
				'menu_order' => array( $post_entity['menu_order'] ),
			)
		);
	}

	private static function annotated_block_markup_to_blocks_with_metadata( $annotated_block_markup ) {
		$consumer = new AnnotatedBlockMarkupConsumer( $annotated_block_markup );
		return $consumer->consume();
	}

	/**
	 * Resize image to a maximum width and height.
	 *
	 * @param  string $image_path  The path to the image file
	 *
	 * @return string The path to the resized image file
	 */
	public static function resize_to_max_dimensions_if_files_is_an_image( $image_path ) {
		// Only resize if this is an image file
		// getimagesize() returns false for non-images (and
		// also image formats it can't handle)
		$image_size = @getimagesize( $image_path );
		if ( false === $image_size ) {
			return $image_path;
		}

		if ( $image_size[0] > WP_STATIC_FILES_EDITOR_IMAGE_MAX_DIMENSION || $image_size[1] > WP_STATIC_FILES_EDITOR_IMAGE_MAX_DIMENSION ) {
			$editor = wp_get_image_editor( $image_path );
			if ( is_wp_error( $editor ) ) {
				return $image_path;
			}

			$editor->resize( WP_STATIC_FILES_EDITOR_IMAGE_MAX_DIMENSION, WP_STATIC_FILES_EDITOR_IMAGE_MAX_DIMENSION, false );

			// Rotate the image if needed
			if ( function_exists( 'exif_read_data' ) ) {
				$exif = @exif_read_data( $image_path );
				if ( $exif && isset( $exif['Orientation'] ) ) {
					$editor->rotate( exif_imagetype( $image_path ) );
				}
			}

			// Try saving to original path first
			$result = $editor->save( $image_path );

			// If saving fails (read-only), save to temp file
			if ( is_wp_error( $result ) ) {
				$temp_path = wp_tempnam( basename( $image_path ) );
				$result    = $editor->save( $temp_path );
				if ( is_wp_error( $result ) ) {
					return $image_path;
				}
				$image_path = $temp_path;
			}
		}

		return $image_path;
	}

	public static function download_file_endpoint( $request ) {
		$path = '/' . ltrim( wp_unix_path_resolve_dots( $request->get_param( 'path' ) ), '/' );
		$fs   = self::get_data_source()->get_filesystem();

		if ( $fs->is_dir( $path ) ) {
			return new WP_Error( 'file_error', 'Directory download is not supported yet.' );
		}

		// Get file info
		$filename = basename( $path );
		$object   = $fs->open_read_stream( $path );

		// Set headers for file download
		header( 'Content-Type: application/octet-stream' );
		header( "Content-Disposition: attachment; filename=UTF-8''" . urlencode( $filename ) );
		header( 'Content-Length: ' . $object->length() );
		header( 'Cache-Control: no-cache' );

		while ( ! $object->reached_end_of_data() ) {
			$bytes_available = $object->pull( 8192 );
			echo $object->consume( $bytes_available );
		}
		die();
	}

	private static $synchronizing = 0;

	private static function acquire_synchronization_lock() {
		// Skip if in maintenance mode
		if ( wp_is_maintenance_mode() ) {
			return false;
		}

		if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {
			return false;
		}

		if ( ! self::is_data_source_configured() ) {
			return false;
		}

		// @TODO: Synchronize between threads
		if ( self::$synchronizing > 0 ) {
			return false;
		}
		++ self::$synchronizing;

		return true;
	}

	private static function release_synchronization_lock() {
		self::$synchronizing = max( 0, self::$synchronizing - 1 );
	}

	private static function refresh_post_from_local_file( $post ) {
		try {
			if ( ! self::acquire_synchronization_lock() ) {
				return false;
			}

			$path = get_post_meta( $post->ID, 'local_file_path', true );
			if ( ! $path ) {
				return false;
			}
			$entity = self::local_file_to_post_entity( $path );
			if ( ! $entity ) {
				return false;
			}
			if (
				$entity['post_content'] === $post->post_content
				&& $entity['post_title'] === $post->post_title
				&& $entity['menu_order'] === $post->menu_order
			) {
				return $post->post_content;
			}

			// Avoid triggering three-way merge when overriding
			// the database post content with the local file content.
			self::$is_running_wp_insert_post_data = true;
			try {
				$updated = wp_update_post(
					array_merge(
						array(
							'ID' => $post->ID,
						),
						$entity,
					)
				);
			} finally {
				self::$is_running_wp_insert_post_data = false;
			}
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}

			return $entity['post_content'];
		} finally {
			self::release_synchronization_lock();
		}
	}

	/**
	 * @TODO: Use an importer for that instead of hardcoding the logic here.
	 */
	private static function local_file_to_post_entity( $path ) {
		$fs = self::get_data_source()->get_filesystem();
		if ( ! $fs->is_file( $path ) ) {
			// @TODO: Log the error outside of this method.
			// This happens naturally when the underlying file is deleted.
			// It's annoying to keep seeing this error when developing
			// the plugin so I'm commenting it out.
			//
			// Really, this may not even be an error. The caller must
			// decide whether to log the error or handle the failure
			// gracefully.
			//
			// This method only needs to bubble the error information up,
			// e.g. by throwing, returning WP_Error, or setting self::$last_error.
			return false;
		}
		$content = $fs->get_contents( $path );
		if ( ! is_string( $content ) ) {
			// @TODO: Ditto the previous comment.
			return false;
		}
		$extension = pathinfo( $path, PATHINFO_EXTENSION );
		$converter = self::parse_local_file( $content, $extension );
		if ( ! $converter ) {
			return false;
		}

		$new_content = self::wordpressify_static_assets_urls(
			$converter->get_block_markup()
		);
		return array(
			'post_content'  => $new_content,
			'post_title'    => $converter->get_first_meta_value( 'post_title' ) ?? '',
			// 'post_date_gmt' => $converter->get_first_meta_value( 'post_date_gmt' ) ?? '',
			'menu_order'    => $converter->get_first_meta_value( 'menu_order' ) ?? '',
			// 'meta_input' => $converter->get_all_metadata(),
		);
	}

	private static function parse_local_file( $content, $format ) {
		switch ( $format ) {
			case 'xhtml':
				$converter = new AnnotatedBlockMarkupConsumer(
					XMLProcessor::create_from_string( $content )
				);
				break;
			case 'html':
				$converter = new AnnotatedBlockMarkupConsumer(
					WP_HTML_Processor::create_fragment( $content )
				);
				break;
			case 'md':
				$converter = new MarkdownConsumer( $content );
				break;
			default:
				return false;
		}

		return $converter->consume();
	}

	private static function post_to_mergable_string( $post, $format ) {
		if ( 'html' === $format ) {
			return trim( $post['post_content'], "\n " );
		}
		$blocks_with_metadata = self::post_entity_to_blocks_with_metadata( $post );
		$encoded_as_format    = self::convert_post_data_to_string( $blocks_with_metadata, $format );
		$reencoded_as_blocks  = self::parse_local_file( $encoded_as_format, $format );
		$producer             = new AnnotatedBlockMarkupProducer( $reencoded_as_blocks );
		$result               = trim( $producer->produce(), "\n " );
		return $result;
	}

	private static function convert_post_data_to_string( BlocksWithMetadata $blocks_with_metadata, $format ) {
		$blocks_with_metadata = new BlocksWithMetadata(
			self::unwordpressify_static_assets_urls(
				$blocks_with_metadata->get_block_markup()
			),
			$blocks_with_metadata->get_all_metadata()
		);

		switch ( $format ) {
			case 'md':
				$producer = new MarkdownProducer( $blocks_with_metadata );
				break;
			case 'xhtml':
				// @TODO: Add proper support for XHTML – perhaps via the serialize() method?
				throw new Exception( 'Serializing to XHTML is not supported yet' );
			case 'html':
			default:
				$producer = new AnnotatedBlockMarkupProducer( $blocks_with_metadata );
				break;
		}

		return $producer->produce();
	}

	/**
	 * Convert references to files served via download_file_endpoint
	 * to an absolute path referring to the corresponding static files
	 * in the local filesystem.
	 */
	private static function unwordpressify_static_assets_urls( $content ) {
		$site_url_raw           = rtrim( get_site_url(), '/' ) . '/';
		$site_url               = WPURL::parse( $site_url_raw );
		$expected_endpoint_path = '/wp-json/static-files-editor/v1/download-file';
		$p                      = new BlockMarkupUrlProcessor( $content, $site_url );
		while ( $p->next_url() ) {
			$url = $p->get_parsed_url();
			if ( ! is_child_url_of( $url, $site_url_raw ) ) {
				continue;
			}

			// Account for sites with no nice permalink structure
			if ( $url->searchParams->has( 'rest_route' ) ) {
				$url = WPURL::parse( $url->searchParams->get( 'rest_route' ), $site_url );
			}

			// Naively check for the endpoint that serves the file.
			// WordPress can use a custom REST API prefix which this
			// check doesn't account for. It assumes the endpoint path
			// is unique enough to not conflict with other paths.
			//
			// It may need to be revisited if any conflicts arise in
			// the future.
			if ( ! str_ends_with( $url->pathname, $expected_endpoint_path ) ) {
				continue;
			}

			// At this point we're certain the URL intends to download
			// a static file managed by this plugin.

			// Let's replace the URL in the content with the relative URL.
			$original_url = $url->searchParams->get( 'path' );
			$p->set_url( $original_url, $url );
		}

		return $p->get_updated_html();
	}

	/**
	 * Convert references to files served via path to the
	 * corresponding download_file_endpoint references.
	 *
	 * @TODO: Plug in the attachment IDs into image blocks
	 */
	private static function wordpressify_static_assets_urls( $content ) {
		$parsed_site_url        = WPURL::parse( rtrim( get_site_url(), '/' ) . '/' );
		$expected_endpoint_path = wp_join_unix_paths(
			$parsed_site_url->pathname,
			'wp-json/static-files-editor/v1/download-file'
		);
		$p                      = new BlockMarkupUrlProcessor( $content, $parsed_site_url );
		while ( $p->next_url() ) {
			$url = $p->get_parsed_url();
			if ( ! is_child_url_of( $url, $parsed_site_url ) ) {
				continue;
			}

			// @TODO: Also work with <a> tags, account
			// for .md and directory links etc.
			if ( 'IMG' !== $p->get_tag() ) {
				continue;
			}

			$new_url           = WPURL::parse( $url->pathname, $parsed_site_url );
			$new_url->pathname = $expected_endpoint_path;
			$new_url->searchParams->set( 'path', $p->get_raw_url() );
			$p->set_url( $new_url->__toString(), $new_url );
		}

		return $p->get_updated_html();
	}

	public static function get_local_files_list( $subdirectory = '' ) {
		$list = array();
		if ( ! self::is_data_source_configured() ) {
			return $list;
		}
		// Get all file paths and post IDs in one query
		$file_posts = get_posts(
			array(
				'post_type'      => WP_LOCAL_FILE_POST_TYPE,
				'meta_key'       => 'local_file_path',
				'posts_per_page' => - 1,
				'fields'         => 'id=>meta',
			)
		);

		$path_to_post = array();
		foreach ( $file_posts as $post ) {
			$file_path = get_post_meta( $post->ID, 'local_file_path', true );
			if ( $file_path ) {
				$path_to_post[ $file_path ] = $post;
			}
		}

		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'posts_per_page' => - 1,
				'meta_key'       => 'local_file_path',
			)
		);
		foreach ( $attachments as $attachment ) {
			$attachment_path = get_post_meta( $attachment->ID, 'local_file_path', true );
			if ( $attachment_path ) {
				$path_to_post[ $attachment_path ] = $attachment;
			}
		}

		$base_dir = $subdirectory ? $subdirectory : '/';
		$fs       = self::get_data_source()->get_filesystem();
		self::build_local_file_list( $fs, $base_dir, $list, $path_to_post );

		$keyed_list = array();
		foreach ( $list as $item ) {
			$item['id'] = $item['path'];

			$keyed_list[ $item['path'] ] = $item;
		}

		return $keyed_list;
	}

	private static function build_local_file_list( $fs, $dir, &$list, $path_to_post ) {
		$items = $fs->ls( $dir );
		if ( false === $items ) {
			return;
		}

		foreach ( $items as $item ) {
			// Exclude the autosaves directory from the files tree
			if ( '/' === $dir && WP_AUTOSAVES_DIRECTORY === $item ) {
				continue;
			}
			// Exclude the .gitkeep file from the files tree.
			// WP_Git_Filesystem::mkdir() creates an empty .gitkeep file in each created
			// directory since Git doesn't support empty directories.
			if ( '.gitkeep' === $item ) {
				continue;
			}

			$path = '/' === $dir ? "/$item" : "$dir/$item";

			if ( $fs->is_dir( $path ) ) {
				$node   = array(
					'type'     => 'directory',
					'path'     => $path,
					'id'       => $path,
					'children' => array(),
				);
				$list[] = $node;

				// Recursively build children
				self::build_local_file_list( $fs, $path, $list, $path_to_post );
			} else {
				$node = array(
					'type' => 'file',
					'path' => $path,
					'id'   => $path,
				);

				if ( isset( $path_to_post[ $path ] ) ) {
					$node['post_id']    = $path_to_post[ $path ]->ID;
					$node['post_type']  = $path_to_post[ $path ]->post_type;
					$node['post_title'] = $path_to_post[ $path ]->post_title;
				}

				$list[] = $node;
			}
		}
	}

	/**
	 * Import static pages from a disk, if one exists.
	 *
	 * @TODO: Error handling
	 */
	public static function import_static_pages() {
		if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {
			return;
		}
		define( 'WP_IMPORTING', true );

		// Make sure the post type is registered even if we're
		// running before the init hook.
		self::register_post_type();

		// Prevent ID conflicts
		self::reset_db_data();

		if ( ! self::is_data_source_configured() ) {
			return;
		}

		return self::do_import_static_pages(
			array(
				'from_filesystem' => self::get_data_source()->get_filesystem(),
			)
		);
	}

	private static function do_import_static_pages( $options = array() ) {
		$fs       = $options['from_filesystem'];
		$importer = StreamImporter::create(
			function () use ( $fs, $options ) {
				return new FilesystemEntityReader(
					$fs,
					array(
						'post_type'         => WP_LOCAL_FILE_POST_TYPE,
						'post_tree_options' => $options['post_tree_options'] ?? array(),
					)
				);
			},
			array(
				'attachment_downloader_options' => array(
					'source_from_filesystem' => $fs,
				),
			)
		);

		$import_session = ImportSession::create(
			array(
				'data_source' => 'static_pages',
			)
		);

		return data_liberation_import_step( $import_session, $importer );
	}

	private static function register_post_type() {
		register_post_type(
			WP_LOCAL_FILE_POST_TYPE,
			array(
				'labels'       => array(
					'name'               => 'Local Files',
					'singular_name'      => 'Local File',
					'add_new'            => 'Add New',
					'add_new_item'       => 'Add New Local File',
					'edit_item'          => 'Edit Local File',
					'new_item'           => 'New Local File',
					'view_item'          => 'View Local File',
					'search_items'       => 'Search Local Files',
					'not_found'          => 'No local files found',
					'not_found_in_trash' => 'No local files found in Trash',
				),
				'public'       => true,
				'show_ui'      => true,
				'show_in_menu' => false,
				'hierarchical' => true,
				'supports'     => array(
					'title',
					'editor',
					'page-attributes',
					'revisions',
					'custom-fields',
				),
				'has_archive'  => false,
				'show_in_rest' => true,
			)
		);

		// Register the meta field for file paths
		register_post_meta(
			WP_LOCAL_FILE_POST_TYPE,
			'local_file_path',
			array(
				'type'         => 'string',
				'description'  => 'Path to the local file',
				'single'       => true,
				'show_in_rest' => true,
			)
		);
	}

	/**
	 * Resets the database to a clean state.
	 *
	 * @TODO: Make it work with MySQL, right now it uses SQLite-specific code.
	 */
	private static function reset_db_data() {
		$GLOBALS['@pdo']->query( 'DELETE FROM wp_posts WHERE id > 0' );
		$GLOBALS['@pdo']->query( "UPDATE SQLITE_SEQUENCE SET SEQ=0 WHERE NAME='wp_posts'" );

		$GLOBALS['@pdo']->query( 'DELETE FROM wp_postmeta WHERE post_id > 1' );
		$GLOBALS['@pdo']->query( "UPDATE SQLITE_SEQUENCE SET SEQ=20 WHERE NAME='wp_postmeta'" );

		$GLOBALS['@pdo']->query( 'DELETE FROM wp_comments' );
		$GLOBALS['@pdo']->query( "UPDATE SQLITE_SEQUENCE SET SEQ=0 WHERE NAME='wp_comments'" );

		$GLOBALS['@pdo']->query( 'DELETE FROM wp_commentmeta' );
		$GLOBALS['@pdo']->query( "UPDATE SQLITE_SEQUENCE SET SEQ=0 WHERE NAME='wp_commentmeta'" );
	}

	public static function get_or_create_post_for_file_endpoint( $request ) {
		try {
			if ( ! self::acquire_synchronization_lock() ) {
				return new WP_Error( 'synchronization_lock_failed', 'Failed to acquire synchronization lock' );
			}

			$file_path   = '/' . ltrim( wp_unix_path_resolve_dots( $request->get_param( 'path' ) ), '/' );
			$create_file = $request->get_param( 'create_file' );

			if ( ! $file_path ) {
				return new WP_Error( 'missing_path', 'File path is required' );
			}

			$post = self::get_or_create_post_for_file( $file_path, $create_file );

			if ( is_wp_error( $post ) ) {
				return $post;
			}
		} finally {
			self::release_synchronization_lock();
		}

		$refreshed_post = self::refresh_post_from_local_file( $post );
		if ( is_wp_error( $refreshed_post ) ) {
			return $refreshed_post;
		}

		return array(
			'post_id' => $post->ID,
		);
	}

	private static function get_or_create_post_for_file( $file_path, $create_file = false ) {
		$fs = self::get_data_source()->get_filesystem();
		if ( $create_file && ! $fs->is_file( $file_path ) ) {
			$fs->put_contents( $file_path, '' );
		}
		$existing_posts = get_posts(
			array(
				'post_type'      => WP_LOCAL_FILE_POST_TYPE,
				'meta_key'       => 'local_file_path',
				'meta_value'     => $file_path,
				'posts_per_page' => 1,
			)
		);
		if ( ! empty( $existing_posts ) ) {
			return $existing_posts[0];
		}
		$post_data = array(
			'post_title'   => basename( $file_path ),
			'post_type'    => WP_LOCAL_FILE_POST_TYPE,
			'post_status'  => 'publish',
			'post_content' => '',
		);
		$post_id   = wp_insert_post( $post_data );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		if ( false === update_post_meta( $post_id, 'local_file_path', $file_path ) ) {
			return new WP_Error( 'failed_to_update_local_file_path', 'Failed to update local file path' );
		}

		return get_post( $post_id );
	}

	public static function get_files_list_endpoint() {
		return self::get_local_files_list();
	}

	public static function create_file_endpoint( $request ) {
		try {
			if ( ! self::acquire_synchronization_lock() ) {
				return;
			}
			$path    = '/' . ltrim( wp_unix_path_resolve_dots( $request->get_param( 'path' ) ), '/' );
			$content = $request->get_param( 'content' );
			$fs      = self::get_data_source()->get_filesystem();
			if ( ! $fs->is_dir( dirname( $path ) ) ) {
				$fs->mkdir( dirname( $path ), array( 'recursive' => true ) );
			}
			$fs->put_contents( $path, $content );
			$post = self::get_or_create_post_for_file( $path, false );

			return array(
				'id' => $path,
				'post_id' => $post->ID,
				'path' => $path,
				'post_title' => $post->post_title,
			);
		} finally {
			self::release_synchronization_lock();
		}
	}

	public static function update_file_endpoint( $request ) {
		try {
			if ( ! self::acquire_synchronization_lock() ) {
				return;
			}
			// Need to rawurldecode() because PHP only decodes query parameters automatically.
			// Paths need manual treatment.
			$from_path = '/' . ltrim( wp_unix_path_resolve_dots( rawurldecode( $request->get_param( 'id' ) ) ), '/' );
			$from_path = rtrim( $from_path, '/' );

			$to_path = $request->get_param( 'path' );
			$to_path = rtrim( $to_path, '/' );

			if ( ! $from_path || ! $to_path ) {
				return new WP_Error( 'missing_path', 'Both source and target paths are required' );
			}

			$fs = self::get_data_source()->get_filesystem();
			if ( $fs->is_file( $from_path ) ) {
				// Find and update associated post
				$previous_content = $fs->get_contents( $from_path );
				$existing_posts   = get_posts(
					array(
						'post_type'      => WP_LOCAL_FILE_POST_TYPE,
						'meta_key'       => 'local_file_path',
						'meta_value'     => $from_path,
						'posts_per_page' => 1,
					)
				);
				$existing_post    = count( $existing_posts ) > 0 ? $existing_posts[0] : null;

				$moved = false;

				if ( $existing_post ) {
					// Regenerate the content from scratch if we're changing the file format.
					$previous_extension = pathinfo( $from_path, PATHINFO_EXTENSION );
					$new_extension      = pathinfo( $to_path, PATHINFO_EXTENSION );
					if ( WP_LOCAL_FILE_POST_TYPE === $existing_post->post_type && $previous_extension !== $new_extension ) {
						$parsed      = self::parse_local_file(
							$previous_content,
							$previous_extension
						);
						$new_content = self::convert_post_data_to_string(
							$parsed,
							$new_extension
						);
						$fs->put_contents(
							$to_path,
							$new_content
						);
						$fs->rm( $from_path );
						$moved = true;
					}

					// Update the local file path
					if ( false === update_post_meta( $existing_post->ID, 'local_file_path', $to_path ) ) {
						throw new Exception( 'Failed to update local file path' );
					}
				}
			} elseif ( $fs->is_dir( $from_path ) ) {
				// Update the local file paths for all posts within the directory
				$nested_posts = get_posts(
					array(
						'post_type'      => WP_LOCAL_FILE_POST_TYPE,
						'meta_query'     => array(
							array(
								'key'     => 'local_file_path',
								'value'   => $from_path . '%',
								'compare' => 'LIKE',
							),
						),
						'posts_per_page' => - 1,
					)
				);

				foreach ( $nested_posts as $existing_post ) {
					$current_path = get_post_meta( $existing_post->ID, 'local_file_path', true );
					$new_path     = $to_path . substr( $current_path, strlen( $from_path ) );

					if ( false === update_post_meta( $existing_post->ID, 'local_file_path', $new_path ) ) {
						throw new Exception( 'Failed to update local file path for post ID ' . $existing_post->ID );
					}
				}
			}

			if ( ! $moved ) {
				$fs->rename( $from_path, $to_path );
			}

			return array( 'success' => true );
		} finally {
			self::release_synchronization_lock();
		}
	}

	/**
	 * Imports files from the HTTP request into WordPress.
	 *
	 * This method:
	 * * Creates the uploaded files in the filesystem managed by this plugin.
	 * * Imports the uploaded files into WordPress as posts and attachments.
	 *
	 * @TODO: Rethink the attachments handling. Right now, we're creating two copies
	 *        of each static asset. One in the managed filesystem (which could be a Git repo)
	 *        and one in the WordPress uploads directory. Perhaps this is the way to go,
	 *        but let's have a discussion about it.
	 */
	public static function create_files_batch_endpoint( $request ) {
		try {
			if ( ! self::acquire_synchronization_lock() ) {
				return;
			}
			$uploaded_fs = UploadedFilesystem::create( $request, 'content' );

			// Copy the uploaded files to the main filesystem
			$main_fs       = self::get_data_source()->get_filesystem();
			$create_in_dir = '/' . ltrim( wp_unix_path_resolve_dots( $request->get_param( 'path' ) ), '/' );
			copy_between_filesystems(
				array(
					'source_filesystem' => $uploaded_fs,
					'source_path'       => '/',
					'target_filesystem' => $main_fs,
					'target_path'       => $create_in_dir,
				)
			);

			// Import the uploaded files into WordPress
			$parent_id = null;
			if ( $create_in_dir ) {
				$parent_post = get_posts(
					array(
						'post_type'      => WP_LOCAL_FILE_POST_TYPE,
						'meta_key'       => 'local_file_path',
						'meta_value'     => $create_in_dir,
						'posts_per_page' => 1,
					)
				);
				if ( ! empty( $parent_post ) ) {
					$parent_id = $parent_post[0]->ID;
				}
			}

			$result = self::do_import_static_pages(
				array(
					'from_filesystem'   => $uploaded_fs,
					'post_tree_options' => array(
						'root_parent_id'     => $parent_id,
						'create_index_pages' => false,
					),
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			/**
			 * @TODO: A method such as $import_session->get_imported_entities()
			 *        that iterates over the imported entities would be highly
			 *        useful here. We don't have one, so we need the clunky
			 *        inference below to get the imported posts.
			 */
			$created_files = array();
			$visitor       = new FilesystemVisitor( $uploaded_fs );
			while ( $visitor->next() ) {
				$event = $visitor->get_event();
				if ( ! $event->is_entering() ) {
					continue;
				}
				$paths = $event->files;
				if ( 1 === $visitor->get_current_depth() ) {
					// Make sure we save the top-level directories
					$paths = array_merge( array( $event->dir ), $event->files );
				}
				foreach ( $paths as $path ) {
					$type         = $uploaded_fs->is_dir( $path ) ? 'directory' : 'file';
					$post_id      = null;
					$created_path = wp_join_unix_paths( $create_in_dir, $path );
					if ( 'post' === $type ) {
						$created_post = get_posts(
							array(
								'post_type'      => WP_LOCAL_FILE_POST_TYPE,
								'meta_key'       => 'local_file_path',
								'meta_value'     => $created_path,
								'posts_per_page' => 1,
							)
						);
						$post_id      = $created_post ? $created_post[0]->ID : null;
					}
					$created_files[] = array(
						'path'    => $created_path,
						'post_id' => $post_id,
						'type'    => $type,
					);
				}
			}

			return array(
				'created_files' => $created_files,
			);
		} finally {
			self::release_synchronization_lock();
		}
	}

	public static function get_git_branches( $git_repo_url ) {
		$repo = new GitRepository( InMemoryFilesystem::create() );
		$repo->add_remote( 'origin', $git_repo_url );
		$remote   = new GitRemote( $repo, 'origin' );
		$refs     = $remote->ls_refs( 'refs/heads/' );
		$head_ref = $remote->ls_refs( 'HEAD' );

		$by_hash                  = array_flip( $refs );
		$default_branch_full_name = $by_hash[ $head_ref['HEAD'] ];
		unset( $refs[ $default_branch_full_name ] );

		$default_branch_nice_name = substr( $default_branch_full_name, strlen( 'refs/heads/' ) ) . ' (default)';
		$refs_names               = array(
			array(
				'fullName' => $default_branch_full_name,
				'niceName' => $default_branch_nice_name,
			),
		);
		foreach ( $refs as $ref => $hash ) {
			$refs_names[] = array(
				'fullName' => $ref,
				'niceName' => substr( $ref, strlen( 'refs/heads/' ) ),
			);
		}

		return array(
			'default_branch' => $default_branch_full_name,
			'refs' => $refs_names,
		);
	}

	public static function get_git_branches_endpoint( $request ) {
		$git_repo_string = $request->get_param( 'gitRepo' );
		$provider        = $request->get_param( 'provider' );
		$git_repo_url    = self::get_git_remote_url( $git_repo_string, array( 'provider' => $provider ) );
		return self::get_git_branches( $git_repo_url );
	}

	public static function get_git_files_endpoint( $request ) {
		$repo = new GitRepository( InMemoryFilesystem::create() );

		$git_repo_url = $request->get_param( 'gitRepo' );
		$provider     = $request->get_param( 'provider' );
		$repo->add_remote( 'origin', self::get_git_remote_url( $git_repo_url, array( 'provider' => $provider ) ) );
		$remote = new GitRemote( $repo, 'origin' );

		$refs = $remote->ls_refs( 'refs/heads/' );

		$branch = $request->get_param( 'branch' );
		if ( ! isset( $refs[ $branch ] ) ) {
			return new WP_Error( 'branch_not_found', 'Branch "' . $branch . '" not found' );
		}
		$branch_tip = $refs[ $branch ];

		$objects_index = $remote->list_objects( $branch_tip );
		$fs            = GitFilesystem::create( $objects_index );

		return array( 'files' => ls_recursive( $fs ) );
	}

	public static function get_git_remote_url( $git_repo_url, $options = array() ) {
		switch ( $options['provider'] ) {
			case 'github':
				$url           = WPURL::parse( $git_repo_url );
				$url->username = get_option( 'msf_github_token', '' );
				$url           = $url->toString();
				break;
			case 'git':
			default:
				$url = $git_repo_url;
				break;
		}
		if ( ! str_ends_with( $url, '.git' ) ) {
			$url .= '.git';
		}
		return $url;
	}

	public static function delete_file_endpoint( $request ) {
		// Need to rawurldecode() because PHP only decodes query parameters automatically.
		// Paths need manual treatment.
		$path = '/' . ltrim( wp_unix_path_resolve_dots( rawurldecode( $request->get_param( 'id' ) ) ), '/' );
		if ( ! $path ) {
			return new WP_Error( 'missing_path', 'File path is required' );
		}

		try {
			if ( ! self::acquire_synchronization_lock() ) {
				return new WP_Error( 'synchronization_lock_failed', 'Failed to acquire synchronization lock' );
			}
			// Find and delete associated post
			$existing_posts = get_posts(
				array(
					'post_type'      => WP_LOCAL_FILE_POST_TYPE,
					'meta_key'       => 'local_file_path',
					'meta_value'     => $path,
					'posts_per_page' => 1,
				)
			);

			if ( ! empty( $existing_posts ) ) {
				wp_delete_post( $existing_posts[0]->ID, true );
			}

			// Delete the actual file
			$fs = self::get_data_source()->get_filesystem();
			if ( $fs->is_dir( $path ) ) {
				if ( ! $fs->rmdir( $path, array( 'recursive' => true ) ) ) {
					return new WP_Error( 'delete_failed', 'Failed to delete directory' );
				}
			} elseif ( ! $fs->rm( $path ) ) {
				return new WP_Error( 'delete_failed', 'Failed to delete file' );
			}

			return array( 'success' => true );
		} finally {
			self::release_synchronization_lock();
		}
	}

	public static function save_settings_endpoint( WP_REST_Request $request ) {
		$settings = array(
			'dataSourceType' => $request->get_param( 'dataSourceType' ),
			'gitRepo' => $request->get_param( 'gitRepo' ),
			'selectedBranch' => $request->get_param( 'selectedBranch' ),
			'subdirectory' => $request->get_param( 'subdirectory' ),
			'localDirectory' => $request->get_param( 'localDirectory' ),
			'selectedRepo' => $request->get_param( 'selectedRepo' ),
			'gitUserName' => 'WordPress',
			'gitUserEmail' => 'wordpress@example.com',
			'.gitPath' => WP_STATIC_PAGES_DIR,
			'remoteName' => 'origin',
		);

		update_option( 'static_files_editor_settings', $settings );

		return array( 'success' => true );
	}

	public static function data_source_sync_endpoint() {
		try {
			if ( ! self::acquire_synchronization_lock() ) {
				return new WP_REST_Response( 'Failed to acquire synchronization lock', 500 );
			}

			try {
				self::sync_data_source();
				return new WP_REST_Response( self::get_sync_info(), 200 );
			} catch ( Exception $e ) {
				throw $e;
				error_log( 'Failed to sync: ' . $e->getMessage() );
				return new WP_REST_Response(
					json_encode(
						array(
							'error' => true,
						)
					),
					500
				);
			}
		} finally {
			self::release_synchronization_lock();
		}
	}

	private static function sync_data_source() {
		$data_source = self::get_data_source();
		try {
			$data_source->sync();
			update_site_option(
				'wp_sync_details',
				array(
					'lastSyncTime' => time(),
					'version' => $data_source->get_current_version(),
				)
			);
		} catch ( Exception $e ) {
			$sync_details = self::get_sync_info();
			// @TODO: Expose some kind of error message, not just a boolean yes/no.
			// At the same time, do not reveal too much about the internals –
			// there is a chance the error message would expose a private
			// detail or a piece of security-related information.
			$sync_details['error'] = true;
			update_site_option( 'wp_sync_details', $sync_details );
			error_log( 'Error synchronizing data source: ' . $e->getMessage() );
			throw new Exception( 'Failed to sync', 0, $e );
		}
	}

	public static function get_sync_info() {
		$sync_details                       = get_site_option(
			'wp_sync_details',
			array(
				'lastSyncTime' => 0,
				'version' => null,
			)
		);
		$data_source                        = self::get_data_source();
		$sync_details['hasUnsyncedChanges'] = $data_source->get_current_version() !== $sync_details['version'];
		return $sync_details;
	}

	public static function get_settings() {
		$user        = wp_get_current_user();
		$uploads_dir = wp_upload_dir();

		$settings = get_option( 'static_files_editor_settings' ) ? get_option( 'static_files_editor_settings' ) : array();
		$settings = array_merge(
			array(
				'gitRepo'        => '',
				'selectedBranch' => '',
				'subdirectory'   => '/',
				'localRepoPath'  => $uploads_dir['basedir'] . '/static-files-editor',
				'gitUserName'    => $user->display_name ?? 'WordPress User',
				'gitUserEmail'   => $user->user_email ?? 'wordpress.admin@localhost',
			),
			array_filter( $settings )
		);

		return $settings;
	}

	/**
	 * Get GitHub repositories for the authenticated user
	 */
	public static function get_github_repos_endpoint() {
		$github_token = get_option( 'msf_github_token', '' );

		if ( empty( $github_token ) ) {
			return new WP_Error( 'no_token', 'GitHub token not found', array( 'status' => 400 ) );
		}

		$response = wp_remote_get(
			'https://api.github.com/user/repos?visibility=all&sort=updated&per_page=100',
			array(
				'headers' => array(
					'Authorization' => "Bearer {$github_token}",
					'Accept' => 'application/vnd.github.v3+json',
					'User-Agent' => 'WordPress/Static-Files-Editor',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'github_api_error', $response->get_error_message(), array( 'status' => 500 ) );
		}

		$body  = wp_remote_retrieve_body( $response );
		$repos = json_decode( $body, true );

		if ( ! is_array( $repos ) ) {
			return new WP_Error( 'invalid_response', 'Invalid response from GitHub API', array( 'status' => 500 ) );
		}

		foreach ( $repos as $key => $repo ) {
			$git_url = $repo['git_url'];
			if ( str_starts_with( $git_url, 'git://' ) ) {
				$git_url = 'https' . substr( $git_url, 3 );
			}
			$repos[ $key ]['http_clone_url'] = $git_url;
		}

		return $repos;
	}

	/**
	 * Store GitHub token endpoint
	 */
	public static function store_github_token_endpoint( $request ) {
		$token = $request->get_param( 'token' );

		if ( empty( $token ) ) {
			return new WP_Error( 'no_token', 'No token provided', array( 'status' => 400 ) );
		}

		// Store the token in site options
		update_option( 'msf_github_token', $token );

		return array( 'success' => true );
	}

	// Endpoint to clear GitHub token
	public static function clear_github_token_endpoint() {
		// Delete the token from site options
		delete_option( 'msf_github_token' );

		return array( 'success' => true );
	}
}

WP_Static_Files_Editor_Plugin::initialize();
