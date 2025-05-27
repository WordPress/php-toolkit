<?php

namespace WordPress\DataLiberation\EntityReader;

use WordPress\DataLiberation\DataFormatConsumer\BlocksWithMetadata;
use WordPress\DataLiberation\DataFormatConsumer\MarkupProcessorConsumer;
use WordPress\Filesystem\Filesystem;
use WordPress\XML\XMLProcessor;

use function WordPress\Filesystem\wp_join_unix_paths;

/**
 * https://www.w3.org/AudioVideo/ebook/
 *
 * An EPUB Publication is transported as a single file (a "portable document") that contains:
 * * a Package Document (OPF file) which specifies all the Publication's constituent content documents and their required resources, defines a reading order  and associates Publication-level metadata and navigation information.
 *    * A metadata element including and/or referencing metadata applicable to the entire Publication and particular resources within it.
 *    * A manifest element: identifies (via IRI) and describes (via MIME media type) the set of resources that constitute the EPUB Publication.
 *    * A spine element : defines the default reading order of the Publication. (An ordered list of Publication Resources (EPUB Content Documents).
 *    * A Bindings element defines a set of custom handlers for media types not supported by EPUB3. If the Reading System cannot support the specific media type, it could use scripting fallback if supported.
 * * all Content Documents
 * * all other required resources for processing the Publication.
 *
 * The OCF Container is packaged into a physical single ZIP file containing:
 * * Mime Type file: application/epub+zip.
 * * META-INF folder (container file which points to the location of the .opf file), signatures, encryption, rights, are xml files
 * * OEBPS folder stores the book content .(opf, ncx, html, svg, png, css, etc. files)
 */
class EPubEntityReader implements EntityReader {

	protected $zip;
	protected $finished = false;
	protected $current_post_id;
	protected $remaining_html_files;
	protected $current_html_reader;
	protected $manifest;
	protected $manifest_path;

	public function __construct( Filesystem $zip, $first_post_id = 1 ) {
		$this->zip             = $zip;
		$this->current_post_id = $first_post_id;
	}

	public function next_entity() {
		if ( $this->finished ) {
			return false;
		}

		if ( null === $this->remaining_html_files ) {
			if ( false === $this->parse_manifest() ) {
				_doing_it_wrong( __METHOD__, 'The EPUB file did not contain a manifest.', '1.0.0' );
				$this->finished = true;

				return false;
			}

			foreach ( $this->manifest['items'] as $item ) {
				if ( $item['media-type'] !== 'application/xhtml+xml' ) {
					continue;
				}
				if ( ( $item['properties'] ?? '' ) === 'nav' ) {
					continue;
				}
				$this->remaining_html_files[] = wp_join_unix_paths(
					dirname( $this->manifest_path ),
					$item['href']
				);
			}
		}

		while ( true ) {
			if ( null !== $this->current_html_reader ) {
				if (
					! $this->current_html_reader->is_finished() &&
					$this->current_html_reader->next_entity()
				) {
					return true;
				}
			}

			if ( count( $this->remaining_html_files ) === 0 ) {
				$this->finished = true;

				return false;
			}

			$html_file        = array_shift( $this->remaining_html_files );
			$html             = $this->zip->get_contents( $html_file );
			$converter        = new MarkupProcessorConsumer(
				XMLProcessor::create_from_string( $html )
			);
			$blocks_with_meta = $converter->consume();
			$meta             = $blocks_with_meta->get_all_metadata();
			if ( ! array_key_exists( 'post_name', $meta ) ) {
				$meta['post_name'] = array(
					basename( $html_file, '.xhtml' ),
				);
			}
			if ( ! array_key_exists( 'post_title', $meta ) ) {
				$meta['post_title'] = array(
					basename( $html_file, '.xhtml' ),
				);
			}
			$meta['post_type']         = array( 'page' );
			$meta['post_status']       = array( 'publish' );
			$meta['link']              = array( 'file://' . $html_file );
			$blocks_with_meta          = new BlocksWithMetadata(
				$blocks_with_meta->get_block_markup(),
				$meta
			);
			$this->current_html_reader = new HTMLEntityReader(
				$blocks_with_meta,
				$this->current_post_id
			);
			++ $this->current_post_id;
		}

		return false;
	}

	/**
	 * An absolute path to the manifest file. Starting with slash.
	 */
	public function get_manifest_path() {
		if ( null === $this->manifest_path ) {
			$this->parse_manifest();
		}

		return $this->manifest_path;
	}

	private function parse_manifest() {
		if ( null !== $this->manifest ) {
			return true;
		}

		$xml = XMLProcessor::create_from_string(
			$this->zip->get_contents( 'META-INF/container.xml' )
		);
		if ( false === $xml->next_tag( 'rootfile' ) ) {
			return false;
		}

		$full_path = $xml->get_attribute( 'full-path' );
		if ( ! $full_path ) {
			return false;
		}

		$this->manifest_path = '/' . ltrim( $full_path, '/' );
		$manifest            = $this->zip->get_contents( $this->manifest_path );
		if ( ! $manifest ) {
			return false;
		}
		$xml = XMLProcessor::create_from_string(
			$manifest
		);

		$parsed = array(
			'metadata' => array(),
			'items'    => array(),
		);
		while ( $xml->next_tag() ) {
			$parsed_entry = array();
			$keys         = $xml->get_attribute_qualified_names_with_prefix( '' );
			foreach ( $keys as $key ) {
				$parsed_entry[ $key ] = $xml->get_attribute( $key );
			}
			if ( $xml->matches_breadcrumbs( array( 'metadata', '*' ) ) ) {
				$parsed['metadata'][] = array(
					'tag'        => $xml->get_tag_local_name(),
					'attributes' => $parsed_entry,
				);
			} elseif ( $xml->matches_breadcrumbs( array( 'manifest', 'item' ) ) ) {
				$parsed_entry['type'] = 'item';
				$parsed['items'][]    = $parsed_entry;
			}
		}
		$this->manifest = $parsed;

		return true;
	}

	public function get_entity() {
		return $this->current_html_reader->get_entity();
	}

	public function is_finished(): bool {
		return $this->finished;
	}

	public function get_reentrancy_cursor() {
		return '';
	}
}
