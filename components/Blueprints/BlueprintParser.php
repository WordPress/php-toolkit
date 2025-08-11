<?php

namespace WordPress\Blueprints;

use InvalidArgumentException;
use WordPress\Blueprints\DataReference\InlineFile;
use WordPress\Blueprints\Exception\BlueprintExecutionException;
use WordPress\Blueprints\Validator\HumanFriendlySchemaValidator;
use WordPress\Blueprints\Versions\Version1\V1ToV2Transpiler;
use WordPress\Blueprints\VersionStrings\PHPVersion;
use WordPress\Blueprints\VersionStrings\VersionConstraint;
use WordPress\Blueprints\VersionStrings\WordPressVersion;

use function WordPress\Encoding\utf8_is_valid_byte_stream;

class BlueprintParser {
    /**
     * @var RunnerConfiguration
     */
    private $configuration;

    public function __construct( RunnerConfiguration $configuration ) {
        $this->configuration = $configuration;
    }

    public function parse( string $blueprint_string ): Blueprint {
        // **UTF-8 Encoding:** Assert the Blueprint input is UTF-8 encoded.
		$is_valid_utf8 = false;
		if ( function_exists( 'mb_check_encoding' ) ) {
			$is_valid_utf8 = mb_check_encoding( $blueprint_string, 'UTF-8' );
		} else {
			$is_valid_utf8 = utf8_is_valid_byte_stream( $blueprint_string );
		}

		if ( ! $is_valid_utf8 ) {
			throw new BlueprintExecutionException( 'Blueprint must be encoded as UTF-8.' );
		}

        // **JSON Validity:** Assert the input is a valid JSON document.
		$blueprint_array = json_decode( $blueprint_string, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			throw new BlueprintExecutionException( 'Blueprint must be a valid JSON document.' );
		}

        if ( ! is_array( $blueprint_array ) ) {
			throw new BlueprintExecutionException( 'Blueprint must be an array.' );
		}

        // **Blueprint v1 Detection:** If the Blueprint is v1, transpile it to v2.
        if ( ! isset( $blueprint_array['version'] ) ) {
			$error = V1ToV2Transpiler::validate_v1_blueprint( $blueprint_array );
			if ( $error ) {
				throw new BlueprintExecutionException( 'Invalid Blueprint v1 provided.', 0, null, $error );
			}
			$this->configuration->getLogger()->debug( 'Blueprint v1 detected. Transpiling to v2...' );

			$transpiler      = new V1ToV2Transpiler( $this->configuration->getLogger() );
            $blueprint_array = $transpiler->upgrade( $blueprint_array );
		}

        $this->configuration->getLogger()->debug( 'Final resolved Blueprint: ' . json_encode( $blueprint_array, JSON_PRETTY_PRINT ) );
        $blueprint_array = apply_filters( 'blueprint.resolved', $blueprint_array );

        // **JSON Schema Validity:** Assert the Blueprint conforms to the JSON schema.
		$validator = new HumanFriendlySchemaValidator(
			json_decode( file_get_contents( __DIR__ . '/Versions/Version2/json-schema/schema-v2.json' ), true )
		);

		$error = $validator->validate( $blueprint_array );
		if ( $error ) {
			throw new BlueprintExecutionException( 'Blueprint does not conform to the schema.', 0, null, $error );
		}

		// Get version constraints.
        $php_version_constraint = $this->getPhpVersionConstraint( $blueprint_array );
        $wp_version_constraint  = $this->getWordPressVersionConstraint( $blueprint_array );

        // Create the execution plan.
        $execution_plan = $this->createExecutionPlan( $blueprint_array );

        return new Blueprint(
            $blueprint_string,
            $blueprint_array,
            $php_version_constraint,
            $wp_version_constraint,
            $execution_plan
        );
    }

