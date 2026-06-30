<?php

namespace WordPress\Svn;

use WordPress\Filesystem\Filesystem;

/**
 * A Subversion working copy: a directory tree on a filesystem plus the
 * `.svn` administrative area that tracks where it came from.
 *
 * The administrative format is specific to this component – it is NOT
 * compatible with the SQLite-based `.svn` format the official client
 * uses, in either direction. Metadata lives in `.svn/wc.json` and
 * pristine (as-checked-out) file contents live in `.svn/pristine/`,
 * keyed by their MD5 checksum.
 *
 * Pristine copies are what make offline `status` and conflict detection
 * possible: a file is locally modified when its content differs from
 * its pristine copy.
 *
 * Entries are keyed by forward-slash paths relative to the working copy
 * root; the root directory itself is the entry with the key ''.
 */
class SvnWorkingCopy {
	const ADMIN_DIR = '.svn';
	const FORMAT    = 1;

	/**
	 * Working copies of svn:externals are nested inside their parent
	 * working copy. Limit how deep externals may nest to break cycles
	 * where repositories reference each other.
	 */
	const MAX_EXTERNALS_DEPTH = 5;

	/**
	 * @var Filesystem
	 */
	private $filesystem;

	/**
	 * Absolute path of the working copy root within $filesystem.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Decoded wc.json contents.
	 *
	 * @var array
	 */
	private $data;

	private function __construct( Filesystem $filesystem, $root, $data ) {
		$this->filesystem = $filesystem;
		$this->root       = rtrim( $root, '/' );
		$this->data       = $data;
	}

	/**
	 * Creates a fresh administrative area for a new checkout.
	 *
	 * @param  Filesystem $filesystem  The filesystem to check out onto.
	 * @param  string     $root        Absolute path of the working copy root.
	 * @param  array      $info        {
	 *     @type string $url              The checked-out URL.
	 *     @type string $repository_root  The repository root URL.
	 *     @type string $uuid             The repository UUID.
	 *     @type int    $revision         The checked-out revision.
	 *     @type string $depth            The checkout depth.
	 * }
	 * @return SvnWorkingCopy
	 */
	public static function initialize( Filesystem $filesystem, $root, $info ) {
		$data = array(
			'format'          => self::FORMAT,
			'url'             => rtrim( $info['url'], '/' ),
			'repository_root' => rtrim( $info['repository_root'], '/' ),
			'uuid'            => $info['uuid'],
			'revision'        => $info['revision'],
			'depth'           => isset( $info['depth'] ) ? $info['depth'] : 'infinity',
			'entries'         => array(
				'' => array(
					'kind'     => 'dir',
					'revision' => $info['revision'],
				),
			),
			'externals'       => array(),
		);

		$working_copy = new SvnWorkingCopy( $filesystem, $root, $data );
		$filesystem->mkdir( $working_copy->get_admin_path( 'pristine' ), array( 'recursive' => true ) );
		$working_copy->save();

		return $working_copy;
	}

	/**
	 * Opens an existing working copy.
	 *
	 * @param  Filesystem $filesystem  The filesystem holding the working copy.
	 * @param  string     $root        Absolute path of the working copy root.
	 * @return SvnWorkingCopy
	 * @throws SvnException When $root is not a working copy created by this component.
	 */
	public static function open( Filesystem $filesystem, $root ) {
		$root      = rtrim( $root, '/' );
		$json_path = $root . '/' . self::ADMIN_DIR . '/wc.json';
		if ( ! $filesystem->is_file( $json_path ) ) {
			throw new SvnException( "'{$root}' is not a php-toolkit Subversion working copy (no .svn/wc.json found)." );
		}
		$data = json_decode( $filesystem->get_contents( $json_path ), true );
		if ( ! is_array( $data ) || ! isset( $data['format'] ) ) {
			throw new SvnException( "The working copy metadata at '{$json_path}' is corrupted." );
		}
		if ( self::FORMAT !== $data['format'] ) {
			throw new SvnException( "Unsupported working copy format {$data['format']}." );
		}

		return new SvnWorkingCopy( $filesystem, $root, $data );
	}

