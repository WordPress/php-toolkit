<?php
/**
 * WordPress eXtended RSS file parser implementations
 *
 * @package WordPress
 * @subpackage Importer
 */

/**
 * WXR Parser that uses the XMLProcessor component.
 */
class WXR_Parser_XML_Processor {
	public $authors       = array();
	public $posts         = array();
	public $categories    = array();
	public $tags          = array();
	public $terms         = array();
	public $base_url      = '';
	public $base_blog_url = '';

	/**
	 * Parse a WXR file
	 *
	 * @param string $file Path to WXR file
	 * @return array|WP_Error Parsed data or error object
	 */
	public function parse( $file ) {
		if ( ! file_exists( $file ) ) {
			return new WP_Error( 'WXR_parse_error', __( 'WXR file does not exist', 'wordpress-importer' ) );
		}

		$xml_content = file_get_contents( $file );
		if ( false === $xml_content ) {
			return new WP_Error( 'WXR_parse_error', __( 'Could not read WXR file', 'wordpress-importer' ) );
		}

		$processor = WordPress\XML\XMLProcessor::create_from_string( $xml_content );
		if ( false === $processor ) {
			return new WP_Error( 'WXR_parse_error', __( 'Could not create XML processor', 'wordpress-importer' ) );
		}

		// Initialize variables
		$this->authors = array();
		$this->posts = array();
		$this->categories = array();
		$this->tags = array();
		$this->terms = array();
		$this->base_url = '';
		$this->base_blog_url = '';
		$wxr_version = '';

		// Parse the XML document
		while ( $processor->next_tag() ) {
			$tag_name = $processor->get_tag();

			switch ( $tag_name ) {
				case 'wp:wxr_version':
					$wxr_version = $this->get_tag_content( $processor );
					break;

				case 'wp:base_site_url':
					$this->base_url = $this->get_tag_content( $processor );
					if ( empty( $this->base_blog_url ) ) {
						$this->base_blog_url = $this->base_url;
					}
					break;

				case 'wp:base_blog_url':
					$this->base_blog_url = $this->get_tag_content( $processor );
					break;

				case 'wp:author':
					$author = $this->parse_author( $processor );
					if ( ! empty( $author['author_login'] ) ) {
						$this->authors[ $author['author_login'] ] = $author;
					}
					break;

				case 'wp:category':
					$category = $this->parse_category( $processor );
					if ( ! empty( $category ) ) {
						$this->categories[] = $category;
					}
					break;

				case 'wp:tag':
					$tag = $this->parse_tag( $processor );
					if ( ! empty( $tag ) ) {
						$this->tags[] = $tag;
					}
					break;

				case 'wp:term':
					$term = $this->parse_term( $processor );
					if ( ! empty( $term ) ) {
						$this->terms[] = $term;
					}
					break;

				case 'item':
					$post = $this->parse_item( $processor );
					if ( ! empty( $post ) ) {
						$this->posts[] = $post;
					}
					break;
			}
		}

		// Validate WXR version
		if ( empty( $wxr_version ) || ! preg_match( '/^\d+\.\d+$/', $wxr_version ) ) {
			return new WP_Error( 'WXR_parse_error', __( 'This does not appear to be a WXR file, missing/invalid WXR version number', 'wordpress-importer' ) );
		}

		return array(
			'authors'       => $this->authors,
			'posts'         => $this->posts,
			'categories'    => $this->categories,
			'tags'          => $this->tags,
			'terms'         => $this->terms,
			'base_url'      => $this->base_url,
			'base_blog_url' => $this->base_blog_url,
			'version'       => $wxr_version,
		);
	}

	/**
	 * Get the text content of the current tag
	 *
	 * @param WordPress\XML\XMLProcessor $processor
	 * @return string
	 */
	private function get_tag_content( $processor ) {
		$content = '';
		$tag_name = $processor->get_tag();
		$depth = 1;

		while ( $processor->next_token() && $depth > 0 ) {
			if ( '#tag' === $processor->get_token_type() ) {
				if ( $processor->get_tag() === $tag_name ) {
					if ( $processor->is_tag_closer() ) {
						$depth--;
					} else {
						$depth++;
					}
				}
			} elseif ( '#text' === $processor->get_token_type() || '#cdata-section' === $processor->get_token_type() ) {
				if ( $depth === 1 ) {
					$content .= $processor->get_modifiable_text();
				}
			}
		}

		return trim( $content );
	}

