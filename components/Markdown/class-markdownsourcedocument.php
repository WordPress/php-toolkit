<?php

namespace WordPress\Markdown;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Parser\MarkdownParser;
use WordPress\DataLiberation\DataFormatConsumer\BlocksWithMetadata;

/**
 * Tracks a Markdown document's original source while exposing editable block markup.
 *
 * This class is meant for editor save flows where Markdown is converted to
 * WordPress blocks, edited, and converted back to Markdown. A normal
 * block-to-Markdown conversion may choose canonical Markdown syntax for the
 * whole document. This class instead records source slices for top-level
 * Markdown blocks and reuses those exact bytes for blocks that did not change.
 *
 * When source blocks cannot be mapped one-to-one to WordPress blocks, the
 * document falls back to a single source unit. That fallback still preserves
 * the original Markdown byte-for-byte when the edited block markup is
 * semantically unchanged.
 */
class MarkdownSourceDocument {

	private $markdown;
	private $block_markup;
	private $metadata;
	private $prefix;
	private $units;

	/**
	 * Creates a source-aware document.
	 *
	 * @param string               $markdown     The original Markdown source.
	 * @param string               $block_markup The block markup produced from the source.
	 * @param array                $metadata     Metadata extracted while consuming Markdown.
	 * @param string               $prefix       Source bytes before the first mapped unit.
	 * @param MarkdownSourceUnit[] $units        Source units mapped to block markup.
	 */
	private function __construct( $markdown, $block_markup, $metadata, $prefix, $units ) {
		$this->markdown     = $markdown;
		$this->block_markup = $block_markup;
		$this->metadata     = $metadata;
		$this->prefix       = $prefix;
		$this->units        = $units;
	}

	/**
	 * Creates a source-aware document from Markdown source.
	 *
	 * The source is parsed twice: once by MarkdownConsumer to obtain WordPress
	 * block markup, and once by CommonMark to obtain top-level block source
	 * positions. When both views contain the same number of top-level blocks,
	 * each block becomes a MarkdownSourceUnit. Otherwise the full document is
	 * kept as one conservative fallback unit.
	 *
	 * @param string $markdown The Markdown source to parse.
	 * @return self Source-aware document containing block markup and source units.
	 */
	public static function from_markdown( $markdown ) {
		$markdown = (string) $markdown;
		$consumer = new MarkdownConsumer( $markdown );
		$blocks_with_metadata = $consumer->consume();
		$block_markup = $blocks_with_metadata->get_block_markup();
		$blocks = self::named_blocks( parse_blocks( $block_markup ) );
		$source_blocks = self::source_blocks( $markdown );
		$line_offsets = self::line_offsets( $markdown );
		$source_line_offset = self::frontmatter_line_offset( $markdown );
		$units = array();

		// Some Markdown constructs do not map one-to-one to named WordPress
		// blocks. Preserve the whole source for unchanged saves in those cases.
		if ( count( $source_blocks ) !== count( $blocks ) ) {
			return new self(
				$markdown,
				$block_markup,
				$blocks_with_metadata->get_all_metadata(),
				'',
				array(
					new MarkdownSourceUnit(
						substr( $markdown, 0 ),
						0,
						strlen( $markdown ),
						$block_markup,
						self::semantic_hash_for_markup( $block_markup )
					),
				)
			);
		}

		$prefix_end = count( $source_blocks ) > 0 ? $line_offsets[ $source_line_offset + $source_blocks[0]->getStartLine() - 1 ] : strlen( $markdown );
		$prefix = substr( $markdown, 0, $prefix_end );
		$count = count( $source_blocks );

		for ( $index = 0; $index < $count; $index++ ) {
			$source_block = $source_blocks[ $index ];
			$start = $line_offsets[ $source_line_offset + $source_block->getStartLine() - 1 ];
			$end = $index + 1 < $count
				? $line_offsets[ $source_line_offset + $source_blocks[ $index + 1 ]->getStartLine() - 1 ]
				: strlen( $markdown );
			$block_markup_for_unit = serialize_block( $blocks[ $index ] );
			$units[] = new MarkdownSourceUnit(
				substr( $markdown, $start, $end - $start ),
				$start,
				$end,
				$block_markup_for_unit,
				self::semantic_hash_for_block( $blocks[ $index ] )
			);
		}

		return new self(
			$markdown,
			$block_markup,
			$blocks_with_metadata->get_all_metadata(),
			$prefix,
			$units
		);
	}

	/**
	 * Returns the WordPress block markup generated from the original Markdown.
	 *
	 * @return string Generated block markup.
	 */
	public function get_block_markup() {
		return $this->block_markup;
	}