	/**
	 * @param  Filesystem $filesystem  The filesystem to inspect.
	 * @param  string     $root        The path to inspect.
	 * @return bool Whether the path is a working copy created by this component.
	 */
	public static function is_working_copy( Filesystem $filesystem, $root ) {
		return $filesystem->is_file( rtrim( $root, '/' ) . '/' . self::ADMIN_DIR . '/wc.json' );
	}

	/**
	 * Persists the metadata to .svn/wc.json.
	 */
	public function save() {
		$this->filesystem->put_contents(
			$this->get_admin_path( 'wc.json' ),
			json_encode( $this->data )
		);
	}

	public function get_filesystem() {
		return $this->filesystem;
	}

	public function get_root() {
		return $this->root;
	}

	public function get_url() {
		return $this->data['url'];
	}

	public function get_repository_root() {
		return $this->data['repository_root'];
	}

	public function get_uuid() {
		return $this->data['uuid'];
	}

	public function get_revision() {
		return $this->data['revision'];
	}

	public function set_revision( $revision ) {
		$this->data['revision'] = $revision;
	}

	public function get_depth() {
		return isset( $this->data['depth'] ) ? $this->data['depth'] : 'infinity';
	}

	/**
	 * @return array Map of external target path (relative to the working
	 *               copy root) => array{url, revision}.
	 */
	public function get_externals() {
		$externals = array();
		foreach ( isset( $this->data['externals'] ) ? $this->data['externals'] : array() as $path => $external ) {
			$externals[ svn_normalize_relative_path( $path, false ) ] = $external;
		}

		return $externals;
	}

	public function set_externals( $externals ) {
		$normalized = array();
		foreach ( $externals as $path => $external ) {
			$normalized[ svn_normalize_relative_path( $path, false ) ] = $external;
		}
		$this->data['externals'] = $normalized;
	}

	/**
	 * @param  string $path  Entry path relative to the root, '' for the root itself.
	 * @return array|null The entry, or null when the path is not versioned.
	 */
	public function get_entry( $path ) {
		$path = svn_normalize_relative_path( $path );

		return isset( $this->data['entries'][ $path ] ) ? $this->data['entries'][ $path ] : null;
	}

	public function set_entry( $path, $entry ) {
		$path = svn_normalize_relative_path( $path );

		$this->data['entries'][ $path ] = $entry;
	}

	public function remove_entry( $path ) {
		$path = svn_normalize_relative_path( $path );
		unset( $this->data['entries'][ $path ] );
	}

	/**
	 * @return array All entries, keyed by relative path.
	 */
	public function get_entries() {
		$entries = array();
		foreach ( $this->data['entries'] as $path => $entry ) {
			$entries[ svn_normalize_relative_path( $path ) ] = $entry;
		}

		return $entries;
	}

	/**
	 * @param  string $path  Directory entry path, '' for the root.
	 * @return string[] Paths of all entries strictly below $path.
	 */
	public function get_entry_paths_under( $path ) {
		$path   = svn_normalize_relative_path( $path );
		$prefix = '' === $path ? '' : $path . '/';
		$found  = array();
		foreach ( $this->get_entries() as $entry_path => $entry ) {
			if ( '' !== $entry_path && ( '' === $prefix || 0 === strpos( $entry_path, $prefix ) ) ) {
				$found[] = $entry_path;
			}
		}

		return $found;
	}

	/**
	 * @param  string $relative_path  Path relative to the working copy root.
	 * @return string The absolute path within the filesystem.
	 */
	public function get_disk_path( $relative_path ) {
		$relative_path = svn_normalize_relative_path( $relative_path );

		return '' === $relative_path ? $this->root : $this->root . '/' . $relative_path;
	}

	/**
	 * @param  string $name  A file name inside the administrative area.
	 * @return string The absolute path within the filesystem.
	 */
	public function get_admin_path( $name ) {
		return $this->root . '/' . self::ADMIN_DIR . '/' . $name;
	}

	/**
	 * Stores pristine file contents, keyed by checksum.
	 *
	 * @param  string $contents  Repository-normal-form contents.
	 * @return string The MD5 checksum the contents are stored under.
	 */
	public function store_pristine( $contents ) {
		$checksum = md5( $contents );
		$path     = $this->get_admin_path( 'pristine/' . $checksum );
		if ( ! $this->filesystem->is_file( $path ) ) {
			$this->filesystem->put_contents( $path, $contents );
		}

		return $checksum;
	}

