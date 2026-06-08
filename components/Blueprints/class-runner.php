<?php

namespace WordPress\Blueprints;

use InvalidArgumentException;
use PDO;
use PDOException;
use WordPress\Blueprints\DataReference\AbsoluteLocalPath;
use WordPress\Blueprints\DataReference\DataReference;
use WordPress\Blueprints\DataReference\DataReferenceResolver;
use WordPress\Blueprints\DataReference\Directory;
use WordPress\Blueprints\DataReference\ExecutionContextPath;
use WordPress\Blueprints\DataReference\File;
use WordPress\Blueprints\DataReference\InlineFile;
use WordPress\Blueprints\DataReference\URLReference;
use WordPress\Blueprints\DataReference\WordPressOrgPlugin;
use WordPress\Blueprints\DataReference\WordPressOrgTheme;
use WordPress\Blueprints\Exception\BlueprintExecutionException;
use WordPress\Blueprints\Exception\PermissionsException;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\SiteResolver\ExistingSiteResolver;
use WordPress\Blueprints\SiteResolver\NewSiteResolver;
use WordPress\Blueprints\Steps\ActivatePluginStep;
use WordPress\Blueprints\Steps\ActivateThemeStep;
use WordPress\Blueprints\Steps\CpStep;
use WordPress\Blueprints\Steps\DefineConstantsStep;
use WordPress\Blueprints\Steps\EnableMultisiteStep;
use WordPress\Blueprints\Steps\Exception;
use WordPress\Blueprints\Steps\ImportContentStep;
use WordPress\Blueprints\Steps\ImportMediaStep;
use WordPress\Blueprints\Steps\ImportThemeStarterContentStep;
use WordPress\Blueprints\Steps\InstallPluginStep;
use WordPress\Blueprints\Steps\InstallThemeStep;
use WordPress\Blueprints\Steps\MkdirStep;
use WordPress\Blueprints\Steps\MvStep;
use WordPress\Blueprints\Steps\RmDirStep;
use WordPress\Blueprints\Steps\RmStep;
use WordPress\Blueprints\Steps\RunPHPStep;
use WordPress\Blueprints\Steps\RunSqlStep;
use WordPress\Blueprints\Steps\SetSiteLanguageStep;
use WordPress\Blueprints\Steps\SetSiteOptionsStep;
use WordPress\Blueprints\Steps\UnzipStep;
use WordPress\Blueprints\Steps\WPCLIStep;
use WordPress\Blueprints\Steps\WriteFilesStep;
use WordPress\Blueprints\Validator\HumanFriendlySchemaValidator;
use WordPress\Blueprints\Versions\Version1\V1ToV2Transpiler;
use WordPress\Blueprints\VersionStrings\PHPVersion;
use WordPress\Blueprints\VersionStrings\VersionConstraint;
use WordPress\Blueprints\VersionStrings\WordPressVersion;
use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\FilesystemException;
use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Filesystem\LocalFilesystem;
use WordPress\HttpClient\ByteStream\RequestReadStream;
use WordPress\HttpClient\Client;
use WordPress\Zip\ZipFilesystem;

use function WordPress\Encoding\wp_is_valid_utf8;
use function WordPress\Filesystem\wp_unix_sys_get_temp_dir;
use function WordPress\Zip\is_zip_file_stream;

class Runner {
	const EXECUTION_MODE_CREATE_NEW_SITE        = 'create-new-site';
	const EXECUTION_MODE_APPLY_TO_EXISTING_SITE = 'apply-to-existing-site';
	const EXECUTION_MODES                       = array(
		self::EXECUTION_MODE_CREATE_NEW_SITE,
		self::EXECUTION_MODE_APPLY_TO_EXISTING_SITE,
	);

	/**
	 * @var RunnerConfiguration
	 */
	private $configuration;
	// TODO: Rename httpClient.
	/**
	 * @var Client
	 */
	private $client;
	/**
	 * @var DataReferenceResolver
	 */
	private $assets;
	/**
	 * @var LocalFilesystem
	 */
	private $blueprint_execution_context;
	/**
	 * @var mixed[]
	 */
	private $blueprint_array;
	/**
	 * @var mixed[]
	 */
	private $data_references_to_auto_resolve = array();
	/**
	 * @var VersionConstraint|null
	 */
	private $php_version_constraint;
	/**
	 * @var VersionConstraint|null
	 */
	private $wp_version_constraint;
	/**
	 * @var string
	 */
	private $recommended_wp_version = 'latest';
	/**
	 * @var Tracker
	 */
	private $main_tracker;
	/**
	 * @var ProgressObserver
	 */
	private $progress_observer;
	/**
	 * @var Runtime|null
	 */
	public $runtime;

	public function __construct( RunnerConfiguration $configuration ) {
		$this->configuration = $configuration;
		$this->validate_configuration( $configuration );

		$this->client       = apply_filters( 'blueprint.http_client', new Client() );
		$this->main_tracker = new Tracker();

		// Set up progress logging.
		$this->progress_observer = $configuration->get_progress_observer() ?? new ProgressObserver();
		$this->progress_observer->attach_to( $this->main_tracker );
	}

	public function get_execution_context(): Filesystem {
		return $this->blueprint_execution_context;
	}

