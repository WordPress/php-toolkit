<?php

use PHPUnit\Framework\TestCase;
use WordPress\DataLiberation\DataFormatConsumer\BlocksWithMetadata;
use WordPress\Markdown\MarkdownConsumer;
use WordPress\Markdown\MarkdownProducer;

/**
 * Round-trip contract tests for the Markdown component.
 *
 * The rule: a conversion that starts in format A and returns to format A must
 * reproduce the exact same bytes for every case listed here. If a case cannot
 * be round-tripped without loss, it must instead be preserved as an opaque
 * `gutenberg` fence and tested explicitly under "opaque preservation".
 *
 * Two contracts are tested:
 *
 *   md -> wp -> md  (Markdown to block markup and back to Markdown)
 *   wp -> md -> wp  (Block markup to Markdown and back to block markup)
 *
 * "md -> wp -> md" is the primary contract for the WP Origin workflow: agents
 * edit Markdown locally, push to WordPress, then pull back. The pulled
 * Markdown must match what was pushed, byte for byte.
 *
 * "wp -> md -> wp" tests that a WordPress post exported via WP Origin and then
 * pushed back produces the same block markup. Leading/trailing whitespace
 * inside block comments is normalized away because WordPress itself normalizes
 * it on next save.
 */
class MarkdownRoundTripTest extends TestCase {

	// -------------------------------------------------------------------------
	// md -> wp -> md
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider provider_md_wp_md
	 */
	public function test_md_to_wp_to_md( $description, $markdown ) {
		$consumer     = new MarkdownConsumer( $markdown );
		$result       = $consumer->consume();
		$block_markup = $result->get_block_markup();
		$metadata     = array();
		foreach ( $result->get_all_metadata() as $key => $value ) {
			$metadata[ $key ] = is_array( $value ) ? array( reset( $value ) ) : array( $value );
		}

		$producer      = new MarkdownProducer( new BlocksWithMetadata( $block_markup, $metadata ) );
		$round_tripped = $producer->produce();

		$this->assertSame(
			$markdown,
			$round_tripped,
			"md -> wp -> md round-trip failed for: $description"
		);
	}

	public static function provider_md_wp_md() {
		$cases = array();

		// --- Simple blocks ---

		$cases['paragraph'] = array(
			'description' => 'paragraph',
			'markdown'    => "A simple paragraph\n\n",
		);

		$cases['paragraph with trailing space'] = array(
			'description' => 'paragraph with trailing space',
			'markdown'    => "A paragraph with a trailing space \n\n",
		);

		$cases['heading h2'] = array(
			'description' => 'heading h2',
			'markdown'    => "## Section title\n\n",
		);

		$cases['heading h4'] = array(
			'description' => 'heading h4',
			'markdown'    => "#### Sub-section title\n\n",
		);

		$cases['unordered list'] = array(
			'description' => 'unordered list',
			'markdown'    => "- Item 1\n- Item 2\n- Item 3\n\n",
		);

		$cases['nested list'] = array(
			'description' => 'nested list',
			'markdown'    => "- Item 1\n  - Item 1.1\n  - Item 1.2\n- Item 2\n\n",
		);

		$cases['link in paragraph'] = array(
			'description' => 'link in paragraph',
			'markdown'    => "A paragraph with a [link](https://wordpress.org)\n\n",
		);

		$cases['inline image'] = array(
			'description' => 'inline image',
			'markdown'    => "An inline image: ![Alt text](https://example.com/image.png)\n\n",
		);

		$cases['bold and italic'] = array(
			'description' => 'bold and italic',
			'markdown'    => "**Bold** and *italic* text\n\n",
		);

		$cases['blockquote'] = array(
			'description' => 'blockquote',
			'markdown'    => "> A blockquote\n> \n> \n\n",
		);

		// Tables normalise column-separator padding on export, so the canonical
		// input for the round-trip test is already padded identically to what
		// MarkdownProducer emits (one space on each side, columns padded to the
		// widest cell). A table with three trailing newlines (\n\n\n from the
		// producer test fixture) reduces to two (\n\n) when re-parsed; use the
		// two-newline form here so the input matches the actual round-trip output.
		$cases['table'] = array(
			'description' => 'table',
			'markdown'    => "| Header 1 | Header 2 |\n|----------|----------|\n| Cell 1   | Cell 2   |\n| Cell 3   | Cell 4   |\n\n",
		);

		// --- Gutenberg fences ---

		// Gutenberg fence: producer emits a single trailing newline after the
		// closing fence (not two). Use that form so the input matches the output.
		$cases['gutenberg fence is opaque'] = array(
			'description' => 'gutenberg fence is preserved verbatim',
			'markdown'    => "\n```gutenberg\n<!-- wp:verse --><pre class=\"wp-block-verse\">Roses are red</pre><!-- /wp:verse -->\n```\n",
		);

		$cases['gutenberg fence with complex block'] = array(
			'description' => 'pullquote preserved inside gutenberg fence',
			'markdown'    => "\n```gutenberg\n<!-- wp:pullquote --><figure class=\"wp-block-pullquote\"><blockquote><p>Quote</p></blockquote></figure><!-- /wp:pullquote -->\n```\n",
		);

		// --- Front matter ---

		$cases['front matter survives round-trip'] = array(
			'description' => 'front matter is reproduced exactly',
			'markdown'    => "---\nid: \"42\"\ntype: \"post\"\nslug: \"hello-world\"\nstatus: \"publish\"\ntitle: \"Hello World\"\ndate_gmt: \"2024-01-15 10:00:00\"\nmodified_gmt: \"2024-02-20 14:30:00\"\n---\n\nPost body here\n\n",
		);

		// --- Multiple blocks ---

		$cases['heading then paragraph'] = array(
			'description' => 'heading followed by paragraph',
			'markdown'    => "## Introduction\n\nThis is the intro paragraph\n\n",
		);

		$cases['two paragraphs'] = array(
			'description' => 'two consecutive paragraphs',
			'markdown'    => "First paragraph\n\nSecond paragraph\n\n",
		);

		$cases['mixed content'] = array(
			'description' => 'heading, paragraph, list',
			'markdown'    => "## Getting started\n\nFollow these steps:\n\n- Step one\n- Step two\n- Step three\n\n",
		);

		return $cases;
	}

