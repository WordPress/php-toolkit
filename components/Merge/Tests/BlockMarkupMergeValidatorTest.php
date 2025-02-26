<?php

namespace WordPress\Merge\Tests;

use WordPress\Merge\Validate\BlockMarkupMergeValidator;
use WordPress\Merge\Validate\InvalidMergeException;

class BlockMarkupMergeValidatorTest extends \PHPUnit\Framework\TestCase {

	/**
	 * @dataProvider corruptedMergeResultsProvider
	 */
	public function test_assert_merge_result_is_structurally_sound( $markup ) {
		$this->expectException( InvalidMergeException::class );
		$validator = new BlockMarkupMergeValidator();
		$validator->validate( $markup );
	}

	public function corruptedMergeResultsProvider() {
		$testCases      = array();
		$testCasesPaths = glob( __DIR__ . '/test-data/corrupted-merge-results/*' );
		foreach ( $testCasesPaths as $path ) {
			$testCases[ basename( $path ) ] = array(
				file_get_contents( $path ),
			);
		}

		return $testCases;
	}
}