	/**
	 * Parse author data
	 *
	 * @param WordPress\XML\XMLProcessor $processor
	 * @return array
	 */
	private function parse_author( $processor ) {
		$author = array();
		$depth = 1;

		while ( $processor->next_token() && $depth > 0 ) {
			if ( '#tag' === $processor->get_token_type() ) {
				$tag_name = $processor->get_tag();

				if ( 'wp:author' === $tag_name ) {
					if ( $processor->is_tag_closer() ) {
						$depth--;
					} else {
						$depth++;
					}
					continue;
				}

				if ( ! $processor->is_tag_closer() ) {
					switch ( $tag_name ) {
						case 'wp:author_id':
							$author['author_id'] = $this->get_tag_content( $processor );
							break;
						case 'wp:author_login':
							$author['author_login'] = $this->get_tag_content( $processor );
							break;
						case 'wp:author_email':
							$author['author_email'] = $this->get_tag_content( $processor );
							break;
						case 'wp:author_display_name':
							$author['author_display_name'] = $this->get_tag_content( $processor );
							break;
						case 'wp:author_first_name':
							$author['author_first_name'] = $this->get_tag_content( $processor );
							break;
						case 'wp:author_last_name':
							$author['author_last_name'] = $this->get_tag_content( $processor );
							break;
					}
				}
			}
		}

		return $author;
	}

	/**
	 * Parse category data
	 *
	 * @param WordPress\XML\XMLProcessor $processor
	 * @return array
	 */
	private function parse_category( $processor ) {
		$category = array();
		$depth = 1;

		while ( $processor->next_token() && $depth > 0 ) {
			if ( '#tag' === $processor->get_token_type() ) {
				$tag_name = $processor->get_tag();

				if ( 'wp:category' === $tag_name ) {
					if ( $processor->is_tag_closer() ) {
						$depth--;
					} else {
						$depth++;
					}
					continue;
				}

				if ( ! $processor->is_tag_closer() ) {
					switch ( $tag_name ) {
						case 'wp:term_id':
							$category['term_id'] = $this->get_tag_content( $processor );
							break;
						case 'wp:cat_name':
							$category['cat_name'] = $this->get_tag_content( $processor );
							break;
						case 'wp:category_nicename':
							$category['category_nicename'] = $this->get_tag_content( $processor );
							break;
						case 'wp:category_parent':
							$category['category_parent'] = $this->get_tag_content( $processor );
							break;
						case 'wp:category_description':
							$category['category_description'] = $this->get_tag_content( $processor );
							break;
						case 'wp:termmeta':
							if ( ! isset( $category['termmeta'] ) ) {
								$category['termmeta'] = array();
							}
							$category['termmeta'][] = $this->parse_meta( $processor, 'wp:termmeta' );
							break;
					}
				}
			}
		}

		return $category;
	}

	/**
	 * Parse tag data
	 *
	 * @param WordPress\XML\XMLProcessor $processor
	 * @return array
	 */
	private function parse_tag( $processor ) {
		$tag = array();
		$depth = 1;

		while ( $processor->next_token() && $depth > 0 ) {
			if ( '#tag' === $processor->get_token_type() ) {
				$tag_name = $processor->get_tag();

				if ( 'wp:tag' === $tag_name ) {
					if ( $processor->is_tag_closer() ) {
						$depth--;
					} else {
						$depth++;
					}
					continue;
				}

				if ( ! $processor->is_tag_closer() ) {
					switch ( $tag_name ) {
						case 'wp:term_id':
							$tag['term_id'] = $this->get_tag_content( $processor );
							break;
						case 'wp:tag_name':
							$tag['tag_name'] = $this->get_tag_content( $processor );
							break;
						case 'wp:tag_slug':
							$tag['tag_slug'] = $this->get_tag_content( $processor );
							break;
						case 'wp:tag_description':
							$tag['tag_description'] = $this->get_tag_content( $processor );
							break;
						case 'wp:termmeta':
							if ( ! isset( $tag['termmeta'] ) ) {
								$tag['termmeta'] = array();
							}
							$tag['termmeta'][] = $this->parse_meta( $processor, 'wp:termmeta' );
							break;
					}
				}
			}
		}

		return $tag;
	}

