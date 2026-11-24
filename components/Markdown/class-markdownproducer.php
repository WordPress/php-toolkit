<?php

namespace WordPress\Markdown;

use WordPress\DataLiberation\DataFormatConsumer\BlocksWithMetadata;
use WordPress\DataLiberation\DataFormatProducer\DataFormatProducer;
use WordPress\DataLiberation\DataLiberationHTMLProcessor;

/**
 * Converts WordPress blocks and metadata to Markdown with frontmatter.
 *
 * Note that converting HTML -> Markdown -> HTML will yield a different
 * string than the original HTML:
 *
 * * Markdown won't preserve all the tiny syntactic details such as
 *   <hr> vs <hr />.
 * * Some block attributes, such as class, are lost in the conversion process.
 *   MarkdownConsumer does not currently make any effort to serialize blocks
 *   with unrepresentable attributes as fenced code fragments.
 */
class MarkdownProducer implements DataFormatProducer {
	private $block_markup;
	private $state;
	private $parents = array();
	private $metadata;
	private $markdown;

	public function __construct( BlocksWithMetadata $blocks_with_meta ) {
		$this->block_markup = $blocks_with_meta->get_block_markup();
		$this->state        = array(
			'indent' => array(),
			'listStyle' => array(),
		);
		$this->metadata     = $blocks_with_meta->get_all_metadata( array( 'first_value_only' => true ) );
	}

	public function produce() {
		if ( null === $this->markdown ) {
			$this->markdown  = '';
			$this->markdown .= $this->frontmatter();
			$this->markdown .= $this->blocks_to_markdown( parse_blocks( $this->block_markup ) );
		}
		return $this->markdown;
	}

	private function frontmatter() {
		if ( empty( $this->metadata ) ) {
			return '';
		}
		$frontmatter = '';
		foreach ( $this->metadata as $key => $value ) {
			$frontmatter .= "$key: " . json_encode( $value ) . "\n";
		}
		return "---\n$frontmatter---\n\n";
	}

	private function blocks_to_markdown( $blocks ) {
		$output = '';
		foreach ( $blocks as $block ) {
			array_push( $this->parents, $block['blockName'] );
			$output .= $this->block_to_markdown( $block );
			array_pop( $this->parents );
		}
		return $output;
	}

