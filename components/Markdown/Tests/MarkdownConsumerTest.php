<?php

use PHPUnit\Framework\TestCase;
use WordPress\Markdown\MarkdownConsumer;

class MarkdownConsumerTest extends TestCase {

	public function test_metadata_extraction() {
		$markdown          = <<<MD
---
post_title: "WordPress 6.8 was released"
post_date: "2024-12-16"
post_modified: "2024-12-16" 
post_author: "1"
post_author_name: "The WordPress Team"
post_author_url: "https://wordpress.org"
post_author_avatar: "https://wordpress.org/wp-content/uploads/2024/04/wordpress-logo-2024.png"
---

# WordPress 6.8 was released

Last week, WordPress 6.8 was released. This release includes a new default theme, a new block editor experience, and a new block library. It also includes a new block editor experience, and a new block library.
MD;
		$consumer          = new MarkdownConsumer( $markdown );
		$result            = $consumer->consume();
		$metadata          = $result->get_all_metadata();
		$expected_metadata = array(
			'post_title' => array( 'WordPress 6.8 was released' ),
			'post_date' => array( '2024-12-16' ),
			'post_modified' => array( '2024-12-16' ),
			'post_author' => array( '1' ),
			'post_author_name' => array( 'The WordPress Team' ),
			'post_author_url' => array( 'https://wordpress.org' ),
			'post_author_avatar' => array( 'https://wordpress.org/wp-content/uploads/2024/04/wordpress-logo-2024.png' ),
		);
		$this->assertEquals( $expected_metadata, $metadata );
	}

