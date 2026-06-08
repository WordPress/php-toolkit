<?php

namespace WordPress\Blueprints\Tests\Unit\Steps;

use PHPUnit\Framework\TestCase;
use WordPress\Blueprints\DataReference\InlineFile;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;
use WordPress\Blueprints\Steps\ImportContentStep;
use WordPress\ByteStream\MemoryPipe;

class ImportContentStepTest extends TestCase {
	public function test_wxr_import_appends_call_inside_existing_php_script() {
		$runtime = new CapturingImportRuntime();
		$source  = new InlineFile(
			array(
				'filename' => 'content.xml',
				'content'  => '<rss />',
			)
		);
		$step    = new ImportContentStep(
			array(
				array(
					'type'   => 'wxr',
					'source' => $source,
				),
			)
		);

		$step->run( $runtime, new Tracker() );

		$importer_script = file_get_contents( __DIR__ . '/../../../Steps/scripts/import-content.php' );
		$appended_code   = substr( $runtime->captured_code, strlen( $importer_script ) );

		$this->assertSame( 0, strpos( ltrim( $appended_code ), 'run_content_import([' ) );
		$this->assertStringNotContainsString( '<?php', $appended_code );
		$this->assertStringNotContainsString( '?>', $appended_code );
	}
}

class CapturingImportRuntime extends Runtime {
	/**
	 * @var string
	 */
	public $captured_code;

	public function __construct() {
	}

	public function create_php_sub_process(
		$code,
		$env = null,
		$input = null,
		$timeout = 60
	) {
		$this->captured_code = $code;

		return new SuccessfulImportProcess();
	}

	public function get_execution_context_root(): ?string {
		return null;
	}
}

class SuccessfulImportProcess {
	public function start() {
	}

	public function getOutputStream( $pipe ) {
		return new MemoryPipe( '{"type":"completion"}' . "\n" );
	}

	public function getExitCode() {
		return 0;
	}

	public function stop() {
	}
}
