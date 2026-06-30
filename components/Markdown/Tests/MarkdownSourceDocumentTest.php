<?php

use PHPUnit\Framework\TestCase;
use WordPress\Markdown\MarkdownSourceDocument;

require_once dirname( __DIR__ ) . '/class-markdownsourceunit.php';
require_once dirname( __DIR__ ) . '/class-markdownsourcedocument.php';

class MarkdownSourceDocumentTest extends TestCase {

	public function test_unchanged_blocks_preserve_original_markdown_bytes() {
		$markdown = <<<MD
---
title: Source Aware
---

# Heading #

Paragraph with __bold__ syntax and [a link][ref].

[ref]: https://example.com

MD;
		$document = MarkdownSourceDocument::from_markdown( $markdown );

		$this->assertSame( $markdown, $document->patch_markdown( $document->get_block_markup() ) );
	}

	public function test_changed_paragraph_does_not_reserialize_unchanged_neighbors() {
		$markdown = <<<MD
# Heading #

Keep __bold__ syntax.

Change this sentence.

Final paragraph with _emphasis_.

MD;
		$document = MarkdownSourceDocument::from_markdown( $markdown );
		$edited_blocks = str_replace(
			'<p>Change this sentence.</p>',
			'<p>Change this sentence, and only this sentence.</p>',
			$document->get_block_markup()
		);
		$patched = $document->patch_markdown( $edited_blocks );

		$this->assertStringContainsString( "# Heading #\n\n", $patched );
		$this->assertStringContainsString( "Keep __bold__ syntax.\n\n", $patched );
		$this->assertStringContainsString( "Change this sentence, and only this sentence.\n\n", $patched );
		$this->assertStringContainsString( "Final paragraph with _emphasis_.\n", $patched );
		$this->assertStringNotContainsString( '**bold**', $patched );
		$this->assertStringNotContainsString( '*emphasis*', $patched );
	}

	public function test_changed_middle_block_preserves_crlf_separators() {
		$markdown = "Before __bold__.\r\n\r\nChange this sentence.\r\n\r\nAfter _emphasis_.\r\n";
		$document = MarkdownSourceDocument::from_markdown( $markdown );
		$edited_blocks = str_replace(
			'<p>Change this sentence.</p>',
			'<p>Change this sentence with CRLF preserved.</p>',
			$document->get_block_markup()
		);
		$patched = $document->patch_markdown( $edited_blocks );

		$this->assertStringContainsString( "Before __bold__.\r\n\r\n", $patched );
		$this->assertStringContainsString( "Change this sentence with CRLF preserved.\r\n\r\n", $patched );
		$this->assertStringContainsString( "After _emphasis_.\r\n", $patched );
		$this->assertStringNotContainsString( "Change this sentence with CRLF preserved.\n\nAfter", $patched );
	}

	public function test_changed_final_block_preserves_missing_final_newline() {
		$markdown = "Before __bold__.\n\nChange this final sentence.";
		$document = MarkdownSourceDocument::from_markdown( $markdown );
		$edited_blocks = str_replace(
			'<p>Change this final sentence.</p>',
			'<p>Change this final sentence without adding a newline.</p>',
			$document->get_block_markup()
		);
		$patched = $document->patch_markdown( $edited_blocks );

		$this->assertSame( "Before __bold__.\n\nChange this final sentence without adding a newline.", $patched );
	}

	/**
	 * @dataProvider provider_tiny_trivia_cases
	 */
	public function test_generated_tiny_trivia_cases_preserve_changed_block_boundaries( $case_name, $before, $target, $after, $expected_changed ) {
		$markdown = $before . $target . $after;
		$document = MarkdownSourceDocument::from_markdown( $markdown );
		$edited_blocks = str_replace(
			'Tiny target paragraph.',
			$expected_changed,
			$document->get_block_markup()
		);
		$patched = $document->patch_markdown( $edited_blocks );

		$this->assertStringStartsWith( $before, $patched, $case_name );
		$this->assertStringContainsString( $expected_changed, $patched, $case_name );
		if ( '' !== $after ) {
			$this->assertStringEndsWith( $after, $patched, $case_name );
		}
		$this->assertSame( 1, substr_count( $patched, $expected_changed ), $case_name );
	}

