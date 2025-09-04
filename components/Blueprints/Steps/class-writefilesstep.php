<?php

namespace WordPress\Blueprints\Steps;

use WordPress\Blueprints\DataReference\DataReference;
use WordPress\Blueprints\DataReference\Directory;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;

use function WordPress\Filesystem\copy_between_filesystems;

/**
 * Represents the 'writeFiles' step.
 */
class WriteFilesStep implements StepInterface {
	/**
	 * An associative array where keys are file paths and values are their contents.
	 * @var array<string, DataReference>
	 */
	public $files;

	/**
	 * @param  array<string, string|DataReference>  $files  Files to write (path => content).
	 */
	public function __construct( array $files ) {
		$this->files = $files;
	}

	public function run( Runtime $runtime, Tracker $tracker ) {
		$total_files = count( $this->files );

		$tracker->set( 10, 'Writing files...' );

		$target_fs     = $runtime->getTargetFilesystem();
		$files_written = 0;

		foreach ( $this->files as $path => $data ) {
			if ( $tracker ) {
				$progress_value = 10 + ( ( $files_written / $total_files ) * 80 );
				$tracker->set( (int) $progress_value, "Writing file {$files_written}/{$total_files}: {$path}" );
			}

			// Create directory if it doesn't exist
			$dir = dirname( $path );
			if ( $dir && $dir !== '/' && $dir !== '.' ) {
				$target_fs->mkdir( $dir, [ 'recursive' => true ] );
			}

			// Handle the data which can be a string or a DataReference
			$file_or_directory = $runtime->resolve( $data );
			if ( $file_or_directory instanceof Directory ) {
				copy_between_filesystems( [
					'source_filesystem' => $file_or_directory->filesystem,
					'source_path'       => '/',
					'target_filesystem' => $target_fs,
					'target_path'       => $path,
					'recursive'         => true,
				] );
			} else {
				$content = $file_or_directory->getStream()->consume_all();
				$target_fs->put_contents( $path, $content );
			}

			$files_written ++;
		}

		$tracker->set( 100, "All {$total_files} files written successfully." );
	}
}
