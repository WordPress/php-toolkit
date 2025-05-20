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
	private $subTrackers;
	/**
	 * @var mixed[]
	 */
	private $dataReferences;
	/**
	 * @var mixed[]
	 */
	private $resolvedDataReferences;
	/**
	 * @var Tracker
	 */
	private $dataResolutionTracker;
	/**
	 * @var Filesystem|null
	 */
	private $executionContext;
	/**
	 * @var string
	 */
	private $tmpRoot;

	public function __construct( Client $client, ?string $tmpRoot = null ) {
		$this->client  = $client;
		$this->tmpRoot = $tmpRoot ?: wp_unix_sys_get_temp_dir();
	}

	public function setExecutionContext( Filesystem $executionContext ) {
		$this->executionContext = $executionContext;
	}

	public function startEagerResolution( array $dataReferences, Tracker $dataResolutionTracker ) {
		$this->dataResolutionTracker = $dataResolutionTracker;
		$this->dataReferences        = $dataReferences;
		$nb_data_references          = count( $this->dataReferences );
		foreach ( $this->dataReferences as $dataReference ) {
			$this->subTrackers[ $dataReference->id ] = $this->dataResolutionTracker->stage(
				1 / $nb_data_references,
				'Resolving data reference #' . $dataReference->id . ': ' . $dataReference->get_human_readable_name()
			);
			$this->resolve( $dataReference, $this->subTrackers[ $dataReference->id ] );
		}
	}

	/** Core service method shared by runner, target resolvers and steps
	 * @return File|Directory
	 */
	public function resolve( DataReference $reference ) {
		// TODO: Comment this. Make semantics clearer.
		if ( isset( $this->resolvedDataReferences[ $reference->id ] ) ) {
			return $this->resolvedDataReferences[ $reference->id ];
		}

		$progress_tracker = $this->subTrackers[ $reference->id ] ?? new Tracker();

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
					'cache_path'       => wp_join_unix_paths( $this->tmpRoot, uniqid( 'blueprints_seekable_cache_' ) ),
					/**
					 * Use a 100MB buffer to support seek()-ing in the streamed ZIP files.
					 * To support ZIPs larger than 100MB, we'll need a custom SeekableRequestReadStream that:
					 *
					 * * Uses range headers when possible.
					 * * Buffers data on disk for seeking(), not in memory.
					 *
					 * @TODO: Support ZIPs >= 100MB.
					 */
					'buffer_size'      => 100 * 1024 * 1024,
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
			// BlueprintParentPath, BlueprintRootPath, BlueprintContextPath, BlueprintRelativePath
		} elseif ( $reference instanceof ExecutionContextPath ) {
			$path = $reference->get_path();
			if ( ! $this->executionContext->exists( $path ) ) {
				throw new DataResolutionException( 'Path referenced in the Blueprint was not found in the execution context: ' . $path );
			}
			if ( $this->executionContext->is_file( $path ) ) {
				$stream         = $this->executionContext->open_read_stream( $path );
				$tracked_stream = new ProgressTrackedReadStream( $stream, $progress_tracker );

				return new File( $tracked_stream, basename( $path ) );
			} elseif ( $this->executionContext->is_dir( $path ) ) {
				// @TODO (low priority): Actually track the download progress for directories.
				$progress_tracker->finish();

				return new Directory(
					new ChrootLayer( $this->executionContext, $path ),
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
			$tmp_dir = wp_join_unix_paths( $this->tmpRoot, 'git-repo-' . uniqid() );

			$repo = new GitRepository( LocalFilesystem::create( $tmp_dir ) );
			$repo->add_remote( 'origin', $reference->get_git_repository() );
			$client = $repo->get_remote_client( 'origin' );
			$client->pull(
				$reference->get_ref(),
				// Sparse checkout
				array(
					'path'    => $reference->get_path(),
					'shallow' => true,
				)
			);

			return new Directory(
				new ChrootLayer( GitFilesystem::create( $repo ), $reference->get_path() ),
				basename( $reference->get_path() ) ?: 'git-repo'
			);
		}

		throw new DataResolutionException( 'Unsupported reference type ' . get_class( $reference ) );
	}
}
