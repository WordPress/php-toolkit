<?php

namespace WordPress\Blueprints\Steps;

/**
 * Keeps flat packages independent of the upgrader's temporary directory name.
 */
final class InstallPackageSource {
	const FILTER_SCRIPT = <<<'PHP'
add_filter(
	'upgrader_source_selection',
	static function ( $source, $working_dir, $current_upgrader ) use ( $upgrader, $package_path ) {
		global $wp_filesystem;

		// WordPress has already selected the package's top-level directory, if it has one.
		if ( $upgrader !== $current_upgrader || is_wp_error( $source ) || untrailingslashit( $source ) !== untrailingslashit( $working_dir ) ) {
			return $source;
		}

		$slug = basename( $package_path, '.zip' );
		if ( '' === $slug || '.' === $slug || '..' === $slug ) {
			return new WP_Error( 'invalid_package_slug', 'The package filename must provide an installation directory name.' );
		}

		if ( $slug === basename( untrailingslashit( $working_dir ) ) ) {
			return $source;
		}

		$entries = $wp_filesystem->dirlist( $source );
		if ( false === $entries ) {
			return new WP_Error( 'package_source_read_failed', 'Could not read the unpacked package.' );
		}

		// Keep the stable directory inside WordPress's private staging path. The extra
		// level accommodates packages that already contain an entry named after the slug.
		$staging = trailingslashit( $working_dir ) . 'blueprint-' . bin2hex( random_bytes( 8 ) );
		$package = trailingslashit( $staging ) . $slug;
		if ( ! $wp_filesystem->mkdir( $staging, FS_CHMOD_DIR ) || ! $wp_filesystem->mkdir( $package, FS_CHMOD_DIR ) ) {
			return new WP_Error( 'package_source_mkdir_failed', 'Could not create a staging directory for the package.' );
		}

		foreach ( array_keys( $entries ) as $entry ) {
			if ( ! $wp_filesystem->move( trailingslashit( $source ) . $entry, trailingslashit( $package ) . $entry ) ) {
				return new WP_Error( 'package_source_move_failed', 'Could not move the unpacked package into its staging directory.' );
			}
		}

		return trailingslashit( $package );
	},
	20,
	3
);
PHP;
}
