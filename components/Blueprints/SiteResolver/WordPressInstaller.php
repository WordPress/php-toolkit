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
			$constants = [
				'DB_NAME' => $dbCreds['path'] ?? 'wp.db',
			];
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

			if (!file_exists($docroot . '/wp-load.php')) {
				fwrite(STDERR, "Blueprint Error: wp-load.php not found in DOCROOT\n");
				exit(1);
			}

			require $docroot . '/wp-load.php';
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			if ($skip_email) {
				add_filter('wp_mail', '__return_false');
				add_filter('send_password_change_email', '__return_false');
			}
			wp_install($title, $admin_user, $admin_email, /*public*/ true, '', $admin_pass);
			if ($site_url) {
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


