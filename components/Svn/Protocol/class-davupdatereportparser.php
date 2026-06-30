<?php

namespace WordPress\Svn\Protocol;

use WordPress\Svn\SvnEditor;
use WordPress\Svn\SvnException;
use WordPress\XML\XMLProcessor;

use function WordPress\Svn\svn_normalize_relative_path;

/**
 * Streaming parser for mod_dav_svn's update-report response.
 *
 * The response is the HTTP twin of the svn:// editor drive: an XML
 * document describing tree changes, with file contents inlined as
 * base64-encoded svndiff data:
 *
 *     <S:update-report xmlns:S="svn:" send-all="true" inline-props="true">
 *       <S:target-revision rev="62480"/>
 *       <S:open-directory rev="62480">
 *         <S:set-prop name="svn:ignore">*.css</S:set-prop>
 *         <S:add-directory name="sub">
 *           <S:add-file name="a.txt">
 *             <S:txdelta>U1ZOAAAADAEMjG5l...</S:txdelta>
 *             <S:prop><V:md5-checksum>a4717…</V:md5-checksum></S:prop>
 *           </S:add-file>
 *         </S:add-directory>
 *         <S:open-file name="b.txt" rev="62454">…</S:open-file>
 *         <S:delete-entry name="old.txt"/>
 *       </S:open-directory>
 *     </S:update-report>
 *
 * Feed response bytes with append_bytes() as they arrive; editor calls
 * are emitted as soon as the relevant XML is complete.
 */
class DavUpdateReportParser {
	const SVN_NAMESPACE = 'svn:';
	const DAV_NAMESPACE = 'http://subversion.tigris.org/xmlns/dav/';

	/**
	 * @var SvnEditor
	 */
	private $editor;

	/**
	 * @var XMLProcessor
	 */
	private $xml;

	/**
	 * Stack of open directory paths; the working copy root is ''.
	 *
	 * @var string[]
	 */
	private $directory_stack = array();

	/**
	 * Path of the file element being parsed, or null outside files.
	 *
	 * @var string|null
	 */
	private $current_file;

	/**
	 * MD5 announced via <V:md5-checksum> for the current file.
	 *
	 * @var string|null
	 */
	private $current_file_md5;

	/**
	 * Name of the element whose text content matters right now:
	 * 'txdelta', 'set-prop', or 'md5-checksum'. Null otherwise.
	 *
	 * @var string|null
	 */
	private $collecting;

	/**
	 * Accumulated text of the element being collected.
	 *
	 * @var string
	 */
	private $collected_text = '';

	/**
	 * Property name of the <S:set-prop> being collected.
	 *
	 * @var string|null
	 */
	private $collected_prop_name;

	/**
	 * Whether the collected property value is base64-encoded.
	 *
	 * @var bool
	 */
	private $collected_prop_base64 = false;

	/**
	 * Whether the document ended and close_edit() was called.
	 *
	 * @var bool
	 */
	private $finished = false;

	/**
	 * Bytes buffered until the XML declaration is complete. XMLProcessor
	 * cannot pause mid-declaration (it bails as "unsupported" instead of
	 * waiting for more input), so the first bytes are held back until
	 * the first '>' arrived.
	 *
	 * @TODO: Teach XMLProcessor to pause on incomplete XML declarations,
	 * then drop this buffer.
	 *
	 * @var string|null Null once the prelude was forwarded.
	 */
	private $prelude = '';

	public function __construct( SvnEditor $editor ) {
		$this->editor = $editor;
		$this->xml    = XMLProcessor::create_for_streaming();

		/*
		 * Checking out something like wordpress-develop streams hundreds
		 * of megabytes of XML through this parser. XMLProcessor only
		 * forgets already-processed bytes once its internal buffer
		 * exceeds its memory budget, which defaults to 1 GiB – far too
		 * high for this forward-only parse. Lower it so large checkouts
		 * run in tens of megabytes of memory.
		 *
		 * @TODO: Expose the memory budget as a public XMLProcessor option
		 * and drop this reflection.
		 */
		try {
			$budget_property = new \ReflectionProperty( get_class( $this->xml ), 'memory_budget' );
			$budget_property->setAccessible( true );
			$budget_property->setValue( $this->xml, 8 * 1024 * 1024 );
		} catch ( \ReflectionException $exception ) {
			// A processor without the property manages its own memory.
		}
	}

