<?php

namespace WordPress\DataLiberation;

use WP_HTML_Processor;
use WP_HTML_Tag_Processor;

/**
 * HTML Processor helpers used by the Data Liberation import pipeline.
 *
 * This class intentionally keeps the same no-burden progressive upgrade model
 * as the public HTML API. When the native extension is loaded, inherited cursor
 * operations such as `next_tag()`, `next_token()`, `get_attribute()`, and
 * `get_modifiable_text()` use the native processor automatically. Consumers do
 * not need to opt in or choose a separate class.
 *
 * The Data Liberation-only helpers below expose source-string offsets and
 * substrings:
 *
 *     $p = DataLiberationHTMLProcessor::create_fragment( '<h1>Title</h1><p>Body</p>' );
 *     $p->next_tag( 'H1' );
 *     $inner_html = $p->get_inner_html(); // "Title".
 *
 *     $p->skip_to_closer();
 *     $after_h1 = substr( $html, $p->get_string_index_after_current_token() );
 *
 * Those offsets are an implementation detail of the PHP parser; native HTML
 * bookmarks are opaque and do not expose byte spans. To keep native processing
 * available for normal scanning, these helpers replay the current cursor
 * position on a PHP-backed mirror and read the offsets from that mirror.
 */
class DataLiberationHTMLProcessor extends WP_HTML_Processor {

	/**
	 * Number of public tokens consumed from this processor.
	 *
	 * This is enough to replay the cursor onto a PHP-backed mirror for the
	 * Data Liberation helpers that need PHP bookmark spans.
	 *
	 * @var int
	 */
	private $tokens_parsed_for_php_replay = 0;

	/**
	 * Token counts captured for public bookmarks.
	 *
	 * Native bookmarks are intentionally opaque. This map lets a later `seek()`
	 * restore the replay counter when the bookmark was created through this
	 * class, so the PHP mirror can land on the same token.
	 *
	 * @var array<string,int>
	 */
	private $php_replay_bookmarks = array();

	/**
	 * Whether the current cursor can be replayed onto the PHP parser.
	 *
	 * @var bool
	 */
	private $can_replay_php_cursor = true;

	/**
	 * Whether this instance exists only as a PHP replay mirror.
	 *
	 * @var bool
	 */
	private $is_php_replay = false;

	public function next_token(): bool {
		$matched = parent::next_token();
		if ( $matched ) {
			++$this->tokens_parsed_for_php_replay;
		}

		return $matched;
	}

	public function set_bookmark( $name ): bool {
		$set = parent::set_bookmark( $name );
		if ( $set ) {
			$this->php_replay_bookmarks[ $name ] = $this->tokens_parsed_for_php_replay;
		}

		return $set;
	}

	public function release_bookmark( $name ): bool {
		$released = parent::release_bookmark( $name );
		if ( $released ) {
			unset( $this->php_replay_bookmarks[ $name ] );
		}

		return $released;
	}

	public function seek( $bookmark_name ): bool {
		$found = parent::seek( $bookmark_name );
		if ( ! $found ) {
			return false;
		}

		if ( isset( $this->php_replay_bookmarks[ $bookmark_name ] ) ) {
			$this->tokens_parsed_for_php_replay = $this->php_replay_bookmarks[ $bookmark_name ];
		} else {
			$this->can_replay_php_cursor = false;
		}

		return true;
	}

	public function get_inner_html() {
		if ( $this->has_active_native_processor() ) {
			$php_processor = $this->create_php_replay_at_current_token();

			return $php_processor instanceof self ? $php_processor->get_inner_html() : false;
		}

		if ( '#tag' !== $this->get_token_type() ) {
			return false;
		}

		if ( $this->is_tag_closer() ) {
			return false;
		}

		if ( false === $this->set_bookmark( 'tag-start' ) ) {
			return false;
		}

		$this->skip_to_closer();

		if ( false === $this->set_bookmark( 'tag-end' ) ) {
			$this->release_bookmark( 'tag-start' );

			return false;
		}

		$tag_start        = $this->get_bookmark_span( 'tag-start' );
		$tag_end          = $this->get_bookmark_span( 'tag-end' );
		$inner_html_start = $tag_start->start + $tag_start->length;
		$inner_html_end   = $tag_end->start - $inner_html_start;

		if ( ! $this->is_php_replay ) {
			$this->seek( 'tag-start' );
			$this->release_bookmark( 'tag-start' );
			$this->release_bookmark( 'tag-end' );
		}

		return substr(
			$this->html,
			$inner_html_start,
			$inner_html_end
		);
	}

