<?php

namespace WordPress\DataLiberation\EntityWriter;

use WordPress\ByteStream\WriteStream\ByteWriteStream;
use WordPress\DataLiberation\DataLiberationException;
use WordPress\DataLiberation\ImportEntity;
use WordPress\XML\XMLProcessor;

class WXRWriter implements EntityWriter {

	private $write_stream;
	private $state     = self::STATE_WRITING;
	private $open_tags = array();

	const STATE_NEW     = 'new';
	const STATE_WRITING = 'writing';
	const STATE_CLOSED  = 'closed';

	public function __construct( ByteWriteStream $write_stream, $cursor = null ) {
		$this->write_stream = $write_stream;

		if ( $cursor === null ) {
			$this->write_stream->append_bytes( "<?xml version=\"1.0\" encoding=\"UTF-8\" ?>\n<rss version=\"2.0\" xmlns:excerpt=\"http://wordpress.org/export/1.2/excerpt/\" xmlns:content=\"http://purl.org/rss/1.0/modules/content/\" xmlns:wfw=\"http://wellformedweb.org/CommentAPI/\" xmlns:dc=\"http://purl.org/dc/elements/1.1/\" xmlns:wp=\"http://wordpress.org/export/1.2/\">\n<channel>\n" );
		} else {
			$this->open_tags = json_decode( $cursor, true )['open_tags'];
		}
	}

	public function append_entity( ImportEntity $entity ) {
		if ( $this->state === self::STATE_CLOSED ) {
			throw new DataLiberationException( 'Cannot write to a closed writer' );
		}

		if ( $entity->get_type() !== 'comment' && ! empty( $this->open_tags ) && end( $this->open_tags ) === 'wp:comments' ) {
			$this->write_stream->append_bytes( "</wp:comments>\n" );
			array_pop( $this->open_tags );
		}

		switch ( $entity->get_type() ) {
			case 'post':
				if ( ! empty( $this->open_tags ) && end( $this->open_tags ) === 'item' ) {
					$this->write_stream->append_bytes( "</item>\n" );
					array_pop( $this->open_tags );
				}
				$this->write_stream->append_bytes( "<item>\n" );
				$this->open_tags[] = 'item';
				$this->append_if_not_empty( 'title', $entity->get_data()['post_title'] );
				$this->append_if_not_empty( 'pubDate', $entity->get_data()['post_date'] );
				$this->append_if_not_empty( 'guid', $entity->get_data()['guid'] );
				$this->append_if_not_empty( 'description', $entity->get_data()['description'] );
				$this->append_if_not_empty( 'content:encoded', $entity->get_data()['content'] );
				$this->append_if_not_empty( 'excerpt:encoded', $entity->get_data()['excerpt'] );
				$this->append_if_not_empty( 'wp:post_id', $entity->get_data()['post_id'] );
				$this->append_if_not_empty( 'wp:post_date', $entity->get_data()['post_date'] );
				$this->append_if_not_empty( 'wp:post_date_gmt', $entity->get_data()['post_date_gmt'] );
				$this->append_if_not_empty( 'wp:comment_status', $entity->get_data()['comment_status'] );
				$this->append_if_not_empty( 'wp:ping_status', $entity->get_data()['ping_status'] );
				$this->append_if_not_empty( 'wp:post_name', $entity->get_data()['post_name'] );
				$this->append_if_not_empty( 'wp:status', $entity->get_data()['status'] );
				$this->append_if_not_empty( 'wp:post_parent', $entity->get_data()['post_parent'] );
				$this->append_if_not_empty( 'wp:menu_order', $entity->get_data()['menu_order'] );
				$this->append_if_not_empty( 'wp:post_type', $entity->get_data()['post_type'] );
				$this->append_if_not_empty( 'wp:post_password', $entity->get_data()['post_password'] );
				$this->append_if_not_empty( 'wp:is_sticky', $entity->get_data()['is_sticky'] );
				break;
			case 'post_meta':
				if ( empty( $this->open_tags ) || end( $this->open_tags ) !== 'item' ) {
					throw new DataLiberationException( 'Cannot append post_meta without a corresponding post' );
				}
				$meta = $entity->get_data();
				$this->write_stream->append_bytes( "<wp:postmeta>\n" );
				$this->append_if_not_empty( 'wp:meta_key', $meta['meta_key'] );
				$this->append_if_not_empty( 'wp:meta_value', $meta['meta_value'] );
				$this->write_stream->append_bytes( "</wp:postmeta>\n" );
				break;
			case 'term':
				if ( empty( $this->open_tags ) || end( $this->open_tags ) !== 'item' ) {
					throw new DataLiberationException( 'Cannot append term without a corresponding post' );
				}
				$term = $entity->get_data();
				$this->write_stream->append_bytes( "<wp:term>\n" );
				$this->append_if_not_empty( 'wp:term_id', $term['term_id'] );
				$this->append_if_not_empty( 'wp:term_taxonomy', $term['taxonomy'] );
				$this->append_if_not_empty( 'wp:term_slug', $term['slug'] );
				$this->append_if_not_empty( 'wp:term_parent', $term['parent'] );
				$this->write_stream->append_bytes( "</wp:term>\n" );
				break;
			case 'comment':
				if ( empty( $this->open_tags ) ) {
					throw new DataLiberationException( 'Cannot append comment without a corresponding post' );
				}
				if ( end( $this->open_tags ) === 'item' ) {
					$this->write_stream->append_bytes( "<wp:comments>\n" );
					$this->open_tags[] = 'wp:comments';
				}
				if ( end( $this->open_tags ) !== 'wp:comments' ) {
					throw new DataLiberationException( 'Cannot append comment outside of a comment list' );
				}
				$comment = $entity->get_data();
				$this->write_stream->append_bytes( "<wp:comment>\n" );
				$this->append_if_not_empty( 'wp:comment_id', $comment['comment_id'] );
				$this->append_if_not_empty( 'wp:comment_author', $comment['comment_author'] );
				$this->append_if_not_empty( 'wp:comment_author_email', $comment['comment_author_email'] );
				$this->append_if_not_empty( 'wp:comment_author_url', $comment['comment_author_url'] );
				$this->append_if_not_empty( 'wp:comment_author_IP', $comment['comment_author_IP'] );
				$this->append_if_not_empty( 'wp:comment_date', $comment['comment_date'] );
				$this->append_if_not_empty( 'wp:comment_date_gmt', $comment['comment_date_gmt'] );
				$this->append_if_not_empty( 'wp:comment_content', $comment['comment_content'] );
				$this->append_if_not_empty( 'wp:comment_approved', $comment['comment_approved'] );
				$this->append_if_not_empty( 'wp:comment_type', $comment['comment_type'] );
				$this->append_if_not_empty( 'wp:comment_parent', $comment['comment_parent'] );
				$this->append_if_not_empty( 'wp:comment_user_id', $comment['comment_user_id'] );
				$this->write_stream->append_bytes( "</wp:comment>\n" );
				break;
		}
	}