	private function block_to_markdown( $block ) {
		$block_name   = $block['blockName'];
		$attributes   = $block['attrs'] ?? array();
		$inner_html   = $block['innerHTML'] ?? '';
		$inner_blocks = $block['innerBlocks'] ?? array();

		switch ( $block_name ) {
			case 'core/paragraph':
				return $this->html_to_markdown( $inner_html ) . "\n\n";

			case 'core/quote':
				if ( empty( $inner_blocks ) ) {
					$inner_blocks = $this->quote_paragraphs_from_html( $inner_html );
				}
				$content = $this->blocks_to_markdown( $inner_blocks );
				$lines   = explode( "\n", $content );
				return implode(
					"\n",
					array_map(
						function ( $line ) {
							return "> $line";
						},
						$lines
					)
				) . "\n\n";

			case 'core/code':
				$code     = $this->html_to_markdown( $inner_html );
				$language = $attributes['language'] ?? '';
				$fence    = str_repeat( '`', max( 3, $this->longest_sequence_of( $code, '`' ) + 1 ) );
				return "{$fence}{$language}\n{$code}\n{$fence}\n\n";

			case 'core/image':
				if ( ! isset( $attributes['url'] ) ) {
					$processor = DataLiberationHTMLProcessor::create_fragment( $inner_html );
					if ( $processor->next_tag( 'img' ) ) {
						$attributes['url'] = $processor->get_attribute( 'src' );
						$attributes['alt'] = $processor->get_attribute( 'alt' );
					}
				}

				$escaped_url = self::escape_url(
					$attributes['url'] ?? ''
				);

				$escaped_alt = $attributes['alt'] ?? '';
				$escaped_alt = str_replace( array( '[', ']' ), '', $escaped_alt );
				return '![' . $escaped_alt . '](' . $escaped_url . ")\n\n";

			case 'core/heading':
				$level = $attributes['level'] ?? null;
				if ( null === $level ) {
					$processor = DataLiberationHTMLProcessor::create_fragment( $inner_html );
					if ( $processor->next_tag() ) {
						$tag = $processor->get_tag();
						if ( strlen( $tag ) > 1 && is_numeric( $tag[1] ) ) {
							$level = (int) $tag[1];
						}
					}
					if ( null === $level ) {
						$level = 1;
					}
				}
				$content = $this->html_to_markdown( $inner_html );
				return str_repeat( '#', $level ) . ' ' . $content . "\n\n";

			case 'core/table':
				// Accumulate all the table contents to compute the markdown
				// column widths.
				$processor   = DataLiberationHTMLProcessor::create_fragment( $inner_html );
				$rows        = array();
				$header      = array();
				$in_header   = false;
				$current_row = array();

				while ( $processor->next_token() ) {
					if ( '#tag' !== $processor->get_token_type() ) {
						continue;
					}

					$tag       = $processor->get_tag();
					$is_closer = $processor->is_tag_closer();

					if ( 'THEAD' === $tag && ! $is_closer ) {
						$in_header = true;
					} elseif ( 'THEAD' === $tag && $is_closer ) {
						$in_header = false;
					} elseif ( 'TR' === $tag && $is_closer ) {
						if ( $in_header ) {
							$header = $current_row;
						} else {
							$rows[] = $current_row;
						}
						$current_row = array();
					} elseif ( ( 'TH' === $tag || 'TD' === $tag ) && ! $is_closer ) {
						$cell_content  = $processor->get_inner_html();
						$current_row[] = $this->html_to_markdown( $cell_content );
					}
				}

				if ( empty( $header ) && ! empty( $rows ) ) {
					$header = array_shift( $rows );
				}

				if ( empty( $header ) ) {
					return '';
				}

				$col_widths = array_map( 'strlen', $header );
				foreach ( $rows as $row ) {
					foreach ( $row as $i => $cell ) {
						$col_widths[ $i ] = max( $col_widths[ $i ], strlen( $cell ) );
					}
				}

				$padded_header = array_map(
					function ( $cell, $width ) {
						return str_pad( $cell, $width );
					},
					$header,
					$col_widths
				);
				$markdown      = '| ' . implode( ' | ', $padded_header ) . " |\n";

				$separator_cells = array_map(
					function ( $width ) {
						return str_repeat( '-', $width + 2 );
					},
					$col_widths
				);
				$markdown       .= '|' . implode( '|', $separator_cells ) . "|\n";

				foreach ( $rows as $row ) {
					$padded_cells = array_map(
						function ( $cell, $width ) {
							return str_pad( $cell, $width );
						},
						$row,
						$col_widths
					);
					$markdown    .= '| ' . implode( ' | ', $padded_cells ) . " |\n";
				}

				return $markdown . "\n";

			case 'core/list':
				if ( empty( $inner_blocks ) ) {
					$inner_blocks = $this->list_items_from_html( $inner_html );
				}
				array_push(
					$this->state['listStyle'],
					array(
						'style' => isset( $attributes['ordered'] ) && $attributes['ordered'] ? ( $attributes['type'] ?? 'decimal' ) : '-',
						'count' => $attributes['start'] ?? 1,
					)
				);
				$list = $this->blocks_to_markdown( $inner_blocks );
				array_pop( $this->state['listStyle'] );
				if ( $this->has_parent( 'core/list-item' ) ) {
					return $list;
				}
				return $list . "\n";

			case 'core/list-item':
				if ( empty( $this->state['listStyle'] ) ) {
					return '';
				}

				$item          = end( $this->state['listStyle'] );
				$bullet        = $this->get_list_bullet( $item );
				$bullet_indent = str_repeat( ' ', strlen( $bullet ) + 1 );

				$content       = $this->html_to_markdown( $inner_html );
				$content_parts = explode( "\n", $content, 2 );
				$content_parts = array_map( 'trim', $content_parts );
				$first_line    = $content_parts[0];
				$rest_lines    = $content_parts[1] ?? '';

				++$item['count'];

				if ( empty( $inner_html ) ) {
					$output = implode( '', $this->state['indent'] ) . "$bullet $first_line\n";
					array_push( $this->state['indent'], $bullet_indent );
					if ( $rest_lines ) {
						$output .= $this->indent( $rest_lines, $bullet_indent );
					}
					array_pop( $this->state['indent'] );
					return $output;
				}

				$markdown = $this->indent( "$bullet $first_line\n" );

				array_push( $this->state['indent'], $bullet_indent );
				if ( $rest_lines ) {
					$markdown .= $this->indent( $rest_lines ) . "\n";
				}
				$inner_blocks_markdown = $this->blocks_to_markdown(
					$inner_blocks
				);
				if ( $inner_blocks_markdown ) {
					$markdown .= $inner_blocks_markdown . "\n";
				}
				array_pop( $this->state['indent'] );

				$markdown = rtrim( $markdown, "\n" );
				if ( $this->has_parent( 'core/list-item' ) ) {
					$markdown .= "\n";
				} else {
					$markdown .= "\n\n";
				}

				return $markdown;

			case 'core/separator':
				return "\n---\n\n";

			default:
				// Short-circuit empty entries produced by the block parser.
				if ( ! $block_name ) {
					return '';
				}
				$markdown   = array();
				$markdown[] = '';
				$markdown[] = '```block';
				$markdown[] = \serialize_block( $block );
				$markdown[] = '```';
				$markdown[] = '';
				return implode( "\n", $markdown );
		}
	}

