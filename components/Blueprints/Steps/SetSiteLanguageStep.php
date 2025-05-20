<?php

namespace WordPress\Blueprints\Steps;

use Exception;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;
use WordPress\HttpClient\Client;
use WordPress\HttpClient\Request;
use WordPress\Zip\ZipFilesystem;

use function WordPress\Filesystem\copy_between_filesystems;

/**
 * Represents the 'setSiteLanguage' step.
 */
class SetSiteLanguageStep implements StepInterface {
	/**
	 * The language code (e.g., 'en_US', 'de_DE').
	 * @var string
	 */
	public $language;

	/**
	 * @param  string  $language  The language code.
	 */
	public function __construct( string $language ) {
		$this->language = $language;
	}

	public function run( Runtime $runtime, Tracker $progress ) {
		$progress->setCaption( 'Translating' );
		$language = $this->language;

		// Define WPLANG constant
		$step = new DefineConstantsStep( [ 'WPLANG' => $language ] );
		$step->run( $runtime, new Tracker() );


		// Create language directories if they don't exist
		$fs                    = $runtime->getTargetFilesystem();
		$languages_dir         = "wp-content/languages";
		$plugins_languages_dir = "{$languages_dir}/plugins";
		$themes_languages_dir  = "{$languages_dir}/themes";

		if ( ! $fs->is_dir( $languages_dir ) ) {
			$fs->mkdir( $languages_dir, 0755, true );
		}
		if ( ! $fs->is_dir( $plugins_languages_dir ) ) {
			$fs->mkdir( $plugins_languages_dir, 0755, true );
		}
		if ( ! $fs->is_dir( $themes_languages_dir ) ) {
			$fs->mkdir( $themes_languages_dir, 0755, true );
		}

		// Get core translation package URL
		$wp_version = trim( $runtime->evalPhpCodeInSubProcess(
			'<?php
            require getenv("DOCROOT") . "/wp-includes/version.php";
            append_output( $wp_version );
            '
		)->outputFileContent );

		// Get plugin translations
		$plugins_data = json_decode( $runtime->evalPhpCodeInSubProcess(
			"<?php
            require_once(getenv('DOCROOT') . '/wp-load.php');
            require_once(getenv('DOCROOT') . '/wp-admin/includes/plugin.php');
            append_output(
				json_encode(
					array_values(
						array_map(
							function(\$plugin) {
								return [
									'slug'    => \$plugin['TextDomain'],
									'version' => \$plugin['Version']
								];
							},
							array_filter(
								get_plugins(),
								function(\$plugin) {
									return !empty(\$plugin['TextDomain']);
								}
							)
						)
					)
				)
			);"
		)->outputFileContent, true );

		// Get theme translations
		$themes_data = json_decode( $runtime->evalPhpCodeInSubProcess(
			"<?php
            require_once(getenv('DOCROOT') . '/wp-load.php');
            require_once(getenv('DOCROOT') . '/wp-admin/includes/theme.php');
            append_output(
				json_encode(
					array_values(
						array_map(
							function(\$theme) {
								return [
									'slug'    => \$theme->get('TextDomain'),
									'version' => \$theme->get('Version')
								];
							},
							wp_get_themes()
						)
					)
				)
			);"
		)->outputFileContent, true );

		$client = $runtime->getHttpClient();

		// Prepare all download URLs
		$download_targets = [];

		// Core translation
		if ( $language === 'en_US' ) {
			$core_translation_url = $this->getWordPressTranslationUrl( $runtime, $wp_version, $language, $client );
			if ( $core_translation_url ) {
				$download_targets[] = [
					'request'    => new Request( $core_translation_url ),
					'target_dir' => $languages_dir,
					'name'       => "core-{$language}",
				];
			}
		}

		// Plugin translations
		if ( is_array( $plugins_data ) ) {
			foreach ( $plugins_data as $plugin ) {
				if ( empty( $plugin['slug'] ) || empty( $plugin['version'] ) ) {
					continue;
				}

				$plugin_translation_url = "https://downloads.wordpress.org/translation/plugin/{$plugin['slug']}/{$plugin['version']}/{$language}.zip";
				$download_targets[]     = [
					'request'    => new Request( $plugin_translation_url ),
					'target_dir' => $plugins_languages_dir,
					'name'       => "plugin-{$plugin['slug']}-{$language}",
					'is_plugin'  => true,
					'slug'       => $plugin['slug'],
				];
			}
		}

		// Theme translations
		if ( is_array( $themes_data ) ) {
			foreach ( $themes_data as $theme ) {
				if ( empty( $theme['slug'] ) || empty( $theme['version'] ) ) {
					continue;
				}

				$theme_translation_url = "https://downloads.wordpress.org/translation/theme/{$theme['slug']}/{$theme['version']}/{$language}.zip";
				$download_targets[]    = [
					'request'    => new Request( $theme_translation_url ),
					'target_dir' => $themes_languages_dir,
					'name'       => "theme-{$theme['slug']}-{$language}",
					'is_theme'   => true,
					'slug'       => $theme['slug'],
				];
			}
		}

		// Download all translations in parallel
		$progress->split( count( $download_targets ) );
		foreach ( $download_targets as $k => $target ) {
			$progress[ $k ]->setCaption( 'Fetching translations for ' . $target['name'] );
			$download_targets[ $k ]['stream'] = $client->fetch( $target['request'], [
				// @see Runtime for more details on these options
				'progress_tracker' => $progress[ $k ],
				'eagerly_enqueue'  => true,
				'buffer_size'      => 100 * 1024 * 1024,
			] );
		}

		foreach ( $download_targets as $target ) {
			try {
				$zipFs = ZipFilesystem::create( $target['stream'] );
				copy_between_filesystems( [
					'source_filesystem' => $zipFs,
					'source_path'       => '/',
					'target_filesystem' => $runtime->getTargetFilesystem(),
					'target_path'       => $target['target_dir'],
					'recursive'         => true,
				] );
			} catch ( Exception $e ) {
				/**
				 * Log warnings for missing plugin and theme translations. This is an expected
				 * scenario. Not all plugins and themes are translated to all languages.
				 */
				if ( isset( $target['is_plugin'] ) ) {
					$runtime->getLogger()->warning(
						sprintf(
							"Warning: Failed to download translations for plugin %s: %s",
							$target['slug'],
							$e->getMessage()
						)
					);
				} elseif ( isset( $target['is_theme'] ) ) {
					$runtime->getLogger()->warning(
						sprintf(
							"Warning: Failed to download translations for theme %s: %s",
							$target['slug'],
							$e->getMessage()
						)
					);
				} else {
					/**
					 * Stop the execution of the blueprint if we fail to download core translations.
					 * WordPress core is expected to be translated to all languages. If a translation
					 * is missing, why use it in the Blueprint?
					 */
					throw new Exception( "Failed to download core translations: " . $e->getMessage(), 0, $e );
				}
			}
		}
	}

	/**
	 * Get the translation package URL for a given WordPress version and language.
	 *
	 * @param  string  $wpVersion  WordPress version
	 * @param  string  $language  Language code
	 *
	 * @return string|false
	 */
	private function getWordPressTranslationUrl( Runtime $runtime, string $wpVersion, string $language, Client $client ) {
		try {
			$api_url           = "https://api.wordpress.org/translations/core/1.0/?version={$wpVersion}";
			$translations_data = $client->fetch( $api_url )->json();

			if ( ! isset( $translations_data['translations'] ) || ! is_array( $translations_data['translations'] ) ) {
				throw new Exception( "Invalid response from WordPress.org translations API" );
			}

			foreach ( $translations_data['translations'] as $translation ) {
				if ( strtolower( $translation['language'] ) === strtolower( $language ) ) {
					return $translation['package'];
				}
			}
		} catch ( Exception $e ) {
			$runtime->getLogger()->warning( "Warning: Failed to fetch translations details from WordPress.org API: " . $e->getMessage() );
		}

		return false;
	}
}
