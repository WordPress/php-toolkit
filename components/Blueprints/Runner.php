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
use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Filesystem\LocalFilesystem;
use WordPress\HttpClient\ByteStream\RequestReadStream;
use WordPress\HttpClient\Client;
use WordPress\Zip\ZipFilesystem;

use function WordPress\Filesystem\wp_unix_sys_get_temp_dir;
use function WordPress\Zip\is_zip_file_stream;

class Runner {
	const EXECUTION_MODE_CREATE_NEW_SITE = 'create-new-site';
	const EXECUTION_MODE_APPLY_TO_EXISTING_SITE = 'apply-to-existing-site';
	const EXECUTION_MODES = [
		self::EXECUTION_MODE_CREATE_NEW_SITE,
		self::EXECUTION_MODE_APPLY_TO_EXISTING_SITE,
	];
	
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
	 * @var LocalFilesystem
	 */
	private $blueprintExecutionContext;
	/**
	 * @var mixed[]
	 */
	private $blueprintArray;
	/**
	 * @var mixed[]
	 */
	private $dataReferencesToAutoResolve = [];
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

		$this->client      = apply_filters('blueprint.http_client', new Client());
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
		if ( ! in_array( $mode, self::EXECUTION_MODES, true ) ) {
			throw new BlueprintExecutionException( "Execution mode must be one of: " . implode( ', ', self::EXECUTION_MODES ) );
		}

		// Validate site URL
		// Note: $options is not defined in this context, so we skip this block.
		// If you want to validate the site URL, you should use $config->getTargetSiteUrl().
		$siteUrl = $config->getTargetSiteUrl();
		if ( $mode === self::EXECUTION_MODE_CREATE_NEW_SITE ) {
			if ( empty( $siteUrl ) ) {
				throw new BlueprintExecutionException( sprintf( "Site URL is required when the execution mode is '%s'.", self::EXECUTION_MODE_CREATE_NEW_SITE ) );
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
			$blueprintString = $this->loadBlueprint();

			// Parse the blueprint string.
			$parser    = new BlueprintParser( $this->configuration );
			$blueprint = $parser->parse( $blueprintString );
			if ( ! $blueprint->isValid() ) {
				throw new BlueprintExecutionException( 'Invalid blueprint: ' . implode( ', ', $blueprint->getErrors() ) );
			}

			// Initialize steps based on the execution plan.
			$plan = [];
			foreach ( $blueprint->getExecutionPlan() as $step ) {
				$plan[] = $this->createStepObject( $step['name'], $step['args'] );
			}

			$this->assets->setExecutionContext( $this->blueprintExecutionContext );
			$progress['blueprint']->finish();

			$progress['targetResolution']->setCaption( 'Resolving target site' );
			$targetSiteFs   = LocalFilesystem::create( $this->configuration->getTargetSiteRoot() );
			$wpCliReference = DataReference::create( 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar' );

			$execution_context = $this->blueprintExecutionContext->get_meta();
			if(
				isset($execution_context['root']) &&
				( !is_string($execution_context['root']) || strlen($execution_context['root']) === 0)
			) {
				throw new BlueprintExecutionException('Execution context was a local directory, but the Runner could not determine the root directory. This should never happen. Please report this as a bug.');
			}

			$this->runtime  = new Runtime(
				$targetSiteFs,
				$this->configuration,
				$this->assets,
				$this->client,
				$blueprint,
				$tempRoot,
				$wpCliReference,
				isset($execution_context['root']) ? $execution_context['root'] : null
			);
			$this->progressObserver->setRuntime( $this->runtime );
			$progress['wpCli']->setCaption( 'Downloading WP-CLI' );
			$this->assets->startEagerResolution( [
				'wp-cli' => $wpCliReference,
			], $progress['wpCli'] );

			// Get recommended WordPress version.
			$wp_version_constraint = $blueprint->getWpVersionConstraint();
			if ( null === $wp_version_constraint ) {
				$recommended_wp_version = 'latest';
			} else {
				$recommended_wp_version = (string) $wp_version_constraint->getRecommended();
			}

			$progress['targetResolution']->setCaption( 'Resolving target site' );
			if ( $this->configuration->getExecutionMode() === self::EXECUTION_MODE_APPLY_TO_EXISTING_SITE ) {
				ExistingSiteResolver::resolve( $this->runtime, $progress['targetResolution'], $wp_version_constraint );
			} else {
				NewSiteResolver::resolve( $this->runtime, $progress['targetResolution'], $wp_version_constraint, $recommended_wp_version );
			}
			$progress['targetResolution']->finish();

			do_action('blueprint.target_resolved');

			$progress['data']->setCaption( 'Resolving data references' );
			$this->assets->startEagerResolution( $this->dataReferencesToAutoResolve, $progress['data'] );
			$this->executePlan( $progress['execution'], $plan, $this->runtime );

			// @TODO: Assert WordPress is still correctly installed
			
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
			// For the purposes of Blueprint resolution, the execution context is the
			// current working directory. This way, a path such as ./blueprint.json
			// will mean "a blueprint.json file in the current working directory" and not
			// "a ./blueprint.json path without a point of reference".
			$this->assets->setExecutionContext( LocalFilesystem::create( getcwd() ) );
			$resolved = $this->assets->resolve( $reference );
			$this->assets->setExecutionContext( null );

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

		return $blueprintString;
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
				$content = [];
				foreach($data['content'] as $contentDefinition) {
					$source = $contentDefinition['source'];
					$source_is_list = is_array($source) && array_keys($source) === range(0, count($source) - 1);
					if(!$source_is_list) {
						$source = [$source];
					}
					foreach($source as $source_item) {
						$data_reference = $this->createDataReference( $source_item, [ ExecutionContextPath::class ], [ 'auto_resolve' => false ] );
						$content[] = array_merge(
							$contentDefinition,
							[ 'source' => $data_reference ]
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
	private function createDataReference( $data, array $additional_reference_classes = [], $options = [] ): DataReference {
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

		if($options['auto_resolve'] ?? true) {
			$this->dataReferencesToAutoResolve[ $reference->id ] = $reference;
		}

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
