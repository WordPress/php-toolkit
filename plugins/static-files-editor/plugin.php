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
use WordPress\Git\GitException;
use WordPress\Git\GitRemote;
use WordPress\Git\GitFilesystem;
use WordPress\Git\GitRepository;
use WordPress\Markdown\MarkdownConsumer;
use WordPress\Markdown\MarkdownProducer;
use WordPress\XML\XMLProcessor;

use function WordPress\DataLiberation\URL\is_child_url_of;
use function WordPress\Filesystem\ls_recursive;
use function WordPress\Filesystem\wp_canonicalize_path;
use function WordPress\Filesystem\wp_join_paths;
use function WordPress\Filesystem\copy_between_filesystems;

if ( ! defined( 'WP_STATIC_PAGES_DIR' ) ) {
    define( 'WP_STATIC_PAGES_DIR', WP_CONTENT_DIR . '/uploads/my-static-pages' );
}

if ( ! defined( 'WP_STATIC_MEDIA_DIR' ) ) {
    define( 'WP_STATIC_MEDIA_DIR', 'media' );
}

if( ! defined( 'WP_LOCAL_FILE_POST_TYPE' )) {
    define( 'WP_LOCAL_FILE_POST_TYPE', 'local_file' );
}

if( ! defined( 'WP_AUTOSAVES_DIRECTORY' )) {
    define( 'WP_AUTOSAVES_DIRECTORY', '.autosaves' );
}

if( ! defined( 'WP_STATIC_FILES_EDITOR_IMAGE_MAX_DIMENSION' )) {
    define( 'WP_STATIC_FILES_EDITOR_IMAGE_MAX_DIMENSION', 1280 );
}

if(isset($_GET['dump'])) {
    add_action('init', function() {
        WP_Static_Files_Editor_Plugin::import_static_pages();
    });
}

require_once __DIR__ . '/data-source-page.php';

class WP_Static_Files_Editor_Plugin {

    /**
     * @var GitFilesystem
     */
    static private $fs;

    /**
     * @var GitRemote
     */
    static private $remote;

    static private function get_fs() {
        if(!self::$fs) {
            if(!is_dir(WP_STATIC_PAGES_DIR)) {
                mkdir(WP_STATIC_PAGES_DIR, 0777, true);
            }
            $config = static::get_settings();
            if(!$config['gitRepo']) {
                return;
            }

            $local_fs = LocalFilesystem::create(WP_STATIC_PAGES_DIR);
            $repo = new GitRepository($local_fs);
            $repo->add_remote('origin', $config['gitRepo']);
            $repo->set_ref_head('HEAD', 'ref: refs/heads/' . $config['selectedBranch']);
            $repo->set_config_value('user.name', $config['gitUserName']);
            $repo->set_config_value('user.email', $config['gitUserEmail']);

            self::$remote = new GitRemote($repo, 'origin');

            // Only force pull at most once every 10 minutes
            $last_pull_time = get_transient('wp_git_last_pull_time');
            if (!$last_pull_time) {
                set_transient('wp_git_last_pull_time', time(), 10 * MINUTE_IN_SECONDS);
                // self::force_pull();
            }

            self::$fs = GitFilesystem::create(
                $repo,
                [
                    'root' => $config['pathToSync'],
                    'auto_push' => true,
                    'remote' => self::$remote,
                ]
            );

            // @TODO: Uncomment
            // if(!self::$fs->is_dir('/' . WP_AUTOSAVES_DIRECTORY)) {
            //     self::$fs->mkdir('/' . WP_AUTOSAVES_DIRECTORY);
            // }
        }
        return self::$fs;
    }

    static public function force_pull() {
        $config = static::get_settings();
        if(!$config['gitRepo']) {
            return false;
        }
        self::get_fs();
        self::$remote->pull(
            $config['selectedBranch'],
            [
                'path' => $config['pathToSync'],
                'force' => true
            ]
        );
        return true;
    }

    static public function menu_item_callback() {
        // Get first post or create new one
        $posts = get_posts(array(
            'post_type' => WP_LOCAL_FILE_POST_TYPE,
            'posts_per_page' => 2,
            'orderby' => 'ID',
            'order' => 'ASC',
        ));

        if (empty($posts)) {
            try {
                if(!self::force_pull()) {
                    throw new GitException('Failed to pull from remote');
                }
                wp_redirect(admin_url('post-new.php?post_type=' . WP_LOCAL_FILE_POST_TYPE));
                exit;
            } catch (GitException $e) {
                // There are more ways to get here than just the new GitException above.
                // @TODO: Don't return false in self::force_pull() but throw instead.
                wp_redirect(admin_url('admin.php?page=static_files_editor-data-source&error=no_data_source'));
                exit('Please configure a data source in the settings page before continuing.');
            }
        } else {
            // Look for the first post that's not the default "my-first-note.md"
            $post_id = null;
            foreach ($posts as $post) {
                $path = get_post_meta($post->ID, 'local_file_path', true);
                if ($path !== '/my-first-note.md') {
                    $post_id = $post->ID;
                    break;
                }
            }
            // Fallback to first post if no other found
            if ($post_id === null) {
                $post_id = $posts[0]->ID;
            }

            $edit_url = admin_url('post.php?post=' . $post_id . '&action=edit');
            wp_redirect($edit_url);
            exit;
        }
    }