    private function getPhpVersionConstraint( array $blueprint ): ?VersionConstraint {
        if ( ! isset( $blueprint['phpVersion'] ) ) {
            return null;
        }

        $min = $max = $recommended = null;
        $php_version = $blueprint['phpVersion'];
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
        $php_version_constraint = new VersionConstraint( $min, $max, $recommended );
        $phpConstraintErrors  = $php_version_constraint->validate();
        if ( ! empty( $phpConstraintErrors ) ) {
            throw new BlueprintExecutionException( 'Invalid PHP version constraint: ' . implode( '; ', $phpConstraintErrors ) );
        }

        // Confirm the environment satisfies the PHP version constraint.
        $currentPhpVersion = PHPVersion::fromString( PHP_VERSION );
        if ( ! $php_version_constraint->satisfiedBy( $currentPhpVersion ) ) {
            throw new BlueprintExecutionException(
                sprintf(
                    'PHP version requirement not satisfied. Blueprint requires %s, but current version is %s',
                    $php_version_constraint->__toString(),
                    $currentPhpVersion
                )
            );
        }
        return $php_version_constraint;
    }

    private function getWordPressVersionConstraint( array $blueprint ): ?VersionConstraint {
        if ( ! isset( $blueprint['wordpressVersion'] ) ) {
            return null;
        }

        $wp_version = $blueprint['wordpressVersion'];
        $min = $max = $recommended = null;
        if ( is_string( $wp_version ) ) {
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
                $max         = WordPressVersion::fromString( $wp_version['max'] );
                $recommended = $max;
                if ( ! $max ) {
                    // @TODO: Reuse this error message
                    // 'Unrecognized WordPress version. Please use "latest", a URL, or a numeric version such as "6.2", "6.0.1", "6.2-beta1", or "6.2-RC1"'
                    throw new BlueprintExecutionException( 'Invalid WordPress version string in wordpressVersion.max: ' . $wp_version['max'] );
                }
            }
            if ( isset( $wp_version['recommended'] ) && $wp_version['recommended'] !== 'latest' ) {
                $recommended = WordPressVersion::fromString( $wp_version['recommended'] );
                if ( false === $recommended ) {
                    throw new BlueprintExecutionException( 'Invalid WordPress version string in wordpressVersion.recommended: ' . $wp_version['recommended'] );
                }
            }
        }

        $wp_version_constraint = new VersionConstraint( $min, $max, $recommended );
        $wpConstraintErrors    = $wp_version_constraint->validate();
        if ( ! empty( $wpConstraintErrors ) ) {
            throw new BlueprintExecutionException( 'Invalid WordPress version constraint: ' . implode( '; ', $wpConstraintErrors ) );
        }
        // Note: In here's we're only checking if the version constraint is defined
        // correctly. The actual version check for WordPress is done in
        // NewSiteResolver and ExistingSiteResolver.

