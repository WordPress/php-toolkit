<?php

namespace WordPress\DataLiberation\EntityReader;

use InvalidArgumentException;
use WordPress\DataLiberation\DataFormatConsumer\BlocksWithMetadata;
use WordPress\DataLiberation\DataFormatConsumer\MarkupProcessorConsumer;
use WordPress\DataLiberation\ImportEntity;
use WordPress\DataLiberation\Importer\ImportUtils;
use WordPress\DataLiberation\URL\WPURL;
use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\Visitor\FilesystemVisitor;
use WordPress\Markdown\MarkdownConsumer;
use WordPress\XML\XMLProcessor;
use WP_HTML_Processor;

use function WordPress\Filesystem\wp_join_unix_paths;

/**
 * Recursively reads files from a filesystem and converts them into WordPress post entities.
 *
 * It identifies the content files, maintains parent-child relationships, and optionally creates
 * index pages. It also converts the content from the data format it was read in to WordPress
 * block markup and metadata.
 *
 * **Usage Example:**
 *
 * ```php
 * $filesystem = new WP_Local_Filesystem();
 * $options = [
 *     'first_post_id' => 100,
 *     'filter_pattern' => '/\.md$/',
 *     'index_file_pattern' => 'index\.md',
 *     'base_url' => 'https://example.com',
 * ];
 * $reader = FilesystemEntityReader::create($filesystem, $options);
 * while ($reader->next_filesystem_node()) {
 *     $current_filesystem_node = $reader->get_current_filesystem_node();
 *     // Process the node, e.g., create a post from it.
 * }
 * ```
 */
class FilesystemEntityReader implements EntityReader {
	/**
	 * The filesystem instance to read from.
	 *
	 * @var Filesystem
	 */
	private $fs;

	/**
	 * Visitor for traversing the filesystem.
	 *
	 * @var FilesystemVisitor
	 */
	private $file_visitor;

	/**
	 * The current filesystem node being processed.
	 *
	 * @var array|null
	 */
	private $current_filesystem_node;

	/**
	 * The enqueued entities to emit.
	 *
	 * @var array
	 */
	private $entities = array();

	/**
	 * The current WordPress entity.
	 *
	 * @var ImportEntity|null
	 */
	private $current_entity;

	/**
	 * Files pending processing.
	 *
	 * @var array
	 */
	private $pending_files = array();

	/**
	 * A filename to emit as the next directory index. If null, there's no matching
	 * directory index file and a placeholder file will be created. If false,
	 * we're not emitting directory indexes at all.
	 *
	 * @var string|false|null
	 */
	private $pending_directory_index;

	/**
	 * A stack of post IDs emitted at each directory depth up to the currently processed
	 * directory.
	 *
	 * @var array
	 */
	private $parent_ids = array();

	/**
	 * The next post ID to assign.
	 *
	 * @var int
	 */
	private $next_post_id;

	/**
	 * Flag to determine if an index page should be created when no index file is found
	 * in a directory.
	 *
	 * @var bool
	 */
	private $create_index_pages;

	/**
	 * Counter for entities read so far.
	 *
	 * @var int
	 */
	private $fs_nodes_emited_so_far = 0;

	/**
	 * Pattern to filter files.
	 *
	 * @var string
	 */
	private $filter_pattern = '##';

	/**
	 * Pattern to identify index files. Matches no filenames by default to enforce
	 * creation of an empty index page.
	 *
	 * @var string
	 */
	private $index_file_pattern = '#$^#';

	/**
	 * Flag to indicate if processing is finished.
	 *
	 * @var bool
	 */
	private $is_finished = false;

	/**
	 * The post type to emit.
	 */
	private $post_type;

	/**
	 * Flag to indicate if processing is finished.
	 *
	 * @var bool
	 */
	private $finished = false;