	/**
	 * @param  string $checksum  The MD5 checksum returned by store_pristine().
	 * @return string The pristine contents.
	 * @throws SvnException When no pristine with this checksum exists.
	 */
	public function read_pristine( $checksum ) {
		$path = $this->get_admin_path( 'pristine/' . $checksum );
		if ( ! $this->filesystem->is_file( $path ) ) {
			throw new SvnException( "The working copy is corrupted: missing pristine {$checksum}." );
		}

		return $this->filesystem->get_contents( $path );
	}

	/**
	 * Reads a working file and converts it back to repository normal
	 * form so it can be compared against pristines and committed.
	 *
	 * @param  string $path   Entry path relative to the root.
	 * @param  array  $entry  The entry, used for its properties.
	 * @return string The file contents in repository normal form.
	 */
	public function read_working_file( $path, $entry ) {
		$contents = $this->filesystem->get_contents( $this->get_disk_path( $path ) );

		return self::translate_from_disk( $contents, isset( $entry['props'] ) ? $entry['props'] : array() );
	}

	/**
	 * Writes repository-normal-form contents to a working file, applying
	 * the svn:eol-style translation the properties ask for.
	 *
	 * @param string $path        Entry path relative to the root.
	 * @param string $contents    Repository-normal-form contents.
	 * @param array  $properties  The file's versioned properties.
	 */
	public function write_working_file( $path, $contents, $properties ) {
		$this->filesystem->put_contents(
			$this->get_disk_path( $path ),
			self::translate_to_disk( $contents, $properties )
		);
	}

	/**
	 * Checks whether a working file differs from its pristine copy.
	 *
	 * @param  string $path   Entry path relative to the root.
	 * @param  array  $entry  The file's entry.
	 * @return bool Whether the file is locally modified.
	 */
	public function is_file_modified( $path, $entry ) {
		if ( ! isset( $entry['checksum'] ) ) {
			return true;
		}
		if ( ! $this->filesystem->is_file( $this->get_disk_path( $path ) ) ) {
			return false;
		}

		$working = $this->read_working_file( $path, $entry );
		if ( md5( $working ) === $entry['checksum'] ) {
			return false;
		}
		if ( ! isset( $entry['props']['svn:eol-style'] ) ) {
			return true;
		}

		// The pristine of an eol-styled file may use line endings that
		// differ from the style's canonical ones (e.g. a 'native' file
		// committed with CRLF by a non-translating client). Compare the
		// normalized forms before declaring the file modified – a plain
		// checkout must never look locally modified.
		$properties = isset( $entry['props'] ) ? $entry['props'] : array();

		return self::translate_from_disk( $this->read_pristine( $entry['checksum'] ), $properties ) !== $working;
	}

	/**
	 * Computes the status of every entry and unversioned path.
	 *
	 * Externals are reported with the status 'external' and their
	 * contents are not descended into – they are independent working
	 * copies. Unmodified entries are not reported at all.
	 *
	 * @return array Map of relative path => one of 'modified', 'added',
	 *               'deleted', 'missing', 'conflicted', 'unversioned',
	 *               'external', 'obstructed'.
	 */
	public function get_status() {
		$status    = array();
		$externals = $this->get_externals();

		foreach ( $this->data['entries'] as $path => $entry ) {
			if ( '' === $path ) {
				continue;
			}
			$schedule = isset( $entry['schedule'] ) ? $entry['schedule'] : null;
			if ( 'add' === $schedule ) {
				$status[ $path ] = 'added';
				continue;
			}
			if ( 'delete' === $schedule ) {
				$status[ $path ] = 'deleted';
				continue;
			}
			if ( ! empty( $entry['conflict'] ) ) {
				$status[ $path ] = 'conflicted';
				continue;
			}
			$disk_path = $this->get_disk_path( $path );
			if ( 'dir' === $entry['kind'] ) {
				if ( ! $this->filesystem->is_dir( $disk_path ) ) {
					$status[ $path ] = $this->filesystem->is_file( $disk_path ) ? 'obstructed' : 'missing';
				}
				continue;
			}
			if ( ! $this->filesystem->is_file( $disk_path ) ) {
				$status[ $path ] = $this->filesystem->is_dir( $disk_path ) ? 'obstructed' : 'missing';
				continue;
			}
			if ( $this->is_file_modified( $path, $entry ) ) {
				$status[ $path ] = 'modified';
			}
		}

		foreach ( $externals as $external_path => $external ) {
			$status[ $external_path ] = 'external';
		}

		// Conflict artifacts (the `<name>.r<revision>` files) are part of
		// the conflict bookkeeping, not unversioned files.
		$conflict_files = array();
		foreach ( $this->get_entries() as $entry ) {
			if ( isset( $entry['conflict_file'] ) ) {
				$conflict_files[ $entry['conflict_file'] ] = true;
			}
		}

		$this->collect_unversioned( '', $status, $externals, $conflict_files );
		ksort( $status );

		return $status;
	}

