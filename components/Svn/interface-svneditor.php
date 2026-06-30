<?php

namespace WordPress\Svn;

/**
 * Receiver of a Subversion tree delta.
 *
 * The "editor" is Subversion's core abstraction: a checkout or an update
 * is the server walking the client through a series of tree operations –
 * add this directory, change that file, delete this entry. Both the
 * svn:// protocol and the HTTP update-report express their responses as
 * editor drives; the session classes translate the wire format into
 * calls on this interface.
 *
 * All paths are relative to the session URL and use forward slashes.
 * File contents arrive as svndiff deltas (see SvnDiffApplier) between
 * apply_textdelta() and textdelta_end() calls.
 */
interface SvnEditor {
	/**
	 * Announces the revision the drive will take the client to.
	 *
	 * @param int $revision  The target revision number.
	 */
	public function set_target_revision( $revision );

	/**
	 * Opens the root of the edited tree. Always the first tree call.
	 */
	public function open_root();

	/**
	 * Adds a new directory.
	 *
	 * @param string $path  Directory path relative to the session URL.
	 */
	public function add_directory( $path );

	/**
	 * Opens an existing directory because something below it changes.
	 *
	 * @param string $path  Directory path relative to the session URL.
	 */
	public function open_directory( $path );

	/**
	 * Sets or deletes a property on a directory.
	 *
	 * @param string      $path   Directory path relative to the session URL ('' for the root).
	 * @param string      $name   Property name, e.g. "svn:externals".
	 * @param string|null $value  New property value, or null to delete the property.
	 */
	public function change_directory_property( $path, $name, $value );

	/**
	 * Closes a directory opened with add_directory() or open_directory().
	 *
	 * @param string $path  Directory path relative to the session URL.
	 */
	public function close_directory( $path );

	/**
	 * Adds a new file. Content arrives via the textdelta calls.
	 *
	 * @param string $path  File path relative to the session URL.
	 */
	public function add_file( $path );

	/**
	 * Opens an existing file whose contents and/or properties change.
	 *
	 * @param string $path  File path relative to the session URL.
	 */
	public function open_file( $path );

	/**
	 * Sets or deletes a property on the currently open file.
	 *
	 * @param string      $path   File path relative to the session URL.
	 * @param string      $name   Property name, e.g. "svn:eol-style".
	 * @param string|null $value  New property value, or null to delete the property.
	 */
	public function change_file_property( $path, $name, $value );

	/**
	 * Starts the content transmission for a file.
	 *
	 * @param string      $path           File path relative to the session URL.
	 * @param string|null $base_checksum  Expected MD5 of the delta base, or null
	 *                                    when the delta applies to an empty file.
	 */
	public function apply_textdelta( $path, $base_checksum );

	/**
	 * Delivers a chunk of the svndiff document for a file.
	 *
	 * @param string $path           File path relative to the session URL.
	 * @param string $svndiff_bytes  Raw svndiff bytes – chunk boundaries are arbitrary.
	 */
	public function write_textdelta_chunk( $path, $svndiff_bytes );

	/**
	 * Ends the content transmission for a file.
	 *
	 * @param string $path  File path relative to the session URL.
	 */
	public function textdelta_end( $path );

	/**
	 * Closes a file opened with add_file() or open_file().
	 *
	 * @param string      $path           File path relative to the session URL.
	 * @param string|null $text_checksum  MD5 of the full resulting text, when the server provides it.
	 */
	public function close_file( $path, $text_checksum );

	/**
	 * Deletes a file or directory.
	 *
	 * @param string $path  Path relative to the session URL.
	 */
	public function delete_entry( $path );

	/**
	 * Ends a successful drive.
	 */
	public function close_edit();

	/**
	 * Ends a failed drive. The working copy may be in a partial state.
	 */
	public function abort_edit();
}
