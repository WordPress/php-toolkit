<?php

namespace WordPress\Merge\Tests;

use WordPress\Merge\Diff\Diff;

class DiffTest extends \PHPUnit\Framework\TestCase {

	public function test_get_new_document() {
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

	public function test_get_old_document() {
		$diff = new Diff(
			array(
				array( Diff::DIFF_EQUAL, "Line 1: The quick brown fox\n" ),
				array( Diff::DIFF_DELETE, "Line 2: jumps over the lazy dog.\n" ),
			)
		);

		$expected = "Line 1: The quick brown fox\nLine 2: jumps over the lazy dog.\n";
		$actual   = $diff->get_old_document();
		$this->assertEquals( $expected, $actual );
	}

	public function test_format_as_delta() {
		$diff = new Diff(
			array(
				array( Diff::DIFF_EQUAL, "Line 1: The quick brown fox\n" ),
				array( Diff::DIFF_DELETE, "Line 2: jumps over the lazy dog.\n" ),
				array( Diff::DIFF_INSERT, 'A new line' ),
			)
		);

		$expected = "=28\r-33\r+A new line";
		$actual   = $diff->format_as_delta();
		$this->assertEquals( $expected, $actual );
	}

	public function test_format_as_git_patch() {
		$diff = new Diff(
			array(
				array( Diff::DIFF_EQUAL, "Line 1: The quick brown fox\n" ),
				array( Diff::DIFF_DELETE, "Line 2: jumps over the lazy dog.\n" ),
				array( Diff::DIFF_INSERT, "Line 2: jumps over the lazy cat.\n" ),
				array( Diff::DIFF_EQUAL, "Line 3: consectetur adipiscing elit.\n" ),
				array( Diff::DIFF_EQUAL, "Next line.\n" ),
				array( Diff::DIFF_EQUAL, "Next line.\n" ),
				array( Diff::DIFF_EQUAL, "Next line.\n" ),
				array( Diff::DIFF_EQUAL, "Next line.\n" ),
				array( Diff::DIFF_EQUAL, "Next line.\n" ),
				array( Diff::DIFF_EQUAL, "Next line.\n" ),
				array( Diff::DIFF_EQUAL, "Next line.\n" ),
				array( Diff::DIFF_INSERT, 'A new line' ),
			)
		);

		$expected = <<<DIFF
diff --git a/string b/string
--- a/string
+++ b/string
@@ -1,5 +1,5 @@  Line 1: The quick brown fox
- Line 2: jumps over the lazy dog.
+ Line 2: jumps over the lazy cat.
  Line 3: consectetur adipiscing elit.
  Next line.
  Next line.
@@ -6,2 +6,2 @@  Next line.
  Next line.
+ A new line
DIFF;
		$actual   = $diff->format_as_git_patch();
		$this->assertEquals( $expected, $actual );
	}
}