	private function validate_configuration( RunnerConfiguration $config ): void {
		// Validate blueprint reference.
		$blueprint = $config->get_blueprint();
		if ( empty( $blueprint ) ) {
			throw new BlueprintExecutionException( 'A Blueprint reference is required.' );
		}

		// Validate execution mode.
		$mode = $config->get_execution_mode();
		if ( ! in_array( $mode, self::EXECUTION_MODES, true ) ) {
			throw new BlueprintExecutionException( 'Execution mode must be one of: ' . implode( ', ', self::EXECUTION_MODES ) );
		}

		// Validate site URL.
		// Note: $options is not defined in this context, so we skip this block.
		// If you want to validate the site URL, you should use $config->get_target_site_url().
		$site_url = $config->get_target_site_url();
		if ( self::EXECUTION_MODE_CREATE_NEW_SITE === $mode ) {
			if ( empty( $site_url ) ) {
				throw new BlueprintExecutionException( sprintf( "Site URL is required when the execution mode is '%s'.", self::EXECUTION_MODE_CREATE_NEW_SITE ) );
			}
		}
		if ( ! empty( $site_url ) && ! filter_var( $site_url, FILTER_VALIDATE_URL ) ) {
			throw new BlueprintExecutionException( 'Site URL is not a valid URL.' );
		}

		// Validate database engine.
		$db_engine = $config->get_database_engine();
		if ( ! in_array( $db_engine, array( 'mysql', 'sqlite' ), true ) ) {
			throw new BlueprintExecutionException( "Database engine must be either 'mysql' or 'sqlite'." );
		}

		// Validate database credentials.
		$db_creds = $config->get_database_credentials();
		if ( 'mysql' === $db_engine ) {
			if ( empty( $db_creds['username'] ) || empty( $db_creds['databaseName'] ) ) {
				throw new BlueprintExecutionException( "MySQL credentials are required when database engine is 'mysql'." );
			}
			// Check if you can connect to the database.
			$host     = $db_creds['host'] ?? '127.0.0.1';
			$port     = $db_creds['port'] ?? 3306;
			$username = $db_creds['username'] ?? '';
			$password = $db_creds['password'] ?? '';
			$database = $db_creds['databaseName'] ?? '';
			$dsn      = "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4";
			try {
				new PDO(
					$dsn,
					$username,
					$password,
					array(
						PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
						PDO::ATTR_TIMEOUT => 3,
					)
				);
			} catch ( PDOException $e ) {
				throw new BlueprintExecutionException(
					sprintf(
						'MySQL was selected as the database engine, but the provided credentials are invalid. DSN string: %s',
						$dsn
					),
					0,
					$e
				);
			}
		} elseif ( 'sqlite' === $db_engine ) {
			if ( empty( $db_creds['path'] ) ) {
				$db_creds['path'] = 'wp-content/.ht.sqlite';
			}
		}
	}

	public function run(): void {
		$temp_root = wp_unix_sys_get_temp_dir() . '/wp-blueprints-runtime-' . uniqid();

		// TODO: Are there cases where we should not have these permissions?
		mkdir( $temp_root, 0777, true );

		try {
			$progress = $this->main_tracker;
			// Create all top-level progress stages upfront so the tracker knows what %
			// of the total work is being done with every progress update.
			$progress->split(
				array(
					'blueprint'        => 5,
					'targetResolution' => 20,
					// @TODO: Put this inside dataResolutionStage.
					'wpCli'            => 1,
					'data'             => 24,
					'execution'        => 50,
				)
			);

			// TODO: What's the client?
			$this->assets = new DataReferenceResolver( $this->client );

			$progress['blueprint']->setCaption( 'Loading Blueprint data' );
			$this->load_blueprint();
			$this->validate_blueprint();
			$this->assets->set_execution_context( $this->blueprint_execution_context );
			// Create the execution plan early on to surface any errors before
			// making the user wait for any downloads or site resolution.
			$plan = $this->create_execution_plan();
			$progress['blueprint']->finish();

			$progress['targetResolution']->setCaption( 'Resolving target site' );
			$target_site_fs   = LocalFilesystem::create( $this->configuration->get_target_site_root() );
			$wp_cli_reference = $this->configuration->get_wp_cli_reference();

			$execution_context = $this->blueprint_execution_context->get_meta();
			if (
				isset( $execution_context['root'] ) &&
				( ! is_string( $execution_context['root'] ) || 0 === strlen( $execution_context['root'] ) )
			) {
				throw new BlueprintExecutionException( 'Execution context was a local directory, but the Runner could not determine the root directory. This should never happen. Please report this as a bug.' );
			}

			$this->runtime = new Runtime(
				$target_site_fs,
				$this->configuration,
				$this->assets,
				$this->client,
				$this->blueprint_array,
				$temp_root,
				$wp_cli_reference,
				isset( $execution_context['root'] ) ? $execution_context['root'] : null
			);
			$this->progress_observer->set_runtime( $this->runtime );
			$progress['wpCli']->setCaption( 'Downloading WP-CLI' );
			$this->assets->start_eager_resolution(
				array(
					'wp-cli' => $wp_cli_reference,
				),
				$progress['wpCli']
			);

			$progress['targetResolution']->setCaption( 'Resolving target site' );
			if ( self::EXECUTION_MODE_APPLY_TO_EXISTING_SITE === $this->configuration->get_execution_mode() ) {
				ExistingSiteResolver::resolve( $this->runtime, $progress['targetResolution'], $this->wp_version_constraint );
			} else {
				NewSiteResolver::resolve( $this->runtime, $progress['targetResolution'], $this->wp_version_constraint, $this->recommended_wp_version );
			}
			$progress['targetResolution']->finish();

			do_action( 'blueprint.target_resolved' );

			$progress['data']->setCaption( 'Resolving data references' );
			$this->assets->start_eager_resolution( $this->data_references_to_auto_resolve, $progress['data'] );
			$this->execute_plan( $progress['execution'], $plan, $this->runtime );

			// @TODO: Assert WordPress is still correctly installed.

			$progress->finish();
		} finally {
			// TODO: Optionally preserve workspace in case of error? Support resuming after error?
			try {
				LocalFilesystem::create( $temp_root )->rmdir(
					'/',
					array(
						'recursive' => true,
					)
				);
			} catch ( FilesystemException $exception ) {
				$this->configuration->get_logger()->warning(
					sprintf(
						'Failed to remove temporary Blueprint workspace %s: %s',
						$temp_root,
						$exception->getMessage()
					)
				);
			}
		}
	}