	public static function provider_tiny_trivia_cases() {
		return array(
			'lf blank separator' => array(
				'lf blank separator',
				"Intro __bold__.\n\n",
				"Tiny target paragraph.\n\n",
				"Tail _emphasis_.\n",
				'Tiny target paragraph changed.',
			),
			'lf no final newline' => array(
				'lf no final newline',
				"Intro __bold__.\n\n",
				'Tiny target paragraph.',
				'',
				'Tiny target paragraph with no final newline.',
			),
			'crlf blank separator' => array(
				'crlf blank separator',
				"Intro __bold__.\r\n\r\n",
				"Tiny target paragraph.\r\n\r\n",
				"Tail _emphasis_.\r\n",
				'Tiny target paragraph with CRLF preserved.',
			),
			'leading blank lines' => array(
				'leading blank lines',
				"Intro __bold__.\n\n\n",
				"Tiny target paragraph.\n\n",
				"Tail _emphasis_.\n",
				'Tiny target paragraph: changed, checked, done.',
			),
			'leading tabs before target' => array(
				'leading tabs before target',
				"Intro __bold__.\n\n\t\n",
				"Tiny target paragraph.\n\n",
				"Tail _emphasis_.\n",
				'Tiny target paragraph with tab trivia.',
			),
			'trailing space line before target' => array(
				'trailing space line before target',
				"Intro __bold__.\n\n   \n",
				"Tiny target paragraph.\n\n",
				"Tail _emphasis_.\n",
				'Tiny target paragraph with space trivia.',
			),
		);
	}

	public function test_inserted_block_is_serialized_between_preserved_blocks() {
		$markdown = <<<MD
First paragraph with __bold__.

Second paragraph with _emphasis_.

MD;
		$document = MarkdownSourceDocument::from_markdown( $markdown );
		$edited_blocks = str_replace(
			'<!-- wp:paragraph -->' . "\n" . '<p>Second paragraph with <em>emphasis</em>.</p>',
			'<!-- wp:paragraph -->' . "\n" . '<p>Inserted paragraph.</p>' . "\n" . '<!-- /wp:paragraph -->' . "\n\n" . '<!-- wp:paragraph -->' . "\n" . '<p>Second paragraph with <em>emphasis</em>.</p>',
			$document->get_block_markup()
		);
		$patched = $document->patch_markdown( $edited_blocks );

		$this->assertStringContainsString( "First paragraph with __bold__.\n\n", $patched );
		$this->assertStringContainsString( "Inserted paragraph.\n\n", $patched );
		$this->assertStringContainsString( "Second paragraph with _emphasis_.\n", $patched );
	}

	public function test_deleted_block_is_removed_without_touching_surrounding_source() {
		$markdown = <<<MD
First paragraph with __bold__.

Delete this paragraph.

Second paragraph with _emphasis_.

MD;
		$document = MarkdownSourceDocument::from_markdown( $markdown );
		$edited_blocks = str_replace(
			"<!-- wp:paragraph -->\n<p>Delete this paragraph.</p>\n<!-- /wp:paragraph -->\n\n",
			'',
			$document->get_block_markup()
		);
		$patched = $document->patch_markdown( $edited_blocks );

		$this->assertStringContainsString( "First paragraph with __bold__.\n\n", $patched );
		$this->assertStringNotContainsString( 'Delete this paragraph.', $patched );
		$this->assertStringContainsString( "Second paragraph with _emphasis_.\n", $patched );
	}

