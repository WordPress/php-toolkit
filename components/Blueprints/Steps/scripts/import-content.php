<?php

use WordPress\Blueprints\DataReference\DataReference;
use WordPress\Blueprints\DataReference\DataReferenceResolver;
use WordPress\Blueprints\DataReference\Directory;
use WordPress\Blueprints\DataReference\ExecutionContextPath;
use WordPress\Blueprints\DataReference\File;
use WordPress\HttpClient\Client;
use WordPress\DataLiberation\EntityReader\EPubEntityReader;
use WordPress\DataLiberation\EntityReader\FilesystemEntityReader;
use WordPress\DataLiberation\EntityReader\WXREntityReader;
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

interface ConsoleWriter {
    /**
     * Write text at the current cursor position
     * 
     * @param string $text Text to write
     */
    public function write(string $text): void;

    /**
     * Move cursor to beginning of line and clear everything after
     */
    public function clearLine(): void;

    /**
     * Replace current line with new text
     * 
     * @param string $text New text for the line
     */
    public function replaceLine(string $text): void;

    /**
     * Write multiple lines, optionally replacing previous output
     * 
     * @param array $lines Array of text lines to write
     * @param bool $replace Whether to replace previous output
     */
    public function writeLines(array $lines, bool $replace = false): void;
}

class PhpConsoleWriter implements ConsoleWriter {
    private $stdout;

    public function __construct() {
        $this->stdout = fopen(getenv('OUTPUT_FILE') ?? 'php://stdout', 'w');
    }

    public function __destruct() {
        fclose($this->stdout);
    }

    public function write(string $text): void {
        fwrite($this->stdout, $text);
    }

    public function clearLine(): void {
        if (!$this->isTty()) {
            return;
        }
        fwrite($this->stdout, "\r\033[K"); // Return to start + clear to end
    }

    public function replaceLine(string $text): void {
        // $this->clearLine();
        $this->write($text . "\n");
    }

    public function writeLines(array $lines, bool $replace = false): void {
        if ($replace && $this->isTty()) {
            // Move up by number of lines and clear them
            foreach ($lines as $i => $line) {
                if ($i > 0) {
                    fwrite($this->stdout, "\033[1A"); // Move up one line
                }
                $this->clearLine();
            }
        }
        
        foreach ($lines as $line) {
            $this->write($line . PHP_EOL);
        }
    }

    private function isTty(): bool {
        return stream_isatty($this->stdout);
    }
}

class ProgressBar {
    private ConsoleWriter $writer;
    private ?int $total;
    private int $current;
    private int $width;
    private string $message;
    private float $startTime;
    private bool $started = false;
    private bool $indeterminate = false;

    public function __construct(ConsoleWriter $writer, ?int $total = 100, int $width = 50) {
        $this->writer = $writer;
        $this->total = $total;
        $this->indeterminate = ($total === null);
        $this->current = 0;
        $this->width = $width;
        $this->message = '';
    }

    public function start(): void {
        if ($this->started) {
            return;
        }
        $this->started = true;
        $this->startTime = microtime(true);
        $this->update();
    }

    public function advance(int $step = 1): void {
        $this->setCurrent($this->current + $step);
    }

    public function setCurrent(int $current): void {
        $this->current = $this->indeterminate ? $current : min($this->total, max(0, $current));
        $this->update();
    }

    public function setMessage(string $message): void {
        $this->message = $message;
        $this->update();
    }

    public function finish(): void {
        if (!$this->started) {
            return;
        }
        if (!$this->indeterminate) {
            $this->current = $this->total;
        }
        $this->update();
        $this->writer->write("\n");
    }

    private function update(): void {
        if (!$this->started) {
            return;
        }

        if ($this->indeterminate) {
            $this->updateIndeterminate();
        } else {
            $this->updateDeterminate();
        }
    }

    private function updateIndeterminate(): void {
        $elapsed = microtime(true) - $this->startTime;
        
        // Create a "moving" animation for indeterminate progress
        $position = (int)($elapsed * 5) % ($this->width * 2);
        if ($position >= $this->width) {
            $position = $this->width * 2 - $position;
        }

		$spaces_before = min(max(0, $position), $this->width - 3);
		$spaces_after = max(0, $this->width - $position - 3);
        
        $bar = str_repeat(' ', $spaces_before) . '<=>' . str_repeat(' ', $spaces_after);
        $status = sprintf(
            "[%s] %d items - %s",
            $bar,
            $this->current,
            $this->message
        );
        
        $this->writer->replaceLine($status);
    }

    private function updateDeterminate(): void {
        $percentage = $this->current / $this->total;
        $filled = (int)round($this->width * $percentage);
        $empty = $this->width - $filled;
        
        $bar = str_repeat('=', $filled);
        if ($empty > 0) {
            $bar .= '>';
            $bar .= str_repeat(' ', $empty - 1);
        }

		$status = sprintf(
			"[%s] %d/%d - %s",
			$bar,
			$this->current,
			$this->total,
			$this->message
		);

        $this->writer->replaceLine($status);
    }
}

function bail_out( $message ) {
	throw new InvalidArgumentException( $message );
}