	/**
	 * Recursively finds paths on disk that have no entry.
	 *
	 * @param string $directory_path  Directory to walk, relative to the root.
	 * @param array  $status          Status map to add findings to.
	 * @param array  $externals       External definitions to skip over.
	 * @param array  $conflict_files  Conflict artifact paths to skip over.
	 */
	private function collect_unversioned( $directory_path, &$status, $externals, $conflict_files ) {
		$disk_path = $this->get_disk_path( $directory_path );
		if ( ! $this->filesystem->is_dir( $disk_path ) ) {
			return;
		}
		foreach ( $this->filesystem->ls( $disk_path ) as $name ) {
			if ( self::ADMIN_DIR === $name ) {
				continue;
			}
			$child_path = '' === $directory_path ? $name : $directory_path . '/' . $name;
			if ( isset( $externals[ $child_path ] ) || isset( $conflict_files[ $child_path ] ) ) {
				continue;
			}
			$entry = $this->get_entry( $child_path );
			if ( null === $entry ) {
				if ( ! isset( $status[ $child_path ] ) ) {
					$status[ $child_path ] = 'unversioned';
				}
				continue;
			}
			if ( 'dir' === $entry['kind'] ) {
				$this->collect_unversioned( $child_path, $status, $externals, $conflict_files );
			}
		}
	}

	/**
	 * Applies svn:eol-style translation for writing a file to disk.
	 *
	 * For the fixed styles (CRLF, CR, LF) Subversion stores the file in
	 * the repository with exactly that line ending, so checked-out files
	 * normally pass through unchanged – the translation only repairs
	 * files whose endings drifted. 'native' files are stored with LF and
	 * this component uses LF as the native line ending on every
	 * platform, mirroring its forward-slashes-everywhere policy.
	 *
	 * @param  string $contents    Repository contents.
	 * @param  array  $properties  The file's versioned properties.
	 * @return string The translated contents.
	 */
	public static function translate_to_disk( $contents, $properties ) {
		return self::normalize_eol( $contents, $properties );
	}

	/**
	 * Normalizes a working file for comparing against pristines and for
	 * committing. The mapping is the same as translate_to_disk(): both
	 * the working file and the repository use the style's line ending,
	 * so editing a CRLF file with an LF-only editor does not produce a
	 * whole-file diff.
	 *
	 * @param  string $contents    On-disk contents.
	 * @param  array  $properties  The file's versioned properties.
	 * @return string The contents in their canonical form.
	 */
	public static function translate_from_disk( $contents, $properties ) {
		return self::normalize_eol( $contents, $properties );
	}

	/**
	 * Converts all line endings to the canonical ending of the file's
	 * svn:eol-style. Files without the property are returned untouched.
	 *
	 * @param  string $contents    The file contents.
	 * @param  array  $properties  The file's versioned properties.
	 * @return string The normalized contents.
	 */
	private static function normalize_eol( $contents, $properties ) {
		if ( ! isset( $properties['svn:eol-style'] ) ) {
			return $contents;
		}
		switch ( trim( $properties['svn:eol-style'] ) ) {
			case 'CRLF':
				return preg_replace( "/\r\n|\r|\n/", "\r\n", $contents );
			case 'CR':
				return preg_replace( "/\r\n|\r|\n/", "\r", $contents );
			case 'LF':
			case 'native':
				return preg_replace( "/\r\n|\r|\n/", "\n", $contents );
			default:
				return $contents;
		}
	}
}
