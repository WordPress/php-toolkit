<?php

namespace WordPress\Blueprints\DataReference;

use WordPress\Blueprints\Exception\DataResolutionException;
use WordPress\Blueprints\Progress\ProgressTrackedReadStream;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\ByteStream\MemoryPipe;
use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\Layer\ChrootLayer;
use WordPress\Filesystem\LocalFilesystem;
use WordPress\Git\GitFilesystem;
use WordPress\Git\GitRepository;
use WordPress\HttpClient\ByteStream\SeekableRequestReadStream;
use WordPress\HttpClient\Client;

use function WordPress\Filesystem\wp_join_unix_paths;
use function WordPress\Filesystem\wp_unix_sys_get_temp_dir;

class DataReferenceResolver {
	/**
	 * @var Client
	 */
	private $client;
	/**
	 * @var mixed[]
	 */
	private $sub_trackers;
	/**
	 * @var mixed[]
	 */
	private $data_references;
	/**
	 * @var mixed[]
	 */
	private $resolved_data_references;
	/**
	 * @var Tracker
	 */
	private $data_resolution_tracker;
	/**
	 * @var Filesystem|null
	 */
	private $execution_context;
	/**
	 * @var string
	 */
	private $tmp_root;

	public function __construct( Client $client, ?string $tmp_root = null ) {
		$this->client   = $client;
		$this->tmp_root = $tmp_root ? $tmp_root : wp_unix_sys_get_temp_dir();
	}

	public function set_execution_context( ?Filesystem $execution_context ) {
		$this->execution_context = $execution_context;
	}

	public function start_eager_resolution( array $data_references, Tracker $data_resolution_tracker ) {
		$this->data_resolution_tracker = $data_resolution_tracker;
		$this->data_references         = $data_references;
		$nb_data_references            = count( $this->data_references );
		foreach ( $this->data_references as $data_reference ) {
			$this->sub_trackers[ $data_reference->id ] = $this->data_resolution_tracker->stage(
				1 / $nb_data_references,
				'Resolving data reference #' . $data_reference->id . ': ' . $data_reference->get_human_readable_name()
			);
			$this->resolve( $data_reference, $this->sub_trackers[ $data_reference->id ] );
		}
	}

	/** Core service method shared by runner, target resolvers and steps
	 *
	 * @return File|Directory
	 */
	public function resolve( DataReference $reference ) {
		// TODO: Comment this. Make semantics clearer.
		if ( ! isset( $this->resolved_data_references[ $reference->id ] ) ) {
			$this->resolved_data_references[ $reference->id ] = $this->resolve_uncached( $reference );
		}
		return $this->resolved_data_references[ $reference->id ];
	}

	// @TODO: Clean up the semantics of this class. Resolve() and separate resolve_uncached() seem confusing. There's
	// a bunch of implicit behaviors related to caching. Ideally we would either have a self-contained resolution
	// method, or co-locate the resolution logic with the data reference classes and only use this class for
	// caching.
	public function resolve_uncached( DataReference $reference ) {
		$progress_tracker = $this->sub_trackers[ $reference->id ] ?? new Tracker();

		if ( $reference instanceof WordPressOrgPlugin ) {
			$reference = new URLReference( 'https://downloads.wordpress.org/plugin/' . $reference->get_slug() . '.latest-stable.zip' );
		} elseif ( $reference instanceof WordPressOrgTheme ) {
			$reference = new URLReference( 'https://downloads.wordpress.org/theme/' . $reference->get_slug() . '.latest-stable.zip' );
		}

		if ( $reference instanceof URLReference ) {
			$url      = $reference->get_url();
			$filename = basename( parse_url( $url, PHP_URL_PATH ) );

			$tracked_stream = new SeekableRequestReadStream(
				$url,
				array(
					'client'           => $this->client,
					'cache_path'       => wp_join_unix_paths( $this->tmp_root, uniqid( 'blueprints_seekable_cache_' ) ),
					/**
					 * Use a 100MB buffer to support seek()-ing in the streamed ZIP files.
					 * To support ZIPs larger than 100MB, we'll need a custom SeekableRequestReadStream that:
					 *
					 * * Uses range headers when possible.
					 * * Buffers data on disk for seeking(), not in memory.
					 *
					 * @TODO: Support ZIPs >= 100MB.
					 */
					'max_lookbehind_bytes'      => 100 * 1024 * 1024,
					'progress_tracker' => $progress_tracker,
					'eagerly_enqueue'  => true,
				)
			);

			// @TODO: An intermediate File object that waits for response headers when you access a stream and throws if the response is not ok.
			return new RemoteFile(
				$tracked_stream,
				$filename
			);
			// TODO: Consider a clearer name. Some not-so-great ballpark ideas:
			// BlueprintParentPath, BlueprintRootPath, BlueprintContextPath, BlueprintRelativePath.
		} elseif ( $reference instanceof ExecutionContextPath ) {
			$path = $reference->get_path();
			if ( ! $this->execution_context->exists( $path ) ) {
				throw new DataResolutionException( 'Path referenced in the Blueprint was not found in the execution context: ' . $path );
			}
			if ( $this->execution_context->is_file( $path ) ) {
				$stream         = $this->execution_context->open_read_stream( $path );
				$tracked_stream = new ProgressTrackedReadStream( $stream, $progress_tracker );

				return new File( $tracked_stream, basename( $path ) );
			} elseif ( $this->execution_context->is_dir( $path ) ) {
				// @TODO (low priority): Actually track the download progress for directories.
				$progress_tracker->finish();

				return new Directory(
					new ChrootLayer( $this->execution_context, $path ),
					basename( $path )
				);
			} else {
				throw new DataResolutionException( 'Path referenced in the Blueprint is not a file or directory: ' . $path );
			}
			// TODO: Lovely name.
		} elseif ( $reference instanceof InlineFile ) {
			$progress_tracker->finish();

			return new File( new MemoryPipe( $reference->get_content() ), $reference->get_filename() );
			// TODO: What is an InlineDirectory?! Can we actually specify directories with file content inline?
		} elseif ( $reference instanceof InlineDirectory ) {
			$progress_tracker->finish();

			return $reference->as_directory();
		} elseif ( $reference instanceof GitPath ) {
			// @TODO (low priority): Actually track the download progress for git repositories.
			$progress_tracker->finish();

			/**
			 * Create a temporary directory for the git repository.
			 *
			 * Do not clean it up after the pull()! That would remove the
			 * data before we're able to consume it in the Step.
			 *
			 * The Blueprint Runner will clean up all temporary directories at
			 * the end of the execution.
			 */
			$tmp_dir = wp_join_unix_paths( $this->tmp_root, 'git-repo-' . uniqid() );

			$repo = new GitRepository( LocalFilesystem::create( $tmp_dir ) );
			$repo->add_remote( 'origin', $reference->get_git_repository() );
			$client = $repo->get_remote_client( 'origin' );
			$client->pull(
				$reference->get_ref(),
				// Sparse checkout.
				array(
					'path'    => $reference->get_path(),
					'shallow' => true,
				)
			);

			return new Directory(
				new ChrootLayer( GitFilesystem::create( $repo ), $reference->get_path() ),
				basename( $reference->get_path() ) ? basename( $reference->get_path() ) : 'git-repo'
			);
		}

		throw new DataResolutionException( 'Unsupported reference type ' . get_class( $reference ) );
	}
}