	public function test_duplicate_blocks_still_preserve_changed_middle_block_neighbors() {
		$markdown = <<<MD
Same paragraph.

Middle __bold__ paragraph.

Same paragraph.

MD;
		$document = MarkdownSourceDocument::from_markdown( $markdown );
		$edited_blocks = str_replace(
			'<p>Middle <b>bold</b> paragraph.</p>',
			'<p>Middle <b>bold</b> paragraph changed.</p>',
			$document->get_block_markup()
		);
		$patched = $document->patch_markdown( $edited_blocks );

		$this->assertSame( 2, substr_count( $patched, "Same paragraph.\n" ) );
		$this->assertStringContainsString( "Middle **bold** paragraph changed.\n\n", $patched );
	}

	public function test_frontmatter_and_leading_comments_are_preserved() {
		$markdown = <<<MD
---
title: Frontmatter
---

<!-- keep this comment -->

Paragraph to edit.

MD;
		$document = MarkdownSourceDocument::from_markdown( $markdown );
		$edited_blocks = str_replace( 'Paragraph to edit.', 'Edited paragraph.', $document->get_block_markup() );
		$patched = $document->patch_markdown( $edited_blocks );

		$this->assertStringStartsWith( "---\ntitle: Frontmatter\n---\n\n<!-- keep this comment -->\n\n", $patched );
		$this->assertStringContainsString( "Edited paragraph.\n", $patched );
	}

	public function test_crlf_frontmatter_is_preserved_when_body_changes() {
		$markdown = "---\r\ntitle: CRLF Frontmatter\r\n---\r\n\r\nParagraph to edit.\r\n";
		$document = MarkdownSourceDocument::from_markdown( $markdown );
		$edited_blocks = str_replace( 'Paragraph to edit.', 'Edited paragraph.', $document->get_block_markup() );
		$patched = $document->patch_markdown( $edited_blocks );

		$this->assertStringStartsWith( "---\r\ntitle: CRLF Frontmatter\r\n---\r\n\r\n", $patched );
		$this->assertStringContainsString( "Edited paragraph.\r\n", $patched );
	}

	public function test_setext_heading_is_preserved_when_following_block_changes() {
		$markdown = <<<MD
Heading with _style_
====================

Paragraph to edit.

MD;
		$document = MarkdownSourceDocument::from_markdown( $markdown );
		$edited_blocks = str_replace( 'Paragraph to edit.', 'Edited paragraph.', $document->get_block_markup() );
		$patched = $document->patch_markdown( $edited_blocks );

		$this->assertStringStartsWith( "Heading with _style_\n====================\n\n", $patched );
		$this->assertStringNotContainsString( '# Heading with', $patched );
		$this->assertStringContainsString( "Edited paragraph.\n", $patched );
	}

	public function test_reference_style_links_and_definitions_are_preserved_when_neighbor_changes() {
		$markdown = <<<MD
Paragraph with [a reference][docs] and __bold__.

[docs]: https://developer.wordpress.org "Developer docs"

Paragraph to edit.

MD;
		$document = MarkdownSourceDocument::from_markdown( $markdown );
		$edited_blocks = str_replace( 'Paragraph to edit.', 'Edited paragraph.', $document->get_block_markup() );
		$patched = $document->patch_markdown( $edited_blocks );

		$this->assertStringContainsString( "Paragraph with [a reference][docs] and __bold__.\n\n", $patched );
		$this->assertStringContainsString( "[docs]: https://developer.wordpress.org \"Developer docs\"\n\n", $patched );
		$this->assertStringContainsString( "Edited paragraph.\n", $patched );
		$this->assertStringNotContainsString( '[a reference](https://developer.wordpress.org', $patched );
	}

	public function test_unchanged_code_fence_is_preserved_when_later_block_changes() {
		$markdown = <<<MD
````php
echo `code`;
```
````

Paragraph to edit.

MD;
		$document = MarkdownSourceDocument::from_markdown( $markdown );
		$edited_blocks = str_replace( 'Paragraph to edit.', 'Edited paragraph.', $document->get_block_markup() );
		$patched = $document->patch_markdown( $edited_blocks );

		$this->assertStringContainsString( "````php\necho `code`;\n```\n````\n\n", $patched );
		$this->assertStringContainsString( "Edited paragraph.\n", $patched );
	}

