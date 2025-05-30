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
use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Filesystem\LocalFilesystem;
use WordPress\HttpClient\ByteStream\RequestReadStream;
use WordPress\HttpClient\Client;
use WordPress\Zip\ZipFilesystem;

use function WordPress\Encoding\utf8_is_valid_byte_stream;
use function WordPress\Filesystem\wp_unix_sys_get_temp_dir;
use function WordPress\Zip\is_zip_file_stream;

class Runner {
	/**
	 * @var RunnerConfiguration
	 */
	private $configuration;
	// TODO: Rename httpClient
	/**
	 * @var Client
	 */
	private $client;
	/**
	 * @var DataReferenceResolver
	 */
	private $assets;
	/**
	 * @var Filesystem
	 */
	private $blueprintExecutionContext;
	/**
	 * @var mixed[]
	 */
	private $blueprintArray;
	/**
	 * @var mixed[]
	 */
	private $dataReferences = [];
	/**
	 * @var VersionConstraint|null
	 */
	private $phpVersionConstraint;
	/**
	 * @var VersionConstraint|null
	 */
	private $wpVersionConstraint;
	/**
	 * @var string
	 */
	private $recommendedWpVersion = 'latest';
	/**
	 * @var Tracker
	 */
	private $mainTracker;
	/**
	 * @var ProgressObserver
	 */
	private $progressObserver;
	/**
	 * @var Runtime|null
	 */
	public $runtime;

	public function __construct( RunnerConfiguration $configuration ) {
		$this->configuration = $configuration;
		$this->validateConfiguration( $configuration );

		$this->client      = new Client();
		$this->mainTracker = new Tracker();

		// Set up progress logging
		$this->progressObserver = $configuration->getProgressObserver() ?? new ProgressObserver();
		$this->progressObserver->attachTo( $this->mainTracker );
	}

	public function getExecutionContext(): Filesystem {
		return $this->blueprintExecutionContext;
	}

	private function validateConfiguration( RunnerConfiguration $config ): void {
		// Validate blueprint reference
		$blueprint = $config->getBlueprint();
		if ( empty( $blueprint ) ) {
			throw new BlueprintExecutionException( "A Blueprint reference is required." );
		}

		// Validate execution mode
		$mode = $config->getExecutionMode();
		if ( ! in_array( $mode, [ 'create-new-site', 'apply-to-existing-site' ], true ) ) {
			throw new BlueprintExecutionException( "Execution mode must be either 'create-new-site' or 'apply-to-existing-site'." );
		}

		// Validate site URL
		// Note: $options is not defined in this context, so we skip this block.
		// If you want to validate the site URL, you should use $config->getTargetSiteUrl().
		$siteUrl = $config->getTargetSiteUrl();
		if ( $mode === 'create-new-site' ) {
			if ( empty( $siteUrl ) ) {
				throw new BlueprintExecutionException( "Site URL is required when the execution mode is 'create-new-site'." );
			}
		}
		if ( ! empty( $siteUrl ) && ! filter_var( $siteUrl, FILTER_VALIDATE_URL ) ) {
			throw new BlueprintExecutionException( "Site URL is not a valid URL." );
		}

		// Validate database engine
		$dbEngine = $config->getDatabaseEngine();
		if ( ! in_array( $dbEngine, [ 'mysql', 'sqlite' ], true ) ) {
			throw new BlueprintExecutionException( "Database engine must be either 'mysql' or 'sqlite'." );
		}

		// Validate database credentials
		$dbCreds = $config->getDatabaseCredentials();
		if ( $dbEngine === 'mysql' ) {
			if ( empty( $dbCreds['username'] ) || empty( $dbCreds['databaseName'] ) ) {
				throw new BlueprintExecutionException( "MySQL credentials are required when database engine is 'mysql'." );
			}
			// Check if you can connect to the database
			$host     = $dbCreds['host'] ?? '127.0.0.1';
			$port     = $dbCreds['port'] ?? 3306;
			$username = $dbCreds['username'] ?? '';
			$password = $dbCreds['password'] ?? '';
			$database = $dbCreds['databaseName'] ?? '';
			$dsn      = "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4";
			try {
				new PDO( $dsn, $username, $password, [
					PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
					PDO::ATTR_TIMEOUT => 3,
				] );
			} catch ( PDOException $e ) {
				throw new BlueprintExecutionException(
					sprintf(
						"MySQL was selected as the database engine, but the provided credentials are invalid. DSN string: %s",
						$dsn
					),
					0,
					$e
				);
			}
		} elseif ( $dbEngine === 'sqlite' ) {
			if ( empty( $dbCreds['path'] ) ) {
				$dbCreds['path'] = 'wp-content/.ht.sqlite';
			}
		}
	}

