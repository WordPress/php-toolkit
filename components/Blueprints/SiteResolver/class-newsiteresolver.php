<?php

namespace WordPress\Blueprints\SiteResolver;

use WordPress\Blueprints\DataReference\DataReference;
use WordPress\Blueprints\DataReference\File;
use WordPress\Blueprints\Exception\BlueprintExecutionException;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;
use WordPress\Blueprints\VersionStrings\VersionConstraint;
use WordPress\HttpClient\Client;
use WordPress\HttpClient\Request;
use WordPress\Zip\ZipFilesystem;

use function WordPress\Filesystem\copy_between_filesystems;
use function WordPress\Filesystem\wp_join_unix_paths;

class NewSiteResolver {
	public static function resolve( Runtime $runtime, Tracker $progress, ?VersionConstraint $wp_version_constraint = null, ?string $recommended_wp_version = 'latest' ) {
		$progress->split(
			array(
				'resolve_assets'    => 2,
				'install_wordpress' => 1,
			)
		);

		// Ensure document root directory exists (LocalFilesystem::create creates it).
		$target_fs = $runtime->get_target_filesystem();
		if ( count( $target_fs->ls( '/' ) ) > 0 ) {
			throw new BlueprintExecutionException( 'The target site root directory must be empty in the create-new-site mode, but it wasn\'t.' );
		}

		// Unzip WordPress core into document root.
		$wp_zip = self::resolve_wordpress_zip_url( $runtime->get_http_client(), $recommended_wp_version );

		$assets = array(
			'wordpress' => DataReference::create( $wp_zip ),
		);
		if ( 'sqlite' === $runtime->get_configuration()->get_database_engine() ) {
			$assets['sqlite-integration'] = $runtime->get_configuration()->get_sqlite_integration_plugin();
		}

		$runtime->get_data_reference_resolver()->start_eager_resolution( $assets, $progress['resolve_assets'] );

		$progress['resolve_assets']->setCaption( 'Downloading WordPress' );

		$resolved = $runtime->resolve( $assets['wordpress'] );
		if ( ! $resolved instanceof File ) {
			throw new BlueprintExecutionException( 'Provided zip reference does not resolve to a file' );
		}
		$zip_fs = ZipFilesystem::create( $resolved->getStream() );

		$path_in_zip = '/';
		if ( ! $zip_fs->exists( '/wp-content' ) && $zip_fs->exists( '/wordpress' ) ) {
			$path_in_zip = '/wordpress';
		}

		$progress['install_wordpress']->set( 0.2, 'Setting up WordPress files' );

		copy_between_filesystems(
			array(
				'source_filesystem' => $zip_fs,
				'source_path'       => $path_in_zip,
				'target_filesystem' => $target_fs,
				'target_path'       => '/',
				'recursive'         => true,
			)
		);

		$progress['install_wordpress']->set( 0.6, 'Installing WordPress' );

		// If SQLite integration zip provided, unzip into appropriate folder.
		if ( 'sqlite' === $runtime->get_configuration()->get_database_engine() ) {
			/*
			 * @TODO: Ensure DB_NAME gets defined in wp-config.php before installing the SQLite plugin.
			 */

			$progress['resolve_assets']->setCaption( 'Downloading SQLite integration plugin' );
			$resolved = $runtime->resolve( $assets['sqlite-integration'] );
			if ( ! $resolved instanceof File ) {
				throw new BlueprintExecutionException( 'Provided zip reference does not resolve to a file' );
			}
			$zip_fs = ZipFilesystem::create( $resolved->getStream() );

			$target_path = '/wp-content/plugins/sqlite-database-integration';
			$source_path = '/';
			if ( $zip_fs->exists( 'sqlite-database-integration' ) ) {
				$source_path = '/sqlite-database-integration';
			}
			copy_between_filesystems(
				array(
					'source_filesystem' => $zip_fs,
					'source_path'       => $source_path,
					'target_filesystem' => $target_fs,
					'target_path'       => $target_path,
					'recursive'         => true,
				)
			);

			$target_fs->copy(
				wp_join_unix_paths( $target_path, 'db.copy' ),
				'/wp-content/db.php'
			);
		}

		// 3. Install WordPress if not installed yet.
		// Technically, this is a "new site" resolver, but it's entirely possible
		// the developer-provided WordPress zip already has a sqlite database with
		// a WordPress site installed.
		if ( ! self::is_wordpress_installed( $runtime, $progress ) ) {
			if ( ! $target_fs->exists( '/wp-config.php' ) ) {
				if ( $target_fs->exists( 'wp-config-sample.php' ) ) {
					$target_fs->copy( 'wp-config-sample.php', 'wp-config.php' );
				} else {
					throw new BlueprintExecutionException( 'Neither wp-config.php, nor wp-config-sample.php was found in the WordPress archive.' );
				}
			}

			// Perform installation using WP-CLI.
			// @TODO (low priority): Remove the WP-CLI dependency to lower the download size for blueprints.phar.
			$progress['install_wordpress']->set( 0.7, 'Installing WordPress' );
			$wp_cli_path = $runtime->get_wp_cli_path();
			$process     = $runtime->start_shell_command(
				array(
					'php',
					$wp_cli_path,
					'core',
					'install',
					'--path=' . $runtime->get_configuration()->get_target_site_root(),

					// For Docker compatibility. If we got this far, Blueprint runner was already
					// allowed to run as root.
					'--allow-root',
					'--url=' . $runtime->get_configuration()->get_target_site_url(),
					'--title=WordPress Site',
					'--admin_user=admin',
					'--admin_password=password',
					'--admin_email=admin@example.com',
					'--skip-email',
				)
			);
			$process->mustRun();

			if ( ! self::is_wordpress_installed( $runtime, $progress ) ) {
				// @TODO: This breaks in Playground CLI.
				throw new BlueprintExecutionException( 'WordPress installation failed' );
			}
		}
		$progress->finish();
	}

