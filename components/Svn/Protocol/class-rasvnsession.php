<?php

namespace WordPress\Svn\Protocol;

use WordPress\Svn\SvnDiff;
use WordPress\Svn\SvnEditor;
use WordPress\Svn\SvnException;
use WordPress\Svn\SvnSession;

/**
 * Subversion repository access over the svn:// protocol (ra_svn).
 *
 * Speaks the wire protocol documented in Subversion's
 * subversion/libsvn_ra_svn/protocol file:
 *
 *  1. The server greets with the protocol versions and capabilities it
 *     supports, the client responds with its own version, capabilities,
 *     and the URL it wants to talk about.
 *  2. The server requests authentication. ANONYMOUS and CRAM-MD5
 *     mechanisms are supported here, which is what stock svnserve
 *     installations offer (SASL mechanisms are not implemented).
 *  3. Commands flow as `( command-name ( args ) )` tuples. Every
 *     command response is preceded by an auth request, which is almost
 *     always the trivial "no authentication needed" form.
 *
 * Reads (checkout/update) arrive as an "editor drive": the server sends
 * a stream of tree-change commands which this class dispatches onto an
 * SvnEditor. Writes (commits) are the mirror image: this class drives
 * the server's editor with the local changes.
 */
class RaSvnSession implements SvnSession {
	/**
	 * @var RaSvnConnection
	 */
	private $connection;

	/**
	 * @var string
	 */
	private $url;

	/**
	 * @var string|null
	 */
	private $username;

	/**
	 * @var string|null
	 */
	private $password;

	/**
	 * @var string
	 */
	private $repository_root;

	/**
	 * @var string
	 */
	private $uuid;

	/**
	 * Counter backing allocate_token().
	 *
	 * @var int
	 */
	private $next_token = 0;

	private function __construct( RaSvnConnection $connection, $url, $username, $password ) {
		$this->connection = $connection;
		$this->url        = $url;
		$this->username   = $username;
		$this->password   = $password;
	}

	/**
	 * Opens a session against an svn:// URL.
	 *
	 * @param  string $url      Repository URL, e.g. "svn://example.com/repo/trunk".
	 * @param  array  $options  {
	 *     @type string $username            Username for CRAM-MD5 authentication.
	 *     @type string $password            Password for CRAM-MD5 authentication.
	 *     @type int    $timeout_ms          Socket read timeout. Default 60000.
	 *     @type int    $connect_timeout_ms  Connection timeout. Default 10000.
	 * }
	 * @return RaSvnSession
	 * @throws SvnException When the URL is invalid, the server is unreachable,
	 *                      or the handshake fails.
	 */
	public static function connect( $url, $options = array() ) {
		$parts = parse_url( $url );
		if ( false === $parts || ! isset( $parts['scheme'], $parts['host'] ) || 'svn' !== $parts['scheme'] ) {
			throw new SvnException( "Not a valid svn:// URL: {$url}" );
		}

		$username = isset( $options['username'] ) ? $options['username'] : ( isset( $parts['user'] ) ? rawurldecode( $parts['user'] ) : null );
		$password = isset( $options['password'] ) ? $options['password'] : ( isset( $parts['pass'] ) ? rawurldecode( $parts['pass'] ) : null );

		$port          = isset( $parts['port'] ) ? $parts['port'] : 3690;
		$path          = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
		$canonical_url = 'svn://' . $parts['host'] . ( 3690 === $port ? '' : ':' . $port ) . $path;

		$connection = RaSvnConnection::connect( $parts['host'], $port, $options );
		$session    = new RaSvnSession( $connection, $canonical_url, $username, $password );
		$session->handshake();

		return $session;
	}

	public function get_session_url() {
		return $this->url;
	}

	public function get_repository_root() {
		return $this->repository_root;
	}

	public function get_uuid() {
		return $this->uuid;
	}

	public function get_latest_revision() {
		$this->send_command( 'get-latest-rev', '' );
		$params = $this->read_command_response();

		return $params[0]->get_number();
	}

	public function check_path( $path, $revision = null ) {
		$this->send_command(
			'check-path',
			RaSvnConnection::encode_string( $path ) . ' ' . $this->encode_optional_revision( $revision )
		);
		$params = $this->read_command_response();

		return $params[0]->get_word();
	}

