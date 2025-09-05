<?php

namespace WordPress\Blueprints\SiteResolver;

use Exception;
use WordPress\Blueprints\Exception\BlueprintExecutionException;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;
use WordPress\Blueprints\VersionStrings\VersionConstraint;
use WordPress\Blueprints\VersionStrings\WordPressVersion;

class ExistingSiteResolver {
	public static function resolve( Runtime $runtime, Tracker $progress, ?VersionConstraint $wpVersionConstraint = null ) {
		$progress->split(
			array(
				'verify_installation' => 3,
				'check_compatibility' => 3,
				'verify_database'     => 4,
			)
		);

		$config   = $runtime->getConfiguration();
		$targetFs = $runtime->getTargetFilesystem();

		// 1. Verify it's a valid WordPress installation
		$progress['verify_installation']->setCaption( 'Verifying WordPress installation' );
		if ( ! $targetFs->exists( 'wp-load.php' ) ) {
			throw new BlueprintExecutionException(
				'The target site does not appear to be a valid WordPress installation (wp-load.php not found)'
			);
		}

		// Additional check to ensure we can actually load WordPress
		try {
			$result = $runtime->evalPhpCodeInSubProcess(
				'<?php
				require_once(getenv("DOCROOT") . "/wp-load.php");
				$is_installed = function_exists("is_blog_installed") && is_blog_installed() ? "true" : "false";
				append_output("WordPress is installed: " . $is_installed);
				'
			)->outputFileContent;

			if ( $result !== 'WordPress is installed: true' ) {
				throw new BlueprintExecutionException(
					'The target site exists but WordPress is not properly installed or configured'
				);
			}
		} catch ( Exception $e ) {
			throw new BlueprintExecutionException(
				'Failed to load WordPress installation: ' . $e->getMessage()
			);
		}

		$progress['verify_installation']->finish();

		// 2. Check WordPress version compatibility
		$progress['check_compatibility']->setCaption( 'Checking WordPress version compatibility' );
		if ( $wpVersionConstraint ) {
			// Get current WordPress version
			$currentWordPressVersion = WordPressVersion::fromString(
				trim(
					$runtime->evalPhpCodeInSubProcess(
						'<?php
						require_once(getenv("DOCROOT") . "/wp-includes/version.php");
						append_output( $wp_version );
						'
					)->outputFileContent
				)
			);

			if ( ! $wpVersionConstraint->satisfiedBy( $currentWordPressVersion ) ) {
				throw new BlueprintExecutionException(
					sprintf(
						'WordPress version incompatible. Blueprint requires %s, but the site has version %s',
						$wpVersionConstraint->__toString(),
						$currentWordPressVersion->__toString()
					)
				);
			}
		}

		// 3. Check PHP version compatibility (already verified at the Blueprint runner level)
		// See BlueprintRunner::validateBlueprint()

		$progress['check_compatibility']->finish();

		// 4. Verify database engine matches
		$progress['verify_database']->setCaption( 'Verifying database configuration' );
		$requiredEngine = $config->getDatabaseEngine();

		// Check if SQLite integration plugin is active when using SQLite
		if ( $requiredEngine === 'sqlite' ) {
			$sqliteActive = $runtime->evalPhpCodeInSubProcess(
				'<?php
				require_once(getenv("DOCROOT") . "/wp-load.php");
				
				// Check if SQLite integration is active
				$sqlite_plugin = WP_CONTENT_DIR . "/plugins/sqlite-database-integration/load.php";
				$plugin_exists = file_exists($sqlite_plugin);
				
				// Also check for the db.php drop-in
				$is_db_file = file_exists(WP_CONTENT_DIR . "/db.php");                    
				append_output( ($plugin_exists && $is_db_file) ? "true" : "false" );
				'
			)->outputFileContent;

			if ( trim( $sqliteActive ) !== 'true' ) {
				throw new BlueprintExecutionException(
					'The Blueprint requires SQLite database engine, but the site is not using SQLite integration'
				);
			}
		} elseif ( $requiredEngine === 'mysql' ) {
			// For MySQL, verify it's not using SQLite
			$usingMysql = $runtime->evalPhpCodeInSubProcess(
				'<?php
				require_once(getenv("DOCROOT") . "/wp-load.php");
				
				// Check if SQLite integration is NOT active
				$active_plugins = get_option("active_plugins");
				$sqlite_plugin = "sqlite-database-integration/load.php";
				$is_sqlite_active = in_array($sqlite_plugin, $active_plugins);
				
				// Also check for the db.php drop-in
				$is_sqlite_db_file = file_exists(WP_CONTENT_DIR . "/db.php") && 
									strpos(file_get_contents(WP_CONTENT_DIR . "/db.php"), "sqlite") !== false;
				
				// Using MySQL if NOT using SQLite
				append_output( (!$is_sqlite_active && !$is_sqlite_db_file) ? "true" : "false" );
				'
			)->outputFileContent;

			if ( trim( $usingMysql ) !== 'true' ) {
				throw new BlueprintExecutionException(
					'The Blueprint requires MySQL database engine, but the site appears to be using SQLite'
				);
			}
		}

		$progress['verify_database']->finish();
		$progress->finish();
	}
}