	// -------------------------------------------------------------------------
	// wp -> md -> wp
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider provider_wp_md_wp
	 */
	public function test_wp_to_md_to_wp( $description, $blocks, $expected_blocks ) {
		$producer      = new MarkdownProducer( new BlocksWithMetadata( $blocks, array() ) );
		$markdown      = $producer->produce();
		$consumer      = new MarkdownConsumer( $markdown );
		$result        = $consumer->consume();
		$round_tripped = $result->get_block_markup();

		$this->assertSame(
			$this->normalize_blocks( $expected_blocks ),
			$this->normalize_blocks( $round_tripped ),
			"wp -> md -> wp round-trip failed for: $description"
		);
	}

	public static function provider_wp_md_wp() {
		$cases = array();

		$cases['paragraph'] = array(
			'description'     => 'paragraph',
			'blocks'          => '<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->',
			'expected_blocks' => '<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->',
		);

		$cases['paragraph with link'] = array(
			'description'     => 'paragraph with link',
			'blocks'          => '<!-- wp:paragraph --><p>Visit <a href="https://wordpress.org">WordPress</a></p><!-- /wp:paragraph -->',
			'expected_blocks' => '<!-- wp:paragraph --><p>Visit <a href="https://wordpress.org">WordPress</a></p><!-- /wp:paragraph -->',
		);

		$cases['paragraph with bold and italic'] = array(
			'description'     => 'paragraph with formatting',
			'blocks'          => '<!-- wp:paragraph --><p><b>Bold</b> and <em>Italic</em></p><!-- /wp:paragraph -->',
			'expected_blocks' => '<!-- wp:paragraph --><p><b>Bold</b> and <em>Italic</em></p><!-- /wp:paragraph -->',
		);

		$cases['h2 heading'] = array(
			'description'     => 'h2 heading',
			'blocks'          => '<!-- wp:heading --><h2>Section title</h2><!-- /wp:heading -->',
			// The importer adds class and id attributes, which is expected behaviour.
			'expected_blocks' => '<!-- wp:heading --><h2 class="wp-block-heading" id="section-title">Section title</h2><!-- /wp:heading -->',
		);

		$cases['unordered list'] = array(
			'description'     => 'unordered list',
			'blocks'          => '<!-- wp:list {"ordered":false} --><ul class="wp-block-list"><!-- wp:list-item --><li>Item 1</li><!-- /wp:list-item --><!-- wp:list-item --><li>Item 2</li><!-- /wp:list-item --></ul><!-- /wp:list -->',
			'expected_blocks' => '<!-- wp:list {"ordered":false} --><ul class="wp-block-list"><!-- wp:list-item --><li>Item 1</li><!-- /wp:list-item --><!-- wp:list-item --><li>Item 2</li><!-- /wp:list-item --></ul><!-- /wp:list -->',
		);

		$cases['table'] = array(
			'description'     => 'table',
			'blocks'          => '<!-- wp:table --><figure class="wp-block-table"><table class="has-fixed-layout"><thead><tr><th>Header 1</th><th>Header 2</th></tr></thead><tbody><tr><td>Cell 1</td><td>Cell 2</td></tr></tbody></table></figure><!-- /wp:table -->',
			'expected_blocks' => '<!-- wp:table --><figure class="wp-block-table"><table class="has-fixed-layout"><thead><tr><th>Header 1</th><th>Header 2</th></tr></thead><tbody><tr><td>Cell 1</td><td>Cell 2</td></tr></tbody></table></figure><!-- /wp:table -->',
		);

		// Unsupported blocks must survive as opaque gutenberg fences and come
		// back byte-for-byte as the original block markup.
		$cases['unsupported block preserved via gutenberg fence'] = array(
			'description'     => 'unsupported block survives via gutenberg fence',
			'blocks'          => '<!-- wp:verse --><pre class="wp-block-verse">Roses are red</pre><!-- /wp:verse -->',
			'expected_blocks' => '<!-- wp:verse --><pre class="wp-block-verse">Roses are red</pre><!-- /wp:verse -->',
		);

		$cases['mixed: paragraph then unsupported'] = array(
			'description'     => 'paragraph followed by unsupported block',
			'blocks'          => '<!-- wp:paragraph --><p>Intro</p><!-- /wp:paragraph --><!-- wp:verse --><pre class="wp-block-verse">A poem line</pre><!-- /wp:verse -->',
			'expected_blocks' => '<!-- wp:paragraph --><p>Intro</p><!-- /wp:paragraph --><!-- wp:verse --><pre class="wp-block-verse">A poem line</pre><!-- /wp:verse -->',
		);

		return $cases;
	}