	public function test_indented_code_block_is_preserved_when_later_block_changes() {
		$markdown = <<<MD
    const keep = "__syntax__";
    console.log(keep);

Paragraph to edit.

MD;
		$document = MarkdownSourceDocument::from_markdown( $markdown );
		$edited_blocks = str_replace( 'Paragraph to edit.', 'Edited paragraph.', $document->get_block_markup() );
		$patched = $document->patch_markdown( $edited_blocks );

		$this->assertStringContainsString( "    const keep = \"__syntax__\";\n    console.log(keep);\n\n", $patched );
		$this->assertStringContainsString( "Edited paragraph.\n", $patched );
	}

	public function test_nested_blockquote_source_is_preserved_when_neighbor_changes() {
		$markdown = <<<MD
> Quote with __bold__.
>
> - first
> - second
>
> Final quote line.

Paragraph to edit.

MD;
		$document = MarkdownSourceDocument::from_markdown( $markdown );
		$edited_blocks = str_replace( 'Paragraph to edit.', 'Edited paragraph.', $document->get_block_markup() );
		$patched = $document->patch_markdown( $edited_blocks );

		$this->assertStringStartsWith( "> Quote with __bold__.\n>\n> - first\n> - second\n>\n> Final quote line.\n\n", $patched );
		$this->assertStringContainsString( "Edited paragraph.\n", $patched );
	}

	public function test_changed_list_rewrites_only_the_list_unit() {
		$markdown = <<<MD
Before __bold__.

* First item
* Second item

After _emphasis_.

MD;
		$document = MarkdownSourceDocument::from_markdown( $markdown );
		$edited_blocks = str_replace( '<li>Second item</li>', '<li>Second item changed</li>', $document->get_block_markup() );
		$patched = $document->patch_markdown( $edited_blocks );

		$this->assertStringContainsString( "Before __bold__.\n\n", $patched );
		$this->assertStringContainsString( "- First item\n- Second item changed\n\n", $patched );
		$this->assertStringContainsString( "After _emphasis_.\n", $patched );
	}

	public function test_unchanged_ordered_list_start_and_marker_spacing_are_preserved() {
		$markdown = <<<MD
Before __bold__.

7.  First item
8.  Second item
9.  Third item

Paragraph to edit.

MD;
		$document = MarkdownSourceDocument::from_markdown( $markdown );
		$edited_blocks = str_replace( 'Paragraph to edit.', 'Edited paragraph.', $document->get_block_markup() );
		$patched = $document->patch_markdown( $edited_blocks );

		$this->assertStringContainsString( "7.  First item\n8.  Second item\n9.  Third item\n\n", $patched );
		$this->assertStringNotContainsString( "1. First item\n2. Second item", $patched );
		$this->assertStringContainsString( "Edited paragraph.\n", $patched );
	}

	public function test_changed_table_rewrites_only_the_table_unit() {
		$markdown = <<<MD
Before __bold__.

| Feature | State |
| :------ | ----: |
| One     |    ok |

After _emphasis_.

MD;
		$document = MarkdownSourceDocument::from_markdown( $markdown );
		$edited_blocks = str_replace( '<td>ok</td>', '<td>done</td>', $document->get_block_markup() );
		$patched = $document->patch_markdown( $edited_blocks );

		$this->assertStringContainsString( "Before __bold__.\n\n", $patched );
		$this->assertStringContainsString( '| Feature | State |', $patched );
		$this->assertStringContainsString( '| One     | done  |', $patched );
		$this->assertStringContainsString( "After _emphasis_.\n", $patched );
	}

	public function test_raw_html_block_is_preserved_when_neighbor_changes() {
		$markdown = <<<MD
<section data-state="raw">
	<strong>Keep raw HTML formatting.</strong>
</section>

Paragraph to edit.

MD;
		$document = MarkdownSourceDocument::from_markdown( $markdown );
		$edited_blocks = str_replace( 'Paragraph to edit.', 'Edited paragraph.', $document->get_block_markup() );
		$patched = $document->patch_markdown( $edited_blocks );

		$this->assertStringStartsWith( "<section data-state=\"raw\">\n\t<strong>Keep raw HTML formatting.</strong>\n</section>\n\n", $patched );
		$this->assertStringContainsString( "Edited paragraph.\n", $patched );
	}

