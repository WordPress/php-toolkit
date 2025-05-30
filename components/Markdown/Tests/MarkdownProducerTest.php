<?php

use PHPUnit\Framework\TestCase;
use WordPress\DataLiberation\DataFormatConsumer\BlocksWithMetadata;
use WordPress\Markdown\MarkdownProducer;

class MarkdownProducerTest extends TestCase {

	/**
	 * @dataProvider provider_test_conversion
	 */
	public function test_blocks_to_markdown_conversion( $blocks, $expected ) {
		$producer = new MarkdownProducer( new BlocksWithMetadata( $blocks, array() ) );

		$this->assertEquals( trim( $expected, "\n" ), trim( $producer->produce(), "\n" ) );
	}

	public static function provider_test_conversion() {
		return array(
			/*
			 * All these whitespace-related tests are absolutely critical for editing and
			 * three-way merging of static files. If whitespaces are lost during the HTML->Markdown
			 * conversion, the diff engine will get multiple whitespaces from the editor,
			 * singular whitespaces from the server, and conclude parts of the document were
			 * deleted.
			 */
			'A simple paragraph – no blank text nodes' => array(
				'blocks' => '<!-- wp:paragraph --><p>A simple paragraph</p><!-- /wp:paragraph -->',
				'expected' => "A simple paragraph\n\n",
			),
			'A simple paragraph – no blank text nodes – single space at the end' => array(
				'blocks' => '<!-- wp:paragraph --><p>A simple paragraph </p><!-- /wp:paragraph -->',
				'expected' => "A simple paragraph \n\n",
			),
			'A simple paragraph – regular block markup formatting – no space at the end' => array(
				'blocks' => <<<HTML
<!-- wp:paragraph -->
<p>A simple paragraph</p>
<!-- /wp:paragraph -->
HTML
				,
				'expected' => "A simple paragraph\n\n\n",
			),
			/**
			 * We must preserve the space at the end of the sentence to avoid a scenario such as:
			 *
			 * Server DB:   "Hello world "
			 * Editor:      "Hello world and another sentence"
			 * Static file: "Hello world"
			 *
			 * Delta DB<->Editor:      =12+and another sentence
			 * Delta DB<->static file: =11\t-1
			 *
			 * Three way merge:
			 * * Preserve "Hello world"
			 * * Note the space was deleted in the static file
			 * * Ignore the "and another sentence" insertion from the editor
			 * * End up with "Hello world"
			 *
			 * The user experience would be quite frustrating:
			 *
			 * 1. They would type "Hello world "
			 * 2. The save spinner would show up as autosave happens
			 * 3. They would keep typing and add "and another sentence" to the document
			 * 4. The server would respons with just "Hello world" without the final space
			 * 5. The client-side three-way merge would notice the server replied with something
			 *    else than we've sent and attempt to merge the documents
			 * 6. The resulting three-way merge would cut "Hello world and another sentence" to just
			 *    "Hello world", destroying the user's last sentence.
			 *
			 * To avoid that, we need to be very careful about the whitespace treatment.
			 */
			'A simple paragraph – regular block markup formatting – single space at the end' => array(
				'blocks' => <<<HTML
<!-- wp:paragraph -->
<p>A simple paragraph </p>
<!-- /wp:paragraph -->
HTML
				,
				'expected' => "A simple paragraph \n\n\n",
			),
			'A simple paragraph – regular HTML formatting – no space at the end' => array(
				'blocks' => <<<HTML
<!-- wp:paragraph -->
<p>
A simple paragraph
</p>
<!-- /wp:paragraph -->
HTML
				,
				'expected' => "A simple paragraph\n\n\n",
			),
			'A simple paragraph with span around two words' => array(
				'blocks' => <<<HTML
<!-- wp:paragraph -->
<p>
<span>A simple</span> paragraph
</p>
<!-- /wp:paragraph -->
HTML
				,
				'expected' => "A simple paragraph\n\n\n",
			),
			'A simple list' => array(
				'blocks' => <<<HTML
<!-- wp:list {"ordered":false} --><ul class="wp-block-list"><!-- wp:list-item --><li>Item 1</li><!-- /wp:list-item --><!-- wp:list-item --><li>Item 2</li><!-- /wp:list-item --></ul><!-- /wp:list -->
HTML
				,
				'expected' => "- Item 1\n- Item 2\n\n",
			),
			'A nested list' => array(
				'blocks' => <<<HTML
<!-- wp:list {"ordered":false} --><ul class="wp-block-list"><!-- wp:list-item --><li>Item 1<!-- wp:list {"ordered":false} --><ul class="wp-block-list"><!-- wp:list-item --><li>Item 1.1</li><!-- /wp:list-item --><!-- wp:list-item --><li>Item 1.2</li><!-- /wp:list-item --></ul><!-- /wp:list --></li><!-- /wp:list-item --><!-- wp:list-item --><li>Item 2</li><!-- /wp:list-item --></ul><!-- /wp:list -->
HTML
				,
				'expected' => "- Item 1\n  - Item 1.1\n  - Item 1.2\n- Item 2\n\n",
			),
			'An image' => array(
				'blocks' => '<!-- wp:paragraph --><p>An inline image: <img alt="An image" src="https://w.org/logo.png"></p><!-- /wp:paragraph -->',
				'expected' => "An inline image: ![An image](https://w.org/logo.png)\n\n",
			),
			'A heading' => array(
				'blocks' => '<!-- wp:heading {"level":4} --><h4>A simple heading</h4><!-- /wp:heading -->',
				'expected' => "#### A simple heading\n\n",
			),
			'A link inside a paragraph' => array(
				'blocks' => '<!-- wp:paragraph --><p>A simple paragraph with a <a href="https://wordpress.org">link</a></p><!-- /wp:paragraph -->',
				'expected' => "A simple paragraph with a [link](https://wordpress.org)\n\n",
			),
			'Formatted text' => array(
				'blocks' => '<!-- wp:paragraph --><p><b>Bold</b> and <em>Italic</em></p><!-- /wp:paragraph -->',
				'expected' => "**Bold** and *Italic*\n\n",
			),
			'A blockquote' => array(
				'blocks' => '<!-- wp:quote --><blockquote class="wp-block-quote"><!-- wp:paragraph --><p>A simple blockquote</p><!-- /wp:paragraph --></blockquote><!-- /wp:quote -->',
				'expected' => "> A simple blockquote\n> \n> \n\n",
			),
			'A table' => array(
				'blocks' => <<<HTML
<!-- wp:table --><figure class="wp-block-table"><table class="has-fixed-layout"><thead><tr><th>Header 1</th><th>Header 2</th></tr></thead><tbody><tr><td>Cell 1</td><td>Cell 2</td></tr><tr><td>Cell 3</td><td>Cell 4</td></tr></tbody></table></figure><!-- /wp:table -->
HTML
				,
				'expected' => <<<MD
| Header 1 | Header 2 |
|----------|----------|
| Cell 1   | Cell 2   |
| Cell 3   | Cell 4   |


MD
				,
			),
		);
	}
}