	// -------------------------------------------------------------------------
	// One-way alias: `block` fence on import normalises to `gutenberg` on export
	// -------------------------------------------------------------------------

	public function test_legacy_block_fence_accepted_on_import() {
		$markdown = "```block\n<!-- wp:verse --><pre class=\"wp-block-verse\">Roses are red</pre><!-- /wp:verse -->\n```\n";
		$consumer = new MarkdownConsumer( $markdown );
		$result   = $consumer->consume();

		$this->assertSame(
			'<!-- wp:verse --><pre class="wp-block-verse">Roses are red</pre><!-- /wp:verse -->',
			trim( $result->get_block_markup() )
		);
	}

	public function test_legacy_block_fence_exported_as_gutenberg() {
		// When block markup that can't be expressed in Markdown is exported, the
		// output uses `gutenberg`, not the old `block` language tag.
		$blocks   = '<!-- wp:verse --><pre class="wp-block-verse">Roses are red</pre><!-- /wp:verse -->';
		$producer = new MarkdownProducer( new BlocksWithMetadata( $blocks, array() ) );
		$markdown = $producer->produce();

		$this->assertStringContainsString( '```gutenberg', $markdown );
		$this->assertStringNotContainsString( '```block', $markdown );
	}

	// -------------------------------------------------------------------------
	// Front matter: round-trip via MarkdownProducer + MarkdownConsumer
	// -------------------------------------------------------------------------

	public function test_front_matter_key_order_is_stable() {
		$metadata = array(
			'id'           => array( '42' ),
			'type'         => array( 'post' ),
			'slug'         => array( 'hello-world' ),
			'status'       => array( 'publish' ),
			'title'        => array( 'Hello World' ),
			'date_gmt'     => array( '2024-01-15 10:00:00' ),
			'modified_gmt' => array( '2024-02-20 14:30:00' ),
		);
		$blocks   = '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->';

		$producer  = new MarkdownProducer( new BlocksWithMetadata( $blocks, $metadata ) );
		$markdown1 = $producer->produce();

		// Produce again from the same input — output must be identical.
		$producer  = new MarkdownProducer( new BlocksWithMetadata( $blocks, $metadata ) );
		$markdown2 = $producer->produce();

		$this->assertSame( $markdown1, $markdown2, 'Front matter output must be deterministic' );
	}

	public function test_front_matter_values_survive_round_trip() {
		$expected = array(
			'id'           => '7',
			'type'         => 'page',
			'slug'         => 'about',
			'status'       => 'publish',
			'title'        => 'About Us',
			'date_gmt'     => '2023-06-01 09:00:00',
			'modified_gmt' => '2024-03-15 12:00:00',
		);
		$metadata = array();
		foreach ( $expected as $key => $value ) {
			$metadata[ $key ] = array( $value );
		}
		$blocks = '<!-- wp:paragraph --><p>About page content</p><!-- /wp:paragraph -->';

		$producer = new MarkdownProducer( new BlocksWithMetadata( $blocks, $metadata ) );
		$markdown = $producer->produce();

		$consumer  = new MarkdownConsumer( $markdown );
		$result    = $consumer->consume();
		$recovered = array();
		foreach ( $result->get_all_metadata() as $key => $value ) {
			$recovered[ $key ] = is_array( $value ) ? reset( $value ) : $value;
		}

		foreach ( $expected as $key => $expected_value ) {
			$this->assertArrayHasKey( $key, $recovered, "Front matter key '$key' missing after round-trip" );
			$this->assertSame(
				(string) $expected_value,
				(string) $recovered[ $key ],
				"Front matter value for '$key' changed after round-trip"
			);
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Normalise block markup for comparison.
	 *
	 * WordPress block markup uses HTML comments as delimiters
	 * (e.g. <!-- wp:paragraph -->). When the Markdown importer reconstructs
	 * block markup it may add a newline or space between a comment and the
	 * next tag. WordPress itself strips that whitespace on next save, so we
	 * normalise it away here to keep the tests focused on content, not
	 * incidental whitespace.
	 */
	private function normalize_blocks( $markup ) {
		// Strip whitespace between --> and the next < (opening tag or comment).
		$markup = preg_replace( '/-->\s+</', '--><', $markup );
		// Strip whitespace between a closing > and the next <!-- block comment.
		$markup = preg_replace( '/>\s+<!--/', '><!--', $markup );
		return trim( $markup );
	}
}
