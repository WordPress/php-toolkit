<?php

namespace WordPress\Merge\Tests;

use WordPress\Merge\Diff\Differ;
use WordPress\Merge\Diff\Diff;
use WordPress\Merge\Diff\LineDiffer;

class LineDifferTest extends \PHPUnit\Framework\TestCase {

	public function test_lines_diff() {
		$base = <<<EOT
Line 1: The quick brown fox
Line 2: jumps over the lazy dog.
Line 4: consectetur adipiscing elit.
EOT;

		$updated = <<<EOT
Line 1: The quick brown fox
Line 2: jumps over the lazy cat.
Line 3: Lorem ipsum dolor sit amet,
Line 4: consectetur adipiscing elit.
EOT;

		$expected_diff = array(
			array( Diff::DIFF_EQUAL, "Line 1: The quick brown fox\n" ),
			array( Diff::DIFF_DELETE, "Line 2: jumps over the lazy dog.\n" ),
			array( Diff::DIFF_INSERT, "Line 2: jumps over the lazy cat.\n" ),
			array( Diff::DIFF_INSERT, "Line 3: Lorem ipsum dolor sit amet,\n" ),
			array( Diff::DIFF_EQUAL, "Line 4: consectetur adipiscing elit.\n" ),
		);
		$actual_diff   = ( new LineDiffer() )->diff( $base, $updated )->get_changes();
		$this->assertEquals( $expected_diff, $actual_diff );
	}

	public function test_evaluate_diff() {
		$diff = new Diff(
			array(
				array( Diff::DIFF_EQUAL, "Line 1: The quick brown fox\n" ),
				array( Diff::DIFF_DELETE, "Line 2: jumps over the lazy dog.\n" ),
			)
		);

		$expected = "Line 1: The quick brown fox\n";
		$actual   = $diff->get_new_document();
		$this->assertEquals( $expected, $actual );
	}
}
