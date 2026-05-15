<?php

namespace WordPress\XML;

/**
 * Internal native-backed XML cursor optimized for public `next_token()` scans.
 *
 * @access private
 */
class XMLNativeCursorProcessor extends XMLProcessor {
	/**
	 * Queued hot compact native rows.
	 *
	 * @var string
	 */
	private $lightweight_batch = '';

	/**
	 * Current byte offset in the queued native rows.
	 *
	 * @var int
	 */
	private $lightweight_batch_offset = 0;

	/**
	 * Number of public tokens exposed by this cursor.
	 *
	 * @var int
	 */
	private $lightweight_tokens_parsed = 0;

	/**
	 * Whether the cursor has materialized back to the PHP parser.
	 *
	 * @var bool
	 */
	private $lightweight_materialized = false;

	/**
	 * Whether the synthetic complete token has been exposed.
	 *
	 * @var bool
	 */
	private $lightweight_complete_exposed = false;

	/**
	 * Current token type.
	 *
	 * @var string|null
	 */
	private $lightweight_token_type = null;

	/**
	 * Current tag local name, or null for non-tags.
	 *
	 * @var string|null
	 */
	private $lightweight_tag_local_name = null;

	/**
	 * Current tag namespace/local-name string, or null for non-tags.
	 *
	 * @var string|null
	 */
	private $lightweight_tag_namespace_and_local_name = null;

	/**
	 * Current cached unprefixed `id` attribute.
	 *
	 * @var string|null
	 */
	private $lightweight_cached_id_attribute = null;

	/**
	 * Whether the current token is a tag.
	 *
	 * @var bool
	 */
	private $lightweight_token_is_tag = false;

	/**
	 * Whether native cursor rows use the smaller cursor-only row format.
	 *
	 * @var bool
	 */
	private $lightweight_uses_cursor_rows = false;

	/**
	 * Whether parent native feature flags have been initialized.
	 *
	 * @var bool
	 */
	private $parent_native_features_initialized = false;

	/**
	 * Constructor.
	 *
	 * @param string       $xml                         XML document.
	 * @param object|array $native_processor_namespaces Native processor or document namespaces.
	 * @param string|null  $use_static_creator          Constructor unlock code for internal PHP replay.
	 */
	public function __construct( $xml, $native_processor_namespaces, $use_static_creator = null ) {
		if ( self::CONSTRUCTOR_UNLOCK_CODE === $use_static_creator ) {
			parent::__construct( $xml, $native_processor_namespaces, $use_static_creator );
			return;
		}

		parent::__construct( $xml, array(), self::CONSTRUCTOR_UNLOCK_CODE );
		parent::input_finished();
		$this->native_processor             = $native_processor_namespaces;
		$this->lightweight_uses_cursor_rows = method_exists( $native_processor_namespaces, 'next_token_cursor_compact_summary_batch' );
	}

	/**
	 * Initializes parent native feature flags before delegating to parent APIs.
	 */
	private function ensure_parent_native_features() {
		if ( $this->parent_native_features_initialized || null === $this->native_processor ) {
			return;
		}

		$native_processor = $this->native_processor;
		$this->set_native_processor( $native_processor );
		$this->parent_native_features_initialized = true;
	}

	/**
	 * Materializes the lightweight cursor into the parent PHP parser.
	 *
	 * @return bool Whether materialization succeeded.
	 */
	private function materialize_lightweight_cursor() {
		if ( $this->lightweight_materialized || 0 === $this->lightweight_tokens_parsed ) {
			return true;
		}

		$tokens_to_replay                 = $this->lightweight_tokens_parsed;
		$this->native_processor           = null;
		$this->lightweight_batch          = '';
		$this->lightweight_batch_offset   = 0;
		$this->lightweight_materialized   = true;
		$this->lightweight_token_type     = null;
		$this->lightweight_tag_local_name = null;

		while ( $tokens_to_replay > 0 ) {
			if ( ! parent::next_token() ) {
				return false;
			}

			--$tokens_to_replay;
		}

		return true;
	}