	/**
	 * Builds core/paragraph blocks from a core/quote block saved in the older
	 * format, with the paragraphs written directly in its HTML instead of as
	 * inner blocks.
	 *
	 * @param string $html The quote block's inner HTML.
	 * @return array[] Paragraph blocks, in the shape parse_blocks() returns.
	 */
	private function quote_paragraphs_from_html( $html ) {
		$paragraphs = array();
		foreach ( $this->get_child_elements_inner_html( $html, array( 'BLOCKQUOTE' ), 'P' ) as $paragraph_html ) {
			$paragraphs[] = self::create_block( 'core/paragraph', '<p>' . $paragraph_html . '</p>' );
		}

		// A quote whose text isn't wrapped in <p> elements becomes a single paragraph.
		if ( empty( $paragraphs ) && '' !== trim( $this->html_to_markdown( $html ) ) ) {
			$paragraphs[] = self::create_block( 'core/paragraph', $html );
		}

		return $paragraphs;
	}

	/**
	 * Builds core/list-item blocks from a core/list block saved in the older
	 * format, with the items written directly in its HTML instead of as
	 * inner blocks.
	 *
	 * @param string $html The list block's inner HTML.
	 * @return array[] List item blocks, in the shape parse_blocks() returns.
	 */
	private function list_items_from_html( $html ) {
		$items = array();
		foreach ( $this->get_child_elements_inner_html( $html, array( 'UL', 'OL' ), 'LI' ) as $item_html ) {
			$items[] = $this->list_item_from_html( $item_html );
		}

		return $items;
	}

