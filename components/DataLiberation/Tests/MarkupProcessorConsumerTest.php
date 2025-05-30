<?php

use PHPUnit\Framework\TestCase;
use WordPress\DataLiberation\DataFormatConsumer\MarkupProcessorConsumer;
use WordPress\XML\XMLProcessor;

class MarkupProcessorConsumerTest extends TestCase {

	public function test_metadata_extraction() {
		$html              = <<<HTML
<meta name=post_title content="WordPress 6.8 was released">
<meta name=post_date content="2024-12-16">
<meta name=post_modified content="2024-12-16">
<meta name=post_author content="1">
<meta name=post_author_name content="The WordPress Team">
<meta name=post_author_url content="https://wordpress.org">
<meta name=post_author_avatar content="https://wordpress.org/wp-content/uploads/2024/04/wordpress-logo-2024.png">
<h1>WordPress 6.8 was released</h1>
<p>Last week, WordPress 6.8 was released. This release includes a new default theme, a new block editor experience, and a new block library. It also includes a new block editor experience, and a new block library.</p>
HTML;
		$consumer          = new MarkupProcessorConsumer( new WP_HTML_Processor( $html ) );
		$blocks_with_meta  = $consumer->consume();
		$metadata          = $blocks_with_meta->get_all_metadata();
		$expected_metadata = array(
			'post_title'         => array( 'WordPress 6.8 was released' ),
			'post_date'          => array( '2024-12-16' ),
			'post_modified'      => array( '2024-12-16' ),
			'post_author'        => array( '1' ),
			'post_author_name'   => array( 'The WordPress Team' ),
			'post_author_url'    => array( 'https://wordpress.org' ),
			'post_author_avatar' => array( 'https://wordpress.org/wp-content/uploads/2024/04/wordpress-logo-2024.png' ),
		);
		$this->assertEquals( $expected_metadata, $metadata );
	}

	/**
	 * @dataProvider provider_test_conversion
	 */
	public function test_html_to_blocks_conversion( $html, $expected ) {
		$consumer         = new MarkupProcessorConsumer( new WP_HTML_Processor( $html ) );
		$blocks_with_meta = $consumer->consume();

		$this->assertEquals( $this->normalize_markup( $expected ), $this->normalize_markup( $blocks_with_meta->get_block_markup() ) );
	}

	private function normalize_markup( $markup ) {
		$processor  = WP_HTML_Processor::create_fragment( $markup );
		$serialized = $processor->serialize();
		$serialized = trim(
			str_replace(
			// Naively remove all the newlines to prevent minor formatting differences
			// from causing false negatives in $expected === $actual.
				"\n",
				'',
				$serialized
			)
		);

		return $serialized;
	}

	public static function provider_test_conversion() {
		return array(
			'A simple paragraph'        => array(
				'html'     => '<p>A simple paragraph</p>',
				'expected' => '<!-- wp:paragraph --><p>A simple paragraph </p><!-- /wp:paragraph -->',
			),
			'A simple list'             => array(
				'html'     => '<ul><li>Item 1</li><li>Item 2</li></ul>',
				'expected' => <<<HTML
<!-- wp:list {"ordered":false} --><ul class="wp-block-list"><!-- wp:list-item -->\n<li>Item 1 </li><!-- /wp:list-item --><!-- wp:list-item --><li>Item 2 </li><!-- /wp:list-item --></ul><!-- /wp:list -->
HTML
			,
			),
			'A non-normative list'      => array(
				'html'     => '<ul><li>Item 1<li>Item 2',
				'expected' => <<<HTML
<!-- wp:list {"ordered":false} --><ul class="wp-block-list"><!-- wp:list-item --><li>Item 1 </li><!-- /wp:list-item --><!-- wp:list-item --><li>Item 2 </li><!-- /wp:list-item --></ul><!-- /wp:list -->
HTML
			,
			),
			'An image'                  => array(
				'html'     => '<img src="https://w.org/logo.png" alt="An image" />',
				'expected' => '<!-- wp:paragraph --><p><img alt="An image" src="https://w.org/logo.png"> </p><!-- /wp:paragraph -->',
			),
			'A heading'                 => array(
				'html'     => '<h4>A simple heading</h4>',
				'expected' => '<!-- wp:heading {"level":4} --><h4>A simple heading </h4><!-- /wp:heading -->',
			),
			'A link inside a paragraph' => array(
				'html'     => '<p>A simple paragraph with a <a href="https://wordpress.org">link</a></p>',
				'expected' => '<!-- wp:paragraph --><p>A simple paragraph with a <a href="https://wordpress.org"> link </a></p><!-- /wp:paragraph -->',
			),
			'Formatted text'            => array(
				'html'     => '<p><strong>Bold</strong> and <em>Italic</em></p>',
				'expected' => '<!-- wp:paragraph --><p><strong> Bold </strong>and <em> Italic </em></p><!-- /wp:paragraph -->',
			),
			'A blockquote'              => array(
				'html'     => '<blockquote>A simple blockquote</blockquote>',
				'expected' => '<!-- wp:quote --><blockquote>A simple blockquote </blockquote><!-- /wp:quote -->',
			),
			'A table'                   => array(
				'html'     => <<<TABLE
                <table>
                    <thead>
                        <tr>
                            <th>Header 1</th>
                            <th>Header 2</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Cell 1</td>
                            <td>Cell 2</td>
                        </tr>
                        <tr>
                            <td>Cell 3</td>
                            <td>Cell 4</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>Footer 1</td>
                            <td>Footer 2</td>
                        </tr>
                    </tfoot>
                </table>
TABLE
			,
				'expected' => <<<HTML
<!-- wp:table --><figure class="wp-block-table"><table class="has-fixed-layout"><thead><tr><th>Header 1 </th><th>Header 2 </th></tr></thead><tbody><tr><td>Cell 1 </td><td>Cell 2 </td></tr><tr><td>Cell 3 </td><td>Cell 4 </td></tr></tbody><tfoot><tr><td>Footer 1 </td><td>Footer 2 </td></tr></tfoot></table></figure><!-- /wp:table -->
HTML
			,
			),
		);
	}

	public function test_html_to_blocks_excerpt() {
		$this->markTestSkipped( 'Skipping this test because of outdated fixture.' );
		// $consumer         = new MarkupProcessorConsumer( WP_HTML_Processor::create_fragment( $input ) );
		// $blocks_with_meta = $consumer->consume();
		// $blocks           = $blocks_with_meta->get_block_markup();

		// $output_file = __DIR__ . '/fixtures/html-to-blocks/excerpt.output.html';
		// if ( getenv( 'UPDATE_FIXTURES' ) ) {
		// 	file_put_contents( $output_file, $blocks );
		// }

		// $this->assertEquals( file_get_contents( $output_file ), $blocks );
	}

	public function test_xhtml_to_blocks_conversion() {
		$input            = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE html>
<html>
    <body>
        <h1>Hello, world!</h1>
        <p>And some content</p>
    </body>
</html>
XML;
		$consumer         = new MarkupProcessorConsumer( XMLProcessor::create_from_string( $input ) );
		$blocks_with_meta = $consumer->consume();
		$blocks           = $blocks_with_meta->get_block_markup();
		$expected         = <<<HTML
<!-- wp:heading {"level":1} --><h1>Hello, world! </h1><!-- /wp:heading --><!-- wp:paragraph --><p>And some content </p><!-- /wp:paragraph -->
HTML;
		$this->assertEquals(
			$this->normalize_markup( $expected ),
			$this->normalize_markup( $blocks )
		);
	}
}