	/**
	 * The root URL of the imported site.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Initializes the reader with filesystem and options.
	 *
	 * @param  Filesystem $filesystem  The filesystem to traverse.
	 * @param  array      $options  Configuration options.
	 */
	public function __construct(
		Filesystem $filesystem,
		$options
	) {
		if ( ! isset( $options['first_post_id'] ) ) {
			$options['first_post_id'] = 2;
			if ( function_exists( 'get_posts' ) ) {
				$max_id = get_posts(
					array(
						'post_type'      => 'any',
						'posts_per_page' => 1,
						'fields'         => 'ids',
						'orderby'        => 'ID',
						'order'          => 'DESC',
					)
				);
				if ( ! empty( $max_id ) ) {
					$options['first_post_id'] = $max_id[0] + 1;
				}
			}
		}
		if ( $options['first_post_id'] === 1 ) {
			throw new InvalidArgumentException( 'First node ID must be greater than 1' );
		}
		if ( ! isset( $options['base_url'] ) ) {
			throw new InvalidArgumentException( 'The "base_url" option is required. It should contain the root URL of the imported site.' );
		}

		$this->fs                 = $filesystem;
		$this->file_visitor       = new FilesystemVisitor( $filesystem );
		$this->post_type          = $options['post_type'] ?? 'page';
		$this->create_index_pages = $options['create_index_pages'] ?? true;
		$this->next_post_id       = $options['first_post_id'];
		$this->filter_pattern     = $options['filter_pattern'] ?? '#\.(?:md|html|xhtml|png|jpg|jpeg|gif|svg|webp|mp4)$#';
		if ( isset( $options['index_file_pattern'] ) ) {
			$this->index_file_pattern = $options['index_file_pattern'];
		}
		$this->base_url = $options['base_url'];
		if ( isset( $options['root_parent_id'] ) ) {
			$this->parent_ids[- 1] = $options['root_parent_id'];
		}
	}

	/**
	 * Get the current entity.
	 *
	 * @return ImportEntity|null The current entity or null if none.
	 */
	public function get_entity() {
		return $this->current_entity;
	}

	/**
	 * Check if the reader has finished reading the filesystem.
	 *
	 * @return bool Whether the reader has finished.
	 */
	public function is_finished(): bool {
		return $this->finished;
	}

	/**
	 * Read the next WordPress post or metadata entity from the filesystem.
	 *
	 * @return bool Whether an entity was read.
	 */
	public function next_entity(): bool {
		if ( $this->is_finished ) {
			return false;
		}

		while ( true ) {
			while ( count( $this->entities ) > 0 ) {
				$this->current_entity = array_shift( $this->entities );

				return true;
			}

			if ( ! $this->next_filesystem_node() ) {
				$this->finished = true;

				return false;
			}

			$post_tree_node = $this->get_current_filesystem_node();
			$metadata       = array(
				'post_id'         => $post_tree_node['post_id'],
				'post_parent'     => $post_tree_node['parent_id'],
				'post_title'      => $post_tree_node['post_title'] ?? null,
				'post_status'     => 'publish',
				'post_type'       => $this->post_type,
				'guid'            => $post_tree_node['local_file_path'],
				'local_file_path' => $post_tree_node['local_file_path'],
				'link'            => WPURL::append_path( $this->base_url, $post_tree_node['local_file_path'] ),
			);
			if ( $post_tree_node['type'] === 'file' ) {
				$extension = pathinfo( $post_tree_node['local_file_path'], PATHINFO_EXTENSION );
				$content   = $this->fs->get_contents( $post_tree_node['local_file_path'] );
				switch ( $extension ) {
					case 'md':
						$converter = new MarkdownConsumer( $content );
						$result    = $converter->consume();
						break;
					case 'xhtml':
						$converter = new MarkupProcessorConsumer( XMLProcessor::create_from_string( $content ) );
						$result    = $converter->consume();
						break;
					case 'html':
						$converter = new MarkupProcessorConsumer( WP_HTML_Processor::create_fragment( $content ) );
						$result    = $converter->consume();
						break;
					default:
						$filetype = 'application/octet-stream';
						if ( function_exists( 'wp_check_filetype' ) ) {
							$filetype = wp_check_filetype( basename( $post_tree_node['local_file_path'] ), null );
							if ( isset( $filetype['type'] ) ) {
								$filetype = $filetype['type'];
							}
						}
						$metadata['post_mime_type'] = $filetype;
						$metadata['post_status']    = 'inherit';
						$metadata['post_title']     = ImportUtils::slug_to_title( basename( $post_tree_node['local_file_path'] ) );
						// The importer will use the same Filesystem instance to
						// source the attachment.
						$metadata['attachment_url'] = 'file://' . $post_tree_node['local_file_path'];
						$result                     = new BlocksWithMetadata( '', array() );
						break;
				}
			} elseif ( $post_tree_node['type'] === 'index_file_placeholder' ) {
				$result                 = new BlocksWithMetadata( '', array() );
				$metadata['post_title'] = ImportUtils::slug_to_title( basename( $post_tree_node['local_file_path'] ) );
			} else {
				// Directory node, let's skip it.
				continue;
			}

			$reader = new BlocksWithMetadataEntityReader(
				$result,
				$post_tree_node['post_id']
			);
			while ( $reader->next_entity() ) {
				$entity = $reader->get_entity();
				$data   = $entity->get_data();
				if ( $entity->get_type() === 'post' ) {
					$data = array_merge( $metadata, $data );
					if ( ! $data['post_title'] ) {
						$data['post_title'] = ImportUtils::slug_to_title( basename( $metadata['local_file_path'] ) );
					}
					$entity = new ImportEntity( $entity->get_type(), $data );
				}
				$this->entities[] = $entity;
			}

			// Also emit:
			$additional_meta = array(
				'local_file_path' => $metadata['local_file_path'],
			);
			foreach ( $additional_meta as $key => $value ) {
				$this->entities[] = new ImportEntity(
					'post_meta',
					array(
						'post_id'    => $post_tree_node['post_id'],
						'meta_key'   => $key,
						'meta_value' => $value,
					)
				);
			}
		}
	}