	/**
	 * Builds a core/list-item block from the inner HTML of an <li> element.
	 *
	 * Nested <ul> and <ol> elements become core/list inner blocks, and the
	 * rest of the HTML becomes the item's own content.
	 *
	 * @param string $html The <li> element's inner HTML.
	 * @return array A list item block, in the shape parse_blocks() returns.
	 */
	private function list_item_from_html( $html ) {
		$processor    = DataLiberationHTMLProcessor::create_fragment( $html );
		$item_html    = '';
		$nested_lists = array();
		// End of the last real (non-virtual) token, as a byte offset in $html.
		$last_end = 0;

		while ( $processor && $processor->next_token() ) {
			if ( $processor->is_virtual() ) {
				continue;
			}

			$tag = '#tag' === $processor->get_token_type() ? $processor->get_tag() : null;
			if ( ( 'UL' === $tag || 'OL' === $tag ) && ! $processor->is_tag_closer() ) {
				$attributes = array( 'ordered' => 'OL' === $tag );
				$start      = $processor->get_attribute( 'start' );
				if ( is_numeric( $start ) ) {
					$attributes['start'] = (int) $start;
				}

				$list_start = $last_end;
				if ( $processor->skip_to_closer() && ! $processor->is_virtual() ) {
					$last_end = $processor->get_string_index_after_current_token();
				} else {
					// The list isn't closed, so it runs to the end of the item.
					$last_end = strlen( $html );
				}

				$nested_lists[] = self::create_block(
					'core/list',
					'',
					$attributes,
					$this->list_items_from_html( substr( $html, $list_start, $last_end - $list_start ) )
				);
				continue;
			}

			$end        = $processor->get_string_index_after_current_token();
			$item_html .= substr( $html, $last_end, $end - $last_end );
			$last_end   = $end;
		}

		return self::create_block( 'core/list-item', '<li>' . $item_html . '</li>', array(), $nested_lists );
	}

	/**
	 * Returns the inner HTML of each $child_tag element that is a direct
	 * child of the first element named in $parent_tags.
	 *
	 * Scans forward only and slices the input by byte offsets, so elements
	 * with an implied closing tag, such as `<li>One<li>Two`, work too.
	 *
	 * @param string   $html        HTML to search.
	 * @param string[] $parent_tags Upper-case tag names the parent may have.
	 * @param string   $child_tag   Upper-case tag name of the children.
	 * @return string[] Inner HTML of each child element.
	 */
	private function get_child_elements_inner_html( $html, $parent_tags, $child_tag ) {
		$processor = DataLiberationHTMLProcessor::create_fragment( $html );
		if ( ! $processor ) {
			return array();
		}

		$child_depth = null;
		while ( $processor->next_tag() ) {
			if ( in_array( $processor->get_tag(), $parent_tags, true ) ) {
				$child_depth = $processor->get_current_depth() + 1;
				break;
			}
		}
		if ( null === $child_depth ) {
			return array();
		}

		$children      = array();
		$content_start = null;
		// End of the last real (non-virtual) token, as a byte offset in $html.
		$last_end = $processor->get_string_index_after_current_token();

		while ( $processor->next_token() ) {
			$is_child = '#tag' === $processor->get_token_type() && $child_tag === $processor->get_tag();
			$depth    = $processor->get_current_depth();

			if ( $is_child && ! $processor->is_tag_closer() && $child_depth === $depth ) {
				$content_start = $processor->is_virtual() ? $last_end : $processor->get_string_index_after_current_token();
			} elseif ( $is_child && $processor->is_tag_closer() && $child_depth - 1 === $depth && null !== $content_start ) {
				// The closer may be virtual, so the content ends where the last real token did.
				$children[]    = substr( $html, $content_start, $last_end - $content_start );
				$content_start = null;
			} elseif ( $processor->is_tag_closer() && $depth < $child_depth - 1 ) {
				// The parent element was closed.
				break;
			}

			if ( ! $processor->is_virtual() ) {
				$last_end = $processor->get_string_index_after_current_token();
			}
		}

		if ( null !== $content_start ) {
			$children[] = substr( $html, $content_start, $last_end - $content_start );
		}

		return $children;
	}

