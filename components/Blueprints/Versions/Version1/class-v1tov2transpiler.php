<?php

namespace WordPress\Blueprints\Versions\Version1;

use Psr\Log\LoggerInterface;
use WordPress\Blueprints\Exception\BlueprintExecutionException;
use WordPress\Blueprints\Validator\HumanFriendlySchemaValidator;
use WordPress\Blueprints\Validator\ValidationError;

use function WordPress\Filesystem\wp_join_unix_paths;

/**
 * @TODO: rewrite https://github.com urls to raw.githubusercontent.com urls like
 *        Blueprint v1 do. Maybe even do it in v2 runner in general?
 */
class V1ToV2Transpiler {

	/**
	 * @var LoggerInterface
	 */
	private $logger;

	static public function validate_v1_blueprint( array $v1 ): ?ValidationError {
		$v = new HumanFriendlySchemaValidator(
			json_decode( file_get_contents( __DIR__ . '/schema-v1.json' ), true )
		);

		// For every steps[] entry with step === "installPlugin" and "pluginZipFile", remove that key and rewrite it as "pluginData"
		if (isset($v1['steps']) && is_array($v1['steps'])) {
			foreach ($v1['steps'] as &$step) {
				if(!is_array($step) || !isset($step['step'])) {
					continue;
				}
				if ($step['step'] === 'installPlugin' && array_key_exists('pluginZipFile', $step)) {
					// If pluginData is not already set, move pluginZipFile to pluginData
					if (!array_key_exists('pluginData', $step)) {
						$step['pluginData'] = $step['pluginZipFile'];
					}
					unset($step['pluginZipFile']);
				} else if ($step['step'] === 'installTheme' && array_key_exists('themeZipFile', $step)) {
					// If themeData is not already set, move themeZipFile to themeData
					if (!array_key_exists('themeData', $step)) {
						$step['themeData'] = $step['themeZipFile'];
					}
					unset($step['themeZipFile']);
				} else if($step['step'] === 'importFile') {
					$step['step'] = 'importWxr';
				}
			}
			unset($step); // break reference
		}

		return $v->validate( $v1 );
	}

