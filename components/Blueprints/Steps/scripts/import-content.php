<?php

use WordPress\Blueprints\DataReference\DataReference;
use WordPress\Blueprints\DataReference\DataReferenceResolver;
use WordPress\Blueprints\DataReference\Directory;
use WordPress\Blueprints\DataReference\ExecutionContextPath;
use WordPress\Blueprints\DataReference\File;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\HttpClient\Client;
use WordPress\DataLiberation\EntityReader\EPubEntityReader;
use WordPress\DataLiberation\EntityReader\FilesystemEntityReader;
use WordPress\DataLiberation\EntityReader\WXREntityReader;
use WordPress\DataLiberation\Importer\EntityImporter;
use WordPress\DataLiberation\Importer\ImportSession;
use WordPress\DataLiberation\Importer\ImportUtils;
use WordPress\DataLiberation\Importer\RetryFrontloadingIterator;
use WordPress\DataLiberation\Importer\StreamImporter;
use WordPress\DataLiberation\URL\WPURL;
use WordPress\Filesystem\LocalFilesystem;
use WordPress\Zip\ZipFilesystem;

use function WordPress\Filesystem\wp_join_unix_paths;

require_once getenv('DOCROOT') . '/wp-load.php';
require_once getenv('DOCROOT') . '/php-toolkit.phar';

// Progress reporting interfaces and implementations

interface ProgressReporter {
    /**
     * Report progress update
     * 
     * @param float $progress Progress percentage (0-100)
     * @param string $caption Progress caption/message
     */
    public function reportProgress(float $progress, string $caption): void;

    /**
     * Report an error
     * 
     * @param string $message Error message
     * @param \Throwable|null $exception Optional exception details
     */
    public function reportError(string $message, ?\Throwable $exception = null): void;

    /**
     * Report completion
     * 
     * @param string $message Completion message
     */
    public function reportCompletion(string $message): void;

    /**
     * Close/cleanup the reporter
     */
    public function close(): void;
}

class TerminalProgressReporter implements ProgressReporter {
    private $stdout;
    private $lastProgress = -1;
    private $lastCaption = '';
    private $progressBarWidth = 50;

    public function __construct() {
        $this->stdout = fopen('php://stdout', 'w');
    }

    public function reportProgress(float $progress, string $caption): void {
        // Don't repeat identical progress
        if ($this->lastProgress === $progress && $this->lastCaption === $caption) {
            return;
        }

        $this->lastProgress = $progress;
        $this->lastCaption = $caption;

        $percentage = min(100, max(0, $progress));
        $filled = (int)round($this->progressBarWidth * ($percentage / 100));
        $empty = $this->progressBarWidth - $filled;
        
        $bar = str_repeat('=', $filled);
        if ($empty > 0 && $filled < $this->progressBarWidth) {
            $bar .= '>';
            $bar .= str_repeat(' ', $empty - 1);
        } else {
            $bar .= str_repeat(' ', $empty);
        }

        $status = sprintf(
            "\r[%s] %3.1f%% - %s",
            $bar,
            $percentage,
            $caption
        );

        if ($this->isTty()) {
            // Clear line and write new progress
            fwrite($this->stdout, "\r\033[K" . $status);
        } else {
            // Non-TTY, just write new line
            fwrite($this->stdout, $status . "\n");
        }
        fflush($this->stdout);
    }

    public function reportError(string $message, ?\Throwable $exception = null): void {
        $this->clearCurrentLine();
        
        $errorMsg = "\033[1;31mError:\033[0m " . $message;
        if ($exception) {
            $errorMsg .= " (" . $exception->getMessage() . ")";
        }
        
        fwrite($this->stdout, $errorMsg . "\n");
        fflush($this->stdout);
    }

    public function reportCompletion(string $message): void {
        $this->clearCurrentLine();
        fwrite($this->stdout, "\033[1;32m" . $message . "\033[0m\n");
        fflush($this->stdout);
    }

    public function close(): void {
        if ($this->stdout) {
            fclose($this->stdout);
        }
    }

    private function clearCurrentLine(): void {
        if ($this->isTty()) {
            fwrite($this->stdout, "\r\033[K");
        }
    }

    private function isTty(): bool {
        return stream_isatty($this->stdout);
    }
}

class JsonProgressReporter implements ProgressReporter {
    private $outputFile;