	/**
	 * Parse term data
	 *
	 * @param WordPress\XML\XMLProcessor $processor
	 * @return array
	 */
	private function parse_term( $processor ) {
		$term = array();
		$depth = 1;

		while ( $processor->next_token() && $depth > 0 ) {
			if ( '#tag' === $processor->get_token_type() ) {
				$tag_name = $processor->get_tag();

				if ( 'wp:term' === $tag_name ) {
					if ( $processor->is_tag_closer() ) {
						$depth--;
					} else {
						$depth++;
					}
					continue;
				}

				if ( ! $processor->is_tag_closer() ) {
					switch ( $tag_name ) {
						case 'wp:term_id':
							$term['term_id'] = $this->get_tag_content( $processor );
							break;
						case 'wp:term_taxonomy':
							$term['term_taxonomy'] = $this->get_tag_content( $processor );
							break;
						case 'wp:term_slug':
							$term['slug'] = $this->get_tag_content( $processor );
							break;
						case 'wp:term_parent':
							$term['term_parent'] = $this->get_tag_content( $processor );
							break;
						case 'wp:term_name':
							$term['term_name'] = $this->get_tag_content( $processor );
							break;
						case 'wp:term_description':
							$term['term_description'] = $this->get_tag_content( $processor );
							break;
						case 'wp:termmeta':
							if ( ! isset( $term['termmeta'] ) ) {
								$term['termmeta'] = array();
							}
							$term['termmeta'][] = $this->parse_meta( $processor, 'wp:termmeta' );
							break;
					}
				}
			}
		}

		return $term;
	}

	/**
	 * Parse item (post) data
	 *
	 * @param WordPress\XML\XMLProcessor $processor
	 * @return array
	 */
	private function parse_item( $processor ) {
		$post = array();
		$post_comments = array();
		$post_terms = array();
		$depth = 1;

		while ( $processor->next_token() && $depth > 0 ) {
			if ( '#tag' === $processor->get_token_type() ) {
				$tag_name = $processor->get_tag();

				if ( 'item' === $tag_name ) {
					if ( $processor->is_tag_closer() ) {
						$depth--;
					} else {
						$depth++;
					}
					continue;
				}

				if ( ! $processor->is_tag_closer() ) {
					switch ( $tag_name ) {
						case 'title':
							$post['post_title'] = $this->get_tag_content( $processor );
							break;
						case 'guid':
							$post['guid'] = $this->get_tag_content( $processor );
							break;
						case 'dc:creator':
							$post['post_author'] = $this->get_tag_content( $processor );
							break;
						case 'content:encoded':
							$content = $this->get_tag_content( $processor );
							// Normalize tags and line breaks like the regex parser
							$content = preg_replace_callback( '|<(/?[A-Z]+)|', array( $this, '_normalize_tag' ), $content );
							$content = str_replace( '<br>', '<br />', $content );
							$content = str_replace( '<hr>', '<hr />', $content );
							$post['post_content'] = $content;
							break;
						case 'excerpt:encoded':
							$excerpt = $this->get_tag_content( $processor );
							// Normalize tags and line breaks like the regex parser
							$excerpt = preg_replace_callback( '|<(/?[A-Z]+)|', array( $this, '_normalize_tag' ), $excerpt );
							$excerpt = str_replace( '<br>', '<br />', $excerpt );
							$excerpt = str_replace( '<hr>', '<hr />', $excerpt );
							$post['post_excerpt'] = $excerpt;
							break;
						case 'wp:post_id':
							$post['post_id'] = $this->get_tag_content( $processor );
							break;
						case 'wp:post_date':
							$post['post_date'] = $this->get_tag_content( $processor );
							break;
						case 'wp:post_date_gmt':
							$post['post_date_gmt'] = $this->get_tag_content( $processor );
							break;
						case 'wp:comment_status':
							$post['comment_status'] = $this->get_tag_content( $processor );
							break;
						case 'wp:ping_status':
							$post['ping_status'] = $this->get_tag_content( $processor );
							break;
						case 'wp:status':
							$post['status'] = $this->get_tag_content( $processor );
							break;
						case 'wp:post_name':
							$post['post_name'] = $this->get_tag_content( $processor );
							break;
						case 'wp:post_parent':
							$post['post_parent'] = $this->get_tag_content( $processor );
							break;
						case 'wp:menu_order':
							$post['menu_order'] = $this->get_tag_content( $processor );
							break;
						case 'wp:post_type':
							$post['post_type'] = $this->get_tag_content( $processor );
							break;
						case 'wp:post_password':
							$post['post_password'] = $this->get_tag_content( $processor );
							break;
						case 'wp:is_sticky':
							$post['is_sticky'] = $this->get_tag_content( $processor );
							break;
						case 'wp:attachment_url':
							$post['attachment_url'] = $this->get_tag_content( $processor );
							break;
						case 'category':
							$term = $this->parse_category_term( $processor );
							if ( ! empty( $term ) ) {
								$post_terms[] = $term;
							}
							break;
						case 'wp:comment':
							$comment = $this->parse_comment( $processor );
							if ( ! empty( $comment ) ) {
								$post_comments[] = $comment;
							}
							break;
						case 'wp:postmeta':
							if ( ! isset( $post['postmeta'] ) ) {
								$post['postmeta'] = array();
							}
							$post['postmeta'][] = $this->parse_meta( $processor, 'wp:postmeta' );
							break;
					}
				}
			}
		}

		if ( ! empty( $post_terms ) ) {
			$post['terms'] = $post_terms;
		}

		if ( ! empty( $post_comments ) ) {
			$post['comments'] = $post_comments;
		}

		if ( isset( $post['post_author'] ) && ! isset( $this->authors[ $post['post_author'] ] ) ) {
			$this->authors[ $post['post_author'] ] = array(
				'author_login' => $post['post_author'],
			);
		}

		return $post;
	}