	public function test_thematic_break_marker_is_preserved_when_neighbor_changes() {
		$markdown = <<<MD
Before __bold__.

___

Paragraph to edit.

MD;
		$document = MarkdownSourceDocument::from_markdown( $markdown );
		$edited_blocks = str_replace( 'Paragraph to edit.', 'Edited paragraph.', $document->get_block_markup() );
		$patched = $document->patch_markdown( $edited_blocks );

		$this->assertStringContainsString( "Before __bold__.\n\n___\n\n", $patched );
		$this->assertStringNotContainsString( "\n---\n", $patched );
		$this->assertStringContainsString( "Edited paragraph.\n", $patched );
	}

	public function test_table_alignment_and_padding_are_preserved_when_neighbor_changes() {
		$markdown = <<<MD
| Feature | State |
| :------ | ----: |
| One     |    ok |

Paragraph to edit.

MD;
		$document = MarkdownSourceDocument::from_markdown( $markdown );
		$edited_blocks = str_replace( 'Paragraph to edit.', 'Edited paragraph.', $document->get_block_markup() );
		$patched = $document->patch_markdown( $edited_blocks );

		$this->assertStringContainsString( "| Feature | State |\n| :------ | ----: |\n| One     |    ok |\n\n", $patched );
		$this->assertStringContainsString( "Edited paragraph.\n", $patched );
	}

	public function test_repeated_blocks_preserve_the_unedited_repetitions_around_a_changed_block() {
		$markdown = <<<MD
Alpha __one__.

Repeat _me_.

Repeat _me_.

Omega __two__.

MD;
		$document = MarkdownSourceDocument::from_markdown( $markdown );
		$edited_blocks = $this->replace_first(
			'<p>Repeat <em>me</em>.</p>',
			'<p>Repeat <em>me</em> with a change.</p>',
			$document->get_block_markup()
		);
		$patched = $document->patch_markdown( $edited_blocks );

		$this->assertStringContainsString( "Alpha __one__.\n\n", $patched );
		$this->assertStringContainsString( "Repeat *me* with a change.\n\nRepeat _me_.\n\n", $patched );
		$this->assertStringContainsString( "Omega __two__.\n", $patched );
	}

	/**
	 * @dataProvider provider_medium_neighbor_preservation_cases
	 */
	public function test_generated_medium_neighbor_cases_preserve_surrounding_source( $case_name, $before, $after ) {
		$markdown = $before . "Paragraph to edit.\n\n" . $after;
		$document = MarkdownSourceDocument::from_markdown( $markdown );
		$edited_blocks = str_replace( 'Paragraph to edit.', 'Edited paragraph.', $document->get_block_markup() );
		$patched = $document->patch_markdown( $edited_blocks );

		$this->assertStringStartsWith( $before, $patched, $case_name );
		$this->assertStringContainsString( "Edited paragraph.\n\n", $patched, $case_name );
		$this->assertStringEndsWith( $after, $patched, $case_name );
		$this->assertSame( 1, substr_count( $patched, 'Edited paragraph.' ), $case_name );
	}

	public static function provider_medium_neighbor_preservation_cases() {
		$snippets = self::source_snippets();
		$snippet_names = array_keys( $snippets );
		$snippet_count = count( $snippet_names );
		$cases = array();

		for ( $index = 0; $index < $snippet_count; $index++ ) {
			$before_name = $snippet_names[ $index ];
			$after_name = $snippet_names[ ( $index + 7 ) % $snippet_count ];
			$case_name = $before_name . ' before / ' . $after_name . ' after';
			$cases[ $case_name ] = array( $case_name, $snippets[ $before_name ], $snippets[ $after_name ] );

			$before_name = $snippet_names[ ( $index + 11 ) % $snippet_count ];
			$after_name = $snippet_names[ $index ];
			$case_name = $before_name . ' before / ' . $after_name . ' after';
			$cases[ $case_name ] = array( $case_name, $snippets[ $before_name ], $snippets[ $after_name ] );
		}

		return $cases;
	}

