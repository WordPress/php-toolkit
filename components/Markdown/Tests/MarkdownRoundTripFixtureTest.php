<?php

use PHPUnit\Framework\TestCase;
use WordPress\DataLiberation\DataFormatConsumer\BlocksWithMetadata;
use WordPress\Markdown\MarkdownConsumer;
use WordPress\Markdown\MarkdownProducer;

class MarkdownRoundTripFixtureTest extends TestCase {

	/**
	 * @dataProvider provider_md_to_wp_to_md_fixtures
	 */
	public function test_md_to_wp_to_md_round_trip_uses_exact_fixture_bytes( $fixture_path ) {
		$markdown     = file_get_contents( $fixture_path );
		$consumer     = new MarkdownConsumer( $markdown );
		$result       = $consumer->consume();
		$block_markup = $result->get_block_markup();
		$metadata     = array();
		foreach ( $result->get_all_metadata() as $key => $values ) {
			$metadata[ $key ] = $values;
		}

		$producer      = new MarkdownProducer( new BlocksWithMetadata( $block_markup, $metadata ) );
		$round_tripped = $producer->produce();

		$this->assertSame(
			$markdown,
			$round_tripped,
			sprintf(
				'Fixture %s changed bytes during md -> wp -> md round-trip (%s != %s).',
				basename( $fixture_path ),
				sha1( $markdown ),
				sha1( $round_tripped )
			)
		);
	}

	public static function provider_md_to_wp_to_md_fixtures() {
		return self::paths_to_cases(
			__DIR__ . '/fixtures/roundtrip/md-to-wp-to-md/*.md'
		);
	}

	/**
	 * @dataProvider provider_wp_to_md_to_wp_fixtures
	 */
	public function test_wp_to_md_to_wp_round_trip_matches_fixture_markup( $fixture_path ) {
		$block_markup = file_get_contents( $fixture_path );
		$producer     = new MarkdownProducer( new BlocksWithMetadata( $block_markup, array() ) );
		$markdown     = $producer->produce();
		$consumer     = new MarkdownConsumer( $markdown );
		$result       = $consumer->consume();

		$this->assertSame(
			$this->normalize_blocks( $block_markup ),
			$this->normalize_blocks( $result->get_block_markup() ),
			sprintf(
				'Fixture %s changed bytes during wp -> md -> wp round-trip (%s != %s).',
				basename( $fixture_path ),
				sha1( $this->normalize_blocks( $block_markup ) ),
				sha1( $this->normalize_blocks( $result->get_block_markup() ) )
			)
		);
	}

	public static function provider_wp_to_md_to_wp_fixtures() {
		return self::paths_to_cases(
			__DIR__ . '/fixtures/roundtrip/wp-to-md-to-wp/*.html'
		);
	}

	private static function paths_to_cases( $pattern ) {
		$cases = array();
		foreach ( glob( $pattern ) as $path ) {
			$cases[ basename( $path ) ] = array( $path );
		}

		return $cases;
	}

	private function normalize_blocks( $markup ) {
		$markup = preg_replace( '/-->\s+</', '--><', $markup );
		$markup = preg_replace( '/>\s+<!--/', '><!--', $markup );

		return trim( $markup );
	}
}
