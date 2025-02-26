<?php

namespace WordPress\Git\Tests;

use PHPUnit\Framework\TestCase;
use WordPress\Git\Protocol\Parser\PackParser;

class PackParserTest extends TestCase {

	public function test_parse_simple_pack() {
		$parser = new PackParser();
		/**
		 * This fixture was produced with the following command:
		 *
		 * ```
		 * git rev-list --objects --all | head -n 4 | git pack-objects components/Git/Tests/fixtures/pack-simple.pack
		 * ```
		 */

		$parser->append_bytes(
			file_get_contents( __DIR__ . '/fixtures/pack-simple.pack' )
		);
		$this->assertTrue( $parser->next_token() );
		$this->assertEquals( '#pack-header', $parser->get_token_type() );

		$this->assertTrue( $parser->next_token() );
		$this->assertEquals( '#object-header', $parser->get_token_type() );
		$this->assertEquals( 'commit', $parser->get_object_type_name() );

		$this->assertTrue( $parser->next_token() );
		$this->assertEquals( '#object-body', $parser->get_token_type() );
		$this->assertEquals( 'commit', $parser->get_object_type_name() );
		$this->assertEquals(
			<<<BODY
tree 0a55136b1c405d19a1e269c79f11713455aeb6cd
parent 1ea132870e8ba97f211c00b43722248eb842332f
author Adam Zieliński <adam@adamziel.com> 1736778854 +0100
committer Adam Zieliński <adam@adamziel.com> 1736778854 +0100

Add InflateReader

BODY
			,
			$parser->get_body_chunk()
		);
		$this->assertTrue( $parser->next_token() );
		$this->assertEquals( '#object-hash', $parser->get_token_type() );

		$this->assertTrue( $parser->next_token() );
		$this->assertEquals( '#object-header', $parser->get_token_type() );
		$this->assertEquals( 'commit', $parser->get_object_type_name() );
		$this->assertTrue( $parser->next_token() );
		$this->assertEquals( '#object-body', $parser->get_token_type() );
		$this->assertEquals( 'commit', $parser->get_object_type_name() );
		$this->assertTrue( $parser->next_token() );
		$this->assertEquals( '#object-hash', $parser->get_token_type() );

		$this->assertTrue( $parser->next_token() );
		$this->assertEquals( '#object-header', $parser->get_token_type() );
		$this->assertEquals( 'commit', $parser->get_object_type_name() );
		$this->assertTrue( $parser->next_token() );
		$this->assertEquals( '#object-body', $parser->get_token_type() );
		$this->assertEquals( 'commit', $parser->get_object_type_name() );
		$this->assertTrue( $parser->next_token() );
		$this->assertEquals( '#object-hash', $parser->get_token_type() );

		$this->assertTrue( $parser->next_token() );
		$this->assertEquals( '#object-header', $parser->get_token_type() );
		$this->assertEquals( 'commit', $parser->get_object_type_name() );
		$this->assertTrue( $parser->next_token() );
		$this->assertEquals( '#object-body', $parser->get_token_type() );
		$this->assertEquals( 'commit', $parser->get_object_type_name() );
		$this->assertTrue( $parser->next_token() );
		$this->assertEquals( '#object-hash', $parser->get_token_type() );

		$this->assertFalse( $parser->next_token() );
	}

	public function test_parse_as_commit() {
		$parser = new PackParser();
		$parser->append_bytes(
			file_get_contents( __DIR__ . '/fixtures/pack-simple.pack' )
		);
		// Skip past the PACK header
		$parser->next_token();

		$this->assertTrue( $parser->next_token() );
		$parser->parse_body_as_commit();
		$commit = $parser->get_commit();
		$this->assertEquals( 'Adam Zieliński <adam@adamziel.com>', $commit->author );
		$this->assertEquals( '1736778854 +0100', $commit->author_date );
		$this->assertEquals( 'Adam Zieliński <adam@adamziel.com>', $commit->committer );
		$this->assertEquals( '1736778854 +0100', $commit->committer_date );
		$this->assertEquals( "Add InflateReader\n", $commit->message );
		$this->assertEquals( '67a7d00ae6792fad26c8f69e7ff82b7a1d7bc471', $commit->hash );
	}

	public function test_parse_tree() {
		// @TODO: Store raw tree data in a file instead of going through the pack parser
		$parser = new PackParser();
		$parser->append_bytes( file_get_contents( __DIR__ . '/fixtures/pack-tree.pack' ) );
		// Skip past the PACK header
		$parser->next_token();

		$this->assertTrue( $parser->next_token() );
		$this->assertEquals( 'tree', $parser->get_object_type_name() );

		$this->assertTrue( $parser->parse_body_as_tree() );

		$tree        = $parser->get_tree();
		$keys        = array_keys( $tree->entries );
		$first_entry = $tree->entries[ $keys[0] ];
		$this->assertEquals( '.github', $first_entry->name );
		$this->assertEquals( '040000', $first_entry->get_mode_bucket() );
		$this->assertEquals( '614260657b661e57774e4f9663c09d5e252079bd', $first_entry->hash );

		$second_entry = $tree->entries[ $keys[1] ];
		$this->assertEquals( '.gitignore', $second_entry->name );
		$this->assertEquals( '100644', $second_entry->get_mode_bucket() );
		$this->assertEquals( '9c4a6396f9de332c35c2addd46a625fe0ea47e90', $second_entry->hash );

		$names = array();
		foreach ( $tree->entries as $entry ) {
			$names[] = $entry->name;
		}
		$this->assertEquals(
			array(
				'.github',
				'.gitignore',
				'PLAN.md',
				'RATIONALE.md',
				'README.md',
				'bin',
				'blueprint.json',
				'components',
				'composer.base.json',
				'composer.json',
				'composer.lock',
				'phpcs.xml',
				'phpunit.xml',
				'plugins',
				'run.sh',
				'testing-grounds.php',
			),
			$names
		);

		$this->assertTrue( $parser->next_token() );
		$this->assertEquals( '#object-hash', $parser->get_token_type() );
		$this->assertEquals( '0a55136b1c405d19a1e269c79f11713455aeb6cd', $parser->get_object_hash() );
	}
}