	/**
	 * Returns metadata extracted from the Markdown document.
	 *
	 * @return array Metadata keyed by field name.
	 */
	public function get_all_metadata() {
		return $this->metadata;
	}

	/**
	 * Returns the source units mapped to top-level WordPress blocks.
	 *
	 * @return MarkdownSourceUnit[] Source units in document order.
	 */
	public function get_source_units() {
		return $this->units;
	}

	/**
	 * Applies edited block markup to the original Markdown source.
	 *
	 * Unchanged blocks are matched by semantic hash and copied from the original
	 * Markdown source. Changed and inserted blocks are serialized with
	 * MarkdownProducer. For changed blocks, surrounding line-oriented trivia is
	 * reused from the replaced source unit so CRLF separators, blank lines, and
	 * missing final newlines are not normalized.
	 *
	 * @param string $edited_block_markup The edited WordPress block markup.
	 * @return string Patched Markdown source.
	 */
	public function patch_markdown( $edited_block_markup ) {
		if ( 1 === count( $this->units ) && $this->units[0]->get_semantic_hash() === self::semantic_hash_for_markup( $edited_block_markup ) ) {
			return $this->markdown;
		}

		$edited_blocks = self::named_blocks( parse_blocks( (string) $edited_block_markup ) );
		$original_hashes = array_map(
			function ( MarkdownSourceUnit $unit ) {
				return $unit->get_semantic_hash();
			},
			$this->units
		);
		$edited_hashes = array_map( array( __CLASS__, 'semantic_hash_for_block' ), $edited_blocks );
		$matches = self::longest_common_subsequence( $original_hashes, $edited_hashes );
		$markdown = $this->prefix;
		$original_index = 0;
		$edited_index = 0;

		foreach ( $matches as $match ) {
			$markdown .= $this->markdown_for_changed_blocks(
				$edited_blocks,
				$edited_index,
				$match['edited'],
				$original_index,
				$match['original']
			);
			$markdown .= $this->units[ $match['original'] ]->get_source();
			$original_index = $match['original'] + 1;
			$edited_index = $match['edited'] + 1;
		}

		$markdown .= $this->markdown_for_changed_blocks(
			$edited_blocks,
			$edited_index,
			count( $edited_blocks ),
			$original_index,
			count( $this->units )
		);

		return $markdown;
	}

	/**
	 * Returns the original Markdown source.
	 *
	 * @return string Original Markdown source.
	 */
	public function get_original_markdown() {
		return $this->markdown;
	}

	/**
	 * Serializes edited blocks that appear between two unchanged matches.
	 *
	 * The original range may be shorter than the edited range when blocks were
	 * inserted. Only replacements can borrow source trivia from original units;
	 * pure insertions use MarkdownProducer's normal block separators.
	 *
	 * @param array[] $edited_blocks  Edited block objects from parse_blocks().
	 * @param int     $edited_start   First edited block index to serialize.
	 * @param int     $edited_end     One past the last edited block index.
	 * @param int     $original_start First original source unit index in the gap.
	 * @param int     $original_end   One past the last original source unit index.
	 * @return string Markdown for changed or inserted blocks.
	 */
	private function markdown_for_changed_blocks( array $edited_blocks, $edited_start, $edited_end, $original_start, $original_end ) {
		$markdown = '';
		$original_available = $original_end - $original_start;
		for ( $edited_index = $edited_start; $edited_index < $edited_end; $edited_index++ ) {
			$relative_index = $edited_index - $edited_start;
			$original_index = $original_start + $relative_index;
			if ( $relative_index < $original_available && isset( $this->units[ $original_index ] ) ) {
				$markdown .= $this->units[ $original_index ]->get_leading_trivia();
				$markdown .= self::with_trailing_trivia(
					self::markdown_for_block( $edited_blocks[ $edited_index ] ),
					$this->units[ $original_index ]->get_trailing_trivia()
				);
				continue;
			}
			$markdown .= self::markdown_for_block( $edited_blocks[ $edited_index ] );
		}

		return $markdown;
	}

	/**
	 * Replaces MarkdownProducer's trailing line endings with source trivia.
	 *
	 * @param string $markdown        Serialized Markdown for a changed block.
	 * @param string $trailing_trivia Original trailing trivia to preserve.
	 * @return string Serialized Markdown with original trailing trivia.
	 */
	private static function with_trailing_trivia( $markdown, $trailing_trivia ) {
		return self::trim_trailing_line_endings( $markdown ) . $trailing_trivia;
	}