	/**
	 * @dataProvider provider_large_document_cases
	 */
	public function test_generated_large_document_cases_preserve_every_unedited_slice( $case_name, $before_parts, $after_parts ) {
		$before = implode( '', $before_parts );
		$after = implode( '', $after_parts );
		$markdown = $before . "Paragraph to edit.\n\n" . $after;
		$document = MarkdownSourceDocument::from_markdown( $markdown );
		$edited_blocks = str_replace( 'Paragraph to edit.', 'Edited paragraph in a large document.', $document->get_block_markup() );
		$patched = $document->patch_markdown( $edited_blocks );

		$this->assertStringStartsWith( $before, $patched, $case_name );
		$this->assertStringContainsString( "Edited paragraph in a large document.\n\n", $patched, $case_name );
		$this->assertStringEndsWith( $after, $patched, $case_name );
		$this->assertSame( 1, substr_count( $patched, 'Edited paragraph in a large document.' ), $case_name );

		foreach ( array_merge( $before_parts, $after_parts ) as $source_part ) {
			$this->assertStringContainsString( $source_part, $patched, $case_name );
		}
	}

	/**
	 * @dataProvider provider_unmapped_unchanged_documents
	 */
	public function test_generated_unmapped_documents_preserve_original_when_unchanged( $case_name, $markdown ) {
		$document = MarkdownSourceDocument::from_markdown( $markdown );

		$this->assertSame( $markdown, $document->patch_markdown( $document->get_block_markup() ), $case_name );
	}

	public static function provider_unmapped_unchanged_documents() {
		$unsupported_snippets = array(
			'task list before' => "- [x] Checked item\n- [ ] Open item\n\nParagraph to edit.\n",
			'task list after' => "Paragraph to edit.\n\n- [x] Checked item\n- [ ] Open item\n",
			'duplicate reference definitions' => "A [link][same].\n\n[same]: https://example.com/a\n\nParagraph to edit.\n\nAnother [link][same].\n\n[same]: https://example.com/b\n",
			'html followed by markdown without blank' => "<div>\nRaw HTML\n</div>\nParagraph to edit.\n",
			'unclosed html block' => "<div>\n\nParagraph to edit.\n",
			'mixed task list document' => "Before __bold__.\n\n- [x] Checked item\n- [ ] Open item\n\nParagraph to edit.\n\nAfter _emphasis_.\n",
			'task list nested in quote' => "> - [x] Quoted checked item\n> - [ ] Quoted open item\n\nParagraph to edit.\n",
			'raw markdown inside html' => "<section>\n# Not a Markdown heading here\n</section>\n\nParagraph to edit.\n",
		);
		$cases = array();

		foreach ( $unsupported_snippets as $case_name => $markdown ) {
			$cases[ $case_name ] = array( $case_name, $markdown );
		}

		return $cases;
	}

	public static function provider_large_document_cases() {
		$snippets = array_values( self::source_snippets() );
		$cases = array();
		$snippet_count = count( $snippets );

		for ( $case_index = 0; $case_index < 10; $case_index++ ) {
			$before_parts = array();
			$after_parts = array();
			for ( $offset = 0; $offset < 8; $offset++ ) {
				$before_parts[] = $snippets[ ( $case_index + $offset * 3 ) % $snippet_count ];
				$after_parts[] = $snippets[ ( $case_index * 2 + $offset * 5 + 1 ) % $snippet_count ];
			}

			$case_name = 'large mixed document ' . $case_index;
			$cases[ $case_name ] = array( $case_name, $before_parts, $after_parts );
		}

		return $cases;
	}