	/*──────────────── Blueprint load / validation / createExecutionPlan ─────────────. */
	private function load_blueprint() {
		$reference = $this->configuration->get_blueprint();

		if ( is_array( $reference ) ) {
			$this->blueprint_array             = $reference;
			$this->blueprint_execution_context = InMemoryFilesystem::create();

			return;
		}

		// AbsoluteLocalPath is a necessary special case to correctly support
		// Windows absolute paths. There's so much more to them than C:\
		//
		// See https://www.fileside.app/blog/2023-03-17_windows-file-paths/.
		if ( $reference instanceof AbsoluteLocalPath ) {
			$resolved                          = new File(
				FileReadStream::from_path( $reference->get_path() ),
				$reference->get_filename()
			);
			$blueprint_string                  = $resolved->getStream()->consume_all();
			$this->blueprint_execution_context = LocalFilesystem::create( dirname( $reference->get_path() ) );
		} else {
			// For the purposes of Blueprint resolution, the execution context is the
			// current working directory. This way, a path such as ./blueprint.json
			// will mean "a blueprint.json file in the current working directory" and not
			// "a ./blueprint.json path without a point of reference".
			$this->assets->set_execution_context( LocalFilesystem::create( getcwd() ) );
			$resolved = $this->assets->resolve( $reference );
			$this->assets->set_execution_context( null );

			if ( $resolved instanceof File ) {
				$stream = $resolved->getStream();

				// @TODO: A general http error checking solution for all resources.
				if ( $stream instanceof RequestReadStream ) {
					$response = $stream->await_response();
					if ( ! $response->ok() ) {
						throw new BlueprintExecutionException(
							sprintf(
								'Failed to load blueprint from %s. Server responded with %d %s.',
								$reference instanceof URLReference ? $reference->get_url() : $reference,
								$response->status_code,
								$response->get_reason_phrase()
							)
						);
					}
				}

				if ( is_zip_file_stream( $stream ) ) {
					$blueprint_string                  = $this->blueprint_execution_context->get_contents( '/blueprint.json' );
					$this->blueprint_execution_context = ZipFilesystem::create( $stream );
				} else {
					// JSON file.
					$blueprint_string = $stream->consume_all();
					if ( $reference instanceof URLReference ) {
						// @TODO: Only display this if the Blueprint references any bundled files. And in that case,
						// make it a fatal error.
						$this->configuration->get_logger()->warning( 'Blueprints loaded from remote URLs have no execution context.' );
						$this->blueprint_execution_context = InMemoryFilesystem::create();
					} elseif ( $reference instanceof ExecutionContextPath ) {
						// It was resolved as an ExecutionContextPath, but it's actually a local
						// filesystem path at this point
						// The execution context is the directory containing the blueprint.json file.
						$this->blueprint_execution_context = LocalFilesystem::create( dirname( $reference->get_path() ) );
					} elseif ( $reference instanceof InlineFile ) {
						$this->blueprint_execution_context = InMemoryFilesystem::create();
					} else {
						throw new BlueprintExecutionException( 'Unsupported blueprint reference type: ' . get_class( $reference ) );
					}
				}
			} elseif ( $resolved instanceof Directory ) {
				$blueprint_string                  = $resolved->filesystem->get_contents( '/blueprint.json' );
				$this->blueprint_execution_context = $resolved->filesystem;
			} else {
				throw new BlueprintExecutionException( 'Invalid blueprint reference type: ' . get_class( $reference ) );
			}
		}

		// Validate the Blueprint string we've just loaded.

		// **UTF-8 Encoding:** Assert the Blueprint input is UTF-8 encoded.
		if ( ! wp_is_valid_utf8( $blueprint_string ) ) {
			throw new BlueprintExecutionException( 'Blueprint must be encoded as UTF-8.' );
		}

		// **JSON Validity:** Assert the input is a valid JSON document.
		$this->blueprint_array = json_decode( $blueprint_string, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new BlueprintExecutionException( 'Blueprint must be a valid JSON document.' );
		}

		if ( ! is_array( $this->blueprint_array ) ) {
			throw new BlueprintExecutionException( 'Blueprint must be an array.' );
		}
	}