	public function run(): void {
		$tempRoot = wp_unix_sys_get_temp_dir() . '/wp-blueprints-runtime-' . uniqid();
		// TODO: Are there cases where we should not have these permissions?
		mkdir( $tempRoot, 0777, true );

		try {
			$progress = $this->mainTracker;
			// Create all top-level progress stages upfront so the tracker knows what %
			// of the total work is being done with every progress update.
			$progress->split( [
				'blueprint'        => 5,
				'targetResolution' => 20,
				// @TODO: Put this inside dataResolutionStage
				'wpCli'            => 1,
				'data'             => 24,
				'execution'        => 50,
			] );

			// TODO: What's the client?
			$this->assets = new DataReferenceResolver( $this->client );

			$progress['blueprint']->setCaption( 'Loading Blueprint data' );
			$this->loadBlueprint();
			$this->validateBlueprint();
			$this->assets->setExecutionContext( $this->blueprintExecutionContext );
			// Create the execution plan early on to surface any errors before
			// making the user wait for any downloads or site resolution.
			$plan = $this->createExecutionPlan();
			$progress['blueprint']->finish();

			$progress['targetResolution']->setCaption( 'Resolving target site' );
			$targetSiteFs   = LocalFilesystem::create( $this->configuration->getTargetSiteRoot() );
			$wpCliReference = DataReference::create( 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar' );
			$this->runtime  = new Runtime(
				$targetSiteFs,
				$this->configuration,
				$this->assets,
				$this->client,
				$this->blueprintArray,
				$tempRoot,
				$wpCliReference
			);
			$this->progressObserver->setRuntime( $this->runtime );
			$progress['wpCli']->setCaption( 'Downloading WP-CLI' );
			$this->assets->startEagerResolution( [
				'wp-cli' => $wpCliReference,
			], $progress['wpCli'] );

			$progress['targetResolution']->setCaption( 'Resolving target site' );
			if ( $this->configuration->getExecutionMode() === 'apply-to-existing-site' ) {
				ExistingSiteResolver::resolve( $this->runtime, $progress['targetResolution'], $this->wpVersionConstraint );
			} else {
				NewSiteResolver::resolve( $this->runtime, $progress['targetResolution'], $this->wpVersionConstraint, $this->recommendedWpVersion );
			}
			$progress['targetResolution']->finish();

			$progress['data']->setCaption( 'Resolving data references' );
			$this->assets->startEagerResolution( $this->dataReferences, $progress['data'] );
			$this->executePlan( $progress['execution'], $plan, $this->runtime );
			$progress->finish();
		} finally {
			// TODO: Optionally preserve workspace in case of error? Support resuming after error?
			LocalFilesystem::create( $tempRoot )->rmdir( '/', [
				'recursive' => true,
			] );
		}
	}

	/*──────────────── Blueprint load / validation / createExecutionPlan ─────────────*/
	private function loadBlueprint() {
		$reference = $this->configuration->getBlueprint();

		if ( is_array( $reference ) ) {
			$this->blueprintArray            = $reference;
			$this->blueprintExecutionContext = InMemoryFilesystem::create();

			return;
		}

		// AbsoluteLocalPath is a necessary special case to correctly support
		// Windows absolute paths. There's so much more to them than C:\
		//
		// See https://www.fileside.app/blog/2023-03-17_windows-file-paths/
		if ( $reference instanceof AbsoluteLocalPath ) {
			$resolved = new File(
				FileReadStream::from_path( $reference->get_path() ),
				$reference->get_filename()
			);
			$blueprintString                 = $resolved->getStream()->consume_all();
			$this->blueprintExecutionContext = LocalFilesystem::create( dirname( $reference->get_path() ) );
		} else {
			$resolved = $this->assets->resolve( $reference );
			if ( $resolved instanceof File ) {
				$stream = $resolved->getStream();

				// @TODO: A general http error checking solution for all resources
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
					$blueprintString                 = $this->blueprintExecutionContext->get_contents( '/blueprint.json' );
					$this->blueprintExecutionContext = new ZipFilesystem( $stream );
				} else {
					// JSON file
					$blueprintString = $stream->consume_all();
					if ( $reference instanceof URLReference ) {
						// @TODO: Only display this if the Blueprint references any bundled files. And in that case,
						//        make it a fatal error.
						$this->configuration->getLogger()->warning( 'Blueprints loaded from remote URLs have no execution context.' );
						$this->blueprintExecutionContext = InMemoryFilesystem::create();
					} elseif ( $reference instanceof ExecutionContextPath ) {
						// It was resolved as an ExecutionContextPath, but it's actually a local
						// filesystem path at this point.
						// The execution context is the directory containing the blueprint.json file.
						$this->blueprintExecutionContext = LocalFilesystem::create( dirname( $reference->get_path() ) );
					} elseif ( $reference instanceof InlineFile ) {
						$this->blueprintExecutionContext = InMemoryFilesystem::create();
					} else {
						throw new BlueprintExecutionException( 'Unsupported blueprint reference type: ' . get_class( $reference ) );
					}
				}
			} elseif ( $resolved instanceof Directory ) {
				$blueprintString                 = $resolved->filesystem->get_contents( '/blueprint.json' );
				$this->blueprintExecutionContext = $resolved->filesystem;
			} else {
				throw new BlueprintExecutionException( 'Invalid blueprint reference type: ' . get_class( $reference ) );
			}
		}

		// Validate the Blueprint string we've just loaded.

		// **UTF-8 Encoding:** Assert the Blueprint input is UTF-8 encoded.
		$is_valid_utf8 = false;
		if ( function_exists( 'mb_check_encoding' ) ) {
			$is_valid_utf8 = mb_check_encoding( $blueprintString, 'UTF-8' );
		} else {
			$is_valid_utf8 = utf8_is_valid_byte_stream( $blueprintString );
		}

		if ( ! $is_valid_utf8 ) {
			throw new BlueprintExecutionException( 'Blueprint must be encoded as UTF-8.' );
		}

		// **JSON Validity:** Assert the input is a valid JSON document.
		$this->blueprintArray = json_decode( $blueprintString, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			throw new BlueprintExecutionException( 'Blueprint must be a valid JSON document.' );
		}

		if ( ! is_array( $this->blueprintArray ) ) {
			throw new BlueprintExecutionException( 'Blueprint must be an array.' );
		}
	}