	private function append_if_not_empty( $tag_name, &$content ) {
		if ( $content !== null ) {
			$this->write_stream->append_bytes( $this->create_xml_tag( $tag_name, $content ) );
		}
	}

	private function create_xml_tag( $tag_name, $content ) {
		$xml = XMLProcessor::create_from_string(
			"<$tag_name>text</$tag_name>\n",
			null,
			'UTF-8',
			array(
				'excerpt' => 'http://wordpress.org/export/1.2/excerpt/',
				'content' => 'http://purl.org/rss/1.0/modules/content/',
				'wfw' => 'http://wellformedweb.org/CommentAPI/',
				'dc' => 'http://purl.org/dc/elements/1.1/',
				'wp' => 'http://wordpress.org/export/1.2/',
			)
		);
		$xml->next_token(); // Move to the opening tag
		$xml->next_token(); // Move to the text node
		$xml->set_modifiable_text( $content );

		return $xml->get_updated_xml();
	}

	public function finalize() {
		while ( ! empty( $this->open_tags ) ) {
			$tag = array_pop( $this->open_tags );
			$this->write_stream->append_bytes( "</$tag>\n" );
		}
		$this->write_stream->append_bytes( "</channel>\n</rss>\n" );
	}

	public function close_writing() {
		$this->state = self::STATE_CLOSED;
	}

	public function get_reentrancy_cursor(): string {
		return json_encode( array( 'open_tags' => $this->open_tags ) );
	}
}