	private function validate_blueprint(): void {
		if ( ! isset( $this->blueprint_array['version'] ) ) {
			$error = V1ToV2Transpiler::validate_v1_blueprint( $this->blueprint_array );
			if ( $error ) {
				throw new BlueprintExecutionException( 'Invalid Blueprint v1 provided.', 0, null, $error );
			}
			$this->configuration->get_logger()->debug( 'Blueprint v1 detected. Transpiling to v2...' );

			$transpiler            = new V1ToV2Transpiler( $this->configuration->get_logger() );
			$this->blueprint_array = $transpiler->upgrade( $this->blueprint_array );
		}

		$this->configuration->get_logger()->debug( 'Final resolved Blueprint: ' . json_encode( $this->blueprint_array, JSON_PRETTY_PRINT ) );

		$this->blueprint_array = apply_filters( 'blueprint.resolved', $this->blueprint_array );

		// Assert the Blueprint conforms to the latest JSON schema.
		$v     = new HumanFriendlySchemaValidator(
			json_decode( file_get_contents( __DIR__ . '/Versions/Version2/json-schema/schema-v2.json' ), true )
		);
		$error = $v->validate( $this->blueprint_array );
		if ( $error ) {
			throw new BlueprintExecutionException( 'Blueprint does not conform to the schema.', 0, null, $error );
		}

		// PHP Version Constraint.
		if ( isset( $this->blueprint_array['phpVersion'] ) ) {
			$min = $max = $recommended = null;

			$php_version = $this->blueprint_array['phpVersion'];
			if ( is_string( $php_version ) ) {
				$parsed_version = PHPVersion::fromString( $php_version );
				if ( ! $parsed_version ) {
					throw new BlueprintExecutionException( 'Invalid PHP version string in phpVersion: ' . $php_version );
				}
				$recommended = $parsed_version;
			} else {
				if ( isset( $php_version['min'] ) ) {
					$min = PHPVersion::fromString( $php_version['min'] );
					if ( ! $min ) {
						throw new BlueprintExecutionException( 'Invalid PHP version string in phpVersion.min: ' . $php_version['min'] );
					}
				}
				if ( isset( $php_version['max'] ) ) {
					$max = PHPVersion::fromString( $php_version['max'] );
					if ( ! $max ) {
						throw new BlueprintExecutionException( 'Invalid PHP version string in phpVersion.max: ' . $php_version['max'] );
					}
				}
				if ( isset( $php_version['recommended'] ) ) {
					$recommended = PHPVersion::fromString( $php_version['recommended'] );
					if ( ! $recommended ) {
						throw new BlueprintExecutionException( 'Invalid PHP version string in phpVersion.recommended: ' . $php_version['recommended'] );
					}
				}
			}
			$this->php_version_constraint = new VersionConstraint( $min, $max, $recommended );
			$php_constraint_errors        = $this->php_version_constraint->validate();
			if ( ! empty( $php_constraint_errors ) ) {
				throw new BlueprintExecutionException( 'Invalid PHP version constraint: ' . implode( '; ', $php_constraint_errors ) );
			}

			// Confirm the environment satisfies the PHP version constraint.
			$current_php_version = PHPVersion::fromString( PHP_VERSION );
			if ( ! $this->php_version_constraint->satisfied_by( $current_php_version ) ) {
				throw new BlueprintExecutionException(
					sprintf(
						'PHP version requirement not satisfied. Blueprint requires %s, but current version is %s',
						$this->php_version_constraint->__toString(),
						$current_php_version
					)
				);
			}
		}

		// WordPress Version Constraint.
		if ( isset( $this->blueprint_array['wordpressVersion'] ) ) {
			$wp_version = $this->blueprint_array['wordpressVersion'];
			$min        = $max = $recommended = null;
			if ( is_string( $wp_version ) ) {
				$this->recommended_wp_version = $wp_version;
				$recommended                  = WordPressVersion::fromString( $wp_version );
				if ( false === $recommended ) {
					throw new BlueprintExecutionException( 'Invalid WordPress version string in wordpressVersion: ' . $wp_version );
				}
			} else {
				if ( isset( $wp_version['min'] ) ) {
					if ( 'latest' === $wp_version['min'] ) {
						throw new BlueprintExecutionException(
							'Setting wordpressVersion.min to "latest" is not allowed and probably not what you want. Either set wordPressVersion.recommended to "latest" or set wordPressVersion.min to a specific version string instead.'
						);
					}
					$min = WordPressVersion::fromString( $wp_version['min'] );
					if ( ! $min ) {
						throw new BlueprintExecutionException( 'Invalid WordPress version string in wordpressVersion.min: ' . $wp_version['min'] );
					}
				}
				// Latest version is implicitly the default and it's only for resolving
				// the WordPress version to install. It's not used for version checks on
				// existing sites and VersionConstraint doesn't support it. It doesn't have
				// enough information anyway – the meaning of "latest" changes over time.
				if ( isset( $wp_version['max'] ) && 'latest' !== $wp_version['max'] ) {
					$this->recommended_wp_version = $wp_version['max'];
					$max                          = WordPressVersion::fromString( $wp_version['max'] );
					if ( ! $max ) {
						// @TODO: Reuse this error message.
						// 'Unrecognized WordPress version. Please use "latest", a URL, or a numeric version such as "6.2", "6.0.1", "6.2-beta1", or "6.2-RC1"'.
						throw new BlueprintExecutionException( 'Invalid WordPress version string in wordpressVersion.max: ' . $wp_version['max'] );
					}
				}
				if ( isset( $wp_version['recommended'] ) && 'latest' !== $wp_version['recommended'] ) {
					$this->recommended_wp_version = $wp_version['recommended'];
					$recommended                  = WordPressVersion::fromString( $wp_version['recommended'] );
					if ( false === $recommended ) {
						throw new BlueprintExecutionException( 'Invalid WordPress version string in wordpressVersion.recommended: ' . $wp_version['recommended'] );
					}
				}
			}

			$this->wp_version_constraint = new VersionConstraint( $min, $max, $recommended );
			$wp_constraint_errors        = $this->wp_version_constraint->validate();
			if ( ! empty( $wp_constraint_errors ) ) {
				throw new BlueprintExecutionException( 'Invalid WordPress version constraint: ' . implode( '; ', $wp_constraint_errors ) );
			}
			// Note: In here's we're only checking if the version constraint is defined
			// correctly. The actual version check for WordPress is done in
			// NewSiteResolver and ExistingSiteResolver.
		}

		// Validate the override constraint if it was set.
		if ( $this->wp_version_constraint ) {
			$wp_constraint_errors = $this->wp_version_constraint->validate();
			if ( ! empty( $wp_constraint_errors ) ) {
				throw new BlueprintExecutionException( 'Invalid WordPress version constraint from CLI override: ' . implode( '; ', $wp_constraint_errors ) );
			}
		}
	}

