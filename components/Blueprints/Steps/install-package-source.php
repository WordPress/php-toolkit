<?php

namespace WordPress\Blueprints\Steps;

use WP_Error;

/**
 * Stabilizes the install directory of a flat plugin or theme package.
 *
 * The WordPress upgrader derives the destination name from the selected
 * source directory. Recheck this hook when core changes how it selects
 * the source or names the destination.
 *
 * @internal Loaded only by the WordPress install subprocess.
 */
function stabilize_package_source( $source, $working_dir, $package_path ) {
	global $wp_filesystem;

	// WordPress has already selected the package's top-level directory, if it has one.
	if ( is_wp_error( $source ) || untrailingslashit( $source ) !== untrailingslashit( $working_dir ) ) {
		return $source;
	}

	$slug = basename( $package_path, '.zip' );
	if ( '' === $slug || '.' === $slug || '..' === $slug ) {
		return new WP_Error( 'invalid_package_slug', 'The package filename must provide an installation directory name.' );
	}

	// Older upgraders already name the unpack directory after the package.
	if ( basename( untrailingslashit( $working_dir ) ) === $slug ) {
		return $source;
	}

	$entries = $wp_filesystem->dirlist( $source );
	if ( false === $entries ) {
		return new WP_Error( 'package_source_read_failed', 'Could not read the unpacked package.' );
	}

	// Keep the stable directory inside WordPress's randomized staging path. The extra
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
}