	/**
	 * Materializes only after the lightweight cursor has advanced.
	 *
	 * @return bool Whether materialization succeeded.
	 */
	private function materialize_lightweight_cursor_if_started() {
		if ( 0 === $this->lightweight_tokens_parsed ) {
			$this->ensure_parent_native_features();
			return true;
		}

		return $this->materialize_lightweight_cursor();
	}

	/**
	 * Whether the current public token came from the lightweight cursor.
	 *
	 * @return bool Whether lightweight token state is active.
	 */
	private function has_lightweight_token() {
		return null !== $this->lightweight_token_type;
	}

	public function next_token() {
		if ( $this->lightweight_materialized || null === $this->native_processor ) {
			return parent::next_token();
		}

		if ( $this->lightweight_complete_exposed ) {
			return false;
		}

		if ( 0 === $this->lightweight_tokens_parsed && null !== parent::get_token_type() ) {
			$this->lightweight_materialized = true;
			return parent::next_token();
		}

		$batch        = $this->lightweight_batch;
		$batch_length = strlen( $batch );
		if ( $this->lightweight_batch_offset >= $batch_length ) {
			$batch = $this->lightweight_uses_cursor_rows
				? $this->native_processor->next_token_cursor_compact_summary_batch( 1024 )
				: $this->native_processor->next_token_hot_compact_summary_batch( 1024 );
			if ( ! is_string( $batch ) || '' === $batch ) {
				if ( $this->native_processor->is_finished() ) {
					$this->lightweight_token_type                   = '#complete';
					$this->lightweight_tag_local_name               = null;
					$this->lightweight_tag_namespace_and_local_name = null;
					$this->lightweight_cached_id_attribute          = null;
					$this->lightweight_token_is_tag                 = false;
					$this->lightweight_complete_exposed             = true;
					++$this->lightweight_tokens_parsed;

					return true;
				}

				return false;
			}

			$this->lightweight_batch        = $batch;
			$this->lightweight_batch_offset = 0;
			$batch_length                   = strlen( $batch );
		}

		$row_start = $this->lightweight_batch_offset;
		$row_end   = strpos( $batch, "\x1e", $row_start );
		if ( false === $row_end ) {
			$row_end = $batch_length;
		}

		$this->lightweight_batch_offset = $row_end + 1;
		$token_kind                     = $batch[ $row_start ];
		if ( 't' !== $token_kind ) {
			switch ( $token_kind ) {
				case 'x':
					$this->lightweight_token_type = '#xml-declaration';
					break;
				case 'd':
					$this->lightweight_token_type = '#doctype';
					break;
				case 'p':
					$this->lightweight_token_type = '#processing-instructions';
					break;
				case 'c':
					$this->lightweight_token_type = '#comment';
					break;
				case 'a':
					$this->lightweight_token_type = '#cdata-section';
					break;
				default:
					$this->lightweight_token_type = '#text';
					break;
			}

			$this->lightweight_tag_local_name               = null;
			$this->lightweight_tag_namespace_and_local_name = null;
			$this->lightweight_cached_id_attribute          = null;
			$this->lightweight_token_is_tag                 = false;
			++$this->lightweight_tokens_parsed;

			return true;
		}

		$first  = $row_start + 1;
		$second = strpos( $batch, "\x1f", $first + 1 );
		$third  = false === $second ? false : strpos( $batch, "\x1f", $second + 1 );
		if ( false === $third || $third >= $row_end ) {
			return false;
		}

		$local_name = substr( $batch, $first + 1, $second - $first - 1 );
		if ( $this->lightweight_uses_cursor_rows ) {
			$namespace_and_local_name = substr( $batch, $second + 1, $third - $second - 1 );
			$id_attribute_start       = $third + 1;
			$id_attribute_end         = $row_end;
		} else {
			$namespace = substr( $batch, $second + 1, $third - $second - 1 );
			$fourth    = strpos( $batch, "\x1f", $third + 1 );
			if ( false === $fourth || $fourth >= $row_end ) {
				return false;
			}
			$namespace_and_local_name = '' === $namespace ? $local_name : '{' . $namespace . '}' . $local_name;
			$id_attribute_start       = $third + 1;
			$id_attribute_end         = $fourth;
		}

		$this->lightweight_token_type                   = '#tag';
		$this->lightweight_tag_local_name               = $local_name;
		$this->lightweight_tag_namespace_and_local_name = $namespace_and_local_name;
		$this->lightweight_cached_id_attribute          = $id_attribute_start < $id_attribute_end && '1' === $batch[ $id_attribute_start ]
			? substr( $batch, $id_attribute_start + 1, $id_attribute_end - $id_attribute_start - 1 )
			: null;
		$this->lightweight_token_is_tag                 = true;
		++$this->lightweight_tokens_parsed;

		return true;
	}