	private function create_execution_plan(): array {
		$validated_array = $this->blueprint_array;
		// --- Process Declarative Properties into Steps (in order) ---.

		$plan = array();
		// 1. constants.
		if ( ! empty( $validated_array['constants'] ) && is_array( $validated_array['constants'] ) ) {
			$plan[] = $this->create_step_object( 'defineConstants', array( 'constants' => $validated_array['constants'] ) );
		}

		// 2. siteOptions.
		if ( ! empty( $validated_array['siteOptions'] ) && is_array( $validated_array['siteOptions'] ) ) {
			// Ensure siteUrl is not included as per schema Omit<>.
			unset( $validated_array['siteOptions']['siteUrl'] );
			if ( ! empty( $validated_array['siteOptions'] ) ) {
				$plan[] = $this->create_step_object( 'setSiteOptions', array( 'options' => $validated_array['siteOptions'] ) );
			}
		}

		// 3. muPlugins - Install via writeFiles step.
		if ( ! empty( $validated_array['muPlugins'] ) && is_array( $validated_array['muPlugins'] ) ) {
			$files = array();
			foreach ( $validated_array['muPlugins'] as $plugin_path => $plugin_content ) {
				if ( is_string( $plugin_path ) && is_string( $plugin_content ) ) {
					$files[ '/wp-content/mu-plugins/' . $plugin_path ] = $plugin_content;
				} elseif ( is_string( $plugin_content ) ) {
					// Handle numeric keys.
					$files[ '/wp-content/mu-plugins/' . basename( $plugin_content ) ] = $plugin_content;
				}
			}
			if ( ! empty( $files ) ) {
				$plan[] = $this->create_step_object( 'writeFiles', array( 'files' => $files ) );
			}
		}

		// 4. themes (install non-active).
		if ( ! empty( $validated_array['themes'] ) && is_array( $validated_array['themes'] ) ) {
			foreach ( $validated_array['themes'] as $theme_ref ) {
				if ( is_string( $theme_ref ) ) {
					$plan[] = $this->create_step_object(
						'installTheme',
						array(
							'source'               => $theme_ref,
							'active'               => false,
							'importStarterContent' => false,
						)
					);
				} elseif ( is_array( $theme_ref ) && isset( $theme_ref['source'] ) && is_string( $theme_ref['source'] ) ) {
					// Pass through the raw definition for extensibility.
					$plan[] = $this->create_step_object(
						'installTheme',
						array(
							'source'               => $theme_ref['source'],
							'active'               => $theme_ref['active'] ?? false,
							'importStarterContent' => $theme_ref['importStarterContent'] ?? false,
							'targetDirectoryName'  => $theme_ref['targetDirectoryName'] ?? null,
						)
					);
				} else {
					throw new InvalidArgumentException( 'Invalid theme reference format in "themes" array.' );
				}
			}
		}

		// 5. activeTheme (install and activate).
		if ( isset( $validated_array['activeTheme'] ) ) {
			$theme_ref = $validated_array['activeTheme'];
			if ( is_string( $theme_ref ) ) {
				$plan[] = $this->create_step_object(
					'installTheme',
					array(
						'source'               => $theme_ref,
						'active'               => true,
						'importStarterContent' => false,
					)
				);
			} elseif ( is_array( $theme_ref ) && isset( $theme_ref['source'] ) && is_string( $theme_ref['source'] ) ) {
				$plan[] = $this->create_step_object(
					'installTheme',
					array(
						'source'               => $theme_ref['source'],
						'active'               => true,
						'importStarterContent' => $theme_ref['importStarterContent'] ?? false,
						'targetDirectoryName'  => $theme_ref['targetDirectoryName'] ?? null,
					)
				);
			} else {
				throw new InvalidArgumentException( 'Invalid theme reference format for "activeTheme".' );
			}
		}

		// 6. plugins.
		if ( ! empty( $validated_array['plugins'] ) && is_array( $validated_array['plugins'] ) ) {
			foreach ( $validated_array['plugins'] as $plugin_def ) {
				if ( is_string( $plugin_def ) ) {
					$plugin_def = array(
						'source' => $plugin_def,
					);
				}
				$plan[] = $this->create_step_object( 'installPlugin', $plugin_def );
			}
		}

		// 7. fonts – not directly supported; use RunPHP placeholders.
		if ( ! empty( $validated_array['fonts'] ) && is_array( $validated_array['fonts'] ) ) {
			throw new InvalidArgumentException( 'Your Blueprint contains a "fonts" property that is not supported yet.' );
		}

		// 8. media – Import media files.
		if ( ! empty( $validated_array['media'] ) && is_array( $validated_array['media'] ) ) {
			$plan[] = $this->create_step_object( 'importMedia', array( 'media' => $validated_array['media'] ) );
		}

		// 9. siteLanguage.
		if ( ! empty( $validated_array['siteLanguage'] ) && is_string( $validated_array['siteLanguage'] ) ) {
			$plan[] = $this->create_step_object( 'setSiteLanguage', array( 'language' => $validated_array['siteLanguage'] ) );
		}

		// 10. roles - create custom roles using WordPress role management.
		if ( ! empty( $validated_array['roles'] ) && is_array( $validated_array['roles'] ) ) {
			$plan[] = $this->create_step_object( 'createRoles', array( 'roles' => $validated_array['roles'] ) );
		}

		// 11. users - create users using WordPress user management.
		if ( ! empty( $validated_array['users'] ) && is_array( $validated_array['users'] ) ) {
			$plan[] = $this->create_step_object( 'createUsers', array( 'users' => $validated_array['users'] ) );
		}

		// 12. postTypes – generate one MU-plugin per post type, skipping those already registered.
		if ( ! empty( $validated_array['postTypes'] ) && is_array( $validated_array['postTypes'] ) ) {
			$plan[] = $this->create_step_object( 'createPostTypes', array( 'postTypes' => $validated_array['postTypes'] ) );
		}

		// 13. content imports.
		if ( ! empty( $validated_array['content'] ) && is_array( $validated_array['content'] ) ) {
			// @TODO: Consider splitting this into multiple importContent steps, one per piece of content.
			$plan[] = $this->create_step_object( 'importContent', array( 'content' => $validated_array['content'] ) );
		}

		// 14. additionalStepsAfterExecution.
		if ( ! empty( $validated_array['additionalStepsAfterExecution'] ) && is_array( $validated_array['additionalStepsAfterExecution'] ) ) {
			foreach ( $validated_array['additionalStepsAfterExecution'] as $step_data ) {
				$plan[] = $this->create_step_object( $step_data['step'], $step_data );
			}
		}

		foreach ( $plan as $step ) {
			// @TODO: Make sure this doesn't get included twice in the execution plan,
			// e.g. if the Blueprint specified this step manually.
			if ( $step instanceof ImportContentStep ) {
				// if($this->configuration->is_running_as_phar()) {
				// throw new InvalidArgumentException( '@TODO: Importing content is not supported when running as phar.' );
				// } else {.
					$libraries_phar_path = __DIR__ . '/../../dist/php-toolkit.phar';
				if ( ! file_exists( $libraries_phar_path ) ) {
					throw new InvalidArgumentException(
						'In development, you must run `bash bin/build-libraries-phar.sh` to bundle importer libraries before importing content via a Blueprint. ' .
						'It generates a `dist/php-toolkit.phar` file bundling all the libraries required for importing content.'
					);
				}
					$this->configuration->get_logger()->info( 'Loading importer libraries from ' . $libraries_phar_path );
					$source = $this->create_data_reference(
						new InlineFile(
							array(
								'filename' => 'php-toolkit.phar',
								'content' => file_get_contents( $libraries_phar_path ),
							)
						)
					);
				// }.
				array_unshift(
					$plan,
					$this->create_step_object(
						'writeFiles',
						array(
							'files' => array(
								'php-toolkit.phar' => $source,
							),
						)
					)
				);
				break;
			}
		}

		return $plan;
	}