	public function list_directory( $path, $revision = null ) {
		$this->send_command(
			'get-dir',
			RaSvnConnection::encode_string( $path ) . ' ' .
			$this->encode_optional_revision( $revision ) .
			' false true ( kind size has-props created-rev time last-author )'
		);
		$params = $this->read_command_response();

		$entries = array();
		foreach ( $params[2]->get_list() as $entry_item ) {
			$entry     = $entry_item->get_list();
			$entries[] = array(
				'name'        => $entry[0]->get_string(),
				'kind'        => $entry[1]->get_word(),
				'size'        => $entry[2]->get_number(),
				'created_rev' => $entry[4]->get_number(),
			);
		}

		return $entries;
	}

	public function get_file( $path, $revision = null ) {
		$this->send_command(
			'get-file',
			RaSvnConnection::encode_string( $path ) . ' ' . $this->encode_optional_revision( $revision ) . ' true true'
		);
		$params   = $this->read_command_response();
		$checksum = $params[0]->get_optional();
		$contents = '';
		while ( true ) {
			$chunk = $this->connection->read_item()->get_string();
			if ( '' === $chunk ) {
				break;
			}
			$contents .= $chunk;
		}
		// The content stream is followed by a closing command response.
		$this->read_response_tuple();

		return array(
			'contents'   => $contents,
			'checksum'   => null === $checksum ? null : $checksum->get_string(),
			'properties' => $this->parse_versioned_properties( $params[2] ),
		);
	}

	public function get_properties( $path, $revision = null ) {
		$kind = $this->check_path( $path, $revision );
		if ( 'dir' === $kind ) {
			$this->send_command(
				'get-dir',
				RaSvnConnection::encode_string( $path ) . ' ' . $this->encode_optional_revision( $revision ) . ' true false ( )'
			);
			$params = $this->read_command_response();

			return $this->parse_versioned_properties( $params[1] );
		}
		if ( 'file' === $kind ) {
			$this->send_command(
				'get-file',
				RaSvnConnection::encode_string( $path ) . ' ' . $this->encode_optional_revision( $revision ) . ' true false'
			);
			$params = $this->read_command_response();

			return $this->parse_versioned_properties( $params[2] );
		}

		throw new SvnException( "Path '{$path}' does not exist in the repository." );
	}

	public function drive_update( $target_revision, $report, SvnEditor $editor, $options = array() ) {
		$depth = isset( $options['depth'] ) ? $options['depth'] : 'infinity';

		// Pipeline the update command and the working copy report, then
		// consume the server's editor drive.
		$this->send_command(
			'update',
			'( ' . (int) $target_revision . ' ) ' .
			RaSvnConnection::encode_string( '' ) . ' true ' . $depth . ' false false'
		);
		foreach ( $report as $report_entry ) {
			$entry_depth = isset( $report_entry['depth'] ) ? $report_entry['depth'] : 'infinity';
			$this->send_command(
				'set-path',
				RaSvnConnection::encode_string( $report_entry['path'] ) . ' ' .
				(int) $report_entry['revision'] . ' ' .
				RaSvnConnection::encode_boolean( ! empty( $report_entry['start_empty'] ) ) .
				' ( ) ' . $entry_depth
			);
		}
		$this->send_command( 'finish-report', '' );

		$this->consume_editor_drive( $editor );
	}

	public function commit( $message, $operations ) {
		$this->send_command( 'commit', RaSvnConnection::encode_string( $message ) );
		$this->read_command_response();

		try {
			$this->drive_commit_editor( $operations );
		} catch ( SvnException $exception ) {
			// The server may have rejected an editor command and already
			// hung up; surface the queued failure when there is one.
			throw $this->read_pending_commit_failure( $exception );
		}

		return $this->read_commit_info();
	}

	public function close() {
		$this->connection->close();
	}