	/**
	 * Retrieves the current filesystem node being processed.
	 *
	 * @return array|null The current node or null if none.
	 */
	private function get_current_filesystem_node() {
		return $this->current_filesystem_node;
	}

	/**
	 * Advances to the next filesystem node.
	 *
	 * @return bool True if a node is found, false if processing is complete.
	 */
	private function next_filesystem_node() {
		$this->current_filesystem_node = null;
		while ( true ) {
			if ( $this->pending_directory_index !== null ) {
				$dir       = $this->file_visitor->get_event()->dir;
				$depth     = $this->file_visitor->get_current_depth();
				$parent_id = $this->parent_ids[ $depth - 1 ] ?? null;
				if ( $parent_id === null && $depth > 1 ) {
					// There's no parent ID even though we're a few levels deep.
					// This is a scenario where `next_file()` skipped a few levels
					// of directories with no relevant content in them:
					//
					// - /docs/
					// - /foo/
					// - /bar/
					// - /baz.md
					//
					// In this case, we need to backtrack and create the missing
					// parent pages for /bar/ and /foo/.

					// Find the topmost missing parent ID
					$missing_parent_id_depth = 1;
					while ( isset( $this->parent_ids[ $missing_parent_id_depth ] ) ) {
						++$missing_parent_id_depth;
					}

					// Move up to the corresponding directory
					$missing_parent_path = $dir;
					for ( $i = $missing_parent_id_depth; $i < $depth; $i++ ) {
						$missing_parent_path = dirname( $missing_parent_path );
					}

					$this->parent_ids[ $missing_parent_id_depth ] = $this->emit_filesystem_node(
						array(
							'type'            => 'directory',
							'local_file_path' => $missing_parent_path,
							'parent_id'       => $this->parent_ids[ $missing_parent_id_depth - 1 ] ?? null,
						)
					);
				} elseif ( $this->pending_directory_index === false ) {
					// No directory index candidate found in the current directory.
					if ( $depth === 0 && isset( $this->parent_ids[- 1] ) && $parent_id === $this->parent_ids[- 1] ) {
						// We're at the root directory and we have a root parent ID. Let's
						// reuse that as the top-level parent.
						$this->parent_ids[ $depth ] = $this->parent_ids[- 1];
						// We're no longer looking for a directory index.
						$this->pending_directory_index = null;
						continue;
					}

					// Let's create a fake page just to have something in the page tree.
					$this->parent_ids[ $depth ] = $this->emit_filesystem_node(
						array(
							'type'            => 'index_file_placeholder',
							'local_file_path' => $dir,
							'parent_id'       => $parent_id,
						)
					);

					// We're no longer looking for a directory index.
					$this->pending_directory_index = null;
				} else {
					$file_path                  = $this->pending_directory_index;
					$this->parent_ids[ $depth ] = $this->emit_filesystem_node(
						array(
							'type'            => 'file',
							'local_file_path' => $file_path,
							'parent_id'       => $parent_id,
						)
					);
					// We're no longer looking for a directory index.
					$this->pending_directory_index = null;
				}

				return true;
			}
			while ( count( $this->pending_files ) ) {
				$parent_id = $this->parent_ids[ $this->file_visitor->get_current_depth() ] ?? null;
				$file_path = array_shift( $this->pending_files );
				$this->emit_filesystem_node(
					array(
						'type'            => 'file',
						'local_file_path' => $file_path,
						'parent_id'       => $parent_id,
					)
				);

				return true;
			}

			if ( $this->next_file() === false ) {
				break;
			}
		}
		$this->is_finished = true;

		return false;
	}