	private function validateBlueprint(): void {
		if ( ! isset( $this->blueprintArray['version'] ) ) {
			$error = V1ToV2Transpiler::validate_v1_blueprint( $this->blueprintArray );
			if ( $error ) {
				throw new BlueprintExecutionException( 'Invalid Blueprint v1 provided.', 0, null, $error );
			}
			$this->configuration->getLogger()->debug( 'Blueprint v1 detected. Transpiling to v2...' );

			$transpiler           = new V1ToV2Transpiler( $this->configuration->getLogger() );
			$this->blueprintArray = $transpiler->upgrade( $this->blueprintArray );
		}

		$this->configuration->getLogger()->debug( 'Final resolved Blueprint: ' . json_encode( $this->blueprintArray, JSON_PRETTY_PRINT ) );

		// Assert the Blueprint conforms to the latest JSON schema.
		$v     = new HumanFriendlySchemaValidator(
			json_decode( file_get_contents( __DIR__ . '/Versions/Version2/json-schema/schema-v2.json' ), true )
		);
		$error = $v->validate( $this->blueprintArray );
		if ( $error ) {
			throw new BlueprintExecutionException( 'Blueprint does not conform to the schema.', 0, null, $error );
		}

		// PHP Version Constraint
		if ( isset( $this->blueprintArray['phpVersion'] ) ) {
			$min = $max = $recommended = null;

			$php_version = $this->blueprintArray['phpVersion'];
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
			$this->phpVersionConstraint = new VersionConstraint( $min, $max, $recommended );
			$phpConstraintErrors        = $this->phpVersionConstraint->validate();
			if ( ! empty( $phpConstraintErrors ) ) {
				throw new BlueprintExecutionException( 'Invalid PHP version constraint: ' . implode( '; ', $phpConstraintErrors ) );
			}

			// Confirm the environment satisfies the PHP version constraint.
			$currentPhpVersion = PHPVersion::fromString( PHP_VERSION );
			if ( ! $this->phpVersionConstraint->satisfiedBy( $currentPhpVersion ) ) {
				throw new BlueprintExecutionException(
					sprintf(
						'PHP version requirement not satisfied. Blueprint requires %s, but current version is %s',
						$this->phpVersionConstraint->__toString(),
						$currentPhpVersion
					)
				);
			}
		}

		// WordPress Version Constraint
		if ( isset( $this->blueprintArray['wordpressVersion'] ) ) {
			$wp_version = $this->blueprintArray['wordpressVersion'];
			$recommended = null;
			if ( is_string( $wp_version ) ) {
				$this->recommendedWpVersion = $wp_version;
				$recommended = WordPressVersion::fromString( $wp_version );
				if ( false === $recommended ) {
					throw new BlueprintExecutionException( 'Invalid WordPress version string in wordpressVersion: ' . $wp_version );
				}
			} else {
				if ( isset( $wp_version['min'] ) ) {
					if ( $wp_version['min'] === 'latest' ) {
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
				if ( isset( $wp_version['max'] ) && $wp_version['max'] !== 'latest' ) {
					$this->recommendedWpVersion = $wp_version['max'];
					$max = WordPressVersion::fromString( $wp_version['max'] );
					if ( ! $max ) {
						throw new BlueprintExecutionException( 'Invalid WordPress version string in wordpressVersion.max: ' . $wp_version['max'] );
					}
				}
				if ( isset( $wp_version['recommended'] ) && $wp_version['recommended'] !== 'latest' ) {
					$this->recommendedWpVersion = $wp_version['recommended'];
					$recommended = WordPressVersion::fromString( $wp_version['recommended'] );
					if ( false === $recommended ) {
						throw new BlueprintExecutionException( 'Invalid WordPress version string in wordpressVersion.recommended: ' . $wp_version['recommended'] );
					}
				}
			}

			$this->wpVersionConstraint = new VersionConstraint( $min, $max, $recommended );
			$wpConstraintErrors        = $this->wpVersionConstraint->validate();
			if ( ! empty( $wpConstraintErrors ) ) {
				throw new BlueprintExecutionException( 'Invalid WordPress version constraint: ' . implode( '; ', $wpConstraintErrors ) );
			}
			// Note: In here's we're only checking if the version constraint is defined
			// correctly. The actual version check for WordPress is done in
			// NewSiteResolver and ExistingSiteResolver.
		}
	}

	private function createExecutionPlan(): array {
		$validated_array = $this->blueprintArray;
		// --- Process Declarative Properties into Steps (in order) ---

		$plan = [];
		// 1. constants
		if ( ! empty( $validated_array['constants'] ) && is_array( $validated_array['constants'] ) ) {
			$plan[] = $this->createStepObject( 'defineConstants', [ 'constants' => $validated_array['constants'] ] );
		}

		// 2. siteOptions
		if ( ! empty( $validated_array['siteOptions'] ) && is_array( $validated_array['siteOptions'] ) ) {
			// Ensure siteUrl is not included as per schema Omit<>
			unset( $validated_array['siteOptions']['siteUrl'] );
			if ( ! empty( $validated_array['siteOptions'] ) ) {
				$plan[] = $this->createStepObject( 'setSiteOptions', [ 'options' => $validated_array['siteOptions'] ] );
			}
		}

		// 3. muPlugins - Install via writeFiles step
		if ( ! empty( $validated_array['muPlugins'] ) && is_array( $validated_array['muPlugins'] ) ) {
			$files = [];
			foreach ( $validated_array['muPlugins'] as $pluginPath => $pluginContent ) {
				if ( is_string( $pluginPath ) && is_string( $pluginContent ) ) {
					$files[ '/wp-content/mu-plugins/' . $pluginPath ] = $pluginContent;
				} elseif ( is_string( $pluginContent ) ) {
					// Handle numeric keys
					$files[ '/wp-content/mu-plugins/' . basename( $pluginContent ) ] = $pluginContent;
				}
			}
			if ( ! empty( $files ) ) {
				$plan[] = $this->createStepObject( 'writeFiles', [ 'files' => $files ] );
			}
		}

		// 4. themes (install non-active)
		if ( ! empty( $validated_array['themes'] ) && is_array( $validated_array['themes'] ) ) {
			foreach ( $validated_array['themes'] as $themeRef ) {
				if ( is_string( $themeRef ) ) {
					$plan[] = $this->createStepObject( 'installTheme', [
						'source'               => $themeRef,
						'active'               => false,
						'importStarterContent' => false,
					] );
				} elseif ( is_array( $themeRef ) && isset( $themeRef['source'] ) && is_string( $themeRef['source'] ) ) {
					// Pass through the raw definition for extensibility.
					$plan[] = $this->createStepObject( 'installTheme', [
						'source'               => $themeRef['source'],
						'active'               => $themeRef['active'] ?? false,
						'importStarterContent' => $themeRef['importStarterContent'] ?? false,
						'targetDirectoryName'  => $themeRef['targetDirectoryName'] ?? null,
					] );
				} else {
					throw new InvalidArgumentException( 'Invalid theme reference format in "themes" array.' );
				}
			}
		}

		// 5. activeTheme (install and activate)
		if ( isset( $validated_array['activeTheme'] ) ) {
			$themeRef = $validated_array['activeTheme'];
			if ( is_string( $themeRef ) ) {
				$plan[] = $this->createStepObject( 'installTheme', [
					'source'               => $themeRef,
					'active'               => true,
					'importStarterContent' => false,
				] );
			} elseif ( is_array( $themeRef ) && isset( $themeRef['source'] ) && is_string( $themeRef['source'] ) ) {
				$plan[] = $this->createStepObject( 'installTheme', [
					'source'               => $themeRef['source'],
					'active'               => true,
					'importStarterContent' => $themeRef['importStarterContent'] ?? false,
					'targetDirectoryName'  => $themeRef['targetDirectoryName'] ?? null,
				] );
			} else {
				throw new InvalidArgumentException( 'Invalid theme reference format for "activeTheme".' );
			}
		}

		// 6. plugins
		if ( ! empty( $validated_array['plugins'] ) && is_array( $validated_array['plugins'] ) ) {
			foreach ( $validated_array['plugins'] as $pluginDef ) {
				if ( is_string( $pluginDef ) ) {
					$pluginDef = [
						'source' => $pluginDef,
					];
				}
				$plan[] = $this->createStepObject( 'installPlugin', $pluginDef );
			}
		}

		// 7. fonts – not directly supported; use RunPHP placeholders.
		if ( ! empty( $validated_array['fonts'] ) && is_array( $validated_array['fonts'] ) ) {
			throw new InvalidArgumentException( 'Your Blueprint contains a "fonts" property that is not supported yet.' );
		}

		// 8. media – Import media files
		if ( ! empty( $validated_array['media'] ) && is_array( $validated_array['media'] ) ) {
			$plan[] = $this->createStepObject( 'importMedia', [ 'media' => $validated_array['media'] ] );
		}

		// 9. siteLanguage
		if ( ! empty( $validated_array['siteLanguage'] ) && is_string( $validated_array['siteLanguage'] ) ) {
			$plan[] = $this->createStepObject( 'setSiteLanguage', [ 'language' => $validated_array['siteLanguage'] ] );
		}

		// 10. roles - create custom roles using WordPress role management
		if ( ! empty( $validated_array['roles'] ) && is_array( $validated_array['roles'] ) ) {
			$plan[] = $this->createStepObject( 'createRoles', [ 'roles' => $validated_array['roles'] ] );
		}

		// 11. users - create users using WordPress user management
		if ( ! empty( $validated_array['users'] ) && is_array( $validated_array['users'] ) ) {
			$plan[] = $this->createStepObject( 'createUsers', [ 'users' => $validated_array['users'] ] );
		}

		// 12. postTypes – generate one MU-plugin per post type, skipping those already registered.
		if ( ! empty( $validated_array['postTypes'] ) && is_array( $validated_array['postTypes'] ) ) {
			$plan[] = $this->createStepObject( 'createPostTypes', [ 'postTypes' => $validated_array['postTypes'] ] );
		}

		// 13. content imports
		if ( ! empty( $validated_array['content'] ) && is_array( $validated_array['content'] ) ) {
			// @TODO: Consider splitting this into multiple importContent steps, one per piece of content.
			$plan[] = $this->createStepObject( 'importContent', [ 'content' => $validated_array['content'] ] );
		}

		// 14. additionalStepsAfterExecution
		if ( ! empty( $validated_array['additionalStepsAfterExecution'] ) && is_array( $validated_array['additionalStepsAfterExecution'] ) ) {
			foreach ( $validated_array['additionalStepsAfterExecution'] as $stepData ) {
				$plan[] = $this->createStepObject( $stepData['step'], $stepData );
			}
		}

		foreach ( $plan as $step ) {
			// @TODO: Make sure this doesn't get included twice in the execution plan.
			if ( $step instanceof ImportContentStep ) {
				array_unshift( $plan, $this->createStepObject( 'installPlugin', [
					'source' => $this->createDataReference( 'https://playground.wordpress.net/wordpress-importer.zip' ),
				] ) );
				break;
			}
		}

		return $plan;
	}

	/**
	 * Helper method to create a specific step object from its type and data.
	 *
	 * @param  string  $stepType  The 'step' identifier (e.g., 'installPlugin').
	 * @param  array  $data  The properties for the step.
	 *
	 * @return mixed A Step object instance.
	 * @throws InvalidArgumentException If the step type is unknown or data is invalid.
	 */
	private function createStepObject( string $stepType, array $data ) {
		switch ( $stepType ) {
			case 'activatePlugin':
				return new ActivatePluginStep( $data['pluginPath'] );
			case 'activateTheme':
				return new ActivateThemeStep( $data['themeDirectoryName'] );
			case 'cp':
				return new CpStep( $data['fromPath'], $data['toPath'] );
			case 'defineConstants':
				return new DefineConstantsStep( $data['constants'] );
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
				$content = [];
				foreach($data['content'] as $contentDefinition) {
					$source = $contentDefinition['source'];
					$source_is_list = is_array($source) && array_keys($source) === range(0, count($source) - 1);
					if(!$source_is_list) {
						$source = [$source];
					}
					foreach($source as $source_item) {
						$content[] = array_merge(
							$contentDefinition,
							[ 'source' => $this->createDataReference( $source_item, [ ExecutionContextPath::class ] ) ]
						);
					}
				}

				return new ImportContentStep( $content );
			case 'importThemeStarterContent':
				return new ImportThemeStarterContentStep( $data['themeSlug'] ?? null );
			case 'installPlugin':
				$source  = $this->createDataReference( $data['source'], [
					ExecutionContextPath::class,
					WordPressOrgPlugin::class,
				] );
				$active  = $data['active'] ?? true;
				$options = $data['activationOptions'] ?? null;
				$onError = isset( $pluginDef['onError'] ) ? $pluginDef['onError'] : 'throw';

				return new InstallPluginStep( $source, $active, $options, $onError );
			case 'installTheme':
				$source = $this->createDataReference( $data['source'], [
					ExecutionContextPath::class,
					WordPressOrgTheme::class,
				] );

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
					$this->createDataReference( $data['code'], [ ExecutionContextPath::class ] ),
					$data['env'] ?? []
				);
			case 'runSQL':
				$source = $this->createDataReference( $data['source'], [ ExecutionContextPath::class ] );
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
				require_once(getenv("DOCROOT") . "/wp-load.php");
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
					$this->createDataReference( [
						'filename' => 'create-roles.php',
						'content'  => $code,
					] ),
					[ 'ROLES' => $data['roles'] ]
				);

			case 'createUsers':
				if ( empty( $data['users'] ) || ! is_array( $data['users'] ) ) {
					throw new InvalidArgumentException( 'Invalid users data: must be a non-empty array.' );
				}

				$code = '<?php
                require_once(getenv("DOCROOT") . "/wp-load.php");
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
					$this->createDataReference( [
						'filename' => 'create-users.php',
						'content'  => $code,
					] ),
					[ 'USERS' => $data['users'] ]
				);

			case 'createPostTypes':
				if ( empty( $data['postTypes'] ) || ! is_array( $data['postTypes'] ) ) {
					throw new InvalidArgumentException( 'Invalid postTypes data: must be a non-empty array.' );
				}

				// @TODO: Do we need a separate step here? To make sure we're not overwriting existing post types?
				//        Or would WriteFilesStep be enough, perhaps with a "no override" flag?
				// @TODO: Install SCF and use it to register post types.

				$files = [];
				foreach ( $data['postTypes'] as $slug => $args ) {
					if ( ! is_string( $slug ) || $slug === '' ) {
						continue;
					}

					// Ensure $args is an array.
					if ( ! is_array( $args ) ) {
						$args = [];
					}

					// Build a safe file name for the MU-plugin.
					$fileSlug   = preg_replace( '/[^a-z0-9\-]+/i', '-', strtolower( $slug ) );
					$pluginPath = "wp-content/mu-plugins/blueprint-post-type-{$fileSlug}.php";

					// Human-friendly default label.
					$defaultLabel = addslashes( ucwords( str_replace( [ '-', '_' ], ' ', $slug ) ) );
					if ( ! isset( $args['label'] ) ) {
						$args['label'] = $defaultLabel;
					}

					// Compose the plugin source.
					$pluginCode = sprintf(
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

					$files[ $pluginPath ] = $this->createDataReference( [
						'filename' => $pluginPath,
						'content'  => $pluginCode,
					] );
				}

				if ( empty( $files ) ) {
					throw new InvalidArgumentException( 'No valid post types to register.' );
				}

				return new WriteFilesStep( $files );

			case 'runPHP':
				return new RunPHPStep(
					$this->createDataReference( [
						'filename' => 'run-php.php',
						'content'  => $data['code'],
					] ),
					$data['env'] ?? []
				);
			case 'unzip':
				$zipFile = $this->createDataReference( $data['zipFile'], [ ExecutionContextPath::class ] );

				return new UnzipStep( $zipFile, $data['extractToPath'] );
			case 'wp-cli':
				return new WPCLIStep( $data['command'], $data['wpCliPath'] ?? null );
			case 'writeFiles':
				$files = [];
				foreach ( $data['files'] as $path => $content ) {
					$files[ $path ] = $this->createDataReference( $content, [ ExecutionContextPath::class ] );
				}

				return new WriteFilesStep( $files );
			case 'importMedia':
				$media = [];
				foreach ( $data['media'] as $path => $content ) {
					if ( is_string( $content ) ) {
						$media[ $path ] = MediaFileDefinition::fromArray( [
							'source' => $this->createDataReference( $content, [ ExecutionContextPath::class ] ),
						] );
						continue;
					}

					$media[ $path ] = MediaFileDefinition::fromArray( [
						'source'      => $this->createDataReference( $content['source'], [ ExecutionContextPath::class ] ),
						'title'       => $content['title'] ?? null,
						'description' => $content['description'] ?? null,
						'alt'         => $content['alt'] ?? null,
						'caption'     => $content['caption'] ?? null,
					] );
				}

				return new ImportMediaStep( $media );
			default:
				throw new InvalidArgumentException( "Unknown step type: {$stepType}" );
		}
	}