	/**
	 * Helper method to create a specific step object from its type and data.
	 *
	 * @param  string $step_type  The 'step' identifier (e.g., 'installPlugin').
	 * @param  array  $data  The properties for the step.
	 *
	 * @return mixed A Step object instance.
	 * @throws InvalidArgumentException If the step type is unknown or data is invalid.
	 */
	private function create_step_object( string $step_type, array $data ) {
		switch ( $step_type ) {
			case 'activatePlugin':
				return new ActivatePluginStep( $data['pluginPath'] );
			case 'activateTheme':
				return new ActivateThemeStep( $data['themeDirectoryName'] );
			case 'cp':
				return new CpStep( $data['fromPath'], $data['toPath'] );
			case 'defineConstants':
				return new DefineConstantsStep( $data['constants'] );
			case 'enableMultisite':
				return new EnableMultisiteStep();
			case 'importContent':
				/**
				 * Flatten the content declaration from
				 *
				 *     "content": [
				 *         {
				 *             "type": "posts",
				 *             "source": [ "post1.html", "post2.html" ]
				 *         }
				 *     ]
				 *
				 * into
				 *
				 *     "content": [
				 *         {
				 *             "type": "posts",
				 *             "source": "post1.html"
				 *         },
				 *         {
				 *             "type": "posts",
				 *             "source": "post2.html"
				 *         }
				 *     ]
				 */
				$content = array();
				foreach ( $data['content'] as $content_definition ) {
					$source         = $content_definition['source'];
					$source_is_list = is_array( $source ) && array_keys( $source ) === range( 0, count( $source ) - 1 );
					if ( ! $source_is_list ) {
						$source = array( $source );
					}
					foreach ( $source as $source_item ) {
						$data_reference = $this->create_data_reference( $source_item, array( ExecutionContextPath::class ), array( 'auto_resolve' => false ) );
						$content[]      = array_merge(
							$content_definition,
							array( 'source' => $data_reference )
						);
					}
				}

				return new ImportContentStep( $content );
			case 'importThemeStarterContent':
				return new ImportThemeStarterContentStep( $data['themeSlug'] ?? null );
			case 'installPlugin':
				$source   = $this->create_data_reference(
					$data['source'],
					array(
						ExecutionContextPath::class,
						WordPressOrgPlugin::class,
					)
				);
				$active   = $data['active'] ?? true;
				$options  = $data['activationOptions'] ?? null;
				$on_error = isset( $plugin_def['onError'] ) ? $plugin_def['onError'] : 'throw';

				return new InstallPluginStep( $source, $active, $options, $on_error );
			case 'installTheme':
				$source = $this->create_data_reference(
					$data['source'],
					array(
						ExecutionContextPath::class,
						WordPressOrgTheme::class,
					)
				);

				return new InstallThemeStep(
					$source,
					$data['active'] ?? false,
					$data['importStarterContent'] ?? false,
					$data['targetDirectoryName'] ?? null
				);
			case 'mkdir':
				return new MkdirStep( $data['path'] );
			case 'mv':
				return new MvStep( $data['fromPath'], $data['toPath'] );
			case 'rm':
				return new RmStep( $data['path'] );
			case 'rmdir':
				return new RmDirStep( $data['path'] );
			case 'runPHP':
				return new RunPHPStep(
					$this->create_data_reference( $data['code'], array( ExecutionContextPath::class ) ),
					$data['env'] ?? array()
				);
			case 'runSQL':
				$source = $this->create_data_reference( $data['source'], array( ExecutionContextPath::class ) );
				return new RunSqlStep( $source );
			case 'setSiteLanguage':
				return new SetSiteLanguageStep( $data['language'] );
			case 'setSiteOptions':
				return new SetSiteOptionsStep( $data['options'] );

			case 'createRoles':
				if ( empty( $data['roles'] ) || ! is_array( $data['roles'] ) ) {
					throw new InvalidArgumentException( 'Invalid roles data: must be a non-empty array.' );
				}

				$code = '<?php
				require_once(getenv("WP_CORE_DIR") . "/wp-load.php");
				$roles = getenv("ROLES");
                foreach ($roles as $role) {
                    if (empty($role["name"]) || !is_string($role["name"])) {
                        continue;
                    }

                    $role_name = $role["name"];
                    $display_name = $role["display_name"] ?? ucfirst($role_name);
                    $capabilities = $role["capabilities"] ?? array();

                    // Check if role already exists
                    if (!get_role($role_name)) {
                        // Create the role with basic read capability
                        add_role($role_name, $display_name, array("read" => true));
                    }

                    // Get the role object
                    $role_object = get_role($role_name);

                    // Add capabilities
                    if (!empty($capabilities) && is_array($capabilities)) {
                        foreach ($capabilities as $capability => $grant) {
                            $has_cap = filter_var($grant, FILTER_VALIDATE_BOOLEAN);
                            if ($has_cap) {
                                $role_object->add_cap($capability);
                            } else {
                                $role_object->remove_cap($capability);
                            }
                        }
                    }
                }
            ';

				return new RunPHPStep(
					$this->create_data_reference(
						array(
							'filename' => 'create-roles.php',
							'content'  => $code,
						)
					),
					array( 'ROLES' => $data['roles'] )
				);

			case 'createUsers':
				if ( empty( $data['users'] ) || ! is_array( $data['users'] ) ) {
					throw new InvalidArgumentException( 'Invalid users data: must be a non-empty array.' );
				}

				$code = '<?php
                require_once(getenv("WP_CORE_DIR") . "/wp-load.php");
                $users = getenv("USERS");
                foreach ($users as $user) {
                    if (empty($user["username"]) || !is_string($user["username"])) {
                        continue;
                    }

                    $username = $user["username"];
                    $email = $user["email"] ?? $username . "@example.com";
                    $password = $user["password"] ?? wp_generate_password(12, true, true);
                    $role = $user["role"] ?? "subscriber";

                    // Check if user already exists
                    $existing_user = get_user_by("login", $username);
                    if ($existing_user) {
                        continue; // Skip if user already exists
                    }

                    // Create the user
                    $user_id = wp_create_user($username, $password, $email);

                    if (!is_wp_error($user_id)) {
                        // Set role
                        $user_object = new WP_User($user_id);
                        $user_object->set_role($role);

                        // Set user meta if provided
                        if (!empty($user["meta"]) && is_array($user["meta"])) {
                            foreach ($user["meta"] as $meta_key => $meta_value) {
                                update_user_meta($user_id, $meta_key, $meta_value);
                            }
                        }
                    }
                }';

				return new RunPHPStep(
					$this->create_data_reference(
						array(
							'filename' => 'create-users.php',
							'content'  => $code,
						)
					),
					array( 'USERS' => $data['users'] )
				);

			case 'createPostTypes':
				if ( empty( $data['postTypes'] ) || ! is_array( $data['postTypes'] ) ) {
					throw new InvalidArgumentException( 'Invalid postTypes data: must be a non-empty array.' );
				}

				// @TODO: Do we need a separate step here? To make sure we're not overwriting existing post types?
				// Or would WriteFilesStep be enough, perhaps with a "no override" flag?
				// @TODO: Install SCF and use it to register post types.

				$files = array();
				foreach ( $data['postTypes'] as $slug => $args ) {
					if ( ! is_string( $slug ) || '' === $slug ) {
						continue;
					}

					// Ensure $args is an array.
					if ( ! is_array( $args ) ) {
						$args = array();
					}

					// Build a safe file name for the MU-plugin.
					$file_slug   = preg_replace( '/[^a-z0-9\-]+/i', '-', strtolower( $slug ) );
					$plugin_path = "wp-content/mu-plugins/blueprint-post-type-{$file_slug}.php";

					// Human-friendly default label.
					$default_label = addslashes( ucwords( str_replace( array( '-', '_' ), ' ', $slug ) ) );
					if ( ! isset( $args['label'] ) ) {
						$args['label'] = $default_label;
					}

					// Compose the plugin source.
					$plugin_code = sprintf(
						<<<'PHP'
<?php
/**
* Blueprint-generated Custom Post Type: %1$s
* This file is auto-generated – do not edit directly.
*/

add_action(
'init',
static function () {
register_post_type(%1$s, %2$s);
},
0
);
PHP
						,
						var_export( $slug, true ),
						var_export( $args, true )
					);

					$files[ $plugin_path ] = $this->create_data_reference(
						array(
							'filename' => $plugin_path,
							'content'  => $plugin_code,
						)
					);
				}

				if ( empty( $files ) ) {
					throw new InvalidArgumentException( 'No valid post types to register.' );
				}

				return new WriteFilesStep( $files );

			case 'unzip':
				$zip_file = $this->create_data_reference( $data['zipFile'], array( ExecutionContextPath::class ) );

				return new UnzipStep( $zip_file, $data['extractToPath'] );
			case 'wp-cli':
				return new WPCLIStep( $data['command'], $data['wpCliPath'] ?? null );
			case 'writeFiles':
				$files = array();
				foreach ( $data['files'] as $path => $content ) {
					$files[ $path ] = $this->create_data_reference( $content, array( ExecutionContextPath::class ) );
				}

				return new WriteFilesStep( $files );
			case 'importMedia':
				$media = array();
				foreach ( $data['media'] as $path => $content ) {
					if ( is_string( $content ) ) {
						$media[ $path ] = MediaFileDefinition::from_array(
							array(
								'source' => $this->create_data_reference( $content, array( ExecutionContextPath::class ) ),
							)
						);
						continue;
					}

					$media[ $path ] = MediaFileDefinition::from_array(
						array(
							'source'      => $this->create_data_reference( $content['source'], array( ExecutionContextPath::class ) ),
							'title'       => $content['title'] ?? null,
							'description' => $content['description'] ?? null,
							'alt'         => $content['alt'] ?? null,
							'caption'     => $content['caption'] ?? null,
						)
					);
				}

				return new ImportMediaStep( $media );
			default:
				throw new InvalidArgumentException( "Unknown step type: {$step_type}" );
		}
	}

