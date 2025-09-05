<?php

namespace WordPress\Blueprints\Steps;

use InvalidArgumentException;
use WordPress\Blueprints\DataReference\DataReference;
use WordPress\Blueprints\DataReference\File;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;
use WordPress\Zip\ZipFilesystem;

use function WordPress\Filesystem\copy_between_filesystems;

/**
 * Represents the 'unzip' step.
 */
class UnzipStep implements StepInterface {
	/**
	 * Zip file source identifier (URL, ./path, /path).
	 *
	 * @var DataReference
	 */
	public $zipFile;

	/**
	 * The path to extract the zip file to.
	 *
	 * @var string
	 */
	public $extractToPath;

	/**
	 * @param  DataReference $zipFile  Zip file source identifier.
	 * @param  string        $extractToPath  The path to extract the zip file to.
	 */
	public function __construct( DataReference $zipFile, string $extractToPath ) {
		$this->zipFile       = $zipFile;
		$this->extractToPath = $extractToPath;
	}

	public function run( Runtime $runtime, Tracker $tracker ) {
		$tracker->set( 10, 'Unzipping...' );

		$target_fs = $runtime->getTargetFilesystem();

		// Get the data reference for the zip file
		$zip_stream = $runtime->resolve( $this->zipFile );

		if ( ! $zip_stream instanceof File ) {
			throw new InvalidArgumentException( 'The provided resource is not a zip file.' );
		}

		$zip_fs = ZipFilesystem::create( $zip_stream->getStream() );

		$tracker->set( 50, 'Extracting files...' );

		copy_between_filesystems(
			array(
				'source_filesystem' => $zip_fs,
				'source_path'       => '/',
				'target_filesystem' => $target_fs,
				'target_path'       => $this->extractToPath,
				'recursive'         => true,
			)
		);

		$tracker->set( 100, 'Extraction complete' );
	}
}