	/**
	 * Creates a block array in the shape parse_blocks() returns.
	 *
	 * @param string  $name         Block name.
	 * @param string  $inner_html   The block's own HTML, without inner blocks.
	 * @param array   $attributes   Block attributes.
	 * @param array[] $inner_blocks Inner blocks.
	 * @return array The block.
	 */
	private static function create_block( $name, $inner_html, $attributes = array(), $inner_blocks = array() ) {
		return array(
			'blockName'    => $name,
			'attrs'        => $attributes,
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => $inner_html,
			'innerContent' => array( $inner_html ),
		);
	}

	private function html_to_markdown( $html, $parents = array() ) {
		$processor = DataLiberationHTMLProcessor::create_fragment( $html );
		$markdown  = '';

		$last_href = null;
		while ( $processor->next_token() ) {
			if ( '#text' === $processor->get_token_type() ) {
				$markdown .= $processor->get_modifiable_text();
				continue;
			} elseif ( '#tag' !== $processor->get_token_type() ) {
				continue;
			}

			$tag_name = $processor->get_tag();
			$sign     = $processor->is_tag_closer() ? '-' : (
				$processor->expects_closer() ? '+' : ''
			);
			$event    = $sign . $tag_name;
			switch ( $event ) {
				case '+B':
				case '-B':
				case '+STRONG':
				case '-STRONG':
					$markdown .= '**';
					break;

				case '+I':
				case '-I':
				case '+EM':
				case '-EM':
					$markdown .= '*';
					break;

				case '+DEL':
				case '-DEL':
					$markdown .= '~~';
					break;

				case '+CODE':
				case '-CODE':
					if ( ! $this->has_parent( 'core/code' ) ) {
						$markdown .= '`';
					}
					break;

				case '+A':
					$last_href = self::escape_url(
						$processor->get_attribute( 'href' ) ?? ''
					);
					$markdown .= '[';
					break;

				case '-A':
					$markdown .= "]($last_href)";
					$last_href = null;
					break;

				case 'BR':
					$markdown .= "\n";
					break;

				case 'IMG':
					$markdown .= '![' . $processor->get_attribute( 'alt' ) . '](' . $processor->get_attribute( 'src' ) . ')';
					break;
			}
		}

		// The HTML processor gives us all the whitespace verbatim
		// as it was encountered in the byte stream/
		// Let's normalize it to a single space.
		$markdown = trim( $markdown, "\n" );

		// The ltrim() here is arbitrary and potentially wrong,
		// @TODO: Investigate this further and potentially remove
		// all trimming of space characters.
		$markdown = ltrim( $markdown, "\n " );
		$markdown = preg_replace( '/\n+/', "\n", $markdown );
		return $markdown;
	}

	// @TODO: Figure out the correct markdown escaping for URLs.
	private static function escape_url( $url ) {
		$escaped_url = str_replace( ' ', '%20', $url );
		$escaped_url = str_replace( ')', '%29', $escaped_url );
		return $escaped_url;
	}

	private function has_parent( $parent_block_name ) {
		return in_array( $parent_block_name, $this->parents, true );
	}

	private function get_list_bullet( $item ) {
		if ( '-' === $item['style'] ) {
			return '-';
		}
		return $item['count'] . '.';
	}

	private function indent( $data ) {
		if ( empty( $this->state['indent'] ) ) {
			return $data;
		}

		$indent = implode( '', $this->state['indent'] );
		$lines  = explode( "\n", $data );
		return implode(
			"\n",
			array_map(
				function ( $line ) use ( $indent ) {
					return empty( $line ) ? $line : $indent . $line;
				},
				$lines
			)
		);
	}

	private function longest_sequence_of( $input, $substring ) {
		$longest = 0;
		$current = 0;
		$len     = strlen( $input );

		for ( $i = 0; $i < $len; $i++ ) {
			if ( $input[ $i ] === $substring ) {
				++$current;
				$longest = max( $longest, $current );
			} else {
				$current = 0;
			}
		}

		return $longest;
	}
}