    static public function initialize() {
        // Register hooks
        register_activation_hook( __FILE__, array(self::class, 'import_static_pages') );
        register_activation_hook( __FILE__, function() {
            update_option('wp_page_for_privacy_policy', 0);
            update_option('show_on_front', 'posts');
            update_option('wp_editor_fullscreen_default', true);
            update_option('site_editor_fullscreen_default', true);
        } );

        add_action('init', function() {
            self::get_fs();
            self::register_post_type();

            // Redirect menu page to custom route
            global $pagenow;
            if ($pagenow === 'admin.php' && isset($_GET['page']) && $_GET['page'] === 'static_files_editor') {
                self::menu_item_callback();
            }
        });

        add_action('wp', function() {
            // Redirect homepage to static files editor
            if (is_home()) {
                wp_redirect(admin_url('admin.php?page=static_files_editor'));
                exit;
            }
        });

        add_filter( 'big_image_size_threshold', function($threshold) {
            return WP_STATIC_FILES_EDITOR_IMAGE_MAX_DIMENSION;
        });

        // Handle media uploads
        add_filter('wp_generate_attachment_metadata', function($metadata, $attachment_id) {
            try {
                if(!self::acquire_synchronization_lock()) {
                    return $metadata;
                }

                // Don't process thumbnails, only original images
                $file_path = wp_get_original_image_path($attachment_id);
                if(!$file_path) {
                    return $metadata;
                }

                // Skip if the file was already processed
                $local_file_path = get_post_meta($attachment_id, 'local_file_path', true);
                if($local_file_path) {
                    return $metadata;
                }

                $file_path = self::resize_to_max_dimensions_if_files_is_an_image($file_path);

                $main_fs = self::get_fs();
                $local_fs = LocalFilesystem::create(dirname($file_path));

                $file_name = basename($file_path);
                $target_path = wp_join_paths(WP_STATIC_MEDIA_DIR, $file_name);

                // Skip if the file was already processed
                if($main_fs->is_file($target_path)) {
                    return $metadata;
                }

                // Set local_file_path metadata for the attachment
                update_post_meta($attachment_id, 'local_file_path', $target_path);

                // Copy the file to the static media directory
                copy_between_filesystems([
                    'source_filesystem' => $local_fs,
                    'source_path' => $file_name,
                    'target_filesystem' => $main_fs,
                    'target_path' => $target_path,
                ]);

                return $metadata;
            } finally {
                self::release_synchronization_lock();
            }
        }, 10, 2);

        // Handle attachment updates (e.g. image rotations)
        add_action('wp_update_attachment_metadata', function($metadata, $attachment_id) {
            try {
                if(!self::acquire_synchronization_lock()) {
                    return $metadata;
                }

                // Don't process thumbnails, only original images
                $file_path = wp_get_original_image_path($attachment_id);
                if(!$file_path) {
                    return $metadata;
                }

                // Skip if the file isn't synchronized with the local filesystem
                $local_file_path = get_post_meta($attachment_id, 'local_file_path', true);
                if(!$local_file_path) {
                    return $metadata;
                }

                $file_path = self::resize_to_max_dimensions_if_files_is_an_image($file_path);

                $main_fs = self::get_fs();
                $local_fs = LocalFilesystem::create(dirname($file_path));

                $file_name = basename($file_path);
                $target_path = wp_join_paths(WP_STATIC_MEDIA_DIR, $file_name);

                // Skip if the file was already processed
                if($main_fs->is_file($target_path)) {
                    return $metadata;
                }

                // Copy the updated file to the static media directory
                copy_between_filesystems([
                    'source_filesystem' => $local_fs,
                    'source_path' => $file_name,
                    'target_filesystem' => $main_fs,
                    'target_path' => $target_path,
                ]);

                return $metadata;
            } finally {
                self::release_synchronization_lock();
            }
        }, 10, 2);

        // Disable thumbnail generation for local file attachments
        add_filter('intermediate_image_sizes_advanced', function($sizes, $metadata) {
            return array();
        }, 10, 2);

        // Rewrite attachment URLs to use the static files download endpoint
        add_filter('wp_get_attachment_url', function($url, $attachment_id) {
            $local_file_path = get_post_meta($attachment_id, 'local_file_path', true);
            if ($local_file_path) {
                return rest_url('static-files-editor/v1/download-file?path=' . urlencode($local_file_path));
            }
            return $url;
        }, 10, 2);

        add_action('admin_enqueue_scripts', function($hook) {
            wp_register_script(
                'static-files-editor',
                plugins_url('build/index.js', __FILE__),
                array('wp-element', 'wp-components', 'wp-block-editor', 'wp-edit-post', 'wp-plugins', 'wp-editor', 'wp-api-fetch'),
                '1.0.0',
                true
            );

            wp_add_inline_script(
                'static-files-editor',
                'window.WP_LOCAL_FILE_POST_TYPE = ' . json_encode(WP_LOCAL_FILE_POST_TYPE) . ';',
                'before'
            );

            wp_register_style(
                'static-files-editor',
                plugins_url('build/style-index.css', __FILE__),
                array('wp-components', 'wp-block-editor', 'wp-edit-post'),
                '1.0.0'
            );

            $screen = get_current_screen();
            $enqueue_script = $screen && $screen->base === 'post' && $screen->post_type === WP_LOCAL_FILE_POST_TYPE;
            if (!$enqueue_script) {
                return;
            }

            add_filter('show_admin_bar', '__return_false');

            wp_enqueue_script('static-files-editor');
            wp_enqueue_style('static-files-editor');

            // Preload the initial files tree
            wp_add_inline_script('wp-api-fetch', 'wp.apiFetch.use(wp.apiFetch.createPreloadingMiddleware({
                "/static-files-editor/v1/files?per_page=-1": {
                    body: '.json_encode(WP_Static_Files_Editor_Plugin::get_files_list_endpoint()).',
                }
            }));', 'after');
        });

        add_action('rest_api_init', function() {
            register_rest_route('static-files-editor/v1', '/git/branches', array(
                'methods' => 'POST',
                'callback' => array(self::class, 'get_git_branches_endpoint'),
                'permission_callback' => function() {
                    return current_user_can('edit_posts');
                },
            ));

            register_rest_route('static-files-editor/v1', '/git/files', array(
                'methods' => 'POST',
                'callback' => array(self::class, 'get_git_files_endpoint'),
                'permission_callback' => function() {
                    return current_user_can('edit_posts');
                },
            ));

            register_rest_route('static-files-editor/v1', '/git/force-pull', array(
                'methods' => 'POST',
                'callback' => array(self::class, 'git_force_pull_endpoint'),
                'permission_callback' => function() {
                    return current_user_can('edit_posts');
                },
            ));

            register_rest_route('static-files-editor/v1', '/get-or-create-post-for-file', array(
                'methods' => 'POST',
                'callback' => array(self::class, 'get_or_create_post_for_file_endpoint'),
                'permission_callback' => function() {
                    return current_user_can('edit_posts');
                },
            ));

            register_rest_route('static-files-editor/v1', '/files', array(
                'methods' => 'GET',
                'callback' => array(self::class, 'get_files_list_endpoint'),
                'permission_callback' => function() {
                    return current_user_can('edit_posts');
                },
            ));

            register_rest_route('static-files-editor/v1', '/files/(?P<id>.*)', array(
                'methods' => 'PUT',
                'callback' => array(self::class, 'update_file_endpoint'),
                'permission_callback' => function() {
                    return current_user_can('edit_posts');
                },
            ));

            register_rest_route('static-files-editor/v1', '/files/batch', array(
                'methods' => 'POST',
                'callback' => array(self::class, 'create_files_batch_endpoint'),
                'permission_callback' => function() {
                    return current_user_can('edit_posts');
                },
            ));
            register_rest_route('static-files-editor/v1', '/files', array(
                'methods' => 'POST',
                'callback' => array(self::class, 'create_file_endpoint'),
                'permission_callback' => function() {
                    return current_user_can('edit_posts');
                },
            ));

            register_rest_route('static-files-editor/v1', '/files/(?P<id>.*)', array(
                'methods' => 'DELETE',
                'callback' => array(self::class, 'delete_file_endpoint'),
                'permission_callback' => function() {
                    return current_user_can('edit_posts');
                },
            ));

            register_rest_route('static-files-editor/v1', '/download-file', array(
                'methods' => 'GET',
                'callback' => array(self::class, 'download_file_endpoint'),
                'permission_callback' => function() {
                    // @TODO: Restrict access to this endpoint to editors, but
                    //        don't require a nonce. Nonces are troublesome for
                    //        static assets that don't have a dynamic URL.
                    // return current_user_can('edit_posts');
                    return true;
                },
                'args' => array(
                    'path' => array(
                        'required' => true,
                        'type' => 'string',
                        'sanitize_callback' => function($param) {
                            return '/' . ltrim($param, '/');
                        }
                    )
                )
            ));

            register_rest_route('static-files-editor/v1', '/save-settings', array(
                'methods' => 'POST',
                'callback' => array(self::class, 'save_settings_endpoint'),
                'permission_callback' => function() {
                    return current_user_can('manage_options');
                },
            ));
        });

        // @TODO: the_content and rest_prepare_local_file filters run twice for REST API requests.
        //        find a way of only running them once.

        // Add the filter for 'the_content'
        add_filter('the_content', function($content, $post = null) {
            // If no post is provided, try to get it from the global scope
            if (!$post) {
                global $post;
            }

            // Check if this post is of type "local_file"
            if ($post && $post->post_type === WP_LOCAL_FILE_POST_TYPE) {
                // Get the latest content from the database first
                $content = $post->post_content;

                // Then refresh from file if needed
                $new_content = self::refresh_post_from_local_file($post);
                if(false !== $new_content && !is_wp_error($new_content)) {
                    $content = $new_content;
                }
                return $content;
            }

            // Return original content for all other post types
            return $content;
        }, 10, 2);

        // Add filter for REST API responses
        add_filter('rest_prepare_' . WP_LOCAL_FILE_POST_TYPE, function($response, $post, $request) {
            $new_content = self::refresh_post_from_local_file($post);
            if(!is_wp_error($new_content)) {
                $response->data['content']['raw'] = $new_content;
                $response->data['content']['rendered'] = '';
            }
            return $response;
        }, 10, 3);

        // Update the file after post is saved
        add_action('save_post_' . WP_LOCAL_FILE_POST_TYPE, function($post_id, $post, $update) {
            try {
                if(!self::acquire_synchronization_lock()) {
                    return;
                }
                $post_id = $post->ID;
                if (
                    empty($post->ID) ||
                    $post->post_status !== 'publish' ||
                    $post->post_type !== WP_LOCAL_FILE_POST_TYPE
                ) {
                    return;
                }

                $content = self::convert_post_to_string($post);

                $fs = self::get_fs();
                $path = get_post_meta($post_id, 'local_file_path', true);
                if(!$path) {
                    return;
                }
                $fs->put_contents($path, $content, [
                    'message' => 'User saved ' . $post->post_title,
                ]);
            } finally {
                self::release_synchronization_lock();
            }
        }, 10, 3);

        // Also update file when autosave occurs
        add_action('wp_creating_autosave', function($autosave) {
            try {
                if(!self::acquire_synchronization_lock()) {
                    return;
                }
                $autosave = (object)$autosave;
                if (
                    empty($autosave->ID) ||
                    $autosave->post_status !== 'inherit' ||
                    $autosave->post_type !== 'revision'
                ) {
                    return;
                }
                $parent_post = get_post($autosave->post_parent);
                if ($parent_post->post_type !== WP_LOCAL_FILE_POST_TYPE) {
                    return;
                }

                $content = self::convert_post_to_string($autosave);

                $path = wp_join_paths(
                    '/' . WP_AUTOSAVES_DIRECTORY . '/',
                    get_post_meta($parent_post->ID, 'local_file_path', true)
                );
                $fs = self::get_fs();
                $fs->put_contents($path, $content, [
                    'amend' => true,
                ]);
            } finally {
                self::release_synchronization_lock();
            }
        }, 10, 1);
    }

    /**
     * Resize image to a maximum width and height.
     *
     * @param string $image_path The path to the image file
     * @return string The path to the resized image file
     */
    static public function resize_to_max_dimensions_if_files_is_an_image($image_path) {
        // Only resize if this is an image file
        // getimagesize() returns false for non-images (and
        // also image formats it can't handle)
        $image_size = @getimagesize($image_path);
        if ($image_size === false) {
            return $image_path;
        }

        if ($image_size[0] > WP_STATIC_FILES_EDITOR_IMAGE_MAX_DIMENSION || $image_size[1] > WP_STATIC_FILES_EDITOR_IMAGE_MAX_DIMENSION) {
            $editor = wp_get_image_editor($image_path);
            if (is_wp_error($editor)) {
                return $image_path;
            }

            $editor->resize(WP_STATIC_FILES_EDITOR_IMAGE_MAX_DIMENSION, WP_STATIC_FILES_EDITOR_IMAGE_MAX_DIMENSION, false);

            // Rotate the image if needed
            if(function_exists('exif_read_data')) {
                $exif = @exif_read_data($image_path);
                if($exif && isset($exif['Orientation'])) {
                    $editor->rotate(exif_imagetype($image_path));
                }
            }

            // Try saving to original path first
            $result = $editor->save($image_path);

            // If saving fails (read-only), save to temp file
            if (is_wp_error($result)) {
                $temp_path = wp_tempnam(basename($image_path));
                $result = $editor->save($temp_path);
                if (is_wp_error($result)) {
                    return $image_path;
                }
                $image_path = $temp_path;
            }
        }

        return $image_path;
    }

    static public function download_file_endpoint($request) {
        $path = wp_canonicalize_path($request->get_param('path'));
        $fs = self::get_fs();

        if($fs->is_dir($path)) {
            return new WP_Error('file_error', 'Directory download is not supported yet.');
        }

        // Get file info
        $filename = basename($path);
        $object = $fs->open_read_stream($path);

        // Set headers for file download
        header('Content-Type: application/octet-stream');
        header("Content-Disposition: attachment; filename=UTF-8''" . urlencode($filename));
        header('Content-Length: ' . $object->length());
        header('Cache-Control: no-cache');

        while(!$object->reached_end_of_data()) {
            $bytes_available = $object->pull(8192);
            echo $object->consume($bytes_available);
        }
        die();
    }

    static private $synchronizing = 0;
    static private function acquire_synchronization_lock() {
        // Skip if in maintenance mode
        if (wp_is_maintenance_mode()) {
            return false;
        }

        if (defined('WP_IMPORTING') && WP_IMPORTING) {
            return false;
        }

        if(!self::get_fs()) {
            return false;
        }

        // @TODO: Synchronize between threads
        if(self::$synchronizing > 0) {
            return false;
        }
        ++self::$synchronizing;
        return true;
    }

    static private function release_synchronization_lock() {
        self::$synchronizing = max(0, self::$synchronizing - 1);
    }

    static private function refresh_post_from_local_file($post) {
        try {
            if(!self::acquire_synchronization_lock()) {
                return false;
            }

            $post_id = $post->ID;
            $fs = self::get_fs();
            $path = get_post_meta($post_id, 'local_file_path', true);
            if(!$fs->is_file($path)) {
                // @TODO: Log the error outside of this method.
                //        This happens naturally when the underlying file is deleted.
                //        It's annoying to keep seeing this error when developing
                //        the plugin so I'm commenting it out.
                //
                //        Really, this may not even be an error. The caller must
                //        decide whether to log the error or handle the failure
                //        gracefully.
                //
                //        This method only needs to bubble the error information up,
                //        e.g. by throwing, returning WP_Error, or setting self::$last_error.
                return false;
            }
            $content = $fs->get_contents($path);
            if(!is_string($content)) {
                // @TODO: Ditto the previous comment.
                return false;
            }
            $extension = pathinfo($path, PATHINFO_EXTENSION);
            $converter = self::parse_local_file($content, $extension);
            if(!$converter) {
                return false;
            }

            $new_content = self::wordpressify_static_assets_urls(
                $converter->get_block_markup()
            );
            $updated = wp_update_post(array(
                'ID' => $post_id,
                'post_content' => $new_content,
                'post_title' => $converter->get_first_meta_value('post_title') ?? '',
                'post_date_gmt' => $converter->get_first_meta_value('post_date_gmt') ?? '',
                'menu_order' => $converter->get_first_meta_value('menu_order') ?? '',
                // 'meta_input' => $converter->get_all_metadata(),
            ));
            if(is_wp_error($updated)) {
                return $updated;
            }

            return $new_content;
        } finally {
            self::release_synchronization_lock();
        }
    }

    static private function parse_local_file($content, $format) {
        switch($format) {
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

    static private function convert_post_to_string($post, $format=null) {
        if($format === null) {
            $path = get_post_meta($post->ID, 'local_file_path', true);
            if($path) {
                $format = pathinfo($path, PATHINFO_EXTENSION);
            }
        }
        if($format === null) {
            $format = 'html';
        }

        $metadata = [];
        foreach(['post_date_gmt', 'post_title', 'menu_order'] as $key) {
            $metadata[$key] = [get_post_field($key, $post->ID)];
        }
        // @TODO: Also include actual post_meta. Which ones? All? The
        //        ones explicitly set by the user in the editor?

        return self::convert_post_data_to_string(
            new BlocksWithMetadata(
                $post->post_content,
                $metadata
            ),
            $format
        );
    }

    static private function convert_post_data_to_string(BlocksWithMetadata $blocks_with_metadata, $format) {
        $blocks_with_metadata = new BlocksWithMetadata(
            self::unwordpressify_static_assets_urls(
                $blocks_with_metadata->get_block_markup()
            ),
            $blocks_with_metadata->get_all_metadata()
        );

        switch($format) {
            case 'md':
                $producer = new MarkdownProducer( $blocks_with_metadata );
                break;
            case 'xhtml':
                // @TODO: Add proper support for XHTML – perhaps via the serialize() method?
                throw new Exception('Serializing to XHTML is not supported yet');
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
    static private function unwordpressify_static_assets_urls($content) {
        $site_url_raw = rtrim(get_site_url(), '/') . '/';
        $site_url = WPURL::parse($site_url_raw);
        $expected_endpoint_path = '/wp-json/static-files-editor/v1/download-file';
        $p = new BlockMarkupUrlProcessor($content, $site_url);
        while($p->next_url()) {
            $url = $p->get_parsed_url();
            if(!is_child_url_of($url, $site_url_raw)) {
                continue;
            }

            // Account for sites with no nice permalink structure
            if($url->searchParams->has('rest_route')) {
                $url = WPURL::parse($url->searchParams->get('rest_route'), $site_url);
            }

            // Naively check for the endpoint that serves the file.
            // WordPress can use a custom REST API prefix which this
            // check doesn't account for. It assumes the endpoint path
            // is unique enough to not conflict with other paths.
            //
            // It may need to be revisited if any conflicts arise in
            // the future.
            if(!str_ends_with($url->pathname, $expected_endpoint_path)) {
                continue;
            }

            // At this point we're certain the URL intends to download
            // a static file managed by this plugin.

            // Let's replace the URL in the content with the relative URL.
            $original_url = $url->searchParams->get('path');
            $p->set_raw_url($original_url);
        }

        return $p->get_updated_html();
    }

    /**
     * Convert references to files served via path to the
     * corresponding download_file_endpoint references.
     *
     * @TODO: Plug in the attachment IDs into image blocks
     */
    static private function wordpressify_static_assets_urls($content) {
        $parsed_site_url = WPURL::parse(rtrim(get_site_url(), '/') . '/');
        $expected_endpoint_path = wp_join_paths(
            $parsed_site_url->pathname,
            'wp-json/static-files-editor/v1/download-file'
        );
        $p = new BlockMarkupUrlProcessor($content, $parsed_site_url);
        while($p->next_url()) {
            $url = $p->get_parsed_url();
            if(!is_child_url_of($url, $parsed_site_url)) {
                continue;
            }

            // @TODO: Also work with <a> tags, account
            //        for .md and directory links etc.
            if($p->get_tag() !== 'IMG') {
                continue;
            }

            $new_url = WPURL::parse($url->pathname, $parsed_site_url);
            $new_url->pathname = $expected_endpoint_path;
            $new_url->searchParams->set('path', $p->get_raw_url());
            $p->set_raw_url($new_url->__toString());
        }

        return $p->get_updated_html();
    }

    static public function get_local_files_list($subdirectory = '') {
        $list = [];
        $fs = self::get_fs();
        if(!$fs) {
            return $list;
        }

        // Get all file paths and post IDs in one query
        $file_posts = get_posts(array(
            'post_type' => WP_LOCAL_FILE_POST_TYPE,
            'meta_key' => 'local_file_path',
            'posts_per_page' => -1,
            'fields' => 'id=>meta'
        ));

        $path_to_post = array();
        foreach($file_posts as $post) {
            $file_path = get_post_meta($post->ID, 'local_file_path', true);
            if ($file_path) {
                $path_to_post[$file_path] = $post;
            }
        }

        $attachments = get_posts(array(
            'post_type' => 'attachment',
            'posts_per_page' => -1,
            'meta_key' => 'local_file_path',
        ));
        foreach($attachments as $attachment) {
            $attachment_path = get_post_meta($attachment->ID, 'local_file_path', true);
            if ($attachment_path) {
                $path_to_post[$attachment_path] = $attachment;
            }
        }

        $base_dir = $subdirectory ? $subdirectory : '/';
        self::build_local_file_list($fs, $base_dir, $list, $path_to_post);

        $keyed_list = [];
        foreach($list as $item) {
            $item['id'] = $item['path'];
            
            $keyed_list[$item['path']] = $item;
        }

        return $keyed_list;
    }

    static private function build_local_file_list($fs, $dir, &$list, $path_to_post) {
        $items = $fs->ls($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            // Exclude the autosaves directory from the files tree
            if($dir === '/' && $item === WP_AUTOSAVES_DIRECTORY) {
                continue;
            }
            // Exclude the .gitkeep file from the files tree.
            // WP_Git_Filesystem::mkdir() creates an empty .gitkeep file in each created
            // directory since Git doesn't support empty directories.
            if($item === '.gitkeep') {
                continue;
            }

            $path = $dir === '/' ? "/$item" : "$dir/$item";

            if ($fs->is_dir($path)) {
                $node = array(
                    'type' => 'directory',
                    'path' => $path,
                    'id' => $path,
                    'children' => []
                );
                $list[] = $node;

                // Recursively build children
                self::build_local_file_list($fs, $path, $list, $path_to_post);
            } else {
                $node = array(
                    'type' => 'file',
                    'path' => $path,
                    'id' => $path,
                );

                if (isset($path_to_post[$path])) {
                    $node['post_id'] = $path_to_post[$path]->ID;
                    $node['post_type'] = $path_to_post[$path]->post_type;
                    $node['post_title'] = $path_to_post[$path]->post_title;
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
    static public function import_static_pages() {
        if ( defined('WP_IMPORTING') && WP_IMPORTING ) {
            return;
        }
        define('WP_IMPORTING', true);

        // Make sure the post type is registered even if we're
        // running before the init hook.
        self::register_post_type();

        // Prevent ID conflicts
        self::reset_db_data();

        if(!self::get_fs()) {
            return;
        }

        return self::do_import_static_pages([
            'from_filesystem' => self::get_fs(),
        ]);
    }

    static private function do_import_static_pages($options = array()) {
        $fs = $options['from_filesystem'];
        $importer = StreamImporter::create(
            function () use ($fs, $options) {
                return new FilesystemEntityReader(
                    $fs,
                    array(
                        'post_type' => WP_LOCAL_FILE_POST_TYPE,
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
            array (
                'data_source' => 'static_pages'
            )
        );

        return data_liberation_import_step( $import_session, $importer );
    }

    static private function register_post_type() {
        register_post_type(WP_LOCAL_FILE_POST_TYPE, array(
            'labels' => array(
                'name' => 'Local Files',
                'singular_name' => 'Local File',
                'add_new' => 'Add New',
                'add_new_item' => 'Add New Local File',
                'edit_item' => 'Edit Local File',
                'new_item' => 'New Local File',
                'view_item' => 'View Local File',
                'search_items' => 'Search Local Files',
                'not_found' => 'No local files found',
                'not_found_in_trash' => 'No local files found in Trash',
            ),
            'public' => true,
            'show_ui' => true,
            'show_in_menu' => false,
            'hierarchical' => true,
            'supports' => array(
                'title',
                'editor',
                'page-attributes',
                'revisions',
                'custom-fields'
            ),
            'has_archive' => false,
            'show_in_rest' => true,
        ));

        // Register the meta field for file paths
        register_post_meta(WP_LOCAL_FILE_POST_TYPE, 'local_file_path', array(
            'type' => 'string',
            'description' => 'Path to the local file',
            'single' => true,
            'show_in_rest' => true,
        ));
    }

    /**
     * Resets the database to a clean state.
     *
     * @TODO: Make it work with MySQL, right now it uses SQLite-specific code.
     */
    static private function reset_db_data() {
        $GLOBALS['@pdo']->query('DELETE FROM wp_posts WHERE id > 0');
        $GLOBALS['@pdo']->query("UPDATE SQLITE_SEQUENCE SET SEQ=0 WHERE NAME='wp_posts'");

        $GLOBALS['@pdo']->query('DELETE FROM wp_postmeta WHERE post_id > 1');
        $GLOBALS['@pdo']->query("UPDATE SQLITE_SEQUENCE SET SEQ=20 WHERE NAME='wp_postmeta'");

        $GLOBALS['@pdo']->query('DELETE FROM wp_comments');
        $GLOBALS['@pdo']->query("UPDATE SQLITE_SEQUENCE SET SEQ=0 WHERE NAME='wp_comments'");

        $GLOBALS['@pdo']->query('DELETE FROM wp_commentmeta');
        $GLOBALS['@pdo']->query("UPDATE SQLITE_SEQUENCE SET SEQ=0 WHERE NAME='wp_commentmeta'");
    }

    static public function get_or_create_post_for_file_endpoint($request) {
        try {
            if(!self::acquire_synchronization_lock()) {
                return new WP_Error('synchronization_lock_failed', 'Failed to acquire synchronization lock');
            }

            $file_path = wp_canonicalize_path($request->get_param('path'));
            $create_file = $request->get_param('create_file');

            if (!$file_path) {
                return new WP_Error('missing_path', 'File path is required');
            }

            $post_id = self::get_or_create_post_for_file($file_path, $create_file);

            if (is_wp_error($post_id)) {
                return $post_id;
            }

        } finally {
            self::release_synchronization_lock();
        }

        $refreshed_post = self::refresh_post_from_local_file(get_post($post_id));
        if (is_wp_error($refreshed_post)) {
            return $refreshed_post;
        }

        return array(
            'post_id' => $post_id
        );
    }

    static private function get_or_create_post_for_file($file_path, $create_file = false) {
        $fs = self::get_fs();
        if($create_file && !$fs->is_file($file_path)) {
            $fs->put_contents($file_path, '');
        }
        $existing_posts = get_posts(array(
            'post_type' => WP_LOCAL_FILE_POST_TYPE,
            'meta_key' => 'local_file_path',
            'meta_value' => $file_path,
            'posts_per_page' => 1
        ));
        if(!empty($existing_posts)) {
            return $existing_posts[0]->ID;
        }
        $post_data = array(
            'post_title' => basename($file_path),
            'post_type' => WP_LOCAL_FILE_POST_TYPE,
            'post_status' => 'publish',
            'post_content' => '',
        );
        $post_id = wp_insert_post($post_data);
        if(is_wp_error($post_id)) {
            return $post_id;
        }
        if(false === update_post_meta($post_id, 'local_file_path', $file_path)) {
            return new WP_Error('failed_to_update_local_file_path', 'Failed to update local file path');
        }
        return $post_id;
    }

    static public function get_files_list_endpoint() {
        return self::get_local_files_list();
    }

    static public function create_file_endpoint($request) {
        try {
            if(!self::acquire_synchronization_lock()) {
                return;
            }
            $path = wp_canonicalize_path($request->get_param('path'));
            $content = $request->get_param('content');
            $fs = self::get_fs();
            if(!$fs->is_dir(dirname($path))) {
                $fs->mkdir(dirname($path), ['recursive' => true]);
            }
            $fs->put_contents($path, $content);
            $post_id = self::get_or_create_post_for_file($path, false);
            return array( 'id' => $path, 'post_id' => $post_id, 'path' => $path );
        } finally {
            self::release_synchronization_lock();
        }
    }

    static public function update_file_endpoint($request) {
        try {
            if(!self::acquire_synchronization_lock()) {
                return;
            }
            $from_path = wp_canonicalize_path($request->get_param('id'));
            $from_path = rtrim($from_path, '/');

            $to_path = $request->get_param('path');
            $to_path = rtrim($to_path, '/');

            if (!$from_path || !$to_path) {
                return new WP_Error('missing_path', 'Both source and target paths are required');
            }

            $fs = self::get_fs();
            if($fs->is_file($from_path)) {
                // Find and update associated post
                $previous_content = $fs->get_contents($from_path);
                $existing_posts = get_posts(array(
                    'post_type' => WP_LOCAL_FILE_POST_TYPE,
                    'meta_key' => 'local_file_path',
                    'meta_value' => $from_path,
                    'posts_per_page' => 1
                ));
                $existing_post = count($existing_posts) > 0 ? $existing_posts[0] : null;

                $moved = false;

                if ($existing_post) {
                    // Regenerate the content from scratch if we're changing the file format.
                    $previous_extension = pathinfo($from_path, PATHINFO_EXTENSION);
                    $new_extension = pathinfo($to_path, PATHINFO_EXTENSION);
                    if($existing_post->post_type === WP_LOCAL_FILE_POST_TYPE && $previous_extension !== $new_extension) {
                        $parsed = self::parse_local_file(
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
                        $fs->rm($from_path);
                        $moved = true;
                    }

                    // Update the local file path
                    if(false === update_post_meta($existing_post->ID, 'local_file_path', $to_path)) {
                        throw new Exception('Failed to update local file path');
                    }
                }
            } else if($fs->is_dir($from_path)) {
                // Update the local file paths for all posts within the directory
                $nested_posts = get_posts(array(
                    'post_type' => WP_LOCAL_FILE_POST_TYPE,
                    'meta_query' => array(
                        array(
                            'key' => 'local_file_path',
                            'value' => $from_path . '%',
                            'compare' => 'LIKE'
                        )
                    ),
                    'posts_per_page' => -1
                ));

                foreach ($nested_posts as $existing_post) {
                    $current_path = get_post_meta($existing_post->ID, 'local_file_path', true);
                    $new_path = $to_path . substr($current_path, strlen($from_path));

                    if (false === update_post_meta($existing_post->ID, 'local_file_path', $new_path)) {
                        throw new Exception('Failed to update local file path for post ID ' . $existing_post->ID);
                    }
                }
            }

            if(!$moved) {
                $fs->rename($from_path, $to_path);
            }

            return array('success' => true);
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
    static public function create_files_batch_endpoint($request) {
        try {
            if(!self::acquire_synchronization_lock()) {
                return;
            }
            $uploaded_fs = UploadedFilesystem::create($request, 'content');

            // Copy the uploaded files to the main filesystem
            $main_fs = self::get_fs();
            $create_in_dir = wp_canonicalize_path($request->get_param('path'));
            copy_between_filesystems([
                'source_filesystem' => $uploaded_fs,
                'source_path' => '/',
                'target_filesystem' => $main_fs,
                'target_path' => $create_in_dir,
            ]);

            // Import the uploaded files into WordPress
            $parent_id = null;
            if($create_in_dir) {
                $parent_post = get_posts(array(
                    'post_type' => WP_LOCAL_FILE_POST_TYPE,
                    'meta_key' => 'local_file_path',
                    'meta_value' => $create_in_dir,
                    'posts_per_page' => 1
                ));
                if(!empty($parent_post)) {
                    $parent_id = $parent_post[0]->ID;
                }
            }

            $result = self::do_import_static_pages(array(
                'from_filesystem' => $uploaded_fs,
                'post_tree_options' => array(
                    'root_parent_id' => $parent_id,
                    'create_index_pages' => false,
                ),
            ));
            if(is_wp_error($result)) {
                return $result;
            }

            /**
             * @TODO: A method such as $import_session->get_imported_entities()
             *        that iterates over the imported entities would be highly
             *        useful here. We don't have one, so we need the clunky
             *        inference below to get the imported posts.
             */
            $created_files = [];
            $visitor = new FilesystemVisitor($uploaded_fs);
            while($visitor->next()) {
                $event = $visitor->get_event();
                if(!$event->is_entering()) {
                    continue;
                }
                $paths = $event->files;
                if($visitor->get_current_depth() === 1) {
                    // Make sure we save the top-level directories
                    $paths = array_merge([$event->dir], $event->files);
                }
                foreach($paths as $path) {
                    $type = $uploaded_fs->is_dir($path) ? 'directory' : 'file';
                    $post_id = null;
                    $created_path = wp_join_paths($create_in_dir, $path);
                    if($type === 'post') {
                        $created_post = get_posts(array(
                            'post_type' => WP_LOCAL_FILE_POST_TYPE,
                            'meta_key' => 'local_file_path',
                            'meta_value' => $created_path,
                            'posts_per_page' => 1
                        ));
                        $post_id = $created_post ? $created_post[0]->ID : null;
                    }
                    $created_files[] = array(
                        'path' => $created_path,
                        'post_id' => $post_id,
                        'type' => $type,
                    );
                }
            }

            return array(
                'created_files' => $created_files
            );
        } finally {
            self::release_synchronization_lock();
        }
    }

    static public function get_git_branches($git_repo_url) {
        $repo = new GitRepository(InMemoryFilesystem::create());
        $repo->add_remote('origin', $git_repo_url);
        $remote = new GitRemote($repo, 'origin');
        $refs = $remote->ls_refs('refs/heads/');
        $head_ref = $remote->ls_refs('HEAD');
        
        $by_hash = array_flip($refs);
        $default_branch_full_name = $by_hash[$head_ref['HEAD']];
        unset($refs[$default_branch_full_name]);

        $default_branch_nice_name = substr($default_branch_full_name, strlen('refs/heads/')) . ' (default)';
        $refs_names = [
            ['fullName' => $default_branch_full_name, 'niceName' => $default_branch_nice_name]
        ];
        foreach($refs as $ref => $hash) {
            $refs_names[] = ['fullName' => $ref, 'niceName' => substr($ref, strlen('refs/heads/'))];
        }

        return array("default_branch" => $default_branch_full_name, "refs" => $refs_names);
    }

    static public function get_git_branches_endpoint($request) {
        $git_repo_url = $request->get_param('gitRepo');
        if(!str_ends_with($git_repo_url, '.git')) {
            $git_repo_url .= '.git';
        }
        return self::get_git_branches($git_repo_url);
    }

    static public function get_git_files_endpoint($request) {
        $git_repo_url = $request->get_param('gitRepo');
        $branch = $request->get_param('branch');
        if(!str_ends_with($git_repo_url, '.git')) {
            $git_repo_url .= '.git';
        }
        $repo = new GitRepository(InMemoryFilesystem::create());
        $repo->add_remote('origin', $git_repo_url);
        $remote = new GitRemote($repo, 'origin');
        
        $refs = $remote->ls_refs('refs/heads/');
        if(!isset($refs[$branch])) {
            return new WP_Error('branch_not_found', 'Branch "' . $branch . '" not found');
        }
        $branch_tip = $refs[$branch];

        $objects_index = $remote->list_objects($branch_tip);
        $fs = GitFilesystem::create($objects_index);

        return array("files" => ls_recursive($fs));
    }

    static public function delete_file_endpoint($request) {
        $path = wp_canonicalize_path($request->get_param('id'));
        if (!$path) {
            return new WP_Error('missing_path', 'File path is required');
        }

        try {
            if(!self::acquire_synchronization_lock()) {
                return new WP_Error('synchronization_lock_failed', 'Failed to acquire synchronization lock');
            }
            // Find and delete associated post
            $existing_posts = get_posts(array(
                'post_type' => WP_LOCAL_FILE_POST_TYPE,
                'meta_key' => 'local_file_path',
                'meta_value' => $path,
                'posts_per_page' => 1
            ));

            if (!empty($existing_posts)) {
                wp_delete_post($existing_posts[0]->ID, true);
            }

            // Delete the actual file
            $fs = self::get_fs();
            if($fs->is_dir($path)) {
                if (!$fs->rmdir($path, ['recursive' => true])) {
                    return new WP_Error('delete_failed', 'Failed to delete directory');
                }
            } else {
                if (!$fs->rm($path)) {
                    return new WP_Error('delete_failed', 'Failed to delete file');
                }
            }

            return array('success' => true);
        } finally {
            self::release_synchronization_lock();
        }
    }

    static public function save_settings_endpoint(WP_REST_Request $request) {
        $gitRepo = $request->get_param('gitRepo');
        $selectedBranch = $request->get_param('selectedBranch');
        $pathToSync = $request->get_param('pathToSync');

        $new_settings = array(
            'gitRepo' => $gitRepo,
            'selectedBranch' => $selectedBranch,
            'pathToSync' => $pathToSync,
        );
        update_option('static_files_editor_settings', $new_settings);

        return new WP_REST_Response('Settings saved successfully', 200);
    }

    static public function git_force_pull_endpoint($request) {
        try {
            if(!self::acquire_synchronization_lock()) {
                return new WP_REST_Response('Failed to acquire synchronization lock', 500);
            }
            if(!self::force_pull()) {
                return new WP_REST_Response('Force pull failed', 500);
            }
            return new WP_REST_Response('Force pull completed', 200);
        } finally {
            self::release_synchronization_lock();
        }
    }

    static public function get_settings() {
        $user = wp_get_current_user();
        $uploads_dir = wp_upload_dir();

        $settings = get_option('static_files_editor_settings') ?: [];
        $settings = array_merge(array(
            'gitRepo' => '',
            'selectedBranch' => '',
            'pathToSync' => '/',
            'localRepoPath' => $uploads_dir['basedir'] . '/static-files-editor',
            'gitUserName' => $user->display_name ?? 'WordPress User',
            'gitUserEmail' => $user->user_email ?? 'wordpress.admin@localhost',
        ), array_filter($settings));

        if(str_starts_with($settings['selectedBranch'], 'refs/heads/')) {
            $settings['selectedBranch'] = substr($settings['selectedBranch'], strlen('refs/heads/'));
        }

        return $settings;
    }

}

WP_Static_Files_Editor_Plugin::initialize();