	/**
	 * Performs the protocol handshake: greeting exchange, authentication,
	 * and the repository information announcement.
	 */
	private function handshake() {
		$greeting = $this->read_response_tuple();
		$min      = $greeting[0]->get_number();
		$max      = $greeting[1]->get_number();
		if ( $min > 2 || $max < 2 ) {
			throw new SvnException( "The server speaks ra_svn protocol versions {$min}-{$max}; only version 2 is supported." );
		}

		$this->connection->write(
			'( 2 ( edit-pipeline svndiff1 absent-entries depth ) ' .
			RaSvnConnection::encode_string( $this->url ) . ' ' .
			RaSvnConnection::encode_string( 'php-toolkit-svn/1.0' ) .
			' ( ) ) '
		);

		$auth_request = $this->read_response_tuple();
		$this->authenticate( $auth_request );

		$repository_info       = $this->read_response_tuple();
		$this->uuid            = $repository_info[0]->get_string();
		$this->repository_root = $repository_info[1]->get_string();
	}

	/**
	 * Answers an authentication request.
	 *
	 * @param  RaSvnItem[] $auth_request_params  The ( ( mech... ) realm ) tuple.
	 * @throws SvnException When no supported mechanism is available or
	 *                      the credentials are rejected.
	 */
	private function authenticate( $auth_request_params ) {
		$mechanisms = array();
		foreach ( $auth_request_params[0]->get_list() as $mechanism ) {
			$mechanisms[] = $mechanism->get_word();
		}
		$realm = $auth_request_params[1]->get_string();

		if ( 0 === count( $mechanisms ) ) {
			// Trivial auth request: nothing to do.
			return;
		}

		if ( null !== $this->username && null !== $this->password && in_array( 'CRAM-MD5', $mechanisms, true ) ) {
			$this->connection->write( '( CRAM-MD5 ( ) ) ' );
			$step = $this->connection->read_item()->get_list();
			if ( ! $step[0]->is_word( 'step' ) ) {
				throw $this->failure_to_exception( $step );
			}
			$challenge = $step[1]->get_list()[0]->get_string();
			$digest    = hash_hmac( 'md5', $challenge, $this->password );
			$this->connection->write( RaSvnConnection::encode_string( $this->username . ' ' . $digest ) . ' ' );
			$this->read_auth_outcome( $realm );

			return;
		}

		if ( in_array( 'ANONYMOUS', $mechanisms, true ) ) {
			$this->connection->write( '( ANONYMOUS ( ' . RaSvnConnection::encode_string( base64_encode( 'anonymous' ) ) . ' ) ) ' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the wire protocol wants a base64 token.
			$this->read_auth_outcome( $realm );

			return;
		}

		throw new SvnException(
			'The server offers no supported authentication mechanism (offered: ' . implode( ', ', $mechanisms ) . '). ' .
			( null === $this->username ? 'Provide a username and a password to use CRAM-MD5.' : 'Only ANONYMOUS and CRAM-MD5 are supported.' )
		);
	}

	/**
	 * Reads the final success/failure of an authentication exchange.
	 *
	 * @param  string $realm  The authentication realm, used in error messages.
	 * @throws SvnException When authentication failed.
	 */
	private function read_auth_outcome( $realm ) {
		$outcome = $this->connection->read_item()->get_list();
		if ( $outcome[0]->is_word( 'success' ) ) {
			return;
		}
		if ( $outcome[0]->is_word( 'failure' ) ) {
			$messages = array();
			foreach ( $outcome[1]->get_list() as $message ) {
				$messages[] = $message->get_string();
			}

			throw new SvnException( "Authentication failed for realm '{$realm}': " . implode( '; ', $messages ) );
		}

		throw new SvnException( 'Protocol error: unexpected authentication outcome.' );
	}

	/**
	 * Sends one command tuple.
	 *
	 * @param string $name          The command name.
	 * @param string $encoded_args  Already-encoded argument items.
	 */
	private function send_command( $name, $encoded_args ) {
		$this->connection->write( '( ' . $name . ' ( ' . $encoded_args . ' ) ) ' );
	}

	/**
	 * Reads a command response: the per-command auth request followed by
	 * the `( success ( params ) )` or `( failure ( errors ) )` tuple.
	 *
	 * @return RaSvnItem[] The success params.
	 * @throws SvnException When the server reports a failure.
	 */
	private function read_command_response() {
		$this->consume_auth_request();

		return $this->read_response_tuple();
	}

	/**
	 * Reads a `( success ( params ) )` tuple and returns the params,
	 * converting failures into exceptions.
	 *
	 * @return RaSvnItem[]
	 * @throws SvnException When the server reports a failure.
	 */
	private function read_response_tuple() {
		$tuple = $this->connection->read_item()->get_list();
		if ( $tuple[0]->is_word( 'success' ) ) {
			return $tuple[1]->get_list();
		}
		if ( $tuple[0]->is_word( 'failure' ) ) {
			throw $this->failure_to_exception( $tuple );
		}

		throw new SvnException( "Protocol error: expected a command response, got '{$tuple[0]->get_word()}'." );
	}

	/**
	 * Consumes the auth request the server sends before every command
	 * response, re-authenticating when the server asks for it.
	 */
	private function consume_auth_request() {
		$tuple = $this->connection->read_item()->get_list();
		if ( $tuple[0]->is_word( 'failure' ) ) {
			throw $this->failure_to_exception( $tuple );
		}
		if ( ! $tuple[0]->is_word( 'success' ) ) {
			throw new SvnException( 'Protocol error: expected an auth request.' );
		}
		$this->authenticate( $tuple[1]->get_list() );
	}

	/**
	 * Converts a `( failure ( ( apr-err message file line ) ... ) )`
	 * tuple into an exception.
	 *
	 * @param  RaSvnItem[] $tuple  The failure tuple items.
	 * @return SvnException
	 */
	private function failure_to_exception( $tuple ) {
		$messages = array();
		foreach ( $tuple[1]->get_list() as $error ) {
			$error_parts = $error->get_list();
			$message     = $error_parts[1]->get_string();
			if ( '' !== $message ) {
				$messages[] = $message . ' (SVN error ' . $error_parts[0]->get_number() . ')';
			}
		}
		if ( 0 === count( $messages ) ) {
			$messages[] = 'The server reported an unspecified error.';
		}

		return new SvnException( implode( '; ', $messages ) );
	}

	/**
	 * Encodes an optional revision argument: `( N )` or `( )` for "latest".
	 *
	 * @param  int|null $revision  The revision, or null for the latest.
	 * @return string
	 */
	private function encode_optional_revision( $revision ) {
		return null === $revision ? '( )' : '( ' . (int) $revision . ' )';
	}

	/**
	 * Extracts versioned properties from a wire proplist, dropping the
	 * unversioned svn:entry:* and svn:wc:* bookkeeping properties.
	 *
	 * @param  RaSvnItem $proplist_item  A list of ( name value ) tuples.
	 * @return array Property name => value.
	 */
	private function parse_versioned_properties( $proplist_item ) {
		$properties = array();
		foreach ( $proplist_item->get_list() as $pair_item ) {
			$pair = $pair_item->get_list();
			$name = $pair[0]->get_string();
			if ( 0 === strpos( $name, 'svn:entry:' ) || 0 === strpos( $name, 'svn:wc:' ) ) {
				continue;
			}
			$properties[ $name ] = $pair[1]->get_string();
		}

		return $properties;
	}

	/**
	 * Returns true for property names the working copy must not store,
	 * such as svn:entry:committed-rev.
	 *
	 * @param  string $name  The property name.
	 * @return bool
	 */
	private function is_entry_property( $name ) {
		return 0 === strpos( $name, 'svn:entry:' ) || 0 === strpos( $name, 'svn:wc:' );
	}

	/**
	 * Reads editor commands from the server and dispatches them onto the
	 * editor until the drive completes.
	 *
	 * @param  SvnEditor $editor  The editor to drive.
	 * @throws SvnException When the server reports a failure.
	 */
	private function consume_editor_drive( SvnEditor $editor ) {
		// Token => path maps. The wire protocol addresses open files and
		// directories by server-chosen tokens; the editor interface uses paths.
		$directory_paths = array();
		$file_paths      = array();

		while ( true ) {
			$tuple   = $this->connection->read_item()->get_list();
			$command = $tuple[0]->get_word();
			$params  = isset( $tuple[1] ) ? $tuple[1]->get_list() : array();

			switch ( $command ) {
				case 'success':
					// Auth requests interleave with the drive; the trivial
					// form needs no action and the non-trivial one is
					// handled by authenticate().
					if ( 2 === count( $params ) && RaSvnItem::TYPE_LIST === $params[0]->type && RaSvnItem::TYPE_STRING === $params[1]->type ) {
						$this->authenticate( $params );
					}
					break;

				case 'failure':
					$editor->abort_edit();
					throw $this->failure_to_exception( $tuple );

				case 'target-rev':
					$editor->set_target_revision( $params[0]->get_number() );
					break;

				case 'open-root':
					$directory_paths[ $params[1]->get_string() ] = '';
					$editor->open_root();
					break;

				case 'add-dir':
					$path                                        = $params[0]->get_string();
					$directory_paths[ $params[2]->get_string() ] = $path;
					$editor->add_directory( $path );
					break;

				case 'open-dir':
					$path                                        = $params[0]->get_string();
					$directory_paths[ $params[2]->get_string() ] = $path;
					$editor->open_directory( $path );
					break;

				case 'change-dir-prop':
					$name = $params[1]->get_string();
					if ( ! $this->is_entry_property( $name ) ) {
						$value = $params[2]->get_optional();
						$editor->change_directory_property(
							$directory_paths[ $params[0]->get_string() ],
							$name,
							null === $value ? null : $value->get_string()
						);
					}
					break;

				case 'close-dir':
					$token = $params[0]->get_string();
					$editor->close_directory( $directory_paths[ $token ] );
					unset( $directory_paths[ $token ] );
					break;

				case 'add-file':
					$path                                   = $params[0]->get_string();
					$file_paths[ $params[2]->get_string() ] = $path;
					$editor->add_file( $path );
					break;

				case 'open-file':
					$path                                   = $params[0]->get_string();
					$file_paths[ $params[2]->get_string() ] = $path;
					$editor->open_file( $path );
					break;

				case 'change-file-prop':
					$name = $params[1]->get_string();
					if ( ! $this->is_entry_property( $name ) ) {
						$value = $params[2]->get_optional();
						$editor->change_file_property(
							$file_paths[ $params[0]->get_string() ],
							$name,
							null === $value ? null : $value->get_string()
						);
					}
					break;

				case 'apply-textdelta':
					$base_checksum = $params[1]->get_optional();
					$editor->apply_textdelta(
						$file_paths[ $params[0]->get_string() ],
						null === $base_checksum ? null : $base_checksum->get_string()
					);
					break;

				case 'textdelta-chunk':
					$editor->write_textdelta_chunk(
						$file_paths[ $params[0]->get_string() ],
						$params[1]->get_string()
					);
					break;

				case 'textdelta-end':
					$editor->textdelta_end( $file_paths[ $params[0]->get_string() ] );
					break;

				case 'close-file':
					$token         = $params[0]->get_string();
					$text_checksum = $params[1]->get_optional();
					$editor->close_file(
						$file_paths[ $token ],
						null === $text_checksum ? null : $text_checksum->get_string()
					);
					unset( $file_paths[ $token ] );
					break;

				case 'delete-entry':
					$editor->delete_entry( $params[0]->get_string() );
					break;

				case 'absent-dir':
				case 'absent-file':
					// The server cannot show us this entry (typically due to
					// path-based authorization). There is nothing to do.
					break;

				case 'close-edit':
					// Acknowledge the drive, consume the final command
					// response, and finish.
					$this->connection->write( '( success ( ) ) ' );
					$this->read_final_drive_response();
					$editor->close_edit();

					return;

				case 'abort-edit':
					$editor->abort_edit();
					throw new SvnException( 'The server aborted the update before it completed.' );

				default:
					throw new SvnException( "Protocol error: unknown editor command '{$command}'." );
			}
		}
	}

	/**
	 * Reads the command response that concludes an editor drive. Unlike
	 * regular command responses it is not necessarily preceded by an
	 * auth request, so both forms are tolerated.
	 *
	 * @throws SvnException When the server reports a failure.
	 */
	private function read_final_drive_response() {
		while ( true ) {
			$tuple = $this->connection->read_item()->get_list();
			if ( $tuple[0]->is_word( 'failure' ) ) {
				throw $this->failure_to_exception( $tuple );
			}
			if ( ! $tuple[0]->is_word( 'success' ) ) {
				throw new SvnException( "Protocol error: expected the final update response, got '{$tuple[0]->get_word()}'." );
			}
			$params = $tuple[1]->get_list();
			if ( 2 === count( $params ) && RaSvnItem::TYPE_LIST === $params[0]->type && RaSvnItem::TYPE_STRING === $params[1]->type ) {
				// An interleaved auth request.
				$this->authenticate( $params );
				continue;
			}

			return;
		}
	}

	/**
	 * Drives the server's commit editor with a set of changes.
	 *
	 * @param  array[] $operations  See SvnSession::commit().
	 * @throws SvnException When the operations are inconsistent.
	 */
	private function drive_commit_editor( $operations ) {
		$operations_by_parent = $this->group_operations_by_parent( $operations );
		$this->next_token     = 0;

		$root_token = $this->allocate_token( 'd' );
		$this->connection->write( '( open-root ( ( ) ' . RaSvnConnection::encode_string( $root_token ) . ' ) ) ' );
		$this->drive_commit_directory( '', $root_token, $operations_by_parent );
		$this->connection->write( '( close-dir ( ' . RaSvnConnection::encode_string( $root_token ) . ' ) ) ' );
		$this->connection->write( '( close-edit ( ) ) ' );
	}

	/**
	 * @param  string $prefix  'd' for directories, 'f' for files.
	 * @return string A token unique within the current editor drive.
	 */
	private function allocate_token( $prefix ) {
		return $prefix . ( $this->next_token++ );
	}

	/**
	 * Groups commit operations by the directory that contains them and
	 * registers every ancestor directory that must be opened.
	 *
	 * @param  array[] $operations  See SvnSession::commit().
	 * @return array Map of directory path => list of operations on its direct children.
	 */
	private function group_operations_by_parent( $operations ) {
		$by_parent = array( '' => array() );
		foreach ( $operations as $operation ) {
			if ( ! isset( $operation['op'], $operation['path'] ) ) {
				throw new SvnException( 'Each commit operation needs an "op" and a "path".' );
			}
			$path = trim( $operation['path'], '/' );
			if ( '' === $path ) {
				throw new SvnException( 'Commit operations cannot target the session root itself.' );
			}
			$operation['path'] = $path;

			$parent = self::parent_path( $path );
			if ( ! isset( $by_parent[ $parent ] ) ) {
				$by_parent[ $parent ] = array();
			}
			$by_parent[ $parent ][] = $operation;

			// Register all ancestors so the drive knows to descend into them.
			$ancestor = $parent;
			while ( '' !== $ancestor ) {
				$ancestor = self::parent_path( $ancestor );
				if ( ! isset( $by_parent[ $ancestor ] ) ) {
					$by_parent[ $ancestor ] = array();
				}
			}
		}

		return $by_parent;
	}

	/**
	 * @param  string $path  A relative path.
	 * @return string The parent path, '' for top-level entries.
	 */
	private static function parent_path( $path ) {
		$slash_position = strrpos( $path, '/' );

		return false === $slash_position ? '' : substr( $path, 0, $slash_position );
	}

	/**
	 * Emits the editor commands for one directory level, then recurses.
	 *
	 * @param string $directory_path        The directory being driven, '' for the root.
	 * @param string $directory_token       The wire token of the open directory.
	 * @param array  $operations_by_parent  See group_operations_by_parent().
	 */
	private function drive_commit_directory( $directory_path, $directory_token, $operations_by_parent ) {
		$operations = isset( $operations_by_parent[ $directory_path ] ) ? $operations_by_parent[ $directory_path ] : array();

		// Deletes first so that replacements (delete + add of the same
		// path) drive correctly.
		usort(
			$operations,
			function ( $a, $b ) {
				$a_is_delete = 'delete' === $a['op'] ? 0 : 1;
				$b_is_delete = 'delete' === $b['op'] ? 0 : 1;
				if ( $a_is_delete !== $b_is_delete ) {
					return $a_is_delete - $b_is_delete;
				}

				return strcmp( $a['path'], $b['path'] );
			}
		);

		$added_directories = array();
		foreach ( $operations as $operation ) {
			switch ( $operation['op'] ) {
				case 'delete':
					$this->connection->write(
						'( delete-entry ( ' .
						RaSvnConnection::encode_string( $operation['path'] ) . ' ' .
						( isset( $operation['base_revision'] ) ? '( ' . (int) $operation['base_revision'] . ' )' : '( )' ) . ' ' .
						RaSvnConnection::encode_string( $directory_token ) .
						' ) ) '
					);
					break;

				case 'add-directory':
					$token = $this->allocate_token( 'd' );
					$this->connection->write(
						'( add-dir ( ' .
						RaSvnConnection::encode_string( $operation['path'] ) . ' ' .
						RaSvnConnection::encode_string( $directory_token ) . ' ' .
						RaSvnConnection::encode_string( $token ) .
						' ( ) ) ) '
					);
					$this->write_property_changes( 'change-dir-prop', $token, isset( $operation['properties'] ) ? $operation['properties'] : array() );
					$this->drive_commit_directory( $operation['path'], $token, $operations_by_parent );
					$this->connection->write( '( close-dir ( ' . RaSvnConnection::encode_string( $token ) . ' ) ) ' );
					$added_directories[ $operation['path'] ] = true;
					break;

				case 'add-file':
				case 'modify-file':
					$token = $this->allocate_token( 'f' );
					if ( 'add-file' === $operation['op'] ) {
						$this->connection->write(
							'( add-file ( ' .
							RaSvnConnection::encode_string( $operation['path'] ) . ' ' .
							RaSvnConnection::encode_string( $directory_token ) . ' ' .
							RaSvnConnection::encode_string( $token ) .
							' ( ) ) ) '
						);
					} else {
						$this->connection->write(
							'( open-file ( ' .
							RaSvnConnection::encode_string( $operation['path'] ) . ' ' .
							RaSvnConnection::encode_string( $directory_token ) . ' ' .
							RaSvnConnection::encode_string( $token ) . ' ' .
							( isset( $operation['base_revision'] ) ? '( ' . (int) $operation['base_revision'] . ' )' : '( )' ) .
							' ) ) '
						);
					}
					$this->write_file_contents( $token, $operation['contents'] );
					$this->write_property_changes( 'change-file-prop', $token, isset( $operation['properties'] ) ? $operation['properties'] : array() );
					$this->connection->write(
						'( close-file ( ' .
						RaSvnConnection::encode_string( $token ) .
						' ( ' . RaSvnConnection::encode_string( md5( $operation['contents'] ) ) . ' ) ) ) '
					);
					break;

				case 'modify-properties':
					$token = $this->allocate_token( isset( $operation['kind'] ) && 'dir' === $operation['kind'] ? 'd' : 'f' );
					if ( isset( $operation['kind'] ) && 'dir' === $operation['kind'] ) {
						$this->connection->write(
							'( open-dir ( ' .
							RaSvnConnection::encode_string( $operation['path'] ) . ' ' .
							RaSvnConnection::encode_string( $directory_token ) . ' ' .
							RaSvnConnection::encode_string( $token ) . ' ' .
							( isset( $operation['base_revision'] ) ? '( ' . (int) $operation['base_revision'] . ' )' : '( )' ) .
							' ) ) '
						);
						$this->write_property_changes( 'change-dir-prop', $token, $operation['properties'] );
						$this->drive_commit_directory( $operation['path'], $token, $operations_by_parent );
						$this->connection->write( '( close-dir ( ' . RaSvnConnection::encode_string( $token ) . ' ) ) ' );
						$added_directories[ $operation['path'] ] = true;
					} else {
						$this->connection->write(
							'( open-file ( ' .
							RaSvnConnection::encode_string( $operation['path'] ) . ' ' .
							RaSvnConnection::encode_string( $directory_token ) . ' ' .
							RaSvnConnection::encode_string( $token ) . ' ' .
							( isset( $operation['base_revision'] ) ? '( ' . (int) $operation['base_revision'] . ' )' : '( )' ) .
							' ) ) '
						);
						$this->write_property_changes( 'change-file-prop', $token, $operation['properties'] );
						$this->connection->write( '( close-file ( ' . RaSvnConnection::encode_string( $token ) . ' ( ) ) ) ' );
					}
					break;

				default:
					throw new SvnException( "Unknown commit operation '{$operation['op']}'." );
			}
		}

		// Descend into directories that only contain nested operations.
		foreach ( $operations_by_parent as $path => $unused ) {
			if ( '' === $path || isset( $added_directories[ $path ] ) ) {
				continue;
			}
			if ( self::parent_path( $path ) !== $directory_path ) {
				continue;
			}
			$has_direct_operation = false;
			foreach ( $operations as $operation ) {
				if ( $operation['path'] === $path && 'delete' !== $operation['op'] ) {
					$has_direct_operation = true;
					break;
				}
			}
			if ( $has_direct_operation ) {
				continue;
			}
			$token = $this->allocate_token( 'd' );
			$this->connection->write(
				'( open-dir ( ' .
				RaSvnConnection::encode_string( $path ) . ' ' .
				RaSvnConnection::encode_string( $directory_token ) . ' ' .
				RaSvnConnection::encode_string( $token ) .
				' ( ) ) ) '
			);
			$this->drive_commit_directory( $path, $token, $operations_by_parent );
			$this->connection->write( '( close-dir ( ' . RaSvnConnection::encode_string( $token ) . ' ) ) ' );
		}
	}

	/**
	 * Sends a file's full contents as an svndiff0 delta against an empty base.
	 *
	 * @param string $token     The open file's wire token.
	 * @param string $contents  The new full text of the file.
	 */
	private function write_file_contents( $token, $contents ) {
		$encoded_token = RaSvnConnection::encode_string( $token );
		$this->connection->write( '( apply-textdelta ( ' . $encoded_token . ' ( ) ) ) ' );
		$svndiff = SvnDiff::encode_fulltext( $contents );
		$offset  = 0;
		$length  = strlen( $svndiff );
		while ( $offset < $length ) {
			$chunk = substr( $svndiff, $offset, 131072 );
			$this->connection->write( '( textdelta-chunk ( ' . $encoded_token . ' ' . RaSvnConnection::encode_string( $chunk ) . ' ) ) ' );
			$offset += strlen( $chunk );
		}
		$this->connection->write( '( textdelta-end ( ' . $encoded_token . ' ) ) ' );
	}

	/**
	 * Emits change-file-prop / change-dir-prop commands.
	 *
	 * @param string $command     'change-file-prop' or 'change-dir-prop'.
	 * @param string $token       The open node's wire token.
	 * @param array  $properties  Property name => value, null value deletes.
	 */
	private function write_property_changes( $command, $token, $properties ) {
		foreach ( $properties as $name => $value ) {
			$this->connection->write(
				'( ' . $command . ' ( ' .
				RaSvnConnection::encode_string( $token ) . ' ' .
				RaSvnConnection::encode_string( $name ) . ' ' .
				( null === $value ? '( )' : '( ' . RaSvnConnection::encode_string( $value ) . ' )' ) .
				' ) ) '
			);
		}
	}

	/**
	 * Reads the post-drive commit outcome: the close-edit acknowledgment,
	 * an auth request, and the ( new-rev date author ) commit info.
	 *
	 * @return array { @type int $revision  @type string|null $author  @type string|null $date }
	 * @throws SvnException When the commit was rejected.
	 */
	private function read_commit_info() {
		while ( true ) {
			$item = $this->connection->read_item();
			$list = $item->get_list();
			if ( count( $list ) > 0 && RaSvnItem::TYPE_WORD === $list[0]->type ) {
				if ( $list[0]->is_word( 'failure' ) ) {
					throw $this->failure_to_exception( $list );
				}
				if ( $list[0]->is_word( 'success' ) ) {
					// Either the close-edit acknowledgment or an interleaved
					// auth request; both need no action here.
					continue;
				}
				throw new SvnException( "Protocol error: unexpected '{$list[0]->get_word()}' while waiting for commit info." );
			}

			// A bare list: ( new-rev ( date ) ( author ) ( post-commit-errors ) ).
			$date   = $list[1]->get_optional();
			$author = $list[2]->get_optional();

			return array(
				'revision' => $list[0]->get_number(),
				'author'   => null === $author ? null : $author->get_string_or_word(),
				'date'     => null === $date ? null : $date->get_string(),
			);
		}
	}

	/**
	 * After a mid-drive write error, the server has usually already
	 * queued a failure response explaining what it rejected. Prefer that
	 * message over the generic broken-pipe error.
	 *
	 * @param  SvnException $fallback  The exception to throw when no failure is queued.
	 * @return SvnException
	 */
	private function read_pending_commit_failure( SvnException $fallback ) {
		try {
			while ( true ) {
				$list = $this->connection->read_item()->get_list();
				if ( count( $list ) > 0 && $list[0]->is_word( 'failure' ) ) {
					return $this->failure_to_exception( $list );
				}
			}
		} catch ( SvnException $exception ) {
			return $fallback;
		}
	}
}