	public function test_metadata_extraction_2() {
		$markdown          = <<<MD
---
post_title: title :)
menu_order: 0
---

Content
MD;
		$consumer          = new MarkdownConsumer( $markdown );
		$result            = $consumer->consume();
		$metadata          = $result->get_all_metadata();
		$expected_metadata = array(
			'post_title' => array( 'title :)' ),
			'menu_order' => array( 0 ),
		);
		$this->assertEquals( $expected_metadata, $metadata );
	}

	/**
	 * @dataProvider provider_test_conversion
	 */
	public function test_markdown_to_blocks_conversion( $markdown, $expected ) {
		$consumer = new MarkdownConsumer( $markdown );
		$result   = $consumer->consume();
		$blocks   = $result->get_block_markup();

		$this->assertEquals( $this->normalize_markup( $expected ), $this->normalize_markup( $blocks ) );
	}

	private function normalize_markup( $markup ) {
		$processor  = WP_HTML_Processor::create_fragment( $markup );
		$serialized = $processor->serialize();
		$serialized = trim(
			str_replace(
				array(
					// Even more naively, remove all the newlines.
					"\n",
				),
				'',
				$serialized
			)
		);
		return $serialized;
	}

	public static function provider_test_conversion() {
		return array(
			'A simple paragraph' => array(
				'markdown' => 'A simple paragraph',
				'expected' => '<!-- wp:paragraph --><p>A simple paragraph</p><!-- /wp:paragraph -->',
			),
			'A simple paragraph – no blank text nodes' => array(
				'markdown' => 'A simple paragraph',
				'expected' => '<!-- wp:paragraph --><p>A simple paragraph</p><!-- /wp:paragraph -->',
			),
			'A simple paragraph – no blank text nodes – multiple spaces between words' => array(
				'markdown' => 'A simple    paragraph',
				'expected' => '<!-- wp:paragraph --><p>A simple    paragraph</p><!-- /wp:paragraph -->',
			),
			'A simple paragraph – no blank text nodes – single space at the end' => array(
				'markdown' => 'A simple paragraph ',
				'expected' => '<!-- wp:paragraph --><p>A simple paragraph </p><!-- /wp:paragraph -->',
			),
			'A simple paragraph – regular block markup formatting – no space at the end' => array(
				'markdown' => <<<MD
                A simple paragraph
                MD,
				'expected' => '<!-- wp:paragraph --><p>A simple paragraph</p><!-- /wp:paragraph -->',
			),
			'A simple paragraph – regular block markup formatting – single space at the end' => array(
				'markdown' => <<<MD
                A simple paragraph 
                MD,
				'expected' => '<!-- wp:paragraph --><p>A simple paragraph </p><!-- /wp:paragraph -->',
			),
			'A simple paragraph – regular HTML formatting – no space at the end' => array(
				'markdown' => <<<MD
                A simple paragraph
                MD,
				'expected' => '<!-- wp:paragraph --><p>A simple paragraph</p><!-- /wp:paragraph -->',
			),
			'A simple paragraph – regular HTML formatting – single space at the end' => array(
				'markdown' => <<<MD
                A simple paragraph 
                MD,
				'expected' => '<!-- wp:paragraph --><p>A simple paragraph </p><!-- /wp:paragraph -->',
			),
			'A simple list' => array(
				'markdown' => "- Item 1\n- Item 2",
				'expected' => <<<HTML
<!-- wp:list {"ordered":false} --><ul class="wp-block-list"><!-- wp:list-item --><li>Item 1</li><!-- /wp:list-item --><!-- wp:list-item --><li>Item 2</li><!-- /wp:list-item --></ul><!-- /wp:list -->
HTML
				,
			),
			'A nested list' => array(
				'markdown' => "- Item 1\n  - Item 1.1\n  - Item 1.2\n- Item 2",
				'expected' => <<<HTML
<!-- wp:list {"ordered":false} --><ul class="wp-block-list"><!-- wp:list-item --><li>Item 1<!-- wp:list {"ordered":false} --><ul class="wp-block-list"><!-- wp:list-item --><li>Item 1.1</li><!-- /wp:list-item --><!-- wp:list-item --><li>Item 1.2</li><!-- /wp:list-item --></ul><!-- /wp:list --></li><!-- /wp:list-item --><!-- wp:list-item --><li>Item 2</li><!-- /wp:list-item --></ul><!-- /wp:list -->
HTML
				,
			),
			'An image' => array(
				'markdown' => 'An inline image: ![An image](https://w.org/logo.png)',
				'expected' => '<!-- wp:paragraph --><p>An inline image: <img alt="An image" src="https://w.org/logo.png"></p><!-- /wp:paragraph -->',
			),
			'A heading - level 4' => array(
				'markdown' => '#### A simple heading',
				'expected' => '<!-- wp:heading {"level":4} --><h4 class="wp-block-heading" id="a-simple-heading">A simple heading</h4><!-- /wp:heading -->',
			),
			'A heading - level 2' => array(
				'markdown' => '## A simple heading',
				'expected' => '<!-- wp:heading --><h2 class="wp-block-heading" id="a-simple-heading">A simple heading</h2><!-- /wp:heading -->',
			),
			'A heading - level 1' => array(
				'markdown' => '# A simple heading',
				'expected' => '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading" id="a-simple-heading">A simple heading</h1><!-- /wp:heading -->',
			),
			'A link inside a paragraph' => array(
				'markdown' => 'A simple paragraph with a [link](https://wordpress.org)',
				'expected' => '<!-- wp:paragraph --><p>A simple paragraph with a <a href="https://wordpress.org">link</a></p><!-- /wp:paragraph -->',
			),
			'Formatted text' => array(
				'markdown' => '**Bold** and *Italic*',
				'expected' => '<!-- wp:paragraph --><p><b>Bold</b> and <em>Italic</em></p><!-- /wp:paragraph -->',
			),
			'A blockquote' => array(
				'markdown' => '> A simple blockquote',
				'expected' => '<!-- wp:quote --><blockquote class="wp-block-quote"><!-- wp:paragraph --><p>A simple blockquote</p><!-- /wp:paragraph --></blockquote><!-- /wp:quote -->',
			),
			'A table' => array(
				'markdown' => <<<MD
| Header 1 | Header 2 |
|----------|----------|
| Cell 1   | Cell 2   |
| Cell 3   | Cell 4   |
MD
				,
				'expected' => <<<HTML
<!-- wp:table --><figure class="wp-block-table"><table class="has-fixed-layout"><thead><tr><th>Header 1</th><th>Header 2</th></tr></thead><tbody><tr><td>Cell 1</td><td>Cell 2</td></tr><tr><td>Cell 3</td><td>Cell 4</td></tr></tbody></table></figure><!-- /wp:table -->
HTML
				,
			),
		);
	}

	public function test_frontmatter_extraction() {
		$markdown          = <<<MD
---
title: "Brian Chesky – Founder Mode & The Art of Hiring"
---

# Brian Chesky – Founder Mode & The Art of Hiring

Here are the key insights...
MD;
		$consumer          = new MarkdownConsumer( $markdown );
		$result            = $consumer->consume();
		$metadata          = $result->get_all_metadata();
		$expected_metadata = array(
			'title' => array( 'Brian Chesky – Founder Mode & The Art of Hiring' ),
		);
		$this->assertEquals( $expected_metadata, $metadata );
	}
}