	/**
	 * Feeds the next chunk of the HTTP response body.
	 *
	 * @param  string $bytes  Response bytes; boundaries are arbitrary.
	 * @throws SvnException When the response is not a valid update-report.
	 */
	public function append_bytes( $bytes ) {
		if ( null !== $this->prelude ) {
			$this->prelude .= $bytes;
			if ( false === strpos( $this->prelude, '>' ) ) {
				return;
			}
			$bytes         = $this->prelude;
			$this->prelude = null;
		}
		$this->xml->append_bytes( $bytes );
		$this->process_available_tokens();
	}

	/**
	 * Signals the end of the HTTP response.
	 *
	 * @throws SvnException When the response ended mid-document.
	 */
	public function finish() {
		if ( null !== $this->prelude ) {
			$this->xml->append_bytes( $this->prelude );
			$this->prelude = null;
		}
		$this->xml->input_finished();
		$this->process_available_tokens();
		if ( ! $this->finished ) {
			throw new SvnException( 'The update-report response ended unexpectedly: ' . ( $this->xml->get_last_error() ? $this->xml->get_last_error() : 'truncated document.' ) );
		}
	}

	private function process_available_tokens() {
		while ( $this->xml->next_token() ) {
			$token_type = $this->xml->get_token_type();
			if ( '#text' === $token_type || '#cdata-section' === $token_type ) {
				if ( null !== $this->collecting ) {
					$this->collected_text .= $this->xml->get_modifiable_text();
				}
				continue;
			}
			if ( '#tag' !== $token_type ) {
				continue;
			}

			// Empty elements such as <S:target-revision rev="4"/> report
			// neither is_tag_opener() nor is_tag_closer(); they act as both.
			if ( $this->xml->is_tag_opener() || $this->xml->is_empty_element() ) {
				$this->handle_element_open();
				if ( $this->xml->is_empty_element() ) {
					$this->handle_element_close();
				}
			} elseif ( $this->xml->is_tag_closer() ) {
				$this->handle_element_close();
			}
		}

		if ( $this->xml->is_paused_at_incomplete_input() ) {
			return;
		}
		if ( null !== $this->xml->get_last_error() ) {
			throw new SvnException( 'Malformed update-report response: ' . $this->xml->get_last_error() );
		}
	}

	private function handle_element_open() {
		$xml_namespace = $this->xml->get_tag_namespace();
		$local_name    = $this->xml->get_tag_local_name();

		if ( self::DAV_NAMESPACE === $xml_namespace && 'md5-checksum' === $local_name && null !== $this->current_file ) {
			$this->start_collecting( 'md5-checksum' );

			return;
		}

		if ( self::SVN_NAMESPACE !== $xml_namespace ) {
			return;
		}

		switch ( $local_name ) {
			case 'target-revision':
				$this->editor->set_target_revision( (int) $this->xml->get_attribute( '', 'rev' ) );
				break;

			case 'open-directory':
				if ( 0 === count( $this->directory_stack ) ) {
					$this->editor->open_root();
					$this->directory_stack[] = '';
				} else {
					$path = $this->child_path( $this->require_name_attribute( 'open-directory' ) );
					$this->editor->open_directory( $path );
					$this->directory_stack[] = $path;
				}
				break;

			case 'add-directory':
				$path = $this->child_path( $this->require_name_attribute( 'add-directory' ) );
				$this->editor->add_directory( $path );
				$this->directory_stack[] = $path;
				break;

			case 'open-file':
				$this->current_file     = $this->child_path( $this->require_name_attribute( 'open-file' ) );
				$this->current_file_md5 = null;
				$this->editor->open_file( $this->current_file );
				break;

			case 'add-file':
				$this->current_file     = $this->child_path( $this->require_name_attribute( 'add-file' ) );
				$this->current_file_md5 = null;
				$this->editor->add_file( $this->current_file );
				break;

			case 'txdelta':
				$base_checksum = $this->xml->get_attribute( '', 'base-checksum' );
				$this->editor->apply_textdelta( $this->current_file, is_string( $base_checksum ) ? $base_checksum : null );
				$this->start_collecting( 'txdelta' );
				break;

			case 'set-prop':
				$this->start_collecting( 'set-prop' );
				$this->collected_prop_name   = $this->require_name_attribute( 'set-prop' );
				$this->collected_prop_base64 = 'base64' === $this->xml->get_attribute( self::DAV_NAMESPACE, 'encoding' );
				break;

			case 'remove-prop':
				$this->change_property( $this->require_name_attribute( 'remove-prop' ), null );
				break;

			case 'delete-entry':
				$this->editor->delete_entry( $this->child_path( $this->require_name_attribute( 'delete-entry' ) ) );
				break;

			case 'absent-directory':
			case 'absent-file':
				// Hidden by authorization rules; nothing to materialize.
				break;
		}
	}