	private static function is_wordpress_installed( Runtime $runtime, Tracker $progress ) {
		$install_check = $runtime->eval_php_code_in_subprocess(
			<<<'PHP'
<?php
		$wp_load = getenv('DOCROOT') . '/wp-load.php';
		if (!file_exists($wp_load)) {
			append_output('0');
			exit;
		}
		require $wp_load;

		append_output( function_exists('is_blog_installed') && is_blog_installed() ? '1' : '0' );
PHP
			,
			array(
				'DOCROOT' => $runtime->get_configuration()->get_target_site_root(),
			),
			null,
			5
		)->output_file_content;

		return '1' === trim( $install_check );
	}

	private static function resolve_wordpress_zip_url( Client $client, string $version_string ): string {
		if ( 'latest' === $version_string ) {
			return 'https://wordpress.org/latest.zip';
		}

		if (
			0 === strncmp( $version_string, 'https://', strlen( 'https://' ) ) ||
			0 === strncmp( $version_string, 'http://', strlen( 'http://' ) )
		) {
			return $version_string;
		}

		if ( 'nightly' === $version_string ) {
			return 'https://wordpress.org/nightly-builds/wordpress-latest.zip';
		}

		$latest_versions = $client->fetch( new Request( 'https://api.wordpress.org/core/version-check/1.7/?channel=beta' ) )->json();

		$latest_versions = array_filter(
			$latest_versions['offers'],
			function ( $v ) {
				return 'autoupdate' === $v['response'];
			}
		);
		$latest_non_beta = null;

		foreach ( $latest_versions as $api_version ) {
			// Keep track of the first non-beta version (which is the latest).
			if ( null === $latest_non_beta && false === strpos( $api_version['version'], 'beta' ) ) {
				$latest_non_beta = $api_version;
			}

			if ( 'beta' === $version_string && false !== strpos( $api_version['version'], 'beta' ) ) {
				return $api_version['download'];
			} elseif (
				'latest' === $version_string &&
				false === strpos( $api_version['version'], 'beta' )
			) {
				// The first non-beta item in the list is the latest version.
				return $api_version['download'];
			} elseif (
				substr( $api_version['version'], 0, strlen( $version_string ) ) ===
				$version_string
			) {
				return $api_version['download'];
			} elseif (
				preg_match( '/^\d+\.\d+$/', $version_string ) &&
				$version_string === $api_version['partial_version']
			) {
				// When the Blueprint provides a version like 6.6, we must match on the partial
				// version, e.g. "6.6".
				return $api_version['download'];
			}
		}

		// If we're looking for beta but no beta was found, return latest.
		if ( 'beta' === $version_string && null !== $latest_non_beta ) {
			return $latest_non_beta['download'];
		}

		/**
		 * If we didn't get a useful match in the API response, it could be version that's not
		 * the latest in its channel. Let's assume that if the versioning scheme seems to fit
		 * that hypothesis.
		 */
		if ( preg_match( '/^\d+\.\d+\.\d+$/', $version_string ) ) {
			return 'https://downloads.wordpress.org/release/wordpress-' . $version_string . '.zip';
		}

		throw new BlueprintExecutionException(
			sprintf( 'Invalid WordPress version constraint: %s', $version_string )
		);
	}
}