	/**
	 * @param  mixed $data
	 */
	private function create_data_reference( $data, array $additional_reference_classes = array(), $options = array() ): DataReference {
		$reference = $data instanceof DataReference ? $data : DataReference::create( $data, $additional_reference_classes );

		/**
		 * A Blueprint sourced from an ExecutionContextPath is always local.
		 * We don't have a separate reference type for a "local path". We just assume that,
		 * at the Blueprint resolution stage, execution context is the entire filesystem. Only
		 * then we narrow it down to the Blueprint parent directory.
		 */
		$execution_context_is_local = $this->configuration->get_blueprint() instanceof ExecutionContextPath;
		if (
			$execution_context_is_local &&
			! $this->configuration->is_allowed_local_filesystem_access() &&
			$reference instanceof ExecutionContextPath
		) {
			throw new PermissionsException(
				RunnerConfiguration::PERMISSION_LOCAL_FILESYSTEM_ACCESS,
				sprintf(
					'The Blueprint references a local file (%s).',
					$data
				),
				'You\'ll need to allow local filesystem access via $configuration->setAllowedLocalFilesystemAccess(true) to run it.'
			);
		}

		if ( $options['auto_resolve'] ?? true ) {
			$this->data_references_to_auto_resolve[ $reference->id ] = $reference;
		}

		return $reference;
	}

