<?php

namespace Git\Tests;

use PHPUnit\Framework\TestCase;
use WordPress\Git\Protocol\Parser\PackParser;
use WordPress\Git\Protocol\Parser\TreeParser;

class GitTreeParserTest extends TestCase {

	public function test_parse_tree() {
		// @TODO: Store raw tree data in a file instead of going through the pack parser
		$parser = new PackParser();
		$parser->append_bytes( file_get_contents( __DIR__ . '/fixtures/pack-tree.pack' ) );
		$this->assertTrue( $parser->next_token() );
		$this->assertTrue( $parser->next_token() );
		$this->assertEquals( 'tree', $parser->get_object_type_name() );

		$parser->next_body_chunk();
		$tree_data = $parser->get_body_chunk();

		$tree_parser = new TreeParser(
			array(
				'expected_bytes' => strlen( $tree_data ),
			)
		);
		$tree_parser->append_bytes( $tree_data );
		$this->assertTrue( $tree_parser->next() );
		$entry = $tree_parser->get_tree_entry();
		$this->assertEquals( '.github', $entry->name );
		$this->assertEquals( '040000', $entry->get_mode_bucket() );
		$this->assertEquals( '614260657b661e57774e4f9663c09d5e252079bd', $entry->hash );

		$this->assertTrue( $tree_parser->next() );
		$entry = $tree_parser->get_tree_entry();
		$this->assertEquals( '.gitignore', $entry->name );
		$this->assertEquals( '100644', $entry->get_mode_bucket() );
		$this->assertEquals( '9c4a6396f9de332c35c2addd46a625fe0ea47e90', $entry->hash );

		$parser->next_body_chunk();
		$tree_parser->append_bytes( $parser->get_body_chunk() );
		for ( $i = 0; $i < 8; $i ++ ) {
			$this->assertTrue( $tree_parser->next() );
		}
		$this->assertFalse( $tree_parser->next() );
	}
}