	/**
	 * Parse category term from item
	 *
	 * @param WordPress\XML\XMLProcessor $processor
	 * @return array
	 */
	private function parse_category_term( $processor ) {
		$domain = $processor->get_attribute( 'domain' );
		$nicename = $processor->get_attribute( 'nicename' );
		$name = $this->get_tag_content( $processor );

		// Clean up CDATA wrapper if present
		$name = str_replace( array( '<![CDATA[', ']]>' ), '', $name );

		return array(
			'slug'   => $nicename,
			'domain' => $domain,
			'name'   => $name,
		);
	}

	/**
	 * Parse comment data
	 *
	 * @param WordPress\XML\XMLProcessor $processor
	 * @return array
	 */
	private function parse_comment( $processor ) {
		$comment = array();
		$depth = 1;

		while ( $processor->next_token() && $depth > 0 ) {
			if ( '#tag' === $processor->get_token_type() ) {
				$tag_name = $processor->get_tag();

				if ( 'wp:comment' === $tag_name ) {
					if ( $processor->is_tag_closer() ) {
						$depth--;
					} else {
						$depth++;
					}
					continue;
				}

				if ( ! $processor->is_tag_closer() ) {
					switch ( $tag_name ) {
						case 'wp:comment_id':
							$comment['comment_id'] = $this->get_tag_content( $processor );
							break;
						case 'wp:comment_author':
							$comment['comment_author'] = $this->get_tag_content( $processor );
							break;
						case 'wp:comment_author_email':
							$comment['comment_author_email'] = $this->get_tag_content( $processor );
							break;
						case 'wp:comment_author_IP':
							$comment['comment_author_IP'] = $this->get_tag_content( $processor );
							break;
						case 'wp:comment_author_url':
							$comment['comment_author_url'] = $this->get_tag_content( $processor );
							break;
						case 'wp:comment_date':
							$comment['comment_date'] = $this->get_tag_content( $processor );
							break;
						case 'wp:comment_date_gmt':
							$comment['comment_date_gmt'] = $this->get_tag_content( $processor );
							break;
						case 'wp:comment_content':
							$comment['comment_content'] = $this->get_tag_content( $processor );
							break;
						case 'wp:comment_approved':
							$comment['comment_approved'] = $this->get_tag_content( $processor );
							break;
						case 'wp:comment_type':
							$comment['comment_type'] = $this->get_tag_content( $processor );
							break;
						case 'wp:comment_parent':
							$comment['comment_parent'] = $this->get_tag_content( $processor );
							break;
						case 'wp:comment_user_id':
							$comment['comment_user_id'] = $this->get_tag_content( $processor );
							break;
						case 'wp:commentmeta':
							if ( ! isset( $comment['commentmeta'] ) ) {
								$comment['commentmeta'] = array();
							}
							$comment['commentmeta'][] = $this->parse_meta( $processor, 'wp:commentmeta' );
							break;
					}
				}
			}
		}

		return $comment;
	}

	/**
	 * Parse meta data (postmeta, commentmeta, termmeta)
	 *
	 * @param WordPress\XML\XMLProcessor $processor
	 * @param string $meta_tag_name
	 * @return array
	 */
	private function parse_meta( $processor, $meta_tag_name ) {
		$meta = array();
		$depth = 1;

		while ( $processor->next_token() && $depth > 0 ) {
			if ( '#tag' === $processor->get_token_type() ) {
				$tag_name = $processor->get_tag();

				if ( $meta_tag_name === $tag_name ) {
					if ( $processor->is_tag_closer() ) {
						$depth--;
					} else {
						$depth++;
					}
					continue;
				}

				if ( ! $processor->is_tag_closer() ) {
					switch ( $tag_name ) {
						case 'wp:meta_key':
							$meta['key'] = $this->get_tag_content( $processor );
							break;
						case 'wp:meta_value':
							$meta['value'] = $this->get_tag_content( $processor );
							break;
					}
				}
			}
		}

		return $meta;
	}

	/**
	 * Normalize tag callback for content processing
	 *
	 * @param array $matches
	 * @return string
	 */
	public function _normalize_tag( $matches ) {
		return '<' . strtolower( $matches[1] );
	}
}