    public function __construct() {
        $outputPath = getenv('OUTPUT_FILE') ?: 'php://stdout';
        $this->outputFile = fopen($outputPath, 'w');
    }

    public function reportProgress(float $progress, string $caption): void {
        $this->writeJsonMessage([
            'type' => 'progress',
            'progress' => round($progress, 2),
            'caption' => $caption
        ]);
    }

    public function reportError(string $message, ?\Throwable $exception = null): void {
        $errorData = [
            'type' => 'error',
            'message' => $message
        ];

        if ($exception) {
            $errorData['details'] = [
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString()
            ];
        }

        $this->writeJsonMessage($errorData);
    }

    public function reportCompletion(string $message): void {
        $this->writeJsonMessage([
            'type' => 'completion',
            'message' => $message
        ]);
    }

    public function close(): void {
        if ($this->outputFile) {
            fclose($this->outputFile);
        }
    }

    private function writeJsonMessage(array $data): void {
        fwrite($this->outputFile, json_encode($data) . "\n");
        fflush($this->outputFile);
    }
}

function createProgressReporter(): ProgressReporter {
    // Use JSON mode if OUTPUT_FILE is set or if we're not in a TTY
    if (getenv('OUTPUT_FILE') || !stream_isatty(STDOUT)) {
        return new JsonProgressReporter();
    }
    
    return new TerminalProgressReporter();
}

function bail_out( $message ) {
	throw new InvalidArgumentException( $message );
}