	public function __construct( LoggerInterface $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Upgrade a v1 Blueprint array to a v2 Blueprint array.
	 *
	 * @param  array  $v1
	 *
	 * @return array
	 * @throws BlueprintExecutionException
	 */
	public function upgrade( array $validated_v1_blueprint ): array {
		$v1      = $validated_v1_blueprint;
		$v2      = [
			'version' => 2,
		];
		$v2steps = [];

		// Map $schema if present
		if ( isset( $v1['$schema'] ) ) {
			$v2['$schema'] = $v1['$schema'];
		}

		// Map meta fields
		if ( isset( $v1['meta'] ) ) {
			$v2['blueprintMeta'] = [];
			if ( isset( $v1['meta']['title'] ) ) {
				$v2['blueprintMeta']['name'] = $v1['meta']['title'];
			}
			if ( isset( $v1['meta']['description'] ) ) {
				$v2['blueprintMeta']['description'] = $v1['meta']['description'];
			}
			if ( isset( $v1['meta']['categories'] ) ) {
				$v2['blueprintMeta']['tags'] = $v1['meta']['categories'];
			}
			if ( isset( $v1['meta']['author'] ) ) {
				$v2['blueprintMeta']['authors'] = [ $v1['meta']['author'] ];
			}
		}

		// Map preferredVersions
		if ( isset( $v1['preferredVersions'] ) ) {
			$versions = $v1['preferredVersions'];
			if ( isset( $versions['wp'] ) && $versions['wp'] !== 'latest' ) {
				$v2['wordpressVersion'] = $versions['wp'];
			}
			if ( isset( $versions['php'] ) && $versions['php'] !== 'latest' ) {
				$v2['phpVersion'] = $versions['php'];
			}
		}

		// Unsupported fields
		// @TODO: Actually transpile a few of them:
		// * features -> runtimeOptions.playground.features
		//            -> or consider moving this to runtime configuration – as in
		//               permissions to access the network, disk, etc.
		// * landingPage -> runtimeOptions.landingPage
		// * login -> runtimeOptions.login
		$unsupportedFields        = [
			'features',
			'landingPage',
			'login',
			'phpExtensionBundles',
		];
		$presentUnsupportedFields = [];
		foreach ( $unsupportedFields as $field ) {
			if ( isset( $v1[ $field ] ) ) {
				$presentUnsupportedFields[] = $field;
			}
		}
		if ( ! empty( $presentUnsupportedFields ) ) {
			$this->logger->warning( sprintf( 'The following fields are not yet supported by the v1->v2 Blueprint transpiler and will be ignored: %s.',
				implode( ', ', $presentUnsupportedFields ) ) );
		}

		// SHORTHANDS:

		// Plugins
		if ( isset( $v1['plugins'] ) ) {
			foreach ( $v1['plugins'] as $plugin ) {
				$v2steps[] = [
					'step'   => 'installPlugin',
					'source' => self::convertV1ResourceToV2Reference( $plugin ),
				];
			}
		}

		// Constants
		if ( isset( $v1['constants'] ) ) {
			$v2['constants'] = $v1['constants'];
		}

		// Site options
		if ( isset( $v1['siteOptions'] ) ) {
			$v2['siteOptions'] = $v1['siteOptions'];
		}

		// STEPS:
		if ( isset( $v1['steps'] ) && is_array( $v1['steps'] ) ) {
			foreach ( $v1['steps'] as $v1step ) {
				switch ( $v1step['step'] ) {
					case 'activatePlugin':
						if ( isset( $v1step['progress'] ) ) {
							$this->logger->warning( 'The `progress` option is not supported on activatePlugin step and will be ignored: %s. Use the runtime configuration to set the progress bar instead.' );
						}
						$v2step = [
							'step'       => 'activatePlugin',
							'pluginPath' => $v1step['pluginPath'],
						];
						if ( isset( $v1step['humanReadableName'] ) ) {
							$v2step['humanReadableName'] = $v1step['humanReadableName'];
						}
						$v2steps[] = $v2step;
						break;
					case 'activateTheme':
						if ( isset( $v1step['progress'] ) ) {
							$this->logger->warning( 'The `progress` option is not supported on activateTheme step and will be ignored: %s. Use the runtime configuration to set the progress bar instead.' );
						}
						$v2step = [
							'step'               => 'activateTheme',
							'themeDirectoryName' => $v1step['themeFolderName'],
						];
						if ( isset( $v1step['humanReadableName'] ) ) {
							$v2step['humanReadableName'] = $v1step['humanReadableName'];
						}
						$v2steps[] = $v2step;
						break;
					case 'cp':
						if ( isset( $v1step['progress'] ) ) {
							$this->logger->warning( 'The `progress` option is not supported on cp step and will be ignored: %s. Use the runtime configuration to set the progress bar instead.' );
						}
						$v2steps[] = [
							'step'     => 'cp',
							'fromPath' => self::translatePath( $v1step['fromPath'] ),
							'toPath'   => self::translatePath( $v1step['toPath'] ),
						];
						break;
					case 'defineWpConfigConsts':
						if ( isset( $v1step['progress'] ) ) {
							$this->logger->warning( 'The `progress` option is not supported on defineWpConfigConsts step and will be ignored: %s. Use the runtime configuration to set the progress bar instead.' );
						}
						if ( isset( $v1step['method'] ) ) {
							$this->logger->warning( 'The `method` option is not supported on defineWpConfigConsts step and will be ignored: %s. Use the runtime configuration to set the method instead.' );
						}
						if ( isset( $v1step['virtualize'] ) ) {
							$this->logger->warning( 'The `virtualize` option is not supported on defineWpConfigConsts step and will be ignored: %s. This option is deprecated and will be removed in a future version.' );
						}
						$v2steps[] = [
							'step'      => 'defineConstants',
							'constants' => $v1step['consts'],
						];
						break;
					case 'defineSiteUrl':
						$this->logger->warning( 'The `defineSiteUrl` step is not supported by the Blueprint v2 schema. Use the runner configuration to set the site URL instead.' );
						break;
					case 'enableMultisite':
						$v2steps[] = [
							'step' => 'enableMultisite',
						];
						break;
					case 'importWordPressFiles':
						$this->logger->warning( 'The `importWordPressFiles` step is not supported by the Blueprint v2 schema. The entire step will be ignored.' );
						break;
					case 'runWpInstallationWizard':
						$this->logger->warning( 'The `runWpInstallationWizard` step is not supported by the Blueprint v2 schema. Provide your WordPress export URL in the top-level "wordpressVersion" key and the runner will handle the installation automatically.' );
						break;
					case 'importWxr':
						if ( isset( $v1step['progress'] ) ) {
							$this->logger->warning( 'The `progress` option is not supported on importWxr step and will be ignored: %s. Use the runtime configuration to set the progress bar instead.' );
						}
						$v2steps[] = [
							'step'    => 'importContent',
							'content' => [
								[
									'type'   => 'wxr',
									'source' => self::convertV1ResourceToV2Reference( $v1step['file'] ),
								],
							],
						];
						break;
					case 'importThemeStarterContent':
						// @TODO: We really need to support this in the v2 runner!
						//        Let's add an importContent step.
						$this->logger->warning( 'The `importThemeStarterContent` step is not supported by the Blueprint v2 schema. Use the runner configuration to set the import behavior instead.' );
						break;
					case 'installPlugin':
						if ( isset( $v1step['ifAlreadyInstalled'] ) ) {
							$this->logger->warning( sprintf( 'The `ifAlreadyInstalled` option is not yet supported by the v1->v2 Blueprint transpiler and will be ignored: %s. Use the runtime configuration to set the behavior instead.',
								$v1step['ifAlreadyInstalled'] ) );
						}
						if ( isset( $v1step['progress']['weight'] ) ) {
							$this->logger->warning( 'The `progress.weight` option is not supported Blueprint v2 schema and will be ignored: %s. Use the runtime configuration to set the progress bar instead.' );
						}
						$v2step = [
							'step'   => 'installPlugin',
							'source' => self::convertV1ResourceToV2Reference(
								$v1step['pluginZipFile'] ??
								$v1step['pluginData']
							),
						];
						if ( isset( $v1step['progress']['caption'] ) ) {
							// This isn't an exact tranlation but it will do.
							// v1 caption would be "Installing Jetpack"
							// v2 humanReadableName would be just "Jetpack"
							$v2step['humanReadableName'] = $v1step['progress']['caption'];
						}
						// "activate" defaults to true in both v1 and v2.
						if ( isset( $v1step['options']['activate'] ) ) {
							$v2step['active'] = $v1step['options']['activate'];
						}
						if ( isset( $v1step['options']['targetFolderName'] ) ) {
							$v2step['targetDirectoryName'] = $v1step['options']['targetFolderName'];
						}
						$v2steps[] = $v2step;
						break;
					case 'installTheme':
						if ( isset( $v1step['ifAlreadyInstalled'] ) ) {
							$this->logger->warning( sprintf( 'The `ifAlreadyInstalled` option is not yet supported by the v1->v2 Blueprint transpiler and will be ignored: %s. Use the runtime configuration to set the behavior instead.',
								$v1step['ifAlreadyInstalled'] ) );
						}
						if ( isset( $v1step['progress']['weight'] ) ) {
							$this->logger->warning( 'The `progress.weight` option is not supported Blueprint v2 schema and will be ignored: %s. Use the runtime configuration to set the progress bar instead.' );
						}
						$v2step = [
							'step'   => 'installTheme',
							'source' => self::convertV1ResourceToV2Reference(
								$v1step['themeData'] ??
								$v1step['themeZipFile']
							),
						];
						if ( isset( $v1step['progress']['caption'] ) ) {
							$v2step['humanReadableName'] = $v1step['progress']['caption'];
						}
						if ( isset( $v1step['options']['importStarterContent'] ) ) {
							$v2step['importStarterContent'] = $v1step['options']['importStarterContent'];
						}
						// "activate" defaults to true in both v1 and v2.
						if ( isset( $v1step['options']['activate'] ) ) {
							$v2step['active'] = $v1step['options']['activate'];
						}
						if ( isset( $v1step['options']['targetFolderName'] ) ) {
							$v2step['targetDirectoryName'] = $v1step['options']['targetFolderName'];
						}
						$v2steps[] = $v2step;
						break;
					case 'login':
						$this->logger->warning( 'The `login` step is not yet supported by the v1->v2 Blueprint transpiler and will be ignored.' );
						break;
					case 'mkdir':
						if ( isset( $v1step['progress'] ) ) {
							$this->logger->warning( 'The `progress` option on mkDir step is not supported Blueprint v2 schema and will be ignored: %s. Use the runtime configuration to set the progress bar instead.' );
						}
						$v2steps[] = [
							'step' => 'mkdir',
							'path' => self::translatePath( $v1step['path'] ),
						];
						break;
					case 'mv':
						if ( isset( $v1step['progress'] ) ) {
							$this->logger->warning( 'The `progress` option on mv step is not supported Blueprint v2 schema and will be ignored: %s. Use the runtime configuration to set the progress bar instead.' );
						}
						$v2steps[] = [
							'step'     => 'mv',
							'fromPath' => self::translatePath( $v1step['fromPath'] ),
							'toPath'   => self::translatePath( $v1step['toPath'] ),
						];
						break;
					case 'request':
						$this->logger->warning( 'The `request` step was deprecated in Blueprints v1 and is not supported anymore by Blueprints v2. Replace it with a wp-cli step or a runPHP step.' );
						break;
					case 'resetData':
						if ( isset( $v1step['progress'] ) ) {
							$this->logger->warning( 'The `progress` option on resetData step is not supported Blueprint v2 schema and will be ignored: %s. Use the runtime configuration to set the progress bar instead.' );
						}
						$v2steps[] = [
							'step' => 'runPHP',
							'code' => [
								"filename" => "script.php",
								"content"  => <<<'PHP'
<?php
require getenv('DOCROOT') . '/wp-load.php';

$GLOBALS['@pdo']->query('DELETE FROM wp_posts WHERE id > 0');
$GLOBALS['@pdo']->query("UPDATE SQLITE_SEQUENCE SET SEQ=0 WHERE NAME='wp_posts'");

$GLOBALS['@pdo']->query('DELETE FROM wp_postmeta WHERE post_id > 1');
$GLOBALS['@pdo']->query("UPDATE SQLITE_SEQUENCE SET SEQ=20 WHERE NAME='wp_postmeta'");

$GLOBALS['@pdo']->query('DELETE FROM wp_comments');
$GLOBALS['@pdo']->query("UPDATE SQLITE_SEQUENCE SET SEQ=0 WHERE NAME='wp_comments'");

$GLOBALS['@pdo']->query('DELETE FROM wp_commentmeta');
$GLOBALS['@pdo']->query("UPDATE SQLITE_SEQUENCE SET SEQ=0 WHERE NAME='wp_commentmeta'");
PHP
	,

							],
						];
						break;
					case 'rm':
						if ( isset( $v1step['progress'] ) ) {
							$this->logger->warning( 'The `progress` option on rm step is not supported Blueprint v2 schema and will be ignored: %s. Use the runtime configuration to set the progress bar instead.' );
						}
						$v2steps[] = [
							'step' => 'rm',
							'path' => self::translatePath( $v1step['path'] ),
						];
						break;
					case 'rmDir':
						if ( isset( $v1step['progress'] ) ) {
							$this->logger->warning( 'The `progress` option on rmDir step is not supported Blueprint v2 schema and will be ignored: %s. Use the runtime configuration to set the progress bar instead.' );
						}
						$v2steps[] = [
							'step' => 'rmdir',
							'path' => self::translatePath( $v1step['path'] ),
						];
						break;
					case 'runPHP':
						if ( isset( $v1step['progress'] ) ) {
							$this->logger->warning( 'The `progress` option on runPHP step is not supported Blueprint v2 schema and will be ignored: %s. Use the runtime configuration to set the progress bar instead.' );
						}
						$v2steps[] = [
							'step' => 'runPHP',
							'code' => [
								"filename" => "script.php",
								"content"  => self::convertPhpCode( $v1step['code'] ),
							],
						];
						break;
					case 'runSQL':
						if ( isset( $v1step['progress'] ) ) {
							$this->logger->warning( 'The `progress` option on runSQL step is not supported Blueprint v2 schema and will be ignored: %s. Use the runtime configuration to set the progress bar instead.' );
						}
						$v2steps[] = [
							'step'   => 'runSQL',
							'source' => [
								"filename" => "script.sql",
								"content"  => $v1step['sql'],
							],
						];
						break;
					case 'setSiteLanguage':
						if ( isset( $v1step['progress'] ) ) {
							$this->logger->warning( 'The `progress` option on setSiteLanguage step is not supported Blueprint v2 schema and will be ignored: %s. Use the runtime configuration to set the progress bar instead.' );
						}
						$v2steps[] = [
							'step'     => 'setSiteLanguage',
							'language' => $v1step['language'],
						];
						break;
					case 'setSiteOptions':
						if ( isset( $v1step['progress'] ) ) {
							$this->logger->warning( 'The `progress` option on setSiteOptions step is not supported Blueprint v2 schema and will be ignored: %s. Use the runtime configuration to set the progress bar instead.' );
						}
						$v2steps[] = [
							'step'    => 'setSiteOptions',
							'options' => $v1step['options'],
						];
						break;
					case 'unzip':
						if ( isset( $v1step['progress'] ) ) {
							$this->logger->warning( 'The `progress` option on unzip step is not supported Blueprint v2 schema and will be ignored: %s. Use the runtime configuration to set the progress bar instead.' );
						}
						$v2steps[] = [
							'step'          => 'unzip',
							'zipFile'       => self::convertV1ResourceToV2Reference(
								$v1step['zipPath'] ??
								$v1step['zipFile']
							),
							'extractToPath' => self::translatePath( $v1step['extractToPath'] ),
						];
						break;
					case 'updateUserMeta':
						if ( isset( $v1step['progress'] ) ) {
							$this->logger->warning( 'The `progress` option on updateUserMeta step is not supported Blueprint v2 schema and will be ignored: %s. Use the runtime configuration to set the progress bar instead.' );
						}
						$v2steps[] = [
							'step' => 'runPHP',
							'code' => [
								"filename" => "script.php",
								"content"  => <<<'PHP'
<?php
include getenv("DOCROOT") . '/wp-load.php';
$meta = json_decode(getenv("META"), true);
foreach($meta as $name => $value) {
update_user_meta(getenv("USER_ID"), $name, $value);
}
?>
PHP
	,

							],
							'env'  => [
								'USER_ID' => $v1step['userId'] . "",
								'META'    => json_encode( $v1step['meta'] ),
							],
						];
						break;
					case 'writeFile':
						if ( isset( $v1step['progress'] ) ) {
							$this->logger->warning( 'The `progress` option on writeFile step is not supported Blueprint v2 schema and will be ignored: %s. Use the runtime configuration to set the progress bar instead.' );
						}
						$path      = self::translatePath( $v1step['path'] );
						$v2steps[] = [
							'step'  => 'writeFiles',
							'files' => [
								$path => is_string( $v1step['data'] )
									? [
										'filename' => basename( $path ),
										'content'  => str_ends_with($path, '.php') 
											? self::convertPhpCode($v1step['data']) 
											: $v1step['data'],
									]
									: self::convertV1ResourceToV2Reference(
										$v1step['data']
									),
							],
						];
						break;
					case 'writeFiles':
						if ( isset( $v1step['progress'] ) ) {
							$this->logger->warning( 'The `progress` option on writeFiles step is not supported Blueprint v2 schema and will be ignored: %s. Use the runtime configuration to set the progress bar instead.' );
						}
						$v2step = [
							'step'  => 'writeFiles',
							'files' => [],
						];
						// Prefix paths with "writeToPath".
						// The rest of the data format is compliant with v2.
						$base_path = self::translatePath( $v1step['writeToPath'] );
						if(isset($v1step['filesTree']['resource'])) {
							$v2step['files']['/'] = self::convertV1ResourceToV2Reference( $v1step['filesTree'], $base_path );
						} else {
							foreach ( $v1step['filesTree'] as $path => $data ) {
								$joined_path                     = wp_join_unix_paths( $base_path, $path );
								$v2step['files'][ $joined_path ] = is_string( $data )
								? [
									'filename' => basename( $path ),
									'content'  => str_ends_with($path, '.php') 
										? self::convertPhpCode($data) 
										: $data,
								]
								: self::convertV1ResourceToV2Reference(
									$data
								);
							}
						}
						$v2steps[] = $v2step;
						break;
					case 'wp-cli':
						// @TODO: Don't naively replace on the entire command. Actually parse it and only replace at the beginning
						//        of each argument value.
						$cmd = str_replace('/wordpress/', '', $v1step['command']);
						$cmd = str_replace('wordpress/', '', $cmd);
						$v2steps[] = [
							'step'    => 'wp-cli',
							'command' => $cmd,
						];
						break;
					default:
						$this->logger->warning( sprintf( 'The `%s` step is not yet supported by the v1->v2 Blueprint transpiler and will be ignored.',
							$v1step['step'] ) );
						break;
				}
			}
		}
		$v2['additionalStepsAfterExecution'] = $v2steps;

		return $v2;
	}

	protected static function convertV1ResourceToV2Reference( $resource ) {
		if ( is_string( $resource ) ) {
			// Plugin or theme slug, preserve as-is
			return $resource;
		} elseif ( is_array( $resource ) ) {
			if ( ! isset( $resource['resource'] ) ) {
				throw new BlueprintExecutionException( 'Missing resource type in ' . json_encode( $resource ) );
			}
			switch ( $resource['resource'] ) {
				case 'literal':
					// InlineFile
					return [
						'filename' => $resource['name'],
						'content'  => $resource['contents'],
					];
				case 'wordpress.org/themes':
					// WordPressOrgThemeReference
					return $resource['slug'];
				case 'wordpress.org/plugins':
					// WordPressOrgPluginReference
					return $resource['slug'];
				case 'vfs':
					// TargetSitePath
					return 'site:' . $resource['path'];
				case 'url':
					// URLReference
					$url = $resource['url'];
					// If it's a github.com URL, convert to raw.githubusercontent.com like WordPress Playground does
					if (preg_match('#^https://github\.com/([^/]+)/([^/]+)/(?:blob|raw)/(.+)$#', $url, $matches)) {
						// e.g. https://github.com/user/repo/blob/branch/path/to/file
						//      => https://raw.githubusercontent.com/user/repo/branch/path/to/file
						$user = $matches[1];
						$repo = $matches[2];
						$rest = $matches[3];
						// The first segment of $rest is the branch/ref
						$parts = explode('/', $rest, 2);
						$ref = $parts[0];
						$path = isset($parts[1]) ? $parts[1] : '';
						$url = "https://raw.githubusercontent.com/$user/$repo/$ref/$path";
					}
					return $url;
				case 'bundled':
					// BundledReference – must start with
					// ./ or /
					$path = $resource['path'];
					if ( strncmp( $path, './', strlen( './' ) ) !== 0 && strncmp( $path, '/', strlen( '/' ) ) !== 0 ) {
						$path = './' . $path;
					}

					return $path;
				case "literal:directory":
					// InlineDirectory
					$files = [];
					foreach ( $resource['files'] as $name => $file ) {
						if ( is_string( $file ) ) {
							$files[$name] = $file;
						} else {
							$files[$name] = self::convertV1ResourceToV2Reference( $file );
						}
					}
					return [
						'directoryName' => $resource['name'],
						'files'         => $files,
					];
				case "git:directory":
					// GitDirectoryReference
					return [
						'gitRepository'    => $resource['url'],
						'pathInRepository' => $resource['path'],
						'ref'              => $resource['ref'],
					];
				default:
					throw new BlueprintExecutionException( 'Unknown resource type: ' . $resource['resource'] );
			}
		}
	}

	protected static function translatePath( $path ) {
		// V1 Blueprint paths are absolute
		if ( strncmp( $path, '/wordpress/', strlen( '/wordpress/' ) ) === 0 ) {
			return substr( $path, strlen( '/wordpress/' ) );
		}
		if ( strncmp( $path, 'wordpress/', strlen( 'wordpress/' ) ) === 0 ) {
			return substr( $path, strlen( 'wordpress/' ) );
		}

		return $path;
	}

	protected static function convertPhpCode( $code ) {
		$had_php_tag = substr($code, 0, 5) === '<?php';
		if(!$had_php_tag) {
			$code = '<?php ' . $code;
		}
		$tokens        = token_get_all( $code );
		$convertedCode = '';
		foreach ( $tokens as $token ) {
			if ( !is_array( $token ) ) {
				$convertedCode .= $token;
			}
			[ $id, $text ] = $token;
			switch ( $id ) {
				case T_CONSTANT_ENCAPSED_STRING:
					// Support both single and double quoted strings
					$quote = $text[0];
					$unquoted = substr($text, 1, -1);
					if (
						(
							($quote === "'" || $quote === '"')
							&& strncmp($unquoted, '/wordpress/', strlen('/wordpress/')) === 0
						)
					) {
						$convertedCode .= 'getenv(\'DOCROOT\') . ' . var_export(substr($unquoted, strlen('/wordpress')), true);
					} else if (
						(
							($quote === "'" || $quote === '"')
							&& strncmp($unquoted, 'wordpress/', strlen('wordpress/')) === 0
						)
					) {
						$convertedCode .= 'getenv(\'DOCROOT\') . ' . var_export(substr($unquoted, strlen('wordpress')), true);
					} else {
						$convertedCode .= $text;
					}
					break;
				default:
					$convertedCode .= $text;
					break;
			}
		}
		$convertedCode = trim($convertedCode);
		if(!$had_php_tag && substr($convertedCode, 0, 5) === '<?php') {
			$convertedCode = substr( $convertedCode, 5); // Remove the initial '<?php' added for tokenization
		}
		return $convertedCode;
	}


}
