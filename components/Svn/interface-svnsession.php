<?php

namespace WordPress\Svn;

/**
 * A connection to a Subversion repository.
 *
 * Implementations exist for the svn:// protocol (RaSvnSession) and for
 * http:// and https:// (DavSession). Both expose the same repository
 * primitives so the working copy layer never needs to know which
 * transport is in use.
 *
 * All paths are relative to the session URL and use forward slashes.
 * An empty string denotes the session URL itself. Revisions are
 * integers; passing null means "the latest revision".
 */
interface SvnSession {
	/**
	 * @return string The URL this session was opened at, without credentials.
	 */
	public function get_session_url();

	/**
	 * @return string The repository root URL. Always a prefix of the session URL.
	 */
	public function get_repository_root();

	/**
	 * @return string The repository UUID.
	 */
	public function get_uuid();

	/**
	 * @return int The youngest revision in the repository.
	 */
	public function get_latest_revision();

	/**
	 * Checks what exists at a path.
	 *
	 * @param  string   $path      Path relative to the session URL.
	 * @param  int|null $revision  Revision to inspect, or null for the latest.
	 * @return string One of 'file', 'dir', or 'none'.
	 */
	public function check_path( $path, $revision = null );

	/**
	 * Lists a directory.
	 *
	 * @param  string   $path      Directory path relative to the session URL.
	 * @param  int|null $revision  Revision to inspect, or null for the latest.
	 * @return array[] One entry per child: {
	 *     @type string $name         Child basename.
	 *     @type string $kind         'file' or 'dir'.
	 *     @type int    $size         File size in bytes, 0 for directories.
	 *     @type int    $created_rev  Revision of the last change.
	 * }
	 */
	public function list_directory( $path, $revision = null );

	/**
	 * Fetches a file.
	 *
	 * @param  string   $path      File path relative to the session URL.
	 * @param  int|null $revision  Revision to fetch, or null for the latest.
	 * @return array {
	 *     @type string      $contents    The file contents.
	 *     @type string|null $checksum    MD5 checksum of the contents, when the server provides it.
	 *     @type array       $properties  Versioned properties, name => value.
	 * }
	 */
	public function get_file( $path, $revision = null );

	/**
	 * Fetches the versioned properties of a file or directory.
	 *
	 * @param  string   $path      Path relative to the session URL.
	 * @param  int|null $revision  Revision to inspect, or null for the latest.
	 * @return array Property name => value.
	 */
	public function get_properties( $path, $revision = null );

	/**
	 * Asks the server to drive the editor with the changes needed to
	 * bring the reported working copy state to $target_revision.
	 *
	 * A fresh checkout is an update too: report the root path with
	 * 'start_empty' set to true and the server sends the whole tree.
	 *
	 * @param int       $target_revision  The revision to update to.
	 * @param array[]   $report           One entry per reported path: {
	 *     @type string $path         Path relative to the session URL, '' for the root.
	 *     @type int    $revision     The revision the working copy has.
	 *     @type bool   $start_empty  Whether the path should be treated as empty.
	 * }
	 * @param SvnEditor $editor           Receives the tree delta.
	 * @param array     $options          {
	 *     @type string $depth  'empty', 'files', 'immediates', or 'infinity'. Default 'infinity'.
	 * }
	 */
	public function drive_update( $target_revision, $report, SvnEditor $editor, $options = array() );

	/**
	 * Commits a set of changes as a new revision.
	 *
	 * @param  string  $message     The log message.
	 * @param  array[] $operations  One entry per change, in any order: {
	 *     @type string $op  One of:
	 *         'add-directory'    – { path, properties? }
	 *         'add-file'         – { path, contents, properties? }
	 *         'modify-file'      – { path, contents, base_revision, properties? }
	 *         'modify-properties'– { path, kind ('file'|'dir'), base_revision, properties }
	 *         'delete'           – { path, base_revision? }
	 *     'properties' is a map of property name => value, with null
	 *     meaning "delete this property".
	 * }
	 * @return array {
	 *     @type int    $revision  The new revision number.
	 *     @type string $author    The commit author, when the server reports it.
	 *     @type string $date      The commit timestamp, when the server reports it.
	 * }
	 */
	public function commit( $message, $operations );

	/**
	 * Closes the session and frees the underlying connection.
	 */
	public function close();
}