	private function handle_element_close() {
		$xml_namespace = $this->xml->get_tag_namespace();
		$local_name    = $this->xml->get_tag_local_name();

		if ( self::DAV_NAMESPACE === $xml_namespace && 'md5-checksum' === $local_name && 'md5-checksum' === $this->collecting ) {
			$this->current_file_md5 = trim( $this->collected_text );
			$this->stop_collecting();

			return;
		}

		if ( self::SVN_NAMESPACE !== $xml_namespace ) {
			return;
		}

		switch ( $local_name ) {
			case 'open-directory':
			case 'add-directory':
				$path = array_pop( $this->directory_stack );
				$this->editor->close_directory( $path );
				break;

			case 'open-file':
			case 'add-file':
				$this->editor->close_file( $this->current_file, $this->current_file_md5 );
				$this->current_file     = null;
				$this->current_file_md5 = null;
				break;

			case 'txdelta':
				$svndiff = base64_decode( $this->collected_text ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding wire data, not code.
				$this->stop_collecting();
				if ( false === $svndiff ) {
					throw new SvnException( 'Malformed update-report response: invalid base64 in a txdelta element.' );
				}
				$this->editor->write_textdelta_chunk( $this->current_file, $svndiff );
				$this->editor->textdelta_end( $this->current_file );
				break;

			case 'set-prop':
				$value = $this->collected_text;
				if ( $this->collected_prop_base64 ) {
					$value = base64_decode( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding wire data, not code.
					if ( false === $value ) {
						throw new SvnException( 'Malformed update-report response: invalid base64 in a set-prop element.' );
					}
				}
				$this->change_property( $this->collected_prop_name, $value );
				$this->stop_collecting();
				break;

			case 'update-report':
				$this->editor->close_edit();
				$this->finished = true;
				break;
		}
	}

	/**
	 * Routes a property change to the current file or directory,
	 * dropping svn:entry:* and svn:wc:* bookkeeping properties.
	 *
	 * @param string      $name   The property name.
	 * @param string|null $value  The property value, or null to delete.
	 */
	private function change_property( $name, $value ) {
		if ( 0 === strpos( $name, 'svn:entry:' ) || 0 === strpos( $name, 'svn:wc:' ) ) {
			return;
		}
		if ( null !== $this->current_file ) {
			$this->editor->change_file_property( $this->current_file, $name, $value );
		} else {
			$this->editor->change_directory_property( end( $this->directory_stack ), $name, $value );
		}
	}

	private function start_collecting( $what ) {
		$this->collecting     = $what;
		$this->collected_text = '';
	}

	private function stop_collecting() {
		$this->collecting            = null;
		$this->collected_text        = '';
		$this->collected_prop_name   = null;
		$this->collected_prop_base64 = false;
	}

	private function require_name_attribute( $element ) {
		$name = $this->xml->get_attribute( '', 'name' );
		if ( null === $name || false === $name ) {
			throw new SvnException( "Malformed update-report response: <S:{$element}> without a name attribute." );
		}

		return $name;
	}

	private function child_path( $name ) {
		$parent = end( $this->directory_stack );

		return svn_normalize_relative_path(
			'' === $parent || false === $parent ? $name : $parent . '/' . $name,
			false
		);
	}
}