	/**
	 * Run the steps in the execution plan with progress tracking
	 *
	 * @param  Tracker $progress  The parent tracker for step execution
	 *
	 * @return array Results from each step execution
	 */
	private function execute_plan( Tracker $progress, array $steps, Runtime $runtime ): array {
		/**
		 * Execute the steps in the execution plan with progress tracking
		 */
		$results    = array();
		$step_count = count( $steps );

		if ( 0 === $step_count ) {
			$progress->finish();

			return $results;
		}

		// Create progress trackers for each step upfront.
		$progress->split( range( 0, $step_count ) );
		for ( $i = 0; $i < $step_count; $i++ ) {
			$step         = $steps[ $i ];
			$step_tracker = $progress[ $i ];

			try {
				$results[ $i ] = $step->run( $runtime, $step_tracker );

				// If step didn't call finish(), do it for them.
				if ( ! $step_tracker->isDone() ) {
					$step_tracker->finish();
				}
			} catch ( \Exception $e ) {
				$results[ $i ] = $e;
				// Determine if we should continue or stop execution.
				$continue_on_error = $this->continue_on_error ?? false;
				if ( ! $continue_on_error ) {
					// @TODO: Correlate this message with the original Blueprint,
					// as in – was the step created because of "installPlugin" or not?
					// Which entry of it? etc.
					throw new BlueprintExecutionException(
						sprintf(
							'Error when executing step  %s (#%d in the execution plan)',
							get_class( $step ),
							$i + 1
						),
						0,
						$e
					);
				}

				$step_tracker->setCaption(
					sprintf(
						'%s (FAILED: %s)',
						$step_tracker->getCaption(),
						$e->getMessage()
					)
				);
				$step_tracker->finish();
			}
		}

		return $results;
	}
}
