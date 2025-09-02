<?php

namespace WordPress\Blueprints\SiteResolver;

use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;
use WordPress\Blueprints\Steps\DefineConstantsStep;
use WordPress\Blueprints\Exception\BlueprintExecutionException;

class WordPressInstaller {
	/**
	 * Install WordPress core without relying on WP-CLI.
	 *
	 * Supported options (defaults shown):
	 * - 'site_url'      => $runtime->getConfiguration()->getTargetSiteUrl()
	 * - 'title'         => 'WordPress Site'
	 * - 'admin_user'    => 'admin'
	 * - 'admin_password'=> 'password'
	 * - 'admin_email'   => 'admin@example.com'
	 * - 'skip_email'    => true
	 */
	public function install( Runtime $runtime, Tracker $tracker, array $options = [] ): void {
		$targetFs = $runtime->getTargetFilesystem();
		$tracker->set( 0.65, 'Preparing WordPress installation' );

		// Ensure wp-config.php exists
		if ( ! $targetFs->exists( '/wp-config.php' ) ) {
			if ( $targetFs->exists( 'wp-config-sample.php' ) ) {
				$targetFs->copy( 'wp-config-sample.php', 'wp-config.php' );
			} else {
				throw new BlueprintExecutionException( 'Neither wp-config.php, nor wp-config-sample.php was found in the WordPress archive.' );
			}
		}

		// Define DB constants according to configuration
		$dbEngine  = $runtime->getConfiguration()->getDatabaseEngine();
		$dbCreds   = $runtime->getConfiguration()->getDatabaseCredentials();
		$constants = [];
		if ( $dbEngine === 'mysql' ) {
			$constants = [
				'DB_NAME'     => $dbCreds['databaseName'] ?? 'wordpress',
				'DB_USER'     => $dbCreds['username'] ?? 'root',
				'DB_PASSWORD' => $dbCreds['password'] ?? '',
				'DB_HOST'     => $dbCreds['host'] ?? '127.0.0.1',
			];
		} elseif ( $dbEngine === 'sqlite' ) {
			// Prefer canonical default used elsewhere in the runner
			$dbPath = $dbCreds['path'] ?? 'wp-content/.ht.sqlite';
			if ($dbPath === '' || $dbPath === null) {
				$dbPath = 'wp-content/.ht.sqlite';
			}
			$constants = [ 'DB_NAME' => $dbPath ];

			// Pre-create the database directory to avoid cross‑platform path issues
			$targetFs = $runtime->getTargetFilesystem();
			$relativeDbPath = '/' . ltrim( $dbPath, '/' );
			$dbDir = dirname( $relativeDbPath );
			if ( ! $targetFs->is_dir( $dbDir ) ) {
				$targetFs->mkdir( $dbDir, 0755, true );
			}
			// Best effort to ensure file exists and is writable (SQLite will create as needed)
			if ( ! $targetFs->exists( $relativeDbPath ) ) {
				try { $targetFs->put_contents( $relativeDbPath, '' ); } catch ( \Throwable $e ) { /* ignore */ }
			}

			// Ensure SQLite extension availability for clearer errors on macOS/Windows
			if ( ! extension_loaded('sqlite3') && ! extension_loaded('pdo_sqlite') ) {
				throw new BlueprintExecutionException(
					'SQLite database engine selected, but neither sqlite3 nor pdo_sqlite PHP extension is loaded. '
					. 'Enable one of these extensions or switch --db-engine to mysql.'
				);
			}
		}
		if ( ! empty( $constants ) ) {
			(new DefineConstantsStep( $constants ))->run( $runtime, $tracker );
		}

		// Prepare installation options
		$siteUrl      = $options['site_url'] ?? $runtime->getConfiguration()->getTargetSiteUrl();
		$title        = $options['title'] ?? 'WordPress Site';
		$adminUser    = $options['admin_user'] ?? 'admin';
		$adminPass    = $options['admin_password'] ?? 'password';
		$adminEmail   = $options['admin_email'] ?? 'admin@example.com';
		$skipEmail    = (bool) ( $options['skip_email'] ?? true );

		$tracker->set( 0.7, 'Installing WordPress' );
		$runtime->evalPhpCodeInSubProcess(
			<<<'PHP'
			<?php
			$docroot     = getenv('DOCROOT');
			$site_url    = getenv('SITE_URL');
			$title       = getenv('TITLE');
			$admin_user  = getenv('ADMIN_USER');
			$admin_pass  = getenv('ADMIN_PASS');
			$admin_email = getenv('ADMIN_EMAIL');
			$skip_email  = getenv('SKIP_EMAIL') === '1';
			$host = null; $port = null; $scheme = null;
			if ($site_url) {
				$parts = @parse_url($site_url);
				$host = $parts['host'] ?? null;
				$port = $parts['port'] ?? null;
				$scheme = $parts['scheme'] ?? null;
			}

			if (!file_exists($docroot . '/wp-load.php')) {
				fwrite(STDERR, "Blueprint Error: wp-load.php not found in DOCROOT\n");
				exit(1);
			}

			// Ensure WordPress runs in installing context and suppress emails reliably
			if (!defined('WP_INSTALLING')) {
				define('WP_INSTALLING', true);
			}
			if ($site_url && !defined('WP_HOME')) {
				define('WP_HOME', rtrim($site_url, '/'));
			}
			if ($site_url && !defined('WP_SITEURL')) {
				define('WP_SITEURL', rtrim($site_url, '/'));
			}
			// Normalize web server globals to avoid platform-specific behavior
			if ($host) {
				$_SERVER['HTTP_HOST'] = $host . ($port ? ":$port" : '');
				$_SERVER['SERVER_NAME'] = $host;
			}
			if ($scheme) {
				$_SERVER['HTTPS'] = ($scheme === 'https') ? 'on' : 'off';
				$_SERVER['SERVER_PORT'] = ($scheme === 'https') ? '443' : '80';
				$_SERVER['REQUEST_SCHEME'] = $scheme;
			}
			if (!isset($_SERVER['REQUEST_URI'])) {
				$_SERVER['REQUEST_URI'] = '/';
			}
			if (!isset($_SERVER['SERVER_PROTOCOL'])) {
				$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
			}
			if (!isset($_SERVER['REMOTE_ADDR'])) {
				$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
			}
			if (!isset($_SERVER['SCRIPT_NAME'])) {
				$_SERVER['SCRIPT_NAME'] = '/index.php';
			}
			if (!isset($_SERVER['DOCUMENT_ROOT'])) {
				$_SERVER['DOCUMENT_ROOT'] = $docroot;
			}
			if (!isset($_SERVER['SCRIPT_FILENAME'])) {
				$_SERVER['SCRIPT_FILENAME'] = $docroot . '/index.php';
			}
			require $docroot . '/wp-load.php';
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			if ($skip_email) {
				// Short-circuit wp_mail completely (introduced in WP 5.5)
				if (function_exists('add_filter')) {
					add_filter('pre_wp_mail', '__return_false');
					add_filter('send_password_change_email', '__return_false');
				}
			}
			wp_install($title, $admin_user, $admin_email, /*public*/ true, '', $admin_pass);
			if ($site_url) {
				$site_url = rtrim($site_url, '/');
				update_option('siteurl', $site_url);
				update_option('home', $site_url);
			}
			if (function_exists('flush_rewrite_rules')) {
				flush_rewrite_rules(false);
			}
			PHP
			,
			[
				'DOCROOT'     => $runtime->getConfiguration()->getTargetSiteRoot(),
				'SITE_URL'    => $siteUrl,
				'TITLE'       => $title,
				'ADMIN_USER'  => $adminUser,
				'ADMIN_PASS'  => $adminPass,
				'ADMIN_EMAIL' => $adminEmail,
				'SKIP_EMAIL'  => $skipEmail ? '1' : '0',
			]
		);
	}
}