	public function test_reordered_blocks_preserve_any_unchanged_source_units_the_matcher_can_keep() {
		$markdown = <<<MD
First __bold__.

Second _emphasis_.

MD;
		$document = MarkdownSourceDocument::from_markdown( $markdown );
		$blocks = array_values(
			array_filter(
				parse_blocks( $document->get_block_markup() ),
				function ( $block ) {
					return isset( $block['blockName'] ) && null !== $block['blockName'];
				}
			)
		);
		$edited_blocks = serialize_block( $blocks[1] ) . "\n\n" . serialize_block( $blocks[0] );
		$patched = $document->patch_markdown( $edited_blocks );

		$this->assertStringContainsString( 'Second _emphasis_.', $patched );
		$this->assertStringContainsString( 'First **bold**.', $patched );
	}

	public function test_unmapped_documents_still_preserve_original_source_when_blocks_are_unchanged() {
		$markdown = <<<MD
Paragraph before unsupported markup.

<div>

Nested Markdown paragraph.

</div>

Paragraph after unsupported markup.

MD;
		$document = MarkdownSourceDocument::from_markdown( $markdown );

		$this->assertSame( $markdown, $document->patch_markdown( $document->get_block_markup() ) );
	}

	private function replace_first( $search, $replace, $subject ) {
		$position = strpos( $subject, $search );
		$this->assertNotFalse( $position );

		return substr( $subject, 0, $position ) . $replace . substr( $subject, $position + strlen( $search ) );
	}

	private static function source_snippets() {
		return array(
			'paragraph inline emphasis variants' => "Paragraph with __bold__, _emphasis_, and `inline code`.\n\n",
			'paragraph escaped punctuation' => "Escaped \\*literal asterisks\\* and \\[brackets\\].\n\n",
			'paragraph hard break' => "Line with a hard break  \nthen the next line.\n\n",
			'paragraph raw inline html' => "Inline <span data-x=\"1\">HTML</span> with __markdown__.\n\n",
			'reference link paragraph' => "A [reference link][docs] with __bold__ text.\n\n[docs]: https://developer.wordpress.org \"Docs\"\n\n",
			'reference image paragraph' => "A reference image ![logo][logo] stays indirect.\n\n[logo]: https://example.com/logo.png \"Logo\"\n\n",
			'atx h1 closing marker' => "# Heading one #\n\n",
			'atx h4 no closing marker' => "#### Heading four with _style_\n\n",
			'setext h1 heading' => "Setext primary heading\n======================\n\n",
			'setext h2 heading' => "Setext secondary heading\n------------------------\n\n",
			'fenced code backticks' => "````php\necho `nested`;\n```\n````\n\n",
			'fenced code tildes' => "~~~js\nconst value = \"__keep__\";\n~~~\n\n",
			'indented code block' => "    const keep = \"_syntax_\";\n    console.log(keep);\n\n",
			'unordered star list' => "* First star item\n* Second star item\n\n",
			'unordered plus list' => "+ First plus item\n+ Second plus item\n\n",
			'ordered list offset' => "7.  First ordered item\n8.  Second ordered item\n\n",
			'nested unordered list' => "- Parent item\n  - Child item A\n  - Child item B\n- Sibling item\n\n",
			'blockquote paragraph' => "> Quote with __bold__ and _emphasis_.\n\n",
			'blockquote nested list' => "> Quote intro.\n>\n> - First\n> - Second\n\n",
			'blockquote nested quote' => "> Outer quote\n>\n> > Inner quote with `code`\n\n",
			'table aligned columns' => "| Feature | State |\n| :------ | ----: |\n| One     |    ok |\n\n",
			'table escaped pipes' => "| Name | Value |\n| ---- | ----- |\n| Pipe | a \\| b |\n\n",
			'thematic break underscores' => "___\n\n",
			'thematic break stars' => "***\n\n",
			'html comment block' => "<!-- keep this source comment -->\n\n",
			'raw html section' => "<section data-state=\"raw\">\n\t<strong>Keep raw HTML formatting.</strong>\n</section>\n\n",
			'raw html table' => "<table>\n<tr><td>Raw table</td></tr>\n</table>\n\n",
		);
	}
}