function run_content_import( $options ) {
	$reporter = createProgressReporter();
	$mainTracker = new Tracker();

	// Set up progress reporting
	$mainTracker->events->addListener(
		'WordPress\Blueprints\Progress\ProgressEvent',
		function($event) use ($reporter) {
			$reporter->reportProgress($event->getProgress(), $event->getCaption());
		}
	);

	try {
		define( 'NEW_SITE_CONTENT_ROOT', get_site_url() );
		$reporter->reportProgress(0, 'Target site URL: ' . NEW_SITE_CONTENT_ROOT);

		$accepted_modes = [
			'git',
			'local_directory',
			'wxr',
			'epub',
		];

		if ( !isset( $options['mode'] ) ) {
			bail_out( 'The "mode" option is required.' );
		}

		if ( !in_array( $options['mode'], $accepted_modes ) ) {
			bail_out( sprintf(
				'Invalid "mode" option: %s. Accepted modes are: %s.',
				$options['mode'],
				implode( ', ', $accepted_modes )
			) );
		}

		if(!isset($options['source'])) {
			bail_out( 'The "source" option is required.' );
		}

		// Set up progress stages
		$mainTracker->split([
			'setup' => ['ratio' => 10, 'caption' => 'Setting up import'],
			'indexing' => ['ratio' => 20, 'caption' => 'Indexing entities'],
			'assets' => ['ratio' => 30, 'caption' => 'Processing assets'],
			'importing' => ['ratio' => 40, 'caption' => 'Importing content']
		]);

		$setupTracker = $mainTracker['setup'];
		$setupTracker->set(10, 'Resolving content source');

		$httpClient = new Client();
		$content_source = DataReference::create($options['source'], [
			ExecutionContextPath::class,
		]);
		$resolver = new DataReferenceResolver($httpClient);
		if(isset($options['execution_context_root']) && $options['execution_context_root'] !== '') {
			$resolver->setExecutionContext(
				LocalFilesystem::create($options['execution_context_root'])
			);
		}
		$resolved_source = $resolver->resolve_uncached($content_source);

		$setupTracker->set(30, 'Configuring import mode');

		$chrooted_fs     = null;
		$source_site_url = null;
		if ( in_array( $options['mode'], array( 'local_directory', 'git' ) ) ) {
			// Validate required options
			if ( ! isset( $options['source_site_url'] ) ) {
				bail_out( 'The source_site_url option is required.' );
			}
			$index_file_pattern = '#(?:index|readme)\.(?:md|html|xhtml)$#i';
			$import_path_prefix = '/imported-content';
			$source_site_url    = $options['source_site_url'];

			if(!($resolved_source instanceof Directory)) {
				bail_out( 'The "source" option must resolve to a directory.' );
			}
			$chrooted_fs = $resolved_source->filesystem;
			if ( $options['mode'] === 'local_directory' ) {
				// @TODO: Rethink this, consider which values should we choose for git repos.
				$options['source_site_url'] = 'file:///';
			}

			$entity_reader_factory = function () use ( $chrooted_fs, $source_site_url, $index_file_pattern ) {
				return new FilesystemEntityReader(
					$chrooted_fs,
					array(
						'index_file_pattern' => $index_file_pattern,
						'filter_pattern' => '#\.(?:md|html|xhtml)$#',
						/**
						 * Use a number so large, there's no chance for wp_table INSERTs
						 * to interfere with the post IDs generated by the FilesystemEntityReader.
						 *
						 * Some inserts are ran even by the importer, e.g. frontloading stubs.
						 *
						 * @TODO: Would this collinde on subsequent Blueprint runs for the same site?
						 * @TODO: Make sure this doesn't automatically bump the AUTOINCREMENT counter in MySQL.
						 * @TODO: Bump the AUTOINCREMENT counter manually after a finished import.
						 */
						'first_post_id' => 10000000,
						'base_url' => $source_site_url,
					)
				);
			};

			/**
			 * Maps a filesystem path to a WordPress-friendly URL path we can assign
			 * to the imported page.
			 *
			 * Example: "/docs/README.md" -> "/docs/readme"
			 *
			 * @param string $path The filesystem path to convert
			 * @return string The WordPress-friendly URL path
			 */
			function map_file_path_to_wordpress_url( $path ) {
				global $index_file_pattern, $import_path_prefix;

				/**
				 * Ensure a named top-level parent directory to base the entire
				 * URL structure on. The goal is to have a consistent way to resolve
				 * URLs for all the following files:
				 *
				 * - README.md
				 * - chapter-5/README.md
				 * - chapter-5/section-1.md
				 * - chapter-5/section-3/readme.md
				 *
				 * Without the top-level directory, the best URL we can give the
				 * /README.md file would be `/readme`. However, the `chapter-5/README.md`
				 * would get a URL like `/chapter-5` which is inconsistent. However,
				 * if we transform the path structure as follows, everything becomes
				 * consistent:
				 *
				 * - /imported-content/README.md
				 * - /imported-content/chapter-5/README.md
				 * - /imported-content/chapter-5/section-1.md
				 * - /imported-content/chapter-5/section-3/readme.md
				 *
				 * We want to keep all the links working after the import. A single,
				 * consistent URL mapping strategy makes it much easier. The alternative
				 * would be to maintain a mapping of parents to paths and use it whenever
				 * creating pages and rewriting URLs.
				 *
				 * This isn't trivial. Having a top-level path prefix is not perfect,
				 * but it's a sound compromise.
				 */
				$path = wp_join_unix_paths( $import_path_prefix, $path );

				if ( 1 === preg_match( $index_file_pattern, $path ) ) {
					$path = dirname( $path );
				}

				$extensions = array( '.md', '.html', '.xhtml' );
				foreach ( $extensions as $ext ) {
					if ( str_ends_with( $path, $ext ) ) {
						$path = substr( $path, 0, -strlen( $ext ) );
						break;
					}
				}

				return strtolower( $path );
			}

			/**
			 * Transforms links pointing to imported static files (e.g. ./getting-started.md)
			 * to the format they will have after being imported into WordPress (e.g. /docs/getting-started).
			 */
			add_action(
				'data_liberation.stream_importer.postprocess_url',
				function (
					$processor,
					$context
				) use (
					$chrooted_fs,
					/**
					 * With &, $import_path_prefix reflects the latest value.
					 * Without &, it's a local copy of the value from the outer scope.
					 */
					&$import_path_prefix
				) {
					/**
					 * If we didn't rewrite the base URL, the URL points outside
					 * of the imported root directory. Let's keep it as it is.
					 */
					if ( ! $context['applied_base_url_mapping'] ) {
						return;
					}

					$path_original = $processor->get_parsed_url()->pathname;

					/**
					 * Remove the site path from the URL path and check:
					 * Is this URL pointing to a file that exists in the imported
					 * directory?
					 */
					$base_url_path_prefix  = $context['applied_base_url_mapping']['to']->pathname;
					$path_relative_to_base = substr( $path_original, strlen( $base_url_path_prefix ) );
					if ( $chrooted_fs->is_file( $path_relative_to_base ) ) {
						/**
						 * Yes! We are linking to an imported page. Let's transform the link
						 * to a WordPress-friendly URL scheme.
						 */
						$path_rewritten = map_file_path_to_wordpress_url( $path_relative_to_base );
						$path_rewritten = wp_join_unix_paths( $base_url_path_prefix, $path_rewritten );
					} elseif ( $processor->is_url_absolute() ) {
						/**
						 * No. We are linking to a content page within our site but there is
						 * no corresponding static file. This happens e.g. in the Gutenberg
						 * handbook where the markdown files contain absolute URLs to the deployed
						 * site, e.g.:
						 *
						 *     Start by ensuring you have Node.js and `npm` installed on your computer. Review
						 *     the [Node.js development environment](https://developer.wordpress.org/block-editor/getting-started/devenv/nodejs-development-environment/) guide if not.
						 *
						 * Our best shot is to keep the URL as is, just with the imported
						 * content root prepended to it.
						 */
						$path_rewritten = wp_join_unix_paths( $base_url_path_prefix, $import_path_prefix, $path_relative_to_base );
					} else {
						/**
						 * It's a relative URL pointing somewhere within the URL space we're importing
						 * to, but there is no corresponding static file. This is unexpected. There is
						 * nothing we can do at this point – let's just keep the URL as it is.
						 */
						return;
					}
					$processor->set_url(
						$path_rewritten,
						WPURL::parse( $path_rewritten, $processor->get_parsed_url() )
					);
				},
				10,
				3
			);

			/**
			 * Assigns post_name to every imported static page.
			 */
			add_filter(
				'data_liberation.stream_importer.preprocess_entity',
				function ( $entity ) use ( &$import_path_prefix, $index_file_pattern ) {
					static $preprocessed_an_entity = false;
					if ( $entity->get_type() !== 'post' ) {
						return $entity;
					}

					$data = $entity->get_data();

					if ( isset( $data['parsed_metadata']['slug'] ) ) {
						$data['post_name'] = basename( $data['parsed_metadata']['slug'][0] );
					} elseif ( isset( $data['local_file_path'] ) ) {
						/**
						 * The default import content path is "/imported-content". However,
						 * maybe we can find a friendlier path prefix based on the post
						 * title of the top-level index file.
						 *
						 * For example, a "Getting Started" guide found at "README.md"
						 * could be imported to "/getting-started".
						 */
						if ( ! $preprocessed_an_entity ) {
							$preprocessed_an_entity           = true;
							$dirname                          = dirname( $data['local_file_path'] );
							$dirname_makes_a_bad_slug         = $dirname !== '.' && $dirname === '/';
							$is_index_file                    = 1 === preg_match( $index_file_pattern, $data['local_file_path'] );
							$post_title_not_derived_from_path = $data['post_title'] !== ImportUtils::slug_to_title( basename( $data['local_file_path'] ) );

							if (
								$dirname_makes_a_bad_slug &&
								$is_index_file &&
								$post_title_not_derived_from_path &&
								strlen( $data['post_title'] ) > 1
							) {
								$import_path_prefix = wp_import_slugify( $data['post_title'] );
							}
						}

						$wordpress_url     = map_file_path_to_wordpress_url( $data['local_file_path'] );
						$data['post_name'] = basename( $wordpress_url );
					} else {
						return $entity;
					}

					$entity->set_data( $data );
					return $entity;
				},
				10,
				2
			);
		} elseif ( $options['mode'] === 'wxr' ) {
			if ( ! isset( $options['source'] ) ) {
				help_message_and_die( 'The "wxr file" option is required.' );
			}
			if(!($resolved_source instanceof File)) {
				bail_out( 'The "source" option must resolve to a file.' );
			}
			$entity_reader_factory = function ( $cursor ) use ( $resolved_source ) {
				$stream = $resolved_source->getStream();
				$stream->seek(0);
				return WXREntityReader::create(
					$stream,
					$cursor
				);
			};
		} elseif ( $options['mode'] === 'epub' ) {
			if ( ! isset( $options['source'] ) ) {
				help_message_and_die( 'The "epub file" option is required.' );
			}

			if(!($resolved_source instanceof File)) {
				bail_out( 'The "source" option must resolve to a file.' );
			}
			$zip_fs = ZipFilesystem::create($resolved_source->getStream());
			$entity_reader_factory = function ( $cursor = null ) use ( $zip_fs ) {
				return new EPubEntityReader(
					$zip_fs,
					1000000 // This is first post ID. We should really also accept a cursor
				);
			};
			$reader                = $entity_reader_factory();
			$source_site_url       = 'file://' . dirname( $reader->get_manifest_path() );

			// To source the media files from the EPUB bundle:
			$chrooted_fs = $zip_fs;

			/**
			 * Drop .xhtml extension from the links.
			 */
			add_action(
				'data_liberation.stream_importer.postprocess_url',
				function ( $processor ) {
					$parsed_url = $processor->get_parsed_url();
					if ( ! str_ends_with( $parsed_url->pathname, '.xhtml' ) ) {
						return;
					}
					$parsed_url->pathname = substr( $parsed_url->pathname, 0, -6 );
					$processor->set_url(
						$parsed_url . '',
						$parsed_url
					);
				}
			);
		} else {
			help_message_and_die( 'The "mode" option is required and must be one of: "local_directory", "git", "wxr", or "epub".' );
			exit( 1 );
		}

		$setupTracker->finish();

		$source = $options['source'];
		$reporter->reportProgress($mainTracker->getProgress(), "Importing static files from $source");

		// Parse URL mapping options
		$additional_url_mappings = array();
		foreach ( $options['additional_site_urls'] ?? [] as $url ) {
			$additional_url_mappings[] = array(
				'from' => $url,
				'to' => NEW_SITE_CONTENT_ROOT,
			);
		}

		$importer = StreamImporter::create(
			$entity_reader_factory,
			array(
				'entity_sink' => new EntityImporter(),
				'source_site_url' => $source_site_url,
				'new_site_content_root_url' => NEW_SITE_CONTENT_ROOT,
				'source_media_root_urls' => $options['media_url'] ?? array( $source_site_url ),
				'additional_url_mappings' => $additional_url_mappings,
				'index_batch_size' => 1,
				'attachment_downloader_options' => array(
					'source_from_filesystem' => $chrooted_fs,
				),
			)
		);

		$import_session   = ImportSession::create(
			array(
				'data_source' => 'local_directory',
				// @TODO: the phrase "file_name" doesn't make sense here. We're sourcing
				// data from a directory, not a file. This string is used to tell
				// the user in the UI what this they're importing in this import
				// session. Let's rename it to something more descriptive.
				'file_name' => $options['source'],
			)
		);
		$retries_iterator = new RetryFrontloadingIterator( $import_session->get_id() );
		$importer->set_frontloading_retries_iterator( $retries_iterator );

		// Run the import with progress tracking
		$ignored_message_printed = false;
		do {
			$result = data_liberation_import_step_customized( 
				$import_session, 
				$importer, 
				$mainTracker,
				$reporter
			);
			
			if ( $importer->get_stage() === StreamImporter::STAGE_FINISHED ) {
				$reporter->reportProgress(100, 'Import completed successfully');
				
				// Get the first page with non-empty content.
				$posts = get_posts(
					array(
						'numberposts' => 10,
						'orderby' => 'ID',
						'order' => 'ASC',
						'post_type' => 'page',
						'post_status' => 'publish',
					)
				);

				$url = NEW_SITE_CONTENT_ROOT;
				foreach ( $posts as $post ) {
					if ( ! empty( $post->post_content ) ) {
						$url = get_permalink( $post );
						break;
					}
				}
				
				$reporter->reportCompletion("Import finished! See your imported content at: " . $url);
				break;
			} elseif ( false === $result ) {
				if ( $importer->get_stage() === StreamImporter::STAGE_FRONTLOAD_ASSETS ) {
					if ( ! $ignored_message_printed ) {
						$reporter->reportProgress($mainTracker->getProgress(), "Some assets could not be downloaded – they will be ignored so we can continue with the import.");
						$ignored_message_printed = true;
					}
					// $import_session->mark_frontloading_errors_as_ignored();
				} else {
					$reporter->reportError("Import failed, aborting");
					break;
				}
			}
		} while ( true );

	} catch ( \Throwable $e ) {
		$reporter->reportError("Import failed: " . $e->getMessage(), $e);
		throw $e;
	} finally {
		$reporter->close();
		if ( isset( $cache_fs ) ) {
			$cache_fs->rmdir(
				'/',
				array(
					'recursive' => true,
				)
			);
		}
	}
}

