<?php

namespace WordPress\Blueprints\SiteResolver;

use Exception;
use WordPress\Blueprints\Exception\BlueprintExecutionException;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;
use WordPress\Blueprints\VersionStrings\VersionConstraint;
use WordPress\Blueprints\VersionStrings\WordPressVersion;

class ExistingSiteResolver {
	public static function resolve( Runtime $runtime, Tracker $progress, ?VersionConstraint $wp_version_constraint = null ) {
		$progress->split(
			array(
				'verify_installation' => 3,
				'check_compatibility' => 3,
				'verify_database'     => 4,
			)
		);

		$config    = $runtime->get_configuration();
		$target_fs = $runtime->get_target_filesystem();

		// 1. Verify it's a valid WordPress installation.
		$progress['verify_installation']->setCaption( 'Verifying WordPress installation' );

		// Auto-detect the WordPress core directory. Some hosting setups place
		// the WordPress core files (wp-load.php, wp-admin, wp-includes) in a
		// subdirectory while wp-content stays in the web root.
		if ( ! $target_fs->exists( 'wp-load.php' ) ) {
			$detected_core_dir = self::detect_wordpress_core_dir( $config->get_target_site_root() );
			if ( null !== $detected_core_dir ) {
				$config->set_wordpress_core_dir( $detected_core_dir );
			} else {
				throw new BlueprintExecutionException(
					'The target site does not appear to be a valid WordPress installation (wp-load.php not found)'
				);
			}
		}

		// Additional check to ensure we can actually load WordPress.
		try {
			$result = $runtime->eval_php_code_in_subprocess(
				'<?php
				require_once(getenv("WP_CORE_DIR") . "/wp-load.php");
				$is_installed = function_exists("is_blog_installed") && is_blog_installed() ? "true" : "false";
				append_output("WordPress is installed: " . $is_installed);
				'
			)->output_file_content;

			if ( 'WordPress is installed: true' !== $result ) {
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

		// 2. Check WordPress version compatibility.
		$progress['check_compatibility']->setCaption( 'Checking WordPress version compatibility' );
		if ( $wp_version_constraint ) {
			// Get current WordPress version.
			$current_wordpress_version = WordPressVersion::fromString(
				trim(
					$runtime->eval_php_code_in_subprocess(
						'<?php
						require_once(getenv("WP_CORE_DIR") . "/wp-includes/version.php");
						append_output( $wp_version );
						'
					)->output_file_content
				)
			);

			if ( ! $wp_version_constraint->satisfied_by( $current_wordpress_version ) ) {
				throw new BlueprintExecutionException(
					sprintf(
						'WordPress version incompatible. Blueprint requires %s, but the site has version %s',
						$wp_version_constraint->__toString(),
						$current_wordpress_version->__toString()
					)
				);
			}
		}

		// 3. Check PHP version compatibility (already verified at the Blueprint runner level).
		// See BlueprintRunner::validate_blueprint().

		$progress['check_compatibility']->finish();

		// 4. Verify database engine matches.
		$progress['verify_database']->setCaption( 'Verifying database configuration' );
		$required_engine = $config->get_database_engine();

		// Check if SQLite integration plugin is active when using SQLite.
		if ( 'sqlite' === $required_engine ) {
			$sqlite_active = $runtime->eval_php_code_in_subprocess(
				'<?php
				require_once(getenv("WP_CORE_DIR") . "/wp-load.php");
				
				// Check if SQLite integration is active
				$sqlite_plugin = WP_CONTENT_DIR . "/plugins/sqlite-database-integration/load.php";
				$plugin_exists = file_exists($sqlite_plugin);
				
				// Also check for the db.php drop-in
				$is_db_file = file_exists(WP_CONTENT_DIR . "/db.php");                    
				append_output( ($plugin_exists && $is_db_file) ? "true" : "false" );
				'
			)->output_file_content;

			if ( 'true' !== trim( $sqlite_active ) ) {
				throw new BlueprintExecutionException(
					'The Blueprint requires SQLite database engine, but the site is not using SQLite integration'
				);
			}
		} elseif ( 'mysql' === $required_engine ) {
			// For MySQL, verify it's not using SQLite.
			$using_mysql = $runtime->eval_php_code_in_subprocess(
				'<?php
				require_once(getenv("WP_CORE_DIR") . "/wp-load.php");
				
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
			)->output_file_content;

			if ( 'true' !== trim( $using_mysql ) ) {
				throw new BlueprintExecutionException(
					'The Blueprint requires MySQL database engine, but the site appears to be using SQLite'
				);
			}
		}

		$progress['verify_database']->finish();
		$progress->finish();
	}

	/**
	 * Scans the web root for a WordPress core directory. Some hosting
	 * setups place the core files in a subdirectory while wp-content/
	 * stays in the web root.
	 *
	 * @param  string $web_root Absolute path to the web root.
	 *
	 * @return string|null Absolute path to the WordPress core directory, or
	 *                     null when wp-load.php cannot be found anywhere.
	 */
	public static function detect_wordpress_core_dir( string $web_root ): ?string {
		// Standard layout: wp-load.php is in the web root itself.
		if ( file_exists( $web_root . '/wp-load.php' ) ) {
			return $web_root;
		}

		// Scan immediate subdirectories for wp-load.php. This covers the
		// Check immediate subdirectories for any single-level split layout.
		$entries = @scandir( $web_root );
		if ( false === $entries ) {
			return null;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$candidate = $web_root . '/' . $entry;
			if ( is_dir( $candidate ) && file_exists( $candidate . '/wp-load.php' ) ) {
				return $candidate;
			}
		}

		return null;
	}
}
