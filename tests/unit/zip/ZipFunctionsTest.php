<?php

namespace unit\zip;

use PHPUnitTestCase;
use Symfony\Component\Filesystem\Filesystem;
use WordPress\Zip\ZipException;
use ZipArchive;
use function WordPress\Zip\zip_extract_to;

class ZipFunctionsTest extends PHPUnitTestCase {

	/**
	 * @var string name of the temporary directory for handling zips in tests
	*/
	const TMP_DIRECTORY = __DIR__ . "/tmp";

	/**
	 * @var Filesystem
	 */
	private $filesystem;

	/**
	 * @before
	 */
	public function before() {
		$this->filesystem = new Filesystem();
		$this->filesystem->mkdir( self::TMP_DIRECTORY );
	}

	/**
	 * @after
	 */
	public function after(){
		$this->filesystem->remove( self::TMP_DIRECTORY );
	}

	public function testThrowsExceptionWhenZipContainsFilesWithRelativePaths() {
		$filename = self::TMP_DIRECTORY . '/zip-slip-test.zip';

		$zip = new ZipArchive();
		$zip->open( $filename, ZipArchive::CREATE );
		$zip->addFromString( "../../evilfile.txt","zip-slip-test" );
		$zip->close();

		self::expectException( ZipException::class );
		self::expectExceptionMessage( "Relative paths in zips are not allowed." );
		zip_extract_to( fopen( $filename, 'rb' ), dirname( $filename ) );
	}

	public function testThrowsExceptionWhenZipContainsFilesWithSymlinks() {
		$filename = self::TMP_DIRECTORY . '/zip-symlink-test.zip';

		$zip = new ZipArchive();
		$zip->open( $filename, ZipArchive::CREATE );
		$symlink = symlink( self::TMP_DIRECTORY, 'evillink.txt' );
		$zip->addFile( $symlink,"zip-symlink-test" );
		$zip->close();

		self::expectException( ZipException::class );
		self::expectExceptionMessage( "Semantic links in zips are not allowed." );
		zip_extract_to( fopen( $filename, 'rb' ), dirname( $filename ) );
	}
}
