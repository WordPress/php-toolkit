<?php

use WordPress\DataLiberation\DataFormatConsumer\BlocksWithMetadata;
use WordPress\Filesystem\LocalFilesystem;
use WordPress\Git\GitEndpoint;
use WordPress\Git\GitException;
use WordPress\Git\GitRepository;
use WordPress\Git\Model\Commit;
use WordPress\Git\Model\TreeEntry;
use WordPress\Markdown\MarkdownConsumer;
use WordPress\Markdown\MarkdownProducer;

class WP_Origin_Plugin {
	const DEFAULT_BRANCH  = 'trunk';
	const ROUTE_NAMESPACE = 'git/v1';
	const ROUTE_PATTERN   = '/md\.git(?P<path>/.*)?';
	const EPOCH_TIMESTAMP = 946684800;

	private static $supported_post_types = array( 'post', 'page' );

	public static function bootstrap() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_git_response' ), 10, 4 );
	}

	public static function register_routes() {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_PATTERN,
			array(
				'methods'             => WP_REST_Server::ALLMETHODS,
				'callback'            => array( __CLASS__, 'handle_rest_request' ),
				'permission_callback' => array( __CLASS__, 'check_permissions' ),
			)
		);
	}

	public static function check_permissions( WP_REST_Request $request ) {
		$git_path = self::build_git_path( $request );
		if ( self::is_push_request( $git_path ) ) {
			return current_user_can( 'edit_posts' );
		}

		return is_user_logged_in();
	}

	public static function handle_rest_request( WP_REST_Request $request ) {
		$repository_path = null;

		try {
			$repository_data = self::open_repository();
			$repository      = $repository_data['repository'];
			$repository_path = $repository_data['path'];
			self::sync_repository_from_wordpress( $repository );

			$git_path     = self::build_git_path( $request );
			$request_body = file_get_contents( 'php://input' );

			$push_header = null;
			if ( self::is_push_request( $git_path ) ) {
				$push_header = self::parse_push_header( $request_body );
				if ( false === $push_header ) {
					return self::build_protocol_error_response(
						'git-receive-pack',
						'Invalid push request.'
					);
				}

				$current_head = $repository->get_branch_tip( 'refs/heads/' . self::DEFAULT_BRANCH );
				if ( $push_header['old_oid'] !== $current_head ) {
					return self::build_protocol_error_response(
						'git-receive-pack',
						'Push rejected because the remote changed. Pull the latest changes and try again.'
					);
				}
			}

			$response = new WP_Origin_Buffering_Response();
			$endpoint = new GitEndpoint( $repository );
			$endpoint->handle_request( $git_path, $request_body, $response );

			if ( self::is_push_request( $git_path ) ) {
				try {
					self::apply_repository_changes_to_wordpress(
						$repository,
						$push_header['old_oid'],
						$push_header['new_oid']
					);
				} catch ( Throwable $exception ) {
					return self::build_protocol_error_response(
						'git-receive-pack',
						self::get_throwable_message( $exception )
					);
				}
			}

			return $response->to_rest_response();
		} catch ( Throwable $exception ) {
			return new WP_Error(
				'wp_origin_error',
				self::get_throwable_message( $exception ),
				array( 'status' => 500 )
			);
		} finally {
			self::delete_directory( $repository_path );
		}
	}

	public static function serve_git_response( $served, $result, $request, $server ) {
		unset( $server );

		if ( ! $result instanceof WP_HTTP_Response ) {
			return $served;
		}

		$headers = $result->get_headers();
		if ( empty( $headers[ WP_Origin_Buffering_Response::MARKER_HEADER ] ) ) {
			return $served;
		}

		if ( ! headers_sent() ) {
			status_header( $result->get_status() );
			foreach ( $headers as $name => $value ) {
				if ( WP_Origin_Buffering_Response::MARKER_HEADER === $name ) {
					continue;
				}
				header( $name . ': ' . $value );
			}
		}

		echo $result->get_data();
		return true;
	}

	private static function build_protocol_error_response( $service, $message ) {
		$response = new WP_Origin_Buffering_Response();
		$response->send_header( 'Content-Type', 'application/x-' . $service . '-result' );
		$response->send_header( 'Cache-Control', 'no-cache' );
		$response->send_header( 'Git-Protocol', 'version=2' );
		$response->append_bytes(
			WordPress\Git\Protocol\GitProtocolEncoderPipe::encode_packet_line(
				'error ' . rtrim( $message ) . "\n",
				"\x03"
			) . '0000'
		);

		return $response->to_rest_response();
	}

	private static function build_git_path( WP_REST_Request $request ) {
		$path = $request->get_param( 'path' );
		if ( ! is_string( $path ) || '' === $path ) {
			$path = '/';
		}

		$query_params = $request->get_query_params();
		if ( '/info/refs' === $path && isset( $query_params['service'] ) ) {
			$path .= '?service=' . $query_params['service'];
		}

		return $path;
	}

	private static function is_push_request( $git_path ) {
		return '/git-receive-pack' === $git_path;
	}

	private static function open_repository() {
		$repository_path = trailingslashit( sys_get_temp_dir() ) . 'wp-origin-' . wp_generate_uuid4();
		$repository = new GitRepository(
			LocalFilesystem::create( $repository_path ),
			array(
				'default_branch' => self::DEFAULT_BRANCH,
			)
		);

		if ( ! $repository->get_config_value( 'user.name' ) ) {
			$repository->set_config_value( 'user.name', get_option( 'blogname', 'WP Origin' ) );
		}
		if ( ! $repository->get_config_value( 'user.email' ) ) {
			$repository->set_config_value( 'user.email', get_option( 'admin_email', 'wp-origin@example.com' ) );
		}
		$repository->set_branch_tip( 'HEAD', 'ref: refs/heads/' . self::DEFAULT_BRANCH );

		return array(
			'path'       => $repository_path,
			'repository' => $repository,
		);
	}

	private static function sync_repository_from_wordpress( GitRepository $repository ) {
		$exported_files = self::export_wordpress_content();
		$current_files  = array();

		try {
			$current_head = $repository->get_branch_tip( 'refs/heads/' . self::DEFAULT_BRANCH );
			if ( ! Commit::is_null_hash( $current_head ) ) {
				$current_files = self::read_markdown_files_from_commit( $repository, $current_head );
			}
		} catch ( GitException $exception ) {
			$current_head = Commit::NULL_HASH;
		}

		$updates = array();
		foreach ( $exported_files as $path => $contents ) {
			if ( ! isset( $current_files[ $path ] ) || $current_files[ $path ] !== $contents ) {
				$updates[ $path ] = $contents;
			}
		}

		$deletes = array();
		foreach ( $current_files as $path => $contents ) {
			if ( ! isset( $exported_files[ $path ] ) ) {
				$deletes[] = $path;
			}
		}

		if ( empty( $updates ) && empty( $deletes ) ) {
			return;
		}

		$commit_timestamp = self::EPOCH_TIMESTAMP;
		foreach ( $exported_files as $path => $contents ) {
			$metadata = self::parse_markdown_metadata( $contents );
			if ( isset( $metadata['modified_gmt'] ) ) {
				$maybe_timestamp = strtotime( $metadata['modified_gmt'] . ' UTC' );
				if ( false !== $maybe_timestamp ) {
					$commit_timestamp = max( $commit_timestamp, $maybe_timestamp );
					continue;
				}
			}
			if ( isset( $metadata['date_gmt'] ) ) {
				$maybe_timestamp = strtotime( $metadata['date_gmt'] . ' UTC' );
				if ( false !== $maybe_timestamp ) {
					$commit_timestamp = max( $commit_timestamp, $maybe_timestamp );
				}
			}
		}

		$repository->commit(
			array(
				'updates' => $updates,
				'deletes' => $deletes,
				'commit'  => array(
					'message'        => 'Sync from WordPress',
					'author_date'    => gmdate( Commit::DATE_FORMAT, $commit_timestamp ),
					'committer_date' => gmdate( Commit::DATE_FORMAT, $commit_timestamp ),
				),
			)
		);
	}

	private static function export_wordpress_content() {
		$posts = get_posts(
			array(
				'post_type'      => self::$supported_post_types,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		$files = array();
		foreach ( $posts as $post ) {
			if ( ! current_user_can( 'read_post', $post->ID ) ) {
				continue;
			}

			$metadata = array(
				'id'           => array( (string) $post->ID ),
				'type'         => array( $post->post_type ),
				'slug'         => array( $post->post_name ),
				'status'       => array( $post->post_status ),
				'title'        => array( $post->post_title ),
				'date_gmt'     => array( $post->post_date_gmt ),
				'modified_gmt' => array( $post->post_modified_gmt ),
			);

			$producer       = new MarkdownProducer(
				new BlocksWithMetadata(
					$post->post_content,
					$metadata
				)
			);
			$path           = self::build_markdown_path( $post->post_type, $post->post_name );
			$files[ $path ] = $producer->produce();
		}

		ksort( $files );

		return $files;
	}

	private static function build_markdown_path( $post_type, $slug ) {
		return ltrim( $post_type . '/' . $slug . '.md', '/' );
	}

	private static function apply_repository_changes_to_wordpress( GitRepository $repository, $old_commit, $new_commit ) {
		$old_files = Commit::is_null_hash( $old_commit ) ? array() : self::read_markdown_files_from_commit( $repository, $old_commit );
		$new_files = self::read_markdown_files_from_commit( $repository, $new_commit );

		$updated_post_ids = array();
		foreach ( $new_files as $path => $contents ) {
			if ( isset( $old_files[ $path ] ) && $old_files[ $path ] === $contents ) {
				continue;
			}

			$post_id = self::upsert_post_from_markdown( $path, $contents );
			if ( $post_id ) {
				$updated_post_ids[ $post_id ] = true;
			}
		}

		foreach ( $old_files as $path => $contents ) {
			if ( isset( $new_files[ $path ] ) ) {
				continue;
			}

			$metadata = self::parse_markdown_metadata( $contents );
			$post_id  = isset( $metadata['id'] ) ? intval( $metadata['id'] ) : 0;
			if ( ! $post_id ) {
				$post_id = self::find_post_id_by_path_metadata( $path, $metadata );
			}
			if ( ! $post_id || isset( $updated_post_ids[ $post_id ] ) ) {
				continue;
			}

			if ( isset( $metadata['modified_gmt'] ) ) {
				$current_modified = get_post_field( 'post_modified_gmt', $post_id );
				if ( $current_modified && $current_modified !== $metadata['modified_gmt'] ) {
					throw new Exception( 'Push rejected because a deleted post changed in WordPress. Pull the latest changes and try again.' );
				}
			}
			self::assert_can_edit_post( $post_id );

			if ( false === wp_trash_post( $post_id ) ) {
				throw new Exception( 'Push rejected because WordPress could not trash the deleted content.' );
			}
		}
	}

	private static function upsert_post_from_markdown( $path, $markdown ) {
		$post_type = self::path_to_post_type( $path );
		$slug      = self::path_to_slug( $path );
		$consumer  = new MarkdownConsumer( $markdown );
		$result    = $consumer->consume();
		$metadata  = array();
		foreach ( $result->get_all_metadata() as $key => $value ) {
			$metadata[ $key ] = is_array( $value ) ? reset( $value ) : $value;
		}

		if ( isset( $metadata['type'] ) && $metadata['type'] !== $post_type ) {
			throw new Exception( 'Push rejected because the file post type does not match its directory.' );
		}
		if ( isset( $metadata['slug'] ) && $metadata['slug'] !== $slug ) {
			throw new Exception( 'Push rejected because the file slug does not match its filename.' );
		}

		$post_id = isset( $metadata['id'] ) ? intval( $metadata['id'] ) : 0;
		if ( $post_id && get_post( $post_id ) ) {
			$existing_post = get_post( $post_id );
		} else {
			$post_id       = self::find_post_id_by_path_metadata( $path, $metadata );
			$existing_post = $post_id ? get_post( $post_id ) : null;
		}

		if ( $existing_post && isset( $metadata['modified_gmt'] ) && $existing_post->post_modified_gmt !== $metadata['modified_gmt'] ) {
			throw new Exception( 'Push rejected because WordPress content changed since the last pull.' );
		}

		if ( $existing_post ) {
			self::assert_can_edit_post( $existing_post->ID );
		} else {
			self::assert_can_create_post_type( $post_type );
		}

		$postarr = array(
			'post_type'    => $post_type,
			'post_name'    => isset( $metadata['slug'] ) ? $metadata['slug'] : $slug,
			'post_title'   => isset( $metadata['title'] ) ? $metadata['title'] : ucwords( str_replace( '-', ' ', $slug ) ),
			'post_status'  => isset( $metadata['status'] ) ? $metadata['status'] : 'draft',
			'post_content' => $result->get_block_markup(),
		);

		if ( isset( $metadata['date_gmt'] ) && '' !== $metadata['date_gmt'] ) {
			$postarr['post_date_gmt'] = $metadata['date_gmt'];
		}

		if ( $existing_post ) {
			$postarr['ID'] = $existing_post->ID;
			$post_id       = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$post_id = wp_insert_post( wp_slash( $postarr ), true );
		}

		if ( is_wp_error( $post_id ) ) {
			throw new Exception( $post_id->get_error_message() );
		}

		return $post_id;
	}

	private static function find_post_id_by_path_metadata( $path, $metadata ) {
		$post_type = self::path_to_post_type( $path );
		$slug      = isset( $metadata['slug'] ) ? $metadata['slug'] : self::path_to_slug( $path );
		$posts     = get_posts(
			array(
				'post_type'      => $post_type,
				'name'           => $slug,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		if ( empty( $posts ) ) {
			return 0;
		}

		return intval( $posts[0] );
	}

	private static function path_to_post_type( $path ) {
		$segments = explode( '/', ltrim( $path, '/' ) );
		if ( empty( $segments[0] ) || ! in_array( $segments[0], self::$supported_post_types, true ) ) {
			throw new Exception( 'Push rejected because the file path is outside the supported post type directories.' );
		}

		return $segments[0];
	}

	private static function path_to_slug( $path ) {
		$basename = basename( $path );
		if ( 'md' !== pathinfo( $basename, PATHINFO_EXTENSION ) ) {
			throw new Exception( 'Push rejected because only Markdown files are supported.' );
		}

		return pathinfo( $basename, PATHINFO_FILENAME );
	}

	private static function parse_markdown_metadata( $markdown ) {
		$consumer = new MarkdownConsumer( $markdown );
		$result   = $consumer->consume();
		$metadata = array();
		foreach ( $result->get_all_metadata() as $key => $value ) {
			$metadata[ $key ] = is_array( $value ) ? reset( $value ) : $value;
		}

		return $metadata;
	}

	private static function read_markdown_files_from_commit( GitRepository $repository, $commit_hash ) {
		$commit = $repository->read_object( $commit_hash )->as_commit();
		$files  = array();

		if ( Commit::is_null_hash( $commit->tree ) ) {
			return $files;
		}

		self::collect_tree_files( $repository, $commit->tree, '', $files );
		ksort( $files );

		return $files;
	}

	private static function collect_tree_files( GitRepository $repository, $tree_hash, $prefix, &$files ) {
		$tree = $repository->read_object( $tree_hash )->as_tree();
		foreach ( $tree->entries as $entry ) {
			$path = ltrim( $prefix . '/' . $entry->name, '/' );
			if ( TreeEntry::FILE_MODE_DIRECTORY === $entry->get_mode_bucket() ) {
				self::collect_tree_files( $repository, $entry->hash, $path, $files );
				continue;
			}
			if ( 'md' !== pathinfo( $path, PATHINFO_EXTENSION ) ) {
				continue;
			}
			$files[ $path ] = $repository->read_object( $entry->hash )->consume_all();
		}
	}

	private static function parse_push_header( $request_bytes ) {
		if ( ! preg_match( '/([0-9a-f]{40}) ([0-9a-f]{40}) refs\\/heads\\/' . self::DEFAULT_BRANCH . "\0/", $request_bytes, $matches ) ) {
			return false;
		}

		return array(
			'old_oid' => $matches[1],
			'new_oid' => $matches[2],
		);
	}

	private static function assert_can_edit_post( $post_id ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			throw new Exception( 'Push rejected because you do not have permission to edit one or more posts in this change.' );
		}
	}

	private static function assert_can_create_post_type( $post_type ) {
		$post_type_object = get_post_type_object( $post_type );
		$edit_posts_cap   = $post_type_object && isset( $post_type_object->cap->edit_posts ) ? $post_type_object->cap->edit_posts : 'edit_posts';
		if ( ! current_user_can( $edit_posts_cap ) ) {
			throw new Exception( 'Push rejected because you do not have permission to create this post type.' );
		}
	}

	private static function get_throwable_message( Throwable $throwable ) {
		$message = $throwable->getMessage();
		if ( '' === $message && isset( $throwable->code_str ) && is_string( $throwable->code_str ) && '' !== $throwable->code_str ) {
			$message = $throwable->code_str;
		}
		if ( '' === $message ) {
			$message = get_class( $throwable );
		}

		return $message;
	}

	private static function delete_directory( $path ) {
		if ( ! is_string( $path ) || '' === $path || ! is_dir( $path ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getPathname() );
			} else {
				unlink( $item->getPathname() );
			}
		}

		rmdir( $path );
	}
}
