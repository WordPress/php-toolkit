<?php

namespace WordPress\DataLiberation\EntityWriter;

use WordPress\DataLiberation\DataFormatConsumer\BlocksWithMetadata;
use WordPress\DataLiberation\DataLiberationException;
use WordPress\DataLiberation\ImportEntity;
use WordPress\Filesystem\Filesystem;

use function WordPress\Filesystem\wp_join_unix_paths;

class StaticPostFilesWriter implements EntityWriter {

	private $filesystem;
	private $state = self::STATE_WRITING;
	private $directory_scheme;
	private $parent_stack        = array();
	private $pending_parent_path = '/';
	private $pending_post;
	private $pending_metadata;
	private $static_content_producer_factory;
	private $file_extension;

	const STATE_WRITING    = 'writing';
	const STATE_FINALIZING = 'finalizing';
	const STATE_CLOSED     = 'closed';

	const SCHEME_DATE         = 'date';
	const SCHEME_PARENT_TRAIL = 'parent_trail';

	public function __construct( Filesystem $filesystem, $options = array() ) {
		$this->filesystem       = $filesystem;
		$this->directory_scheme = $options['directory_scheme'] ?? self::SCHEME_DATE;
		if ( self::SCHEME_DATE !== $this->directory_scheme && self::SCHEME_PARENT_TRAIL !== $this->directory_scheme ) {
			throw new DataLiberationException( 'Invalid directory scheme: ' . $this->directory_scheme );
		}
		if ( ! isset( $options['static_content_producer_factory'] ) ) {
			throw new DataLiberationException( 'static_content_producer_factory is required' );
		}
		$this->static_content_producer_factory = $options['static_content_producer_factory'];
		if ( ! isset( $options['file_extension'] ) ) {
			throw new DataLiberationException( 'file_extension is required' );
		}
		$this->file_extension = $options['file_extension'];
		if ( isset( $options['cursor'] ) ) {
			$this->restore_from_cursor( $options['cursor'] );
		}
	}

	public function append_entity( ImportEntity $entity ) {
		if ( self::STATE_CLOSED === $this->state ) {
			throw new DataLiberationException( 'Cannot write to a closed writer' );
		}

		$data = $entity->get_data();

		switch ( $entity->get_type() ) {
			case 'post':
				$post_data                = $entity->get_data();
				$post_data['post_parent'] = $post_data['post_parent'] ?? 0;
				$parent_id                = $post_data['post_parent'];

				// Update the parent stack.
				$pending_post_was_a_parent = true;
				while ( ! empty( $this->parent_stack ) && end( $this->parent_stack )['post_id'] !== $parent_id ) {
					array_pop( $this->parent_stack );
					$pending_post_was_a_parent = false;
				}

				if ( $this->pending_post ) {
					$this->finalize_pending_post( $pending_post_was_a_parent );
				}
				if ( self::SCHEME_PARENT_TRAIL === $this->directory_scheme ) {
					$this->pending_parent_path = $this->create_parent_trail_directory();
				} elseif ( self::SCHEME_DATE === $this->directory_scheme ) {
					$this->pending_parent_path = $this->create_date_based_directory( $post_data );
				}
				$this->pending_post     = $post_data;
				$this->pending_metadata = array();

				array_push( $this->parent_stack, $post_data );
				break;

			case 'post_meta':
				// Attach meta to the current pending post.
				$this->pending_metadata[ $data['meta_key'] ] = array( $data['meta_value'] );
				break;
		}
	}

	private function finalize_pending_post( $pending_post_was_a_parent ) {
		if ( ! $this->pending_post ) {
			return;
		}

		$path = $this->pending_parent_path;
		$slug = $this->slugify( $this->pending_post['post_title'] );
		if ( self::SCHEME_PARENT_TRAIL === $this->directory_scheme ) {
			if ( $pending_post_was_a_parent ) {
				$path = wp_join_unix_paths( $path, $slug, 'index.' . $this->file_extension );
			} else {
				$path = wp_join_unix_paths( $path, $slug . '.' . $this->file_extension );
			}
		} else {
			$path = wp_join_unix_paths( $path, $slug . '.' . $this->file_extension );
		}

		$this->filesystem->mkdir( dirname( $path ), array( 'recursive' => true ) );

		$content           = new BlocksWithMetadata( $this->pending_post['content'], $this->pending_metadata );
		$markdown_producer = call_user_func( $this->static_content_producer_factory, $content );
		$contents          = $markdown_producer->produce();

		$this->filesystem->put_contents( $path, $contents );
	}

	private function create_parent_trail_directory() {
		$base_path_segments = array( '/' );
		foreach ( $this->parent_stack as $parent_post ) {
			$base_path_segments[] = $this->slugify( $parent_post['post_title'] );
		}

		return wp_join_unix_paths( ...$base_path_segments );
	}

	private function create_date_based_directory( $post_data ) {
		$base_path_segments = explode( '-', substr( $post_data['post_date'], 0, 10 ) );

		return wp_join_unix_paths( ...$base_path_segments );
	}

	private function slugify( $title ) {
		return preg_replace( '/[^a-z0-9]+/i', '-', trim( strtolower( $title ) ) );
	}

	public function close_writing() {
		$this->state = self::STATE_FINALIZING;
		$this->finalize_pending_post( false );
		$this->state = self::STATE_CLOSED;
	}

	public function get_reentrancy_cursor(): string {
		throw new DataLiberationException( 'Not implemented' );
	}

	private function restore_from_cursor( $cursor ) {
		throw new DataLiberationException( 'Not implemented' );
	}
}