function run_content_import( $options ) {
	$console_writer = new PhpConsoleWriter();

	define( 'NEW_SITE_CONTENT_ROOT', get_site_url() );
	$console_writer->write( 'Target site URL: ' . NEW_SITE_CONTENT_ROOT . "\n" );

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

	if(!isset($options['execution_context_root'])) {
		bail_out( 'The "execution_context_root" option is required.' );
	}

	$httpClient = new Client();
	$content_source = DataReference::create($options['source'], [
		ExecutionContextPath::class,
	]);
	$execution_context = LocalFilesystem::create($options['execution_context_root']);
	$resolver = new DataReferenceResolver($httpClient);
	$resolver->setExecutionContext($execution_context);
	$resolved_source = $resolver->resolve_uncached($content_source);

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
			return WXREntityReader::create(
				$resolved_source->getStream(),
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


	$source = $options['source'];
	$console_writer->write( "Importing static files from $source\n" );

	try {
		// Parse URL mapping options
		$additional_url_mappings = array();
		foreach ( $options['additional_site_urls'] ?? [] as $url ) {
			$additional_url_mappings[] = array(
				'from' => $url,
				'to' => NEW_SITE_CONTENT_ROOT,
			);
		}

		$console_writer->write( "Starting the import\n" );
		$importer = StreamImporter::create(
			$entity_reader_factory,
			array(
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

		// @TODO: Prettier progress reporting
		$ignored_message_printed = false;
		do {
			$result = data_liberation_import_step_customized( $import_session, $importer, $console_writer );
			if ( $importer->get_stage() === StreamImporter::STAGE_FINISHED ) {
				$console_writer->write( "\n" );
				$console_writer->write( "\033[1;32mImport finished!\033[0m See your imported content at: \n" );

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
				$console_writer->write( "\033[1;36m" . $url . "\033[0m\n" );
				break;
			} elseif ( false === $result ) {
				if ( $importer->get_stage() === StreamImporter::STAGE_FRONTLOAD_ASSETS ) {
					if ( ! $ignored_message_printed ) {
						$console_writer->write( "\nSome assets could not be downloaded – they will be ignored so we can continue with the import.\n" );
						$ignored_message_printed = true;
					}
					// $import_session->mark_frontloading_errors_as_ignored();
				} else {
					$console_writer->write( "Import failed, aborting\n" );
					break;
				}
			} else {
				// Twiddle our thumbs, importing in progress...
			}
		} while ( true );
	} finally {
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
function data_liberation_import_step_customized( ImportSession $session, StreamImporter $importer, ConsoleWriter $console_writer ) {
	$soft_time_limit_seconds = 15;
	$hard_time_limit_seconds = 25;
	$start_time              = microtime( true );
	$fetched_files           = 0;
	$progress_bar            = null;

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
			if ( $should_advance_to_next_stage ) {
				if ( StreamImporter::STAGE_FRONTLOAD_ASSETS === $importer->get_stage() ) {
					$resolved_all_failures = $session->count_unfinished_frontloading_stubs() === 0;
					if ( ! $resolved_all_failures ) {
						// Uncomment once this script's intent becomes exiting on unresolved frontloading failures.
						// if($progress_bar) {
						// $progress_bar->finish();
						// }
						// return false;
					}
				}
			}
			if ( ! $importer->advance_to_next_stage() ) {
				if ( $progress_bar ) {
					$progress_bar->finish();
				}
				return false;
			}
			$session->set_stage( $importer->get_stage() );
			$session->set_reentrancy_cursor( $importer->get_reentrancy_cursor() );
			$console_writer->clearLine();
			$progress_bar = null;

			continue;
		}

		switch ( $importer->get_stage() ) {
			case StreamImporter::STAGE_INDEX_ENTITIES:
				$entities_counts = $importer->get_indexed_entities_counts();
				$session->create_frontloading_stubs( $importer->get_indexed_assets_urls() );
				$session->bump_total_number_of_entities( $entities_counts );
				if ( ! $progress_bar ) {
					$progress_bar = new ProgressBar( $console_writer, null );
					$progress_bar->setMessage( 'Indexing entities' );
					$progress_bar->start();
				}
				$progress_bar->setCurrent( array_sum( $session->get_total_number_of_entities() ) );
				break;

			case StreamImporter::STAGE_FRONTLOAD_ASSETS:
				$progress = $importer->get_frontloading_progress();
				$session->bump_frontloading_progress(
					$progress,
					$importer->get_frontloading_events()
				);

				if ( ! $progress_bar ) {
					$progress_bar = new ProgressBar( $console_writer, null );
					$progress_bar->setMessage( 'Fetching media files' );
					$progress_bar->start();
				}
				$progress_bar->setCurrent( $session->count_unfinished_frontloading_stubs() );
				break;

			case StreamImporter::STAGE_IMPORT_ENTITIES:
				$imported_counts = $importer->get_imported_entities_counts();

				$session->bump_imported_entities_counts( $imported_counts );

				if ( ! $progress_bar ) {
					$progress_bar = new ProgressBar( $console_writer, $session->count_remaining_entities() );
					$progress_bar->setMessage( 'Importing entities' );
					$progress_bar->start();
				}
				$progress_bar->setCurrent( $session->count_all_imported_entities() );
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