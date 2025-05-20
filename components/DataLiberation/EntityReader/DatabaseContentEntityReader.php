<?php

namespace WordPress\DataLiberation\EntityReader;

use PDO;
use PDOStatement;
use WordPress\DataLiberation\DataLiberationException;
use WordPress\DataLiberation\ImportEntity;

/**
 * Reads WordPress content in a "topological content order" – the same as seen
 * in a WXR file. It only exports well-known, meaningful content entities.
 *
 * It emits a parent-less post, then all its meta, then terms, comments, comment meta, etc.
 * Then, it emits a child of that post, all its meta etc.
 *
 * This way, a WXR writer or a Markdown writer can process all the post-related data and
 * then move on to the next post.
 *
 * For exporting entire database tables, use DatabaseRowsEntityReader instead.
 *
 * @since WP_VERSION
 */
class DatabaseContentEntityReader implements EntityReader {

	/**
	 * State constants for the finite state machine
	 */
	const STATE_ADVANCE_TO_NEXT_POST = 'advance_to_next_post';
	const STATE_POST = 'post';
	const STATE_META = 'meta';
	const STATE_TERMS = 'terms';
	const STATE_COMMENTS = 'comments';
	const STATE_FINISHED = 'finished';

	/**
	 * The database connection used to fetch records.
	 *
	 * @since WP_VERSION
	 * @var PDO
	 */
	private $db;

	/**
	 * The current entity being processed.
	 *
	 * @since WP_VERSION
	 * @var ImportEntity|null
	 */
	private $current_entity = null;

	/**
	 * The current post ID being processed.
	 *
	 * @since WP_VERSION
	 * @var int|null
	 */
	private $current_post_id = null;

	/**
	 * The current state of processing for the current post.
	 *
	 * @since WP_VERSION
	 * @var string
	 */
	private $state = self::STATE_POST;

	/**
	 * The table prefix to use.
	 *
	 * @since WP_VERSION
	 * @var string
	 */
	private $table_prefix;

	/**
	 * The stack to keep track of parent posts and their last processed child.
	 *
	 * @since WP_VERSION
	 * @var array
	 */
	private $parent_stack = array(
		array(
			'parent_id'            => 0,
			'last_processed_child' => 0,
		),
	);

	/**
	 * The current meta result set.
	 *
	 * @since WP_VERSION
	 * @var PDOStatement|null
	 */
	private $current_meta_result_set = null;

	/**
	 * The current term result set.
	 *
	 * @since WP_VERSION
	 * @var PDOStatement|null
	 */
	private $current_term_result_set = null;

	/**
	 * The current comment result set.
	 *
	 * @since WP_VERSION
	 * @var PDOStatement|null
	 */
	private $current_comment_result_set = null;

	public static function create( PDO $db, $options = array() ) {
		return new DatabaseContentEntityReader( $db, $options );
	}

	/**
	 * Constructor.
	 *
	 * @param  PDO  $db  The database connection to use.
	 * @param  array  $options  The options to configure the reader.
	 *
	 * @since WP_VERSION
	 *
	 */
	public function __construct( PDO $db, $options = array() ) {
		$this->db           = $db;
		$this->table_prefix = $options['table_prefix'] ?? 'wp_';
		if ( isset( $options['cursor'] ) ) {
			$this->initialize_from_cursor( $options['cursor'] );
		}
	}

	/**
	 * Gets the data for the current entity.
	 *
	 * @return ImportEntity The entity.
	 * @since WP_VERSION
	 */
	public function get_entity(): ImportEntity {
		return $this->current_entity;
	}

	public function is_finished(): bool {
		return $this->state === self::STATE_FINISHED;
	}

	/**
	 * Advances to the next entity in the database.
	 *
	 * @return bool Whether another entity was found.
	 * @since WP_VERSION
	 *
	 */
	public function next_entity() {
		if ( $this->is_finished() ) {
			return false;
		}

		// Process current post and its related data
		while ( true ) {
			switch ( $this->state ) {
				case self::STATE_POST:
					if ( $this->read_next_post() ) {
						return true;
					}
					break;

				case self::STATE_META:
					if ( $this->read_post_meta() ) {
						return true;
					}
					break;

				case self::STATE_TERMS:
					if ( $this->read_post_terms() ) {
						return true;
					}
					break;

				case self::STATE_COMMENTS:
					if ( $this->read_post_comments() ) {
						return true;
					}
					break;

				case self::STATE_FINISHED:
					return false;

				default:
					throw new DataLiberationException( 'Invalid state: ' . $this->state );
			}
		}

		return false;
	}

	private function next_post_at_level( $parent_id = 0, $last_id = 0 ) {
		$stmt = $this->db->prepare(
			"SELECT * FROM {$this->table_prefix}posts WHERE post_parent = ? AND ID > ? ORDER BY ID LIMIT 1"
		);
		$stmt->execute( array( $parent_id, $last_id ) );

		return $stmt->fetch( PDO::FETCH_ASSOC );
	}

	private function read_next_post() {
		$post = null;
		while ( count( $this->parent_stack ) > 0 ) {
			$parent_info = end( $this->parent_stack );
			$post        = $this->next_post_at_level( $parent_info['parent_id'], $parent_info['last_processed_child'] );
			if ( $post ) {
				// Acknowledge we've processed the next child of the last recorded parent
				if ( count( $this->parent_stack ) > 0 ) {
					$last_key                                                = count( $this->parent_stack ) - 1;
					$this->parent_stack[ $last_key ]['last_processed_child'] = $post['ID'];
				}
				// Push current post to parent stack to process its children later
				array_push(
					$this->parent_stack,
					array(
						'parent_id'            => $post['ID'],
						'last_processed_child' => 0,
					)
				);
				break;
			} else {
				// No more posts at this level, move up the stack and try again
				array_pop( $this->parent_stack );
			}
		}

		if ( ! $post ) {
			$this->current_post_id = null;
			$this->current_entity  = null;
			$this->state           = self::STATE_FINISHED;

			return false;
		}

		$this->current_post_id = $post['ID'];
		$this->current_entity  = new ImportEntity( 'post', $post );
		$this->state           = self::STATE_META;

		return true;
	}

	private function read_post_meta() {
		if ( $this->current_meta_result_set === null ) {
			$stmt = $this->db->prepare(
				"SELECT * FROM {$this->table_prefix}postmeta WHERE post_id = ? ORDER BY meta_id"
			);
			$stmt->execute( array( $this->current_post_id ) );
			$this->current_meta_result_set = $stmt;
		}

		$meta = $this->current_meta_result_set->fetch( PDO::FETCH_ASSOC );
		if ( $meta ) {
			$this->current_entity = new ImportEntity( 'post_meta', $meta );

			return true;
		}

		$this->state                   = self::STATE_TERMS;
		$this->current_meta_result_set = null;

		return $this->next_entity();
	}

	private function read_post_terms() {
		if ( $this->current_term_result_set === null ) {
			$stmt = $this->db->prepare(
				"SELECT t.*, tt.* FROM {$this->table_prefix}term_relationships tr
                 JOIN {$this->table_prefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                 JOIN {$this->table_prefix}terms t ON tt.term_id = t.term_id
                 WHERE tr.object_id = ?
                 ORDER BY t.term_id"
			);
			$stmt->execute( array( $this->current_post_id ) );
			$this->current_term_result_set = $stmt;
		}

		$term = $this->current_term_result_set->fetch( PDO::FETCH_ASSOC );
		if ( $term ) {
			$this->current_entity = new ImportEntity( 'term', $term );

			return true;
		}

		$this->state                   = self::STATE_COMMENTS;
		$this->current_term_result_set = null;

		return $this->next_entity();
	}

	private function read_post_comments() {
		if ( $this->current_comment_result_set === null ) {
			$stmt = $this->db->prepare(
				"SELECT * FROM {$this->table_prefix}comments WHERE comment_post_ID = ? ORDER BY comment_ID"
			);
			$stmt->execute( array( $this->current_post_id ) );
			$this->current_comment_result_set = $stmt;
		}

		$comment = $this->current_comment_result_set->fetch( PDO::FETCH_ASSOC );
		if ( $comment ) {
			$this->current_entity = new ImportEntity( 'comment', $comment );

			return true;
		}

		$this->state                      = self::STATE_POST;
		$this->current_comment_result_set = null;

		return $this->next_entity();
	}

	public function get_reentrancy_cursor() {
		return json_encode(
			array(
				'post_id'      => $this->current_post_id,
				'state'        => $this->state,
				'parent_stack' => $this->parent_stack,
			)
		);
	}

	/**
	 * Initializes the reader from a cursor.
	 *
	 * @param  string  $cursor  The cursor to initialize from.
	 *
	 * @since WP_VERSION
	 *
	 */
	private function initialize_from_cursor( $cursor ) {
		$cursor_data = json_decode( $cursor, true );
		if ( $cursor_data ) {
			$this->current_post_id            = $cursor_data['post_id'] ?? null;
			$this->state                      = $cursor_data['state'] ?? self::STATE_POST;
			$this->parent_stack               = $cursor_data['parent_stack'] ?? array();
			$this->current_meta_result_set    = null;
			$this->current_term_result_set    = null;
			$this->current_comment_result_set = null;
		}
	}
}