        return $wp_version_constraint;
    }

    private function createExecutionPlan( array $blueprint ): array {
		$plan = [];

		// 1. constants
		if ( ! empty( $blueprint['constants'] ) && is_array( $blueprint['constants'] ) ) {
			$plan[] = $this->buildConstantsStep( $blueprint['constants'] );
		}

		// 2. siteOptions
		if ( ! empty( $blueprint['siteOptions'] ) && is_array( $blueprint['siteOptions'] ) ) {
			$plan[] = $this->buildSiteOptionsStep( $blueprint['siteOptions'] );
		}

		// 3. muPlugins - Install via writeFiles step
		if ( ! empty( $blueprint['muPlugins'] ) && is_array( $blueprint['muPlugins'] ) ) {
			$plan[] = $this->buildMuPluginsStep( $blueprint['muPlugins'] );
		}

		// 4. themes (install non-active)
		if ( ! empty( $blueprint['themes'] ) && is_array( $blueprint['themes'] ) ) {
			foreach ( $blueprint['themes'] as $themeRef ) {
				$plan[] = $this->buildThemeStep( $themeRef );
			}
		}

		// 5. activeTheme (install and activate)
		if ( isset( $blueprint['activeTheme'] ) ) {
			$plan[] = $this->buildActiveThemeStep( $blueprint['activeTheme'] );
		}

		// 6. plugins
		if ( ! empty( $blueprint['plugins'] ) && is_array( $blueprint['plugins'] ) ) {
            foreach ( $blueprint['plugins'] as $pluginDef ) {
                $plan[] = $this->buildPluginStep( $pluginDef );
            }
		}

		// 7. fonts – not directly supported; use RunPHP placeholders.
		if ( ! empty( $blueprint['fonts'] ) && is_array( $blueprint['fonts'] ) ) {
			throw new InvalidArgumentException( 'Your Blueprint contains a "fonts" property that is not supported yet.' );
		}

		// 8. media – Import media files
		if ( ! empty( $blueprint['media'] ) && is_array( $blueprint['media'] ) ) {
			$plan[] = $this->buildMediaStep( $blueprint['media'] );
		}

		// 9. siteLanguage
		if ( ! empty( $blueprint['siteLanguage'] ) && is_string( $blueprint['siteLanguage'] ) ) {
			$plan[] = $this->buildSiteLanguageStep( $blueprint['siteLanguage'] );
		}

		// 10. roles - create custom roles using WordPress role management
		if ( ! empty( $blueprint['roles'] ) && is_array( $blueprint['roles'] ) ) {
			$plan[] = $this->buildRolesStep( $blueprint['roles'] );
		}

		// 11. users - create users using WordPress user management
		if ( ! empty( $blueprint['users'] ) && is_array( $blueprint['users'] ) ) {
			$plan[] = $this->buildUsersStep( $blueprint['users'] );
		}

		// 12. postTypes – generate one MU-plugin per post type, skipping those already registered.
		if ( ! empty( $blueprint['postTypes'] ) && is_array( $blueprint['postTypes'] ) ) {
			$plan[] = $this->buildPostTypesStep( $blueprint['postTypes'] );
		}

		// 13. content imports
		if ( ! empty( $blueprint['content'] ) && is_array( $blueprint['content'] ) ) {
			$plan[] = $this->buildContentStep( $blueprint['content'] );
		}

		// 14. additionalStepsAfterExecution
		if ( ! empty( $blueprint['additionalStepsAfterExecution'] ) && is_array( $blueprint['additionalStepsAfterExecution'] ) ) {
			foreach ( $blueprint['additionalStepsAfterExecution'] as $stepData ) {
				$plan[] = $this->buildAdditionalStepsAfterExecution( $stepData );
			}
		}

		foreach ( $plan as $step ) {
			// @TODO: Make sure this doesn't get included twice in the execution plan,
			//        e.g. if the Blueprint specified this step manually.
			if ( $step instanceof ImportContentStep ) {
				// if($this->configuration->isRunningAsPhar()) {
				// 	throw new InvalidArgumentException( '@TODO: Importing content is not supported when running as phar.' );
				// } else {
					$libraries_phar_path = __DIR__ . '/../../dist/php-toolkit.phar';
					if(!file_exists($libraries_phar_path)) {
						throw new InvalidArgumentException(
							'In development, you must run `bash bin/build-libraries-phar.sh` to bundle importer libraries before importing content via a Blueprint. '.
							'It generates a `dist/php-toolkit.phar` file bundling all the libraries required for importing content.'
						);
					}
					$this->configuration->getLogger()->info( 'Loading importer libraries from ' . $libraries_phar_path );
					$source = new InlineFile( [
						'filename' => 'php-toolkit.phar',
						'content'  => file_get_contents( $libraries_phar_path )
					] );
				// }
				array_unshift( $plan, [
                    'name' => 'writeFiles',
                    'args' => [
                        'files' => [
                            'php-toolkit.phar' => $source,
                        ],
                    ]
                ] );
				break;
			}
		}

		return $plan;
	}

    private function buildConstantsStep( array $constants ): array {
        return [
            'name'   => 'defineConstants',
            'args'   => [ 'constants' => $constants ],
        ];
    }

    private function buildSiteOptionsStep( array $site_options ): array {
        // Ensure siteUrl is not included as per schema Omit<>
        unset( $site_options['siteUrl'] );
        return [
            'name'   => 'setSiteOptions',
            'args'   => [ 'options' => $site_options ],
        ];
    }

    private function buildMuPluginsStep( array $mu_plugins ): array {
        $files = [];
        foreach ( $mu_plugins as $path => $content ) {
            if ( is_string( $path ) && is_string( $content ) ) {
                $files[ '/wp-content/mu-plugins/' . $path ] = $content;
            } elseif ( is_string( $content ) ) {
                // Handle numeric keys
                $files[ '/wp-content/mu-plugins/' . basename( $content ) ] = $content;
            }
        }

        return [
            'name'   => 'writeFiles',
            'args'   => [ 'files' => $files ],
        ];
    }

    private function buildThemeStep( $theme ): array {
        if ( is_string( $theme ) ) {
            $step = [
                'name' => 'installTheme',
                'args' => [
                    'source'               => $theme,
                    'active'               => false,
                    'importStarterContent' => false,
                ],
            ];
        } elseif ( is_array( $theme ) && isset( $theme['source'] ) && is_string( $theme['source'] ) ) {
            // Pass through the raw definition for extensibility.
            $step = [
                'name' => 'installTheme',
                'args' => [
                    'source'               => $theme['source'],
                    'active'               => $theme['active'] ?? false,
                    'importStarterContent' => $theme['importStarterContent'] ?? false,
                    'targetDirectoryName'  => $theme['targetDirectoryName'] ?? null,
                ],
            ];
        } else {
            throw new InvalidArgumentException( 'Invalid theme reference format in "themes" array.' );
        }

        return $step;
    }

    private function buildActiveThemeStep( $theme ): array {
        if ( is_string( $theme ) ) {
            $theme = [ 'source' => $theme ];
        }
        $theme['active'] = true;
        return $this->buildThemeStep( $theme );
    }

    private function buildPluginStep( $plugin ): array {
        if ( is_string( $plugin ) ) {
            $plugin = [ 'source' => $plugin ];
        }

        return [
            'name'   => 'installPlugin',
            'args'   => $plugin,
        ];
    }

    private function buildMediaStep( $media ): array {
        return [
            'name'   => 'importMedia',
            'args'   => [ 'media' => $media ],
        ];
    }

    private function buildSiteLanguageStep( $site_language ): array {
        return [
            'name'   => 'setSiteLanguage',
            'args'   => [ 'language' => $site_language ],
        ];
    }

    private function buildRolesStep( $roles ): array {
        return [
            'name'   => 'createRoles',
            'args'   => [ 'roles' => $roles ],
        ];
    }

    private function buildUsersStep( $users ): array {
        return [
            'name'   => 'createUsers',
            'args'   => [ 'users' => $users ],
        ];
    }

    private function buildPostTypesStep( $post_types ): array {
        return [
            'name'   => 'createPostTypes',
            'args'   => [ 'postTypes' => $post_types ],
        ];
    }

    private function buildContentStep( $content ): array {
        // @TODO: Consider splitting this into multiple importContent steps,
        //        one per piece of content.
        return [
            'name'   => 'importContent',
            'args'   => [ 'content' => $content ],
        ];
    }

    private function buildAdditionalStepsAfterExecution( $step_data ): array {
        return [
            'name'   => $step_data['step'],
            'args'   => $step_data,
        ];
    }

    private function validateDataSource( string $source, string $allowed_pattern ): ?string {
        if ( strlen( $source ) === 0 ) {
            return 'Source must be a non-empty string.';
        }

        // 1. Absolute URL.
        if ( str_contains( $source, '://' ) ) {
            return null;
        }

        // 2. Bundle-relative path.
        $byte_1 = $source[0];
        $byte_2 = $source[1] ?? null;
        if ( str_contains( $source, '/' ) ) {
            if ( $byte_1 === '/' ) {
                $source = substr( $source, 1 );
            } elseif ( $byte_1 === '.' && $byte_2 === '/' ) {
                $source = substr( $source, 2 );
            }

            if ( ! fnmatch( $allowed_pattern, $source ) ) {
                return sprintf(
                    'Invalid path "%s". Expected to match "%s".',
                    $source,
                    $allowed_pattern
                );
            }
        }

        return null;
    }
}
