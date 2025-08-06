<?php

namespace WordPress\DataLiberation\DataFormatConsumer;

use WordPress\HTML\WP_HTML_Processor;
use function WordPress\Polyfill\parse_blocks;
use function WordPress\Polyfill\serialize_block;

/**
 * Converts a metadata-annotated block markup into block markup+metadata pair.
 *
 * Example:
 *
 * <meta name="post_title" content="My first post">
 * <!-- wp:paragraph {"className":"my-class"} -->
 * <p class="my-class">Hello world!</p>
 * <!-- /wp:paragraph -->
 *
 * Becomes:
 *
 * <!-- wp:paragraph -->
 * <p>Hello <b>world</b>!</p>
 * <!-- /wp:paragraph -->
 *
 * With the following metadata:
 *
 * array(
 *     'post_title' => array( 'My first post' ),
 * )
 */
class AnnotatedBlockMarkupConsumer implements DataFormatConsumer {

	/**
	 * @var string
	 */
	private $original_html;

	/**
	 * @var ConsumedBlockMarkup
	 */
	private $result;

	public function __construct( $original_html ) {
		$this->original_html = $original_html;
	}

	public function consume() {
		if ( ! $this->result ) {
			$block_markup = '';
			$metadata     = array();
			foreach ( parse_blocks( $this->original_html ) as $block ) {
				if ( $block['blockName'] === null ) {
					$html_converter = new MarkupProcessorConsumer( \WordPress\HTML\WP_HTML_Processor::create_fragment( $block['innerHTML'] ) );
					$result         = $html_converter->consume();
					$block_markup   .= $result->get_block_markup() . "\n";
					$metadata       = array_merge( $metadata, $result->get_all_metadata() );
				} else {
					$block_markup .= serialize_block( $block ) . "\n";
				}
			}
			$this->result = new BlocksWithMetadata(
				$block_markup,
				$metadata
			);
		}

		return $this->result;
	}
}
