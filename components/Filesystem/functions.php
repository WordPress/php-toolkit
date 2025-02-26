<?php

namespace WordPress\Filesystem;

function ls_recursive( Filesystem $filesystem, $path = '/' ) {
	$tree = array();
	foreach ( $filesystem->ls( $path ) as $item ) {
		if ( $filesystem->is_dir( $item ) ) {
			$tree[] = array(
				'name' => $item,
				'type' => 'dir',
				'children' => ls_recursive( $filesystem, $item ),
			);
		} else {
			$tree[] = array(
				'name' => $item,
				'type' => 'file',
			);
		}
	}
	return $tree;
}

function copy_between_filesystems( array $args ) {
	/**
	 * @var Filesystem $source
	 * @var Filesystem $destination
	 */
	$source           = $args['source_filesystem'];
	$source_path      = $args['source_path'] ?? '/';
	$destination      = $args['target_filesystem'];
	$destination_path = $args['target_path'] ?? '/';
	$recursive        = $args['recursive'] ?? true;

	if ( $source->is_file( $source_path ) ) {
		$to_stream = $destination->open_write_stream( $destination_path );
		try {
			$from_stream = $source->open_read_stream( $source_path );
			try {
				$chunks_written = 0;
				while ( ! $from_stream->reached_end_of_data() ) {
					$available = $from_stream->pull( 65536 );
					$to_stream->append_bytes( $from_stream->consume( $available ), $to_stream );
					++$chunks_written;
				}
				if ( $chunks_written === 0 ) {
					// Make sure the file receives at least one chunk
					// so we can be sure it gets created in case the
					// destination filesystem is lazy.
					$to_stream->append_bytes( '' );
				}
			} finally {
				$from_stream->close_reading();
			}
		} finally {
			$to_stream->close_writing();
		}
	} elseif ( $source->is_dir( $source_path ) ) {
		if ( ! $recursive ) {
			throw new FilesystemException( 'Cannot copy a directory. Set the option `recursive` to true to copy directories recursively.' );
		}
		if ( ! $destination->is_dir( $destination_path ) ) {
			$destination->mkdir(
				$destination_path,
				array(
					'recursive' => true,
				)
			);
		}
		foreach ( $source->ls( $source_path ) as $item ) {
			copy_between_filesystems(
				array(
					'source_filesystem' => $source,
					'source_path' => wp_join_paths( $source_path, $item ),
					'target_filesystem' => $destination,
					'target_path' => wp_join_paths( $destination_path, $item ),
				)
			);
		}
	} else {
		throw new FilesystemException( 'Path does not exist in the source filesystem: ' . $source_path );
	}
}

function wp_path_segments( $path ) {
	$canonicalized   = wp_canonicalize_path( $path );
	$without_slashes = trim( $canonicalized, '/' );
	return explode( '/', $without_slashes );
}

function wp_parent_paths( $path, $options = array() ) {
	$include_self = $options['include_self'] ?? false;
	$path         = '/' . trim( $path, '/' );
	$segments     = wp_path_segments( $path );
	$paths        = array( '/' );
	yield '/';
	for ( $i = 0; $i < count( $segments ) - 1; $i++ ) {
		$paths[] = $segments[ $i ];
		yield wp_join_paths( ...$paths );
	}
	if ( $include_self ) {
		yield $path;
	}
}

/**
 * Joins multiple path segments together into a single path.
 *
 * Removes any double slashes between path segments.
 */
function wp_join_paths( ...$path_segments ) {
	$paths = array();
	foreach ( $path_segments as $path_segment ) {
		if ( $path_segment !== '' ) {
			$paths[] = $path_segment;
		}
	}
	$path = implode( '/', $paths );

	return preg_replace( '#/+#', '/', $path );
}

/**
 * Cleans up a file path.
 *
 * - Ensures it starts with a forward slash
 * - Removes the /./ segments
 * - Flattens the /../ segments
 *
 * Example:
 *
 * wp_canonicalize_path( 'foo/bar/../baz' ) => '/foo/baz'
 *
 * @param string $path The file path that needs cleaning up
 * @return string The cleaned, absolute path
 */
function wp_canonicalize_path( $path ) {
	// Convert to absolute path
	if ( ! str_starts_with( $path, '/' ) ) {
		$path = '/' . $path;
	}

	// Resolve . and ..
	$parts      = explode( '/', $path );
	$normalized = array();
	foreach ( $parts as $part ) {
		if ( $part === '.' || $part === '' ) {
			continue;
		}
		if ( $part === '..' ) {
			array_pop( $normalized );
			continue;
		}
		$normalized[] = $part;
	}

	// Reconstruct path
	$result = '/' . implode( '/', $normalized );
	if ( $result === '/.' ) {
		$result = '/';
	}
	return $result === '' ? '/' : $result;
}

/**
 * Returns the directory name of a path. Like dirname(), but
 * consistent between different operating systems. wp_dirname("/foo")
 * will return "/" whereas dirname("/foo") would return "\\".
 *
 * @param string $path The path to get the directory name of.
 * @return string The directory name of the path.
 */
function wp_dirname( $path ) {
	// @TODO: Scrutinize this naive implementation. Could
	//        we mess things up on Unix when a directory name
	//        legitimately contains a backslash?
	return str_replace( '\\', '/', dirname( $path ) );
}