	/**
	 * @param  mixed  $data
	 */
	private function createDataReference( $data, array $additional_reference_classes = [] ): DataReference {
		$reference = $data instanceof DataReference ? $data : DataReference::create( $data, $additional_reference_classes );

		/**
		 * A Blueprint sourced from an ExecutionContextPath is always local.
		 * We don't have a separate reference type for a "local path". We just assume that,
		 * at the Blueprint resolution stage, execution context is the entire filesystem. Only
		 * then we narrow it down to the Blueprint parent directory.
		 */
		$executionContextIsLocal = $this->configuration->getBlueprint() instanceof ExecutionContextPath;
		if (
			$executionContextIsLocal &&
			! $this->configuration->isAllowedLocalFilesystemAccess() &&
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

		// @TODO: Ensure we're not creating an ExecutionContextPath based on contents of a remote resource file.
		$this->dataReferences[ $reference->id ] = $reference;

		return $reference;
	}

	/**
	 * Run the steps in the execution plan with progress tracking
	 *
	 * @param  Tracker  $parentTracker  The parent tracker for step execution
	 *
	 * @return array Results from each step execution
	 */
	private function executePlan( Tracker $progress, array $steps, Runtime $runtime ): array {
		/**
		 * Execute the steps in the execution plan with progress tracking
		 */
		$results   = [];
		$stepCount = count( $steps );

		if ( $stepCount === 0 ) {
			$progress->finish();

			return $results;
		}

		// Create progress trackers for each step upfront
		$progress->split( range( 0, $stepCount ) );
		for ( $i = 0; $i < $stepCount; $i ++ ) {
			$step        = $steps[ $i ];
			$stepTracker = $progress[ $i ];

			try {
				$results[ $i ] = $step->run( $runtime, $stepTracker );

				// If step didn't call finish(), do it for them
				if ( ! $stepTracker->isDone() ) {
					$stepTracker->finish();
				}
			} catch ( \Exception $e ) {
				$results[ $i ] = $e;
				// Determine if we should continue or stop execution
				$continueOnError = $this->continueOnError ?? false;
				if ( ! $continueOnError ) {
					// @TODO: Correlate this message with the original Blueprint,
					//        as in – was the step created because of "installPlugin" or not?
					//  	  Which entry of it? etc.
					throw new BlueprintExecutionException(
						sprintf( "Error when executing step  %s (#%d in the execution plan)",
							get_class( $step ),
							$i + 1
						),
						0,
						$e
					);
				}

				$stepTracker->setCaption( sprintf( "%s (FAILED: %s)",
					$stepTracker->getCaption(),
					$e->getMessage()
				) );
				$stepTracker->finish();
			}
		}

		return $results;
	}
}
