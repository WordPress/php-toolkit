<?php

namespace WordPress\Merge\Validate;

use WordPress\DataLiberation\BlockMarkup\BlockMarkupProcessor;
use WP_HTML_Processor;

class BlockMarkupMergeValidator implements MergeValidator {

	public function validate( $html ) {
		/**
		 * Validate the block markup. We treat any warning during parsing
		 * as a failure.
		 */
		$block_markup_processor = new BlockMarkupProcessor( $html );
		while ( $block_markup_processor->next_token() ) {
			$error = $block_markup_processor->get_last_error();
			if ( $error ) {
				throw new InvalidMergeException(
					sprintf( 'Merge resulted in invalid block markup: %s', $error->getMessage() ),
					0,
					$error
				);
			}
		}

		if ( count( $block_markup_processor->get_block_breadcrumbs() ) > 0 ) {
			throw new InvalidMergeException(
				sprintf(
					'Merge resulted in an unclosed blocks: %s',
					implode( ' > ', $block_markup_processor->get_block_breadcrumbs() )
				)
			);
		}

		/**
		 * Validate the resulting HTML
		 */

		// Validate the entire document
		self::assert_html_is_structurally_sound( $html );

		// Validate the inner HTML of each block separately in case
		// there's a structural error spanning the block boundary.
		$block_markup_processor = new BlockMarkupProcessor( $html );
		while ( $block_markup_processor->next_token() ) {
			if ( $block_markup_processor->get_token_type() !== '#block-comment' ) {
				continue;
			}
			$inner_html = $block_markup_processor->skip_and_get_block_inner_html();
			self::assert_html_is_structurally_sound( $inner_html );
		}
	}

	private function assert_html_is_structurally_sound( $html ) {
		$html          .= '<TERMINATE-PROCESSING>';
		$html_processor = WP_HTML_Processor::create_fragment( $html );

		/**
		 * Make the is_virtual() method public to enable deeper inspection.
		 *
		 * @TODO: Review the visibility in the HTML processor class.
		 */
		$reflection = new \ReflectionClass( $html_processor );
		$is_virtual = $reflection->getMethod( 'is_virtual' );
		$is_virtual->setAccessible( true );

		$seen_terminate_tag = false;
		while ( $html_processor->next_token() ) {
			$error = $html_processor->get_last_error();
			if ( $error ) {
				$source = $html_processor->get_unsupported_exception();
				throw new InvalidMergeException(
					sprintf( 'Merge resulted in invalid block markup: %s', $source ? $source->getMessage() : '' ),
					0,
					$source
				);
			}

			/**
			 * If merging three normative HTML documents yields a non-normative HTML
			 * document with virtual tags, the structure is likely corrupted.
			 *
			 * @TODO: is_virtual() is private. Let's review this with Dennis Snell.
			 */
			if ( $is_virtual->invoke( $html_processor ) ) {
				throw new InvalidMergeException(
					"Merge resulted in a non-normative block markup. The inputs are assumed to be normative, ".
                    "which means the merge result is likely corrupted."
				);
			}

			/**
			 * Workaround to let us inspect the stack of open elements right before
			 * the HTML processor implicitly generates virtual closers for the open
			 * elements.
			 *
			 * @TODO Remove the synthetic <TERMINATE-PROCESSING> tag once the HTML
			 * processor supports streaming. We'll be able to communicate we're
			 * still waiting for more input and do not wish to close open elements
			 * just because we've processed the entire HTML chunk.
			 */
			if ( $html_processor->get_tag() === 'TERMINATE-PROCESSING' ) {
				$seen_terminate_tag = true;
				$breadcrumbs        = $html_processor->get_breadcrumbs();
				if ( $breadcrumbs !== array( 'HTML', 'BODY', 'TERMINATE-PROCESSING' ) ) {
					array_pop( $breadcrumbs );
					throw new InvalidMergeException(
						sprintf(
							'Merge resulted in unclosed tags – the document likely got corrupted: %s',
							implode( ' > ', $breadcrumbs )
						)
					);
				}
				break;
			}
		}

		/**
		 * If we haven't stopped at <TERMINATE-PROCESSING>, it means the merged document
		 * ended with RCData, unfinished tag opener, or another type of HTML syntax that
		 * prevented the processor from recognizing the tag. This is a structural error
		 * and we won't let the caller consume that document.
		 */
		if ( ! $seen_terminate_tag ) {
			throw new InvalidMergeException( 'Merging resulted in a structurally corrupted document.' );
		}
	}
}