	/**
	 * Removes trailing CR and LF bytes from a Markdown fragment.
	 *
	 * @param string $text Markdown text.
	 * @return string Markdown text without trailing line endings.
	 */
	private static function trim_trailing_line_endings( $text ) {
		while ( '' !== $text ) {
			$last = $text[ strlen( $text ) - 1 ];
			if ( "\n" !== $last && "\r" !== $last ) {
				break;
			}
			$text = substr( $text, 0, -1 );
		}

		return $text;
	}

	/**
	 * Serializes a single WordPress block to Markdown.
	 *
	 * @param array $block Parsed block object.
	 * @return string Markdown representation of the block.
	 */
	private static function markdown_for_block( array $block ) {
		$producer = new MarkdownProducer(
			new BlocksWithMetadata(
				serialize_block( $block ),
				array()
			)
		);
		return $producer->produce();
	}

	/**
	 * Returns the top-level CommonMark source blocks for a Markdown document.
	 *
	 * @param string $markdown Markdown source.
	 * @return AbstractBlock[] Top-level CommonMark blocks.
	 */
	private static function source_blocks( $markdown ) {
		$environment = new Environment( array() );
		$environment->addExtension( new CommonMarkCoreExtension() );
		$environment->addExtension( new GithubFlavoredMarkdownExtension() );
		$environment->addExtension(
			new \Webuni\FrontMatter\Markdown\FrontMatterLeagueCommonMarkExtension(
				new \Webuni\FrontMatter\FrontMatter()
			)
		);
		$parser = new MarkdownParser( $environment );
		$document = $parser->parse( (string) $markdown );
		$blocks = array();

		foreach ( $document->children() as $child ) {
			if ( $child instanceof AbstractBlock ) {
				$blocks[] = $child;
			}
		}

		return $blocks;
	}

	/**
	 * Returns only named WordPress blocks from a parsed block list.
	 *
	 * @param array[] $blocks Parsed block objects.
	 * @return array[] Named WordPress block objects.
	 */
	private static function named_blocks( array $blocks ) {
		$named = array();
		foreach ( $blocks as $block ) {
			if ( isset( $block['blockName'] ) && null !== $block['blockName'] ) {
				$named[] = $block;
			}
		}
		return $named;
	}

	/**
	 * Returns byte offsets for the start of each source line.
	 *
	 * @param string $text Source text.
	 * @return int[] Byte offsets, starting with 0.
	 */
	private static function line_offsets( $text ) {
		$offsets = array( 0 );
		$length = strlen( $text );
		for ( $i = 0; $i < $length; $i++ ) {
			if ( "\n" === $text[ $i ] ) {
				$offsets[] = $i + 1;
			}
		}
		return $offsets;
	}

	/**
	 * Returns the number of frontmatter lines before Markdown body content.
	 *
	 * CommonMark source positions are relative to the Markdown body when the
	 * frontmatter extension consumes metadata. This offset maps those line
	 * numbers back to byte offsets in the original source.
	 *
	 * @param string $markdown Markdown source.
	 * @return int Number of leading frontmatter lines.
	 */
	private static function frontmatter_line_offset( $markdown ) {
		$lines = self::lines_with_endings( $markdown );
		if ( 0 === count( $lines ) ) {
			return 0;
		}

		$first_line = self::trim_line_ending( $lines[0] );
		if ( '---' !== $first_line && '+++' !== $first_line ) {
			return 0;
		}

		for ( $index = 1; $index < count( $lines ); $index++ ) {
			if ( self::trim_line_ending( $lines[ $index ] ) === $first_line ) {
				return $index + 1;
			}
		}

		return 0;
	}

	/**
	 * Splits text into lines while retaining each line ending.
	 *
	 * @param string $text Source text.
	 * @return string[] Lines, each including its original line ending.
	 */
	private static function lines_with_endings( $text ) {
		$lines = array();
		$line_start = 0;
		$length = strlen( $text );

		for ( $i = 0; $i < $length; $i++ ) {
			if ( "\n" !== $text[ $i ] && "\r" !== $text[ $i ] ) {
				continue;
			}
			if ( "\r" === $text[ $i ] && $i + 1 < $length && "\n" === $text[ $i + 1 ] ) {
				$i++;
			}
			$lines[] = substr( $text, $line_start, $i - $line_start + 1 );
			$line_start = $i + 1;
		}

		if ( $line_start < $length ) {
			$lines[] = substr( $text, $line_start );
		}

		return $lines;
	}

