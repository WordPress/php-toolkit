<?php

use WordPress\DataLiberation\EntityReader\WXREntityReader;
use WordPress\ByteStream\ReadStream\FileReadStream;

class WXR_Parser_Entity_Reader {
	public $authors       = array();
	public $posts         = array();
	public $categories    = array();
	public $tags          = array();
	public $terms         = array();
	public $base_url      = '';
	public $base_blog_url = '';
	public $version       = '';

	private $entity_map = array(
		'wp:comment'     => array(
			'type'   => 'comment',
			'fields' => array(
				'wp:comment_id'           => 'comment_id',
				'wp:comment_author'       => 'comment_author',
				'wp:comment_author_email' => 'comment_author_email',
				'wp:comment_author_url'   => 'comment_author_url',
				'wp:comment_author_IP'    => 'comment_author_IP',
				'wp:comment_date'         => 'comment_date',
				'wp:comment_date_gmt'     => 'comment_date_gmt',
				'wp:comment_content'      => 'comment_content',
				'wp:comment_approved'     => 'comment_approved',
				'wp:comment_type'         => 'comment_type',
				'wp:comment_parent'       => 'comment_parent',
				'wp:comment_user_id'      => 'comment_user_id',
			),
		),
		'wp:commentmeta' => array(
			'type'   => 'comment_meta',
			'fields' => array(
				'wp:meta_key'   => 'meta_key',
				'wp:meta_value' => 'meta_value',
			),
		),
		'wp:author'      => array(
			'type'   => 'user',
			'fields' => array(
				'wp:author_id'           => 'author_id',
				'wp:author_login'        => 'author_login',
				'wp:author_email'        => 'author_email',
				'wp:author_display_name' => 'author_display_name',
				'wp:author_first_name'   => 'author_first_name',
				'wp:author_last_name'    => 'author_last_name',
			),
		),
		'item'           => array(
			'type'   => 'post',
			'fields' => array(
				'title'                => 'post_title',
				'link'                 => 'link',
				'guid'                 => 'guid',
				'description'          => 'excerpt',
				'pubDate'              => 'pubDate',
				'dc:creator'           => 'post_author',
				'content:encoded'      => 'post_content',
				'excerpt:encoded'      => 'post_excerpt',
				'wp:post_id'           => 'post_id',
				'wp:status'            => 'status',
				'wp:post_date'         => 'post_date',
				'wp:post_date_gmt'     => 'post_date_gmt',
				'wp:post_modified'     => 'post_modified',
				'wp:post_modified_gmt' => 'post_modified_gmt',
				'wp:comment_status'    => 'comment_status',
				'wp:ping_status'       => 'ping_status',
				'wp:post_name'         => 'post_name',
				'wp:post_parent'       => 'post_parent',
				'wp:menu_order'        => 'menu_order',
				'wp:post_type'         => 'post_type',
				'wp:post_password'     => 'post_password',
				'wp:is_sticky'         => 'is_sticky',
				'wp:attachment_url'    => 'attachment_url',
			),
		),
		'wp:postmeta'    => array(
			'type'   => 'post_meta',
			'fields' => array(
				'wp:meta_key'   => 'key',
				'wp:meta_value' => 'value',
			),
		),
		'wp:term'        => array(
			'type'   => 'term',
			'fields' => array(
				'wp:term_id'       => 'term_id',
				'wp:term_taxonomy' => 'term_taxonomy',
				'wp:term_slug'     => 'slug',
				'wp:term_parent'   => 'term_parent',
				'wp:term_name'     => 'term_name',
			),
		),
		'wp:tag'         => array(
			'type'   => 'tag',
			'fields' => array(
				'wp:term_id'         => 'term_id',
				'wp:tag_slug'        => 'tag_slug',
				'wp:tag_name'        => 'tag_name',
				'wp:tag_description' => 'tag_description',
			),
		),
		'wp:category'    => array(
			'type'   => 'category',
			'fields' => array(
				'wp:category_nicename'    => 'category_nicename',
				'wp:category_parent'      => 'category_parent',
				'wp:cat_name'             => 'cat_name',
				'wp:category_description' => 'category_description',
			),
		),
	);

	public function parse( $file ) {
		if ( ! file_exists( $file ) ) {
			return new WP_Error( 'WXR_parse_error', __( 'WXR file does not exist', 'wordpress-importer' ) );
		}

		$this->base_url = '';
		$this->base_blog_url = '';
		$this->version = '';
		$this->authors = array();
		$this->posts = array();
		$this->categories = array();
		$this->tags = array();
		$this->terms = array();

		// The WXR version is not available in the entity reader, so we have to parse it manually.
		$xml = file_get_contents( $file );
		if ( ! $xml ) {
			return new WP_Error( 'WXR_parse_error', __( 'Could not read WXR file', 'wordpress-importer' ) );
		}
		preg_match( '|<wp:wxr_version>(\d+\.\d+)</wp:wxr_version>|', $xml, $matches );
		if ( $matches ) {
			$this->version = $matches[1];
		}

		$reader = WXREntityReader::create( null, null, $this->entity_map );
		$reader->append_bytes( $xml );
		$reader->input_finished();

		while ( $reader->next_entity() ) {
			$entity = $reader->get_entity();
			$data = $entity->get_data();
			$type = $entity->get_type();

			switch ( $type ) {
				case 'user':
					$this->authors[ $data['author_login'] ] = $data;
					break;
				case 'category':
					$this->categories[] = $data;
					break;
				case 'tag':
					$this->tags[] = $data;
					break;
				case 'term':
					$this->terms[] = $data;
					break;
				case 'post':
					if ( isset( $data['post_author'] ) && ! isset( $this->authors[ $data['post_author'] ] ) ) {
						$this->authors[ $data['post_author'] ] = array(
							'author_login' => $data['post_author'],
							'author_id' => count($this->authors) + 1,
						);
					}
					$this->posts[] = $data;
					break;
				case 'site_option':
					if ( 'home' === $data['option_name'] ) {
						$this->base_blog_url = $data['option_value'];
					} elseif ( 'siteurl' === $data['option_name'] ) {
						$this->base_url = $data['option_value'];
					}
					break;
			}
		}
		
		if ( $reader->is_paused_at_incomplete_input() ) {
			return new WP_Error( 'WXR_parse_error', __( 'WXR file is incomplete', 'wordpress-importer' ) );
		}
		
		if ( $reader->get_last_error() ) {
			return new WP_Error( 'WXR_parse_error', $reader->get_last_error() );
		}

		return array(
			'authors'       => $this->authors,
			'posts'         => $this->posts,
			'categories'    => $this->categories,
			'tags'          => $this->tags,
			'terms'         => $this->terms,
			'base_url'      => $this->base_url,
			'base_blog_url' => $this->base_blog_url,
			'version'       => $this->version,
		);
	}
}

