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
	static public function resolve( Runtime $runtime, Tracker $progress, ?VersionConstraint $wp_version_constraint = null, ?string $recommended_wp_version = 'latest' ) {
		$progress->split( [
			'resolve_assets'    => 2,
			'install_wordpress' => 1,
		] );

		// Ensure document root directory exists (LocalFilesystem::create creates it)
		$target_fs = $runtime->getTargetFilesystem();
		if ( count( $target_fs->ls( '/' ) ) > 0 ) {
			throw new BlueprintExecutionException( 'The target site root directory must be empty in the create-new-site mode, but it wasn\'t.' );
		}

		// Unzip WordPress core into document root
		$wp_zip = self::resolveWordPressZipUrl( $runtime->getHttpClient(), $recommended_wp_version );

		$assets = [
			'wordpress' => DataReference::create( $wp_zip ),
		];
		if ( $runtime->getConfiguration()->getDatabaseEngine() === 'sqlite' ) {
			$assets['sqlite-integration'] = $runtime->getConfiguration()->getSqliteIntegrationPlugin();
		}

		$runtime->getDataReferenceResolver()->startEagerResolution( $assets, $progress['resolve_assets'] );

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

		copy_between_filesystems( [
			'source_filesystem' => $zip_fs,
			'source_path'       => $path_in_zip,
			'target_filesystem' => $target_fs,
			'target_path'       => '/',
			'recursive'         => true,
		] );

		$progress['install_wordpress']->set( 0.6, 'Installing WordPress' );

		// If SQLite integration zip provided, unzip into appropriate folder
		if ( $runtime->getConfiguration()->getDatabaseEngine() === 'sqlite' ) {
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
			copy_between_filesystems( [
				'source_filesystem' => $zip_fs,
				'source_path'       => $source_path,
				'target_filesystem' => $target_fs,
				'target_path'       => $target_path,
				'recursive'         => true,
			] );

			$target_fs->copy(
				wp_join_unix_paths( $target_path, 'db.copy' ),
				'/wp-content/db.php'
			);
		}

		// 3. Install WordPress if not installed yet.
		//    Technically, this is a "new site" resolver, but it's entirely possible
		//    the developer-provided WordPress zip already has a sqlite database with the
		//    a WordPress site installed..
		if ( ! self::isWordPressInstalled( $runtime, $progress ) ) {
			if ( ! $target_fs->exists( '/wp-config.php' ) ) {
				if ( $target_fs->exists( 'wp-config-sample.php' ) ) {
					$target_fs->copy( 'wp-config-sample.php', 'wp-config.php' );
				} else {
					throw new BlueprintExecutionException( 'Neither wp-config.php, nor wp-config-sample.php was found in the WordPress archive.' );
				}
			}

			// Perform installation using WP-CLI
			// @TODO (low priority): Remove the WP-CLI dependency to lower the download size for blueprints.phar.
			$progress['install_wordpress']->set( 0.7, 'Installing WordPress' );
			$wp_cli_path = $runtime->getWpCliPath();
			$process = $runtime->startShellCommand( [
				'php',
				$wp_cli_path,
				'core',
				'install',
				'--path=' . $runtime->getConfiguration()->getTargetSiteRoot(),

				// For Docker compatibility. If we got this far, Blueprint runner was already
				// allowed to run as root.
				'--allow-root',
				'--url=' . $runtime->getConfiguration()->getTargetSiteUrl(),
				'--title=WordPress Site',
				'--admin_user=admin',
				'--admin_password=password',
				'--admin_email=admin@example.com',
				'--skip-email',
			] );
			$process->mustRun();

			if ( ! self::isWordPressInstalled( $runtime, $progress ) ) {
				// @TODO: This breaks in Playground CLI
				throw new BlueprintExecutionException( 'WordPress installation failed' );
			}
		}
		$progress->finish();
	}

	static private function isWordPressInstalled( Runtime $runtime, Tracker $progress ) {
		$install_check = $runtime->evalPhpCodeInSubProcess(
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
			[
				'DOCROOT' => $runtime->getConfiguration()->getTargetSiteRoot(),
			],
			null,
			5
		)->output_file_content;

		return trim( $install_check ) === '1';
	}

	static private function resolveWordPressZipUrl( Client $client, string $version_string ): string {
		if ( $version_string === 'latest' ) {
			return 'https://wordpress.org/latest.zip';
		}

		if (
			strncmp( $version_string, 'https://', strlen( 'https://' ) ) === 0 ||
			strncmp( $version_string, 'http://', strlen( 'http://' ) ) === 0
		) {
			return $version_string;
		}

		if ( $version_string === 'nightly' ) {
			return 'https://wordpress.org/nightly-builds/wordpress-latest.zip';
		}

		$latest_versions = $client->fetch( new Request( 'https://api.wordpress.org/core/version-check/1.7/?channel=beta' ) )->json();

		$latest_versions = array_filter( $latest_versions['offers'], function ( $v ) {
			return $v['response'] === 'autoupdate';
		} );
		$latest_non_beta = null;
		
		foreach ( $latest_versions as $api_version ) {
			// Keep track of the first non-beta version (which is the latest)
			if ( $latest_non_beta === null && strpos( $api_version['version'], 'beta' ) === false ) {
				$latest_non_beta = $api_version;
			}
			
			if ( $version_string === 'beta' && strpos( $api_version['version'], 'beta' ) !== false ) {
				return $api_version['download'];
			} elseif (
				$version_string === 'latest' &&
				strpos( $api_version['version'], 'beta' ) === false
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
				// version, e.g. "6.6"
				return $api_version['download'];
			}
		}
		
		// If we're looking for beta but no beta was found, return latest
		if ( $version_string === 'beta' && $latest_non_beta !== null ) {
			return $latest_non_beta['download'];
		}

		/**
		 * If we didn't get a useful match in the API response, it could be version that's not
		 * the latest in its channel. Let's assume that if the versioning scheme seems to fit
		 * that hypothesis.
		 */
		if(preg_match('/^\d+\.\d+\.\d+$/', $version_string)) {
			return 'https://downloads.wordpress.org/release/wordpress-' . $version_string . '.zip';
		}

		throw new BlueprintExecutionException(
			sprintf( 'Invalid WordPress version constraint: %s', $version_string )
		);
	}
}