	/**
	 * Removes one line's trailing CR and LF bytes.
	 *
	 * @param string $line Source line.
	 * @return string Line without its trailing line ending.
	 */
	private static function trim_line_ending( $line ) {
		while ( '' !== $line ) {
			$last = $line[ strlen( $line ) - 1 ];
			if ( "\n" !== $last && "\r" !== $last ) {
				break;
			}
			$line = substr( $line, 0, -1 );
		}

		return $line;
	}

	/**
	 * Returns a semantic hash for block markup.
	 *
	 * @param string $block_markup WordPress block markup.
	 * @return string Hash of the canonical block structure.
	 */
	private static function semantic_hash_for_markup( $block_markup ) {
		return hash( 'sha256', json_encode( self::canonical_blocks( self::named_blocks( parse_blocks( $block_markup ) ) ) ) );
	}

	/**
	 * Returns a semantic hash for one block.
	 *
	 * @param array $block Parsed block object.
	 * @return string Hash of the canonical block structure.
	 */
	private static function semantic_hash_for_block( array $block ) {
		return hash( 'sha256', json_encode( self::canonical_block( $block ) ) );
	}

	/**
	 * Returns canonical representations for a list of blocks.
	 *
	 * @param array[] $blocks Parsed block objects.
	 * @return array[] Canonical block structures.
	 */
	private static function canonical_blocks( array $blocks ) {
		$canonical = array();
		foreach ( $blocks as $block ) {
			$canonical[] = self::canonical_block( $block );
		}
		return $canonical;
	}

	/**
	 * Returns a canonical representation of a block for semantic comparison.
	 *
	 * Attribute order is normalized so equivalent blocks can be matched even
	 * when serialization order differs.
	 *
	 * @param array $block Parsed block object.
	 * @return array Canonical block structure.
	 */
	private static function canonical_block( array $block ) {
		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		self::sort_recursive( $attrs );
		$inner_blocks = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] )
			? self::canonical_blocks( $block['innerBlocks'] )
			: array();

		return array(
			'blockName' => isset( $block['blockName'] ) ? $block['blockName'] : null,
			'attrs' => $attrs,
			'innerHTML' => isset( $block['innerHTML'] ) ? $block['innerHTML'] : '',
			'innerBlocks' => $inner_blocks,
		);
	}

	/**
	 * Sorts associative arrays recursively while preserving list order.
	 *
	 * @param mixed $value Value to normalize.
	 */
	private static function sort_recursive( &$value ) {
		if ( ! is_array( $value ) ) {
			return;
		}

		foreach ( $value as &$child ) {
			self::sort_recursive( $child );
		}
		unset( $child );

		if ( self::is_associative_array( $value ) ) {
			ksort( $value );
		}
	}

	/**
	 * Indicates whether an array has non-sequential numeric keys.
	 *
	 * @param array $value Array to inspect.
	 * @return bool True for associative arrays, false for lists.
	 */
	private static function is_associative_array( array $value ) {
		$index = 0;
		foreach ( array_keys( $value ) as $key ) {
			if ( $key !== $index ) {
				return true;
			}
			$index++;
		}
		return false;
	}

	/**
	 * Finds matching unchanged blocks between original and edited sequences.
	 *
	 * The result is used to splice original source around changed gaps. LCS is
	 * intentionally used instead of a greedy scan so repeated identical blocks
	 * still leave the longest possible set of source units untouched.
	 *
	 * @param string[] $left  Original semantic hashes.
	 * @param string[] $right Edited semantic hashes.
	 * @return array[] Matches with original and edited indexes.
	 */
	private static function longest_common_subsequence( array $left, array $right ) {
		$left_count = count( $left );
		$right_count = count( $right );
		$lengths = array_fill( 0, $left_count + 1, array_fill( 0, $right_count + 1, 0 ) );

		for ( $i = $left_count - 1; $i >= 0; $i-- ) {
			for ( $j = $right_count - 1; $j >= 0; $j-- ) {
				if ( $left[ $i ] === $right[ $j ] ) {
					$lengths[ $i ][ $j ] = $lengths[ $i + 1 ][ $j + 1 ] + 1;
				} else {
					$lengths[ $i ][ $j ] = max( $lengths[ $i + 1 ][ $j ], $lengths[ $i ][ $j + 1 ] );
				}
			}
		}

		$matches = array();
		$i = 0;
		$j = 0;
		while ( $i < $left_count && $j < $right_count ) {
			if ( $left[ $i ] === $right[ $j ] ) {
				$matches[] = array(
					'original' => $i,
					'edited' => $j,
				);
				$i++;
				$j++;
			} elseif ( $lengths[ $i + 1 ][ $j ] >= $lengths[ $i ][ $j + 1 ] ) {
				$i++;
			} else {
				$j++;
			}
		}

		return $matches;
	}
}