	/**
	 * Processes the next file in the traversal.
	 *
	 * @return bool True if a file is processed, false otherwise.
	 */
	private function next_file() {
		$this->pending_files = array();
		while ( $this->file_visitor->next() ) {
			$event = $this->file_visitor->get_event();

			if ( $event->is_exiting() ) {
				// Clean up stale IDs to save some memory when processing
				// large directory trees.
				unset( $this->parent_ids[ $event->dir ] );
				continue;
			}

			if ( $event->is_entering() ) {
				$abs_paths = array();
				foreach ( $event->files as $filename ) {
					$abs_paths[] = wp_join_unix_paths( $event->dir, $filename );
				}
				$this->pending_files = array();
				foreach ( $abs_paths as $path ) {
					// Add all the subdirectory into the pending files list – there's
					// a chance the directory wouldn't match the filter pattern, but
					// a descendant file might.
					if ( $this->fs->is_dir( $path ) ) {
						$this->pending_files[] = $path;
					}

					// Only add the files that match the filter pattern.
					if ( $this->fs->is_file( $path ) && preg_match( $this->filter_pattern, $path ) ) {
						$this->pending_files[] = $path;
					}
				}
				if ( ! count( $this->pending_files ) ) {
					// Only consider directories with relevant files in them.
					// Otherwise we'll create fake pages for media directories
					// and other directories that don't contain any content.
					//
					// One corner case is when there's a few levels of directories
					// with a single relevant file at the bottom:
					//
					// - /docs/
					// - /foo/
					// - /bar/
					// - /baz.md
					//
					// In this case, `next_entity()` will backtrack at baz.md and
					// create the missing parent pages.
					continue;
				}
				$directory_index_idx = $this->choose_directory_index( $this->pending_files );
				if ( $directory_index_idx === - 1 ) {
					$this->pending_directory_index = false;
				} else {
					$this->pending_directory_index = $this->pending_files[ $directory_index_idx ];
					unset( $this->pending_files[ $directory_index_idx ] );
				}

				return true;
			}

			return false;
		}

		return false;
	}

	/**
	 * Emits a WordPress post entity based on the provided options.
	 *
	 * @param  array $options  Configuration for the post entity.
	 *
	 * @return int The ID of the created post.
	 */
	protected function emit_filesystem_node( $options ) {
		$post_id = $this->next_post_id;
		++$this->next_post_id;
		$this->current_filesystem_node = array_merge(
			array(
				'post_id' => $post_id,
			),
			$options
		);
		++$this->fs_nodes_emited_so_far;

		return $post_id;
	}

	/**
	 * Chooses an index file from the list of pending files.
	 *
	 * @param  array $files  List of files to choose from.
	 *
	 * @return int The index of the chosen file or -1 if none.
	 */
	protected function choose_directory_index( $files ) {
		foreach ( $files as $idx => $file ) {
			if ( $this->looks_like_directory_index( $file ) ) {
				return $idx;
			}
		}
		if ( ! $this->create_index_pages && count( $files ) > 0 ) {
			return 0;
		}

		return - 1;
	}

	/**
	 * Determines if a file path matches the index file pattern.
	 *
	 * @param  string $path  The file path to check.
	 *
	 * @return bool True if it matches, false otherwise.
	 */
	protected function looks_like_directory_index( $path ) {
		return preg_match( $this->index_file_pattern, basename( $path ) );
	}

	/**
	 * @TODO: Implement this
	 *
	 * @return string
	 */
	public function get_reentrancy_cursor() {
		return '';
	}
}
