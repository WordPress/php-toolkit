<?php
declare(strict_types=1);

namespace WordPress\Svn\Tests;

use WordPress\Svn\SvnDiffApplier;
use WordPress\Svn\SvnEditor;

/**
 * An SvnEditor that records the drive it receives: the ordered list of
 * editor events, the decoded file contents, and the property changes.
 * Used to assert on what the session classes emit.
 */
class RecordingEditor implements SvnEditor {
	/**
	 * @var string[]
	 */
	public $events = array();

	/**
	 * @var array Path => decoded file contents.
	 */
	public $files = array();

	/**
	 * @var array Path => property name => value.
	 */
	public $properties = array();

	/**
	 * @var int|null
	 */
	public $target_revision;

	/**
	 * @var SvnDiffApplier[]
	 */
	private $appliers = array();

	/**
	 * @var array Pristine contents handed to appliers, path => string.
	 */
	private $bases;

	public function __construct( $bases = array() ) {
		$this->bases = $bases;
	}

	public function set_target_revision( $revision ) {
		$this->target_revision = $revision;
		$this->events[]        = "target-revision: {$revision}";
	}

	public function open_root() {
		$this->events[] = 'open-root';
	}

	public function add_directory( $path ) {
		$this->events[] = "add-directory: {$path}";
	}

	public function open_directory( $path ) {
		$this->events[] = "open-directory: {$path}";
	}

	public function change_directory_property( $path, $name, $value ) {
		$this->properties[ $path ][ $name ] = $value;
		$this->events[] = "change-directory-property: {$path} {$name}";
	}

	public function close_directory( $path ) {
		$this->events[] = "close-directory: {$path}";
	}

	public function add_file( $path ) {
		$this->events[] = "add-file: {$path}";
	}

	public function open_file( $path ) {
		$this->events[] = "open-file: {$path}";
	}

	public function change_file_property( $path, $name, $value ) {
		$this->properties[ $path ][ $name ] = $value;
		$this->events[] = "change-file-property: {$path} {$name}";
	}

	public function apply_textdelta( $path, $base_checksum ) {
		$this->appliers[ $path ] = new SvnDiffApplier( isset( $this->bases[ $path ] ) ? $this->bases[ $path ] : '' );
	}

	public function write_textdelta_chunk( $path, $svndiff_bytes ) {
		$this->appliers[ $path ]->append_bytes( $svndiff_bytes );
	}

	public function textdelta_end( $path ) {
		$this->appliers[ $path ]->finish();
		$this->files[ $path ] = $this->appliers[ $path ]->get_target();
		unset( $this->appliers[ $path ] );
	}

	public function close_file( $path, $text_checksum ) {
		$checksum_state = null === $text_checksum ? 'no checksum' : (
			isset( $this->files[ $path ] ) && md5( $this->files[ $path ] ) === $text_checksum ? 'checksum ok' : 'CHECKSUM MISMATCH'
		);
		$this->events[] = "close-file: {$path} ({$checksum_state})";
	}

	public function delete_entry( $path ) {
		$this->events[] = "delete-entry: {$path}";
	}

	public function close_edit() {
		$this->events[] = 'close-edit';
	}

	public function abort_edit() {
		$this->events[] = 'abort-edit';
	}
}