	public function skip_to_closer() {
		$starting_depth = $this->get_current_depth();
		while ( $this->next_token() ) {
			if (
				'#tag' === $this->get_token_type() &&
				$this->is_tag_closer() &&
				$this->get_current_depth() === $starting_depth - 1
			) {
				return true;
			}
		}

		return false;
	}

	public function get_string_index_after_current_token() {
		if ( $this->has_active_native_processor() ) {
			$php_processor = $this->create_php_replay_at_current_token();

			return $php_processor instanceof self ? $php_processor->get_string_index_after_current_token() : false;
		}

		$name = 'current_token';
		$this->set_bookmark( $name );
		$bookmark = $this->get_bookmark_span( '_' . $name );
		$this->release_bookmark( $name );

		return $bookmark->start + $bookmark->length;
	}

	/**
	 * Returns a PHP bookmark span from this object or its PHP delegate.
	 *
	 * @param string $name Bookmark name.
	 * @return WP_HTML_Span Bookmark span.
	 */
	private function get_bookmark_span( $name ) {
		if ( isset( $this->bookmarks[ $name ] ) ) {
			return $this->bookmarks[ $name ];
		}
		if ( isset( $this->bookmarks[ '_' . $name ] ) ) {
			return $this->bookmarks[ '_' . $name ];
		}

		if ( property_exists( $this, 'php_processor' ) ) {
			$property = new \ReflectionProperty( 'WP_HTML_PHP_Tag_Processor', 'bookmarks' );
			$property->setAccessible( true );
			$bookmarks = $property->getValue( $this->php_processor );
			if ( isset( $bookmarks[ $name ] ) ) {
				return $bookmarks[ $name ];
			}
			if ( isset( $bookmarks[ '_' . $name ] ) ) {
				return $bookmarks[ '_' . $name ];
			}
		}

		return null;
	}

	/**
	 * Checks whether this processor has a native delegate.
	 *
	 * The pure PHP HTML Processor loaded on older runtimes does not define the
	 * native-wrapper helper method, so callers must guard the method before
	 * invoking it.
	 *
	 * @return bool Whether a native processor is active.
	 */
	private function has_active_native_processor() {
		return method_exists( $this, 'has_native_processor' ) && $this->has_native_processor();
	}

	/**
	 * Creates a PHP-backed processor at the same public cursor token.
	 *
	 * Native cursor operations are faster for scanning, but native bookmarks do
	 * not expose byte offsets. For offset-only helpers this method replays the
	 * already-consumed public token count on a mirror with native delegation
	 * disabled. Example:
	 *
	 *     $native = DataLiberationHTMLProcessor::create_fragment( '<h1>Title</h1><p>Body</p>' );
	 *     $native->next_tag( 'H1' );
	 *
	 *     // The mirror runs the PHP parser through the same first token, so its
	 *     // bookmark spans point to the original `<h1>` source bytes.
	 *     $php = $native->create_php_replay_at_current_token();
	 *
	 * @return self|null PHP-backed processor at the current token, or null.
	 */
	private function create_php_replay_at_current_token() {
		if ( ! $this->can_replay_php_cursor ) {
			return null;
		}

		$processor = static::create_fragment( $this->html );
		if ( ! $processor instanceof self ) {
			return null;
		}

		$processor->set_native_processor( null );
		$processor->is_php_replay = true;
		for ( $i = 0; $i < $this->tokens_parsed_for_php_replay; ++$i ) {
			if ( ! $processor->next_token() ) {
				return null;
			}
		}

		return $processor;
	}
}
