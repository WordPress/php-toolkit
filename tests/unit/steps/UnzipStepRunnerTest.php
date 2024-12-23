<?php

namespace unit\steps;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnitTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use WordPress\Blueprints\Model\DataClass\UnzipStep;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Resources\ResourceManager;
use WordPress\Blueprints\Runner\Step\UnzipStepRunner;
use WordPress\Blueprints\Runtime\Runtime;

class UnzipStepRunnerTest extends PHPUnitTestCase {

	/**
	 * @var string $document_root
	 */
	private $document_root;

	/**
	 * @var Runtime $runtime
	 */
	private $runtime;

	/**
	 * @var UnzipStepRunner $step_runner
	 */
	private $step_runner;

	/**
	 * @var Filesystem
	 */
	private $filesystem;

	/**
	 * @var ResourceManager&MockObject $resource_manager resource manager mock
	 */
	private $resource_manager;

	/**
	 * @before
	 */
	public function before() {
		$this->document_root = Path::makeAbsolute( 'test', sys_get_temp_dir() );
		$this->runtime = new Runtime( $this->document_root );

		$this->resource_manager = $this->createMock( ResourceManager::class );

		$this->step_runner = new UnzipStepRunner();
		$this->step_runner->setRuntime( $this->runtime );
		$this->step_runner->setResourceManager( $this->resource_manager );

		$this->filesystem = new Filesystem();
	}

	/**
	 * @after
	 */
	public function after() {
		$this->filesystem->remove( $this->document_root );
	}

	public function testUnzipFileWhenUsingAbsolutePath() {
		// TODO: Remove once UnzipStep is implemented
		$this->markTestSkipped('UnzipStep not yet implemented.');

		$zip = __DIR__ . '/resources/test_zip.zip';
		$this->resource_manager->method( 'getStream' )
			->willReturn( fopen( $zip, 'rb' ) );

		$step = new UnzipStep();
		$step->setZipFile( $zip );
		$extracted_file_path = $this->runtime->resolvePath( 'dir/test_zip.txt' );
		$step->setExtractToPath( Path::getDirectory( $extracted_file_path ) );

		$this->step_runner->run( $step, new Tracker() );
		self::assertFileEquals( __DIR__ . '/resources/test_zip.txt', $extracted_file_path );
	}

	public function testUnzipFileWhenUsingRelativePath() {
		// TODO: Remove once UnzipStep is implemented
		$this->markTestSkipped('UnzipStep not yet implemented.');

		$zip = __DIR__ . '/resources/test_zip.zip';
		$this->resource_manager->method( 'getStream' )
			->willReturn( fopen( $zip, 'rb' ) );

		$step = new UnzipStep();
		$step->setZipFile( $zip );
		$step->setExtractToPath( 'dir' );

		$this->step_runner->run( $step, new Tracker() );

		$extracted_file_path = $this->runtime->resolvePath( 'dir/test_zip.txt' );
		self::assertFileEquals( __DIR__ . '/resources/test_zip.txt', $extracted_file_path );
	}
}
