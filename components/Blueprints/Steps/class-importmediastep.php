<?php

namespace WordPress\Blueprints\Steps;

use Exception;
use RuntimeException;
use WordPress\Blueprints\DataReference\DataReference;
use WordPress\Blueprints\DataReference\File;
use WordPress\Blueprints\MediaFileDefinition;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;

use function WordPress\Filesystem\pipe_stream;

/**
 * Represents the 'importMedia' step.
 */
class ImportMediaStep implements StepInterface {
	/**
	 * An associative array of media files to import.
	 *
	 * @var array<string, DataReference|string>
	 */
	private $media;

	/**
	 * @param  array<string, DataReference|string> $media  Media files to import.
	 */
	public function __construct( array $media ) {
		$this->media = $media;
	}

	/**
	 * @return array<string, MediaFileDefinition>
	 */
	public function getMedia(): array {
		return $this->media;
	}

	/**
	 * @param  array<string, MediaFileDefinition> $media
	 */
	public function setMedia( array $media ): void {
		$this->media = $media;
	}

	public function run( Runtime $runtime, Tracker $progress ) {
		$medias = $this->getMedia();

		$total_files = count( $medias );
		if ( 0 === $total_files ) {
			$progress->finish();

			return true;
		}

		$progress->setCaption( 'Importing media files' );
		$progress->split(
			array(
				'download' => 0.5,
				'import'   => 0.5,
			)
		);

		$files_imported = 0;
		$fs             = $runtime->getTargetFilesystem();
		$wp_upload_dir  = $runtime->evalPhpCodeInSubProcess(
			'<?php
			require_once(getenv("DOCROOT") . "/wp-load.php");
			$upload_dir = wp_upload_dir();
			append_output( json_encode($upload_dir) );
			'
		)->output_file_content;

		$upload_dir = json_decode( $wp_upload_dir, true );
		if ( ! $upload_dir || ! isset( $upload_dir['path'] ) ) {
			throw new RuntimeException( 'Failed to get WordPress upload directory' );
		}

		// Get the upload path relative to the WordPress root.
		$upload_base_dir = ltrim( substr( $upload_dir['path'], strlen( $runtime->getConfiguration()->getTargetSiteRoot() ) ), '/' );

		// Ensure the uploads directory exists.
		$fs = $runtime->getTargetFilesystem();
		if ( ! $fs->is_dir( $upload_base_dir ) ) {
			$fs->mkdir( $upload_base_dir, array( 'recursive' => true ) );
		}

		$resolved = $runtime->getDataReferenceResolver()->startEagerResolution(
			array_map(
				function ( $media ) {
					return $media->source;
				},
				$medias
			),
			$progress['download']
		);

		$progress['import']->split( $$total_files );
		foreach ( $medias as $i => $media_definition ) {
			$human_readable_name = $media_definition->source->get_human_readable_name();
			$progress['import'][ $i ]->setCaption( "Importing media file {$i}/{$total_files}: {$human_readable_name}" );

			try {
				$resolved = $runtime->resolve( $media_definition->source );

				if ( ! $resolved instanceof File ) {
					// TODO: What if the schema specifies a resource type that can only be resolved to a file?
					// Then the resolver could throw the error instead of requiring each step to check.
					// But since the resolve interface can return either a File or Directory,.
					// would we have to check anyway?
					// Would there be any value in the runtime having specific methods like resolveFile().
					// and resolveDirectory() that throw if they cannot resolve the requested type?
					throw new RuntimeException( "Failed to resolve media file: $human_readable_name" );
				}

				// Create a new file in the uploads directory.
				$target_path = $this->resolveTargetPath(
					$runtime,
					$media_definition->source,
					$upload_base_dir
				);

				$write_stream = $fs->open_write_stream( $target_path );
				pipe_stream( $resolved->getStream(), $write_stream );
				$resolved->getStream()->close_reading();
				$write_stream->close_writing();

				// Add to WordPress media library.
				$attachment_id = $runtime->evalPhpCodeInSubProcess(
					<<<'CODE'
<?php
require_once(getenv("DOCROOT") . "/wp-load.php");
require_once(getenv("DOCROOT") . "/wp-admin/includes/image.php");

$file_path = getenv("MEDIA_FILE_PATH");
$attachment_meta = json_decode(getenv("ATTACHMENT_META"), true);
$attachment_data = [
'post_title' => $attachment_meta['title'] ?? preg_replace('/\.[^.]+$/', '', basename($file_path)),
'post_mime_type' => wp_check_filetype(basename($file_path), null)['type'] ?? 'application/octet-stream',
'post_content' => $attachment_meta['description'] ?? '',
'post_status' => 'inherit',
'post_excerpt' => $attachment_meta['caption'] ?? '',
'meta_input' => [
'_wp_attachment_image_alt' => $attachment_meta['alt'] ?? '',
],
];                    
$attachment_id = wp_insert_attachment($attachment_data, $file_path);

if (is_wp_error($attachment_id)) {
echo "0";
exit(1);
}

// Generate metadata and create thumbnails if needed
$mime_type = $attachment_data['post_mime_type'];
if (strpos($mime_type, 'image/') === 0) {
$attachment_metadata = wp_generate_attachment_metadata($attachment_id, $file_path);
wp_update_attachment_metadata($attachment_id, $attachment_metadata);
}

echo $attachment_id;
CODE
					,
					array(
						'MEDIA_FILE_PATH' => $target_path,
						'ATTACHMENT_META' => json_encode(
							array(
								'title'       => $media_definition->title,
								'description' => $media_definition->description,
								'alt'         => $media_definition->alt,
								'caption'     => $media_definition->caption,
							)
						),
					)
				);

				if ( ! $attachment_id ) {
					throw new RuntimeException( "Failed to import media file: $human_readable_name" );
				}

				$progress['import'][ $i ]->finish();
			} catch ( Exception $e ) {
				// Log error but continue with other media files.
				// @TODO: Think through exception handling here.
				$runtime->getLogger()->warning( "Failed to import media file {$target_path}: " . $e->getMessage() );
			}

			++$files_imported;
		}

		$progress->finish();
	}

	private function resolveTargetPath(
		Runtime $runtime,
		DataReference $source,
		string $upload_base_dir
	): string {
		$fs = $runtime->getTargetFilesystem();

		$filename = $source->get_filename();
		if ( ! $filename ) {
			throw new RuntimeException(
				sprintf(
					'Failed to get filename for media file: %s. We can\'t infer the extension.',
					$source->get_human_readable_name()
				)
			);
		}

		/**
		 * If we already have a file with the same name, choose a random
		 * filename.
		 */
		$extension   = pathinfo( $filename, PATHINFO_EXTENSION );
		$target_path = $upload_base_dir . '/' . $filename;
		while ( $fs->exists( $target_path ) ) {
			$filename    = substr( sha1( uniqid( 'media_', true ) ), 0, 12 ) . '.' . $extension;
			$target_path = $upload_base_dir . '/' . $filename;
		}

		$parent_dir = dirname( $target_path );
		if ( ! $fs->is_dir( $parent_dir ) ) {
			$fs->mkdir( $parent_dir, array( 'recursive' => true ) );
		}

		return $target_path;
	}
}
