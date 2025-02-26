<?php

namespace WordPress\DataLiberation\DataFormatProducer;

use WordPress\DataLiberation\DataFormatConsumer\BlocksWithMetadata;

/**
 * Turns Block Markup + Metadata into a metadata-annotated Block Markup.
 *
 * Example:
 *
 * The following block markup:
 *
 * <!-- wp:paragraph -->
 * <p>Hello <b>world</b>!</p>
 * <!-- /wp:paragraph -->
 *
 * And metadata:
 *
 * array(
 *     'post_title' => array( 'My first post' ),
 * )
 *
 * Becomes:
 *
 * <meta name="post_title" content="My first post">
 * <!-- wp:paragraph -->
 * <p>Hello <b>world</b>!</p>
 * <!-- /wp:paragraph -->
 */
class AnnotatedBlockMarkupProducer {

	/**
	 * @var BlocksWithMetadata
	 */
	private $blocks_with_meta;

	/**
	 * @var string
	 */
	private $result;

	public function __construct( BlocksWithMetadata $blocks_with_meta ) {
		$this->blocks_with_meta = $blocks_with_meta;
	}

	public function produce() {
		if ( null === $this->result ) {
			$this->result = '';
			foreach ( $this->blocks_with_meta->get_all_metadata() as $key => $values ) {
				foreach ( $values as $value ) {
					$p = new \WP_HTML_Tag_Processor( '<meta>' );
					$p->next_tag();
					$p->set_attribute( 'name', $key );
					if ( is_array( $value ) || is_object( $value ) ) {
						$value = json_encode( $value );
					}
					$p->set_attribute( 'content', $value );
					$p->set_attribute( 'type', gettype( $value ) );
					$this->result .= $p->get_updated_html() . "\n";
				}
			}
			$this->result .= "\n" . trim( $this->blocks_with_meta->get_block_markup(), "\n" );
		}
		return $this->result;
	}
}