/**
 * @TODO: Expose a primitive like the step function below from the
 *        DataLiberation PHP component. Support all sorts of pause conditions
 *        such as time limits, retry counts, memory limits, etc.
 */
function data_liberation_import_step_customized( ImportSession $session, StreamImporter $importer, Tracker $mainTracker, ProgressReporter $reporter ) {
	$soft_time_limit_seconds = 15;
	$hard_time_limit_seconds = 25;
	$start_time              = microtime( true );
	$fetched_files           = 0;

	while ( true ) {
		$time_taken = microtime( true ) - $start_time;
		if ( $time_taken >= $soft_time_limit_seconds ) {
			if ( $importer->get_stage() === StreamImporter::STAGE_FRONTLOAD_ASSETS ) {
				if ( $fetched_files > 0 ) {
					return true;
				}
			} else {
				return true;
			}
		}
		if ( $time_taken >= $hard_time_limit_seconds ) {
			return true;
		}

		if ( true !== $importer->next_step() ) {
			$session->set_reentrancy_cursor( $importer->get_reentrancy_cursor() );

			$should_advance_to_next_stage = null !== $importer->get_next_stage();
			if ( ! $should_advance_to_next_stage ) {
				// @TODO: Report error?
				append_output('Step failed in the middle of a stage: ' . $session->get_stage() . "\n");
				continue;
				// throw new \Exception('Step failed in the middle of a stage: ' . $session->get_stage());
			}

			if ( StreamImporter::STAGE_FRONTLOAD_ASSETS === $importer->get_stage() ) {
				$resolved_all_failures = $session->count_unfinished_frontloading_stubs() === 0;
				if ( ! $resolved_all_failures ) {
					// return false;
				}
			}

			if ( ! $importer->advance_to_next_stage() ) {
				return false;
			}
			$session->set_stage( $importer->get_stage() );
			$session->set_reentrancy_cursor( $importer->get_reentrancy_cursor() );
			if ( $session->get_stage() === StreamImporter::STAGE_FINISHED ) {
				return true;
			}

			continue;
		}

		switch ( $importer->get_stage() ) {
			case StreamImporter::STAGE_INDEX_ENTITIES:
				$entities_counts = $importer->get_indexed_entities_counts();
				$session->create_frontloading_stubs( $importer->get_indexed_assets_urls() );
				$session->bump_total_number_of_entities( $entities_counts );
				
				$totalEntities = array_sum( $session->get_total_number_of_entities() );
				$mainTracker['indexing']->set(
					min(100, ($totalEntities / 100) * 100), // Rough progress calculation
					'Indexing entities (' . $totalEntities . ' found)'
				);
				break;

			case StreamImporter::STAGE_FRONTLOAD_ASSETS:
				$progress = $importer->get_frontloading_progress();
				$session->bump_frontloading_progress(
					$progress,
					$importer->get_frontloading_events()
				);

				$remaining = $session->count_unfinished_frontloading_stubs();
				$mainTracker['assets']->set(
					max(0, 100 - ($remaining / max(1, $remaining + ($progress['downloaded'] ?? 0)) * 100)),
					'Fetching media files (' . $remaining . ' remaining)'
				);
				break;

			case StreamImporter::STAGE_IMPORT_ENTITIES:
				$imported_counts = $importer->get_imported_entities_counts();
				$session->bump_imported_entities_counts( $imported_counts );

				$imported = $session->count_all_imported_entities();
				$total = $session->count_remaining_entities() + $imported;
				$progress = $total > 0 ? ($imported / $total) * 100 : 0;
				
				$mainTracker['importing']->set(
					$progress,
					'Importing entities (' . $imported . '/' . $total . ')'
				);
				break;
		}

		$session->set_reentrancy_cursor( $importer->get_reentrancy_cursor() );
	}
	return false;
}

/**
 * Naive slugification function.
 *
 * @TODO: Use a more sophisticated one with utf-8 support etc.
 */
function wp_import_slugify( $title ) {
	return preg_replace( '/[^a-z0-9]+/i', '-', trim( strtolower( $title ) ) );
}

?>