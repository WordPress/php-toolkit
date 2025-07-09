<?php

namespace WordPress\Blueprints\Tests\Unit\Versions\Version1;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use WordPress\Blueprints\Versions\Version1\V1ToV2Transpiler;

class V1ToV2TranspilerTest extends TestCase {
	private $transpiler;

	protected function setUp(): void {
		$this->transpiler = new V1ToV2Transpiler( new NullLogger() );
	}

	/**
	 * Test converting enableMultisite step from v1 to v2
	 */
	public function testConvertEnableMultisiteStep() {
		$v1Blueprint = [
			'steps' => [
				[
					'step' => 'enableMultisite'
				]
			]
		];

		$v2Blueprint = $this->transpiler->upgrade( $v1Blueprint );

		$this->assertArrayHasKey( 'additionalStepsAfterExecution', $v2Blueprint );
		$this->assertCount( 1, $v2Blueprint['additionalStepsAfterExecution'] );
		
		$step = $v2Blueprint['additionalStepsAfterExecution'][0];
		$this->assertEquals( 'enableMultisite', $step['step'] );
		$this->assertArrayNotHasKey( 'wpCliPath', $step );
	}

	/**
	 * Test converting enableMultisite step with custom wpCliPath from v1 to v2
	 */
	public function testConvertEnableMultisiteStepWithCustomWpCliPath() {
		$v1Blueprint = [
			'steps' => [
				[
					'step' => 'enableMultisite',
					'wpCliPath' => '/custom/path/to/wp-cli.phar'
				]
			]
		];

		$v2Blueprint = $this->transpiler->upgrade( $v1Blueprint );

		$this->assertArrayHasKey( 'additionalStepsAfterExecution', $v2Blueprint );
		$this->assertCount( 1, $v2Blueprint['additionalStepsAfterExecution'] );
		
		$step = $v2Blueprint['additionalStepsAfterExecution'][0];
		$this->assertEquals( 'enableMultisite', $step['step'] );
		$this->assertEquals( '/custom/path/to/wp-cli.phar', $step['wpCliPath'] );
	}

	/**
	 * Test that v2 blueprint doesn't warn about enableMultisite anymore
	 */
	public function testNoWarningForEnableMultisite() {
		$v1Blueprint = [
			'steps' => [
				[
					'step' => 'enableMultisite'
				]
			]
		];

		// This should not throw any warnings or exceptions
		$v2Blueprint = $this->transpiler->upgrade( $v1Blueprint );
		
		// Verify the step was actually converted and not ignored
		$this->assertNotEmpty( $v2Blueprint['additionalStepsAfterExecution'] );
		$this->assertEquals( 'enableMultisite', $v2Blueprint['additionalStepsAfterExecution'][0]['step'] );
	}
}