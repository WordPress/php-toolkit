<?php

namespace WordPress\Git\Tests;

use PHPUnit\Framework\TestCase;
use WordPress\Git\Protocol\Parser\CommitParser;

class CommitParserTest extends TestCase {

	public function test_parse_commit() {
		$commit_bytes = <<<COMMIT
tree 0a55136b1c405d19a1e269c79f11713455aeb6cd
parent 1ea132870e8ba97f211c00b43722248eb842332f
author Adam Zieliński <adam@adamziel.com> 1736778854 +0100
committer Adam Zieliński <adam@adamziel.com> 1736778854 +0100

Add InflateReader

COMMIT;
		$parser       = new CommitParser( $commit_bytes );
		$this->assertTrue( $parser->next() );
		$commit = $parser->get_commit();
		$this->assertEquals( 'Adam Zieliński <adam@adamziel.com>', $commit->author );
		$this->assertEquals( '1736778854 +0100', $commit->author_date );
		$this->assertEquals( 'Adam Zieliński <adam@adamziel.com>', $commit->committer );
		$this->assertEquals( '1736778854 +0100', $commit->committer_date );
		$this->assertEquals( "Add InflateReader\n", $commit->message );
		$this->assertEquals( '67a7d00ae6792fad26c8f69e7ff82b7a1d7bc471', $commit->hash );
		$this->assertFalse( $parser->next() );
	}
}