	public function get_token_type() {
		return null !== $this->lightweight_token_type ? $this->lightweight_token_type : parent::get_token_type();
	}

	public function get_token_name() {
		return null !== $this->lightweight_token_type
			? ( null !== $this->lightweight_tag_local_name ? $this->lightweight_tag_local_name : $this->lightweight_token_type )
			: parent::get_token_name();
	}

	public function get_tag_local_name() {
		return null !== $this->lightweight_token_type ? $this->lightweight_tag_local_name : parent::get_tag_local_name();
	}

	public function get_tag_namespace_and_local_name() {
		return null !== $this->lightweight_token_type ? $this->lightweight_tag_namespace_and_local_name : parent::get_tag_namespace_and_local_name();
	}

	public function get_attribute( $namespace_reference, $local_name ) {
		if ( null !== $this->lightweight_token_type && '' === $namespace_reference && 'id' === $local_name ) {
			return $this->lightweight_token_is_tag ? $this->lightweight_cached_id_attribute : null;
		}

		return $this->materialize_lightweight_cursor_if_started()
			? parent::get_attribute( $namespace_reference, $local_name )
			: null;
	}

	public function next_tag( $query_or_ns = null, $null_or_local_name = null ) {
		return $this->materialize_lightweight_cursor_if_started() && parent::next_tag( $query_or_ns, $null_or_local_name );
	}

	public function get_modifiable_text() {
		return $this->materialize_lightweight_cursor_if_started() ? parent::get_modifiable_text() : '';
	}

	public function set_modifiable_text( $new_value ) {
		return $this->materialize_lightweight_cursor_if_started() && parent::set_modifiable_text( $new_value );
	}

	public function set_attribute( $xml_namespace, $local_name, $value ) {
		return $this->materialize_lightweight_cursor_if_started() && parent::set_attribute( $xml_namespace, $local_name, $value );
	}

	public function remove_attribute( $xml_namespace, $local_name ) {
		return $this->materialize_lightweight_cursor_if_started() && parent::remove_attribute( $xml_namespace, $local_name );
	}

	public function set_bookmark( $name ) {
		return $this->materialize_lightweight_cursor_if_started() && parent::set_bookmark( $name );
	}

	public function seek( $bookmark_name ) {
		return $this->materialize_lightweight_cursor_if_started() && parent::seek( $bookmark_name );
	}

	public function get_breadcrumbs() {
		return $this->materialize_lightweight_cursor_if_started() ? parent::get_breadcrumbs() : null;
	}

	public function matches_breadcrumbs( $breadcrumbs ) {
		return $this->materialize_lightweight_cursor_if_started() && parent::matches_breadcrumbs( $breadcrumbs );
	}

	public function get_current_depth() {
		return $this->materialize_lightweight_cursor_if_started() ? parent::get_current_depth() : 0;
	}

	public function get_tag_namespace() {
		return $this->materialize_lightweight_cursor_if_started() ? parent::get_tag_namespace() : null;
	}

	public function expects_closer() {
		return $this->materialize_lightweight_cursor_if_started() && parent::expects_closer();
	}

	public function is_empty_element() {
		return $this->materialize_lightweight_cursor_if_started() && parent::is_empty_element();
	}

	public function is_tag_closer() {
		return $this->materialize_lightweight_cursor_if_started() && parent::is_tag_closer();
	}

	public function is_tag_opener() {
		return $this->materialize_lightweight_cursor_if_started() && parent::is_tag_opener();
	}

	public function is_finished() {
		return $this->lightweight_complete_exposed || parent::is_finished();
	}
}
