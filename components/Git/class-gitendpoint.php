<?php

namespace WordPress\Git;

use WordPress\ByteStream\MemoryPipe;
use WordPress\Git\Model\Commit;
use WordPress\Git\Protocol\GitProtocolEncoderPipe;
use WordPress\Git\Protocol\Parser\GitProtocolDecoder;
use WordPress\Git\Protocol\Parser\PacketParser;
use WordPress\HttpServer\Response\ResponseWriteStream;

/**
 * Implement Git server protocol v2
 * https://git-scm.com/docs/protocol-v2
 */
class GitEndpoint {
	/**
	 * @var GitRepository
	 */
	private $repository;

	public function __construct( GitRepository $repository ) {
		$this->repository = $repository;
	}

	public function handle_request( $path, $request_bytes, ResponseWriteStream $http_response ) {
		$git_response = new GitProtocolEncoderPipe();
		switch ( $path ) {
			case '/HEAD':
				// $this->handle_head_request($request_bytes, $git_response);
				break;
			case '/info/refs?service=git-upload-pack':
				$this->send_protocol_v2_headers( $http_response, 'git-upload-pack' );
				$git_response->append_packet_lines(
					array(
						"# service=git-upload-pack\n",
						'0000',
						"version 2\n",
						"agent=git/github-395dce4f6ecf\n",
						"ls-refs=unborn\n",
						"fetch=shallow wait-for-done filter\n",
						"server-option\n",
						"object-format=sha1\n",
						'0000',
					)
				);
				break;
			case '/info/refs?service=git-receive-pack':
				$this->send_protocol_v2_headers( $http_response, 'git-receive-pack' );
				$git_response->append_packet_lines(
					array(
						"# service=git-receive-pack\n",
						'0000',
					)
				);
				$this->respond_with_ls_refs(
					$git_response,
					array(
						'capabilities' => 'report-status report-status-v2 delete-refs side-band-64k ofs-delta atomic object-format=sha1 quiet agent=github/spokes-receive-pack-bff11521ff0f3fc96efd2ba7a18ecebb89dc6949 session-id=26DD:527D3:3A481E46:3BF47E4D:677BF4BA push-options',
					)
				);
				break;
			case '/git-upload-pack':
				$this->send_protocol_v2_headers( $http_response, 'git-upload-pack' );
				$parsed = $this->parse_message( $request_bytes );
				switch ( $parsed['capabilities']['command'] ) {
					case 'ls-refs':
						$this->handle_ls_refs_request( $request_bytes, $git_response );
						break;
					case 'fetch':
						$this->handle_fetch_request( $request_bytes, $git_response );
						break;
					default:
						throw new GitException( 'Unknown command: ' . $parsed['capabilities']['command'] );
				}
				break;
			case '/git-receive-pack':
				$this->send_protocol_v2_headers( $http_response, 'git-receive-pack' );
				$http_response->send_header( 'Content-Type', 'application/x-git-receive-pack-result' );
				$http_response->send_header( 'Cache-Control', 'no-cache' );
				$this->handle_push_request( $request_bytes, $git_response );
				$git_response->close_writing();
				break;
			default:
				throw new GitException( 'Unknown path: ' . $path );
		}
		$git_response->close_writing();

		// @TODO: Simplify this with a method such as pipe_to() or
		// a pulling class such as GitHttpResponse
		while ( true ) {
			$available = $git_response->pull( 65536 );
			if ( $available === 0 && $git_response->reached_end_of_data() ) {
				break;
			}
			$http_response->append_bytes( $git_response->consume( $available ) );
		}
		$http_response->close_writing();
	}

	private function send_protocol_v2_headers( ResponseWriteStream $response, $service ) {
		$response->send_header( 'Content-Type', 'application/x-' . $service . '-advertisement' );
		$response->send_header( 'Cache-Control', 'no-cache' );
		$response->send_header( 'Git-Protocol', 'version=2' );
	}

	/**
	 * Handle Git protocol v2 ls-refs command
	 *
	 * ls-refs is the command used to request a reference advertisement in v2.
	 * Unlike the current reference advertisement, ls-refs takes in arguments
	 * which can be used to limit the refs sent from the server.
	 *
	 * Additional features not supported in the base command will be advertised as
	 * the value of the command in the capability advertisement in the form of a space
	 * separated list of features: "<command>=<feature 1> <feature 2>"
	 *
	 * ls-refs takes in the following arguments:
	 *
	 * symrefs
	 * In addition to the object pointed by it, show the underlying ref
	 * pointed by it when showing a symbolic ref.
	 *
	 * peel
	 * Show peeled tags.
	 *
	 * ref-prefix <prefix>
	 * When specified, only references having a prefix matching one of
	 * the provided prefixes are displayed. Multiple instances may be
	 * given, in which case references matching any prefix will be
	 * shown. Note that this is purely for optimization; a server MAY
	 * show refs not matching the prefix if it chooses, and clients
	 * should filter the result themselves.
	 *
	 * unborn
	 * The server will send information about HEAD even if it is a symref
	 * pointing to an unborn branch in the form "unborn HEAD symref-target:<target>".
	 *
	 * @see https://git-scm.com/docs/protocol-v2#_ls_refs
	 *
	 * @param  array $request_bytes  The parsed request data
	 *
	 * @return string The response in Git protocol v2 format
	 */
	public function handle_ls_refs_request( $request_bytes, GitProtocolEncoderPipe $git_response ) {
		$parsed = $this->parse_message( $request_bytes );
		if ( ! $parsed ) {
			// return false;
		}

		$this->respond_with_ls_refs(
			$git_response,
			array(
				'ref-prefix'   => $parsed['arguments']['ref-prefix'] ?? array( '' ),
				'capabilities' => 'multi_ack thin-pack side-band side-band-64k ofs-delta shallow deepen-since deepen-not deepen-relative no-progress include-tag multi_ack_detailed allow-tip-sha1-in-want allow-reachable-sha1-in-want no-done symref=HEAD:refs/heads/trunk filter object-format=sha1 agent=git/github-395dce4f6ecf',
			)
		);
	}

	private function respond_with_ls_refs( GitProtocolEncoderPipe $git_response, $options ) {
		$ref_prefixes              = $options['ref-prefix'] ?? array( '' );
		$capabilities_to_advertise = $options['capabilities'];

		$refs = $this->repository->list_refs( $ref_prefixes );
		reset( $refs );
		$first_ref = key( $refs );
		foreach ( $refs as $ref_name => $ref_hash ) {
			$line = $ref_hash . ' ' . $ref_name;
			if ( $ref_name === $first_ref ) {
				$line .= "\0$capabilities_to_advertise";
			}
			// Format: <hash> <refname>\n
			$git_response->append_packet_line(
				$line . "\n"
			);
		}
		// End the response with 0000
		$git_response->append_packet_line( '0000' );
	}

	/**
	 * Capability Advertisement
	 *
	 * A server which decides to communicate (based on a request from a client) using
	 * protocol version 2, notifies the client by sending a version string in its initial
	 * response followed by an advertisement of its capabilities. Each capability is a key
	 * with an optional value. Clients must ignore all unknown keys. Semantics of unknown
	 * values are left to the definition of each key. Some capabilities will describe
	 * command which can be requested to be executed by the client.
	 *
	 * capability-advertisement = protocol-version
	 *      capability-list
	 *      flush-pkt
	 *
	 * protocol-version = PKT-LINE("version 2" LF)
	 * capability-list = *capability
	 * capability = PKT-LINE(key[=value] LF)
	 *
	 * key = 1*(ALPHA | DIGIT | "-_")
	 * value = 1*(ALPHA | DIGIT | " -_.,?\/{}[]()<>!@#$%^&*+=:;")
	 * flush-pkt = PKT-LINE("0000" LF)
	 *
	 * @see https://git-scm.com/docs/protocol-v2#_capability_advertisement
	 * @return string The capability advertisement in Git protocol v2 format
	 */
	public function capability_advertise() {
		return "version 2\n" .
				"agent=git/2.37.3\n" .
				'0000';
	}

	public function parse_message( $request_bytes_bytes ) {
		$packet_parser = new PacketParser();
		$packet_parser->append_bytes( $request_bytes_bytes );
		$modes = array( 'capabilities', 'arguments', 'done' );
		$mode  = array_shift( $modes );

		$capabilities = array();
		$arguments    = array();
		// @TODO: Fix PacketParser to avoid emiting duplicate packets
		while ( $packet_parser->next_token() ) {
			if ( $packet_parser->get_packet_type() !== '#packet' ) {
				$mode = array_shift( $modes );
				continue;
			}
			if ( $packet_parser->get_token_type() !== '#packet-body' ) {
				continue;
			}
			$packet = $packet_parser->get_body_chunk();
			switch ( $mode ) {
				case 'capabilities':
					if ( strpos( $packet, '=' ) !== false ) {
						list( $key, $value )  = explode( '=', $packet );
						$capabilities[ $key ] = $value;
					} else {
						$capabilities[ $packet ] = true;
					}
					break;
				case 'arguments':
					$space_at = strpos( $packet, ' ' );
					if ( $space_at === false ) {
						$key   = $packet;
						$value = true;
					} else {
						$key   = substr( $packet, 0, $space_at );
						$value = substr( $packet, $space_at + 1 );
					}

					if ( ! array_key_exists( $key, $arguments ) ) {
						$arguments[ $key ] = array();
					}
					$arguments[ $key ][] = $value;
					break;
				case 'done':
					break 2;
			}
		}

		return array(
			'capabilities' => $capabilities,
			'arguments'    => $arguments,
		);
	}

	/**
	 * Handle Git protocol v2 fetch command with "want" packets
	 *
	 * @param  array $request_bytes  The parsed request data
	 *
	 * @return string The response in Git protocol v2 format containing the pack data
	 */
	public function handle_fetch_request( $request_bytes, GitProtocolEncoderPipe $git_response ) {
		$parsed = $this->parse_message( $request_bytes );
		if ( ! $parsed || empty( $parsed['arguments']['want'] ) ) {
			return false;
		}

		$filter_raw = $parsed['arguments']['filter'][0] ?? null;
		$filter     = $this->parse_filter( $filter_raw );
		if ( $filter === false ) {
			throw new GitException( 'Invalid filter: ' . $filter_raw );
		}

		$have_oids = array(
			Commit::NULL_HASH => true,
		);
		if ( isset( $parsed['arguments']['have'] ) ) {
			foreach ( $parsed['arguments']['have'] as $have_hash ) {
				$have_oids[ $have_hash ] = true;
			}
		}

		$objects_to_send = array();
		$acks            = array();
		foreach ( $parsed['arguments']['want'] as $want_hash ) {
			// For all the requested non-shallow commits, find
			// most recent parent commit the client we have in
			// common with the client.
			$common_parent_hash = Commit::NULL_HASH;
			$commit_hash        = $want_hash;
			while ( true ) {
				$reader            = $this->repository->read_object( $commit_hash );
				$objects_to_send[] = $commit_hash;
				if ( $reader->get_object_type_name() !== 'commit' ) {
					// Just send non-commit objects as they are. It would be lovely to
					// delta-compress them in the future.
					continue 2;
				}

				$parsed_commit = $reader->as_commit();
				if ( ! isset( $parsed_commit->parents ) ) {
					$common_parent_hash = Commit::NULL_HASH;
					break;
				}

				// @TODO: Support multiple parents
				$commit_hash = $parsed_commit->get_first_parent_hash();
				if ( isset( $have_oids[ $commit_hash ] ) ) {
					$common_parent_hash = $commit_hash;
					break;
				}
			}

			// For each wanted commit, find objects not present in any of the have commits
			$new_objects = $this->repository->find_objects_added_in(
				$want_hash,
				$common_parent_hash
			);
			if ( $new_objects !== false ) {
				$objects_to_send = array_merge(
					$objects_to_send,
					$new_objects
				);
			}
			if ( ! Commit::is_null_hash( $common_parent_hash ) ) {
				$acks[] = $common_parent_hash;
			}
		}
		$acks = array_unique( $acks );
		if ( isset( $parsed['arguments']['have'] ) && count( $parsed['arguments']['have'] ) > 0 ) {
			$git_response->append_packet_line( "acknowledgments\n" );
			if ( count( $acks ) > 0 ) {
				foreach ( $acks as $ack ) {
					$git_response->append_packet_line( "ACK $ack\n" );
				}
			} else {
				$git_response->append_packet_line( "NAK\n" );
			}
			$git_response->append_packet_line( "ready\n" );
			$git_response->append_packet_line( '0001' );
		}

		// Pack the objects
		$objects_to_send = array_unique( $objects_to_send );
		$pack_objects    = array();
		foreach ( $objects_to_send as $oid ) {
			$reader = $this->repository->read_object( $oid );

			// Apply blob filters if specified
			if ( $reader->get_object_type_name() === 'blob' ) {
				if ( $filter['type'] === 'blob' ) {
					if ( $filter['filter'] === 'none' ) {
						continue; // Skip all blobs
					} elseif ( $filter['filter'] === 'limit' ) {
						$size = $reader->get_uncompressed_size();
						if ( $size > $filter['size'] ) {
							continue; // Skip large blobs
						}
					}
				}
			}

			$pack_objects[] = $oid;
		}

		// Handle deepen if specified
		if ( isset( $parsed['arguments']['deepen'] ) ) {
			// @TODO: Implement history truncation based on deepen value
			// This would involve walking the commit history and including
			// only commits within the specified depth
			throw new GitException( 'Deepen is not implemented yet' );
		}

		$git_response->append_packet_line( "packfile\n" );
		$git_response->append_packfile( $this->repository, $pack_objects, $multiplex = true );
		$git_response->append_packet_line( '0000' );

		return true;
	}

	/**
	 * Handle Git protocol v2 push command
	 *
	 * @param  string              $request_bytes  Raw request bytes
	 * @param  ResponseWriteStream $response  Response writer
	 *
	 * @return bool Success status
	 */
	public function handle_push_request( $request_bytes, GitProtocolEncoderPipe $git_response ) {
		$protocol_reader = new GitProtocolDecoder(
			new MemoryPipe( $request_bytes ),
			array( 'write_to_repository' => $this->repository )
		);
		$header          = $this->parse_push_header( $protocol_reader );
		if ( ! $header || empty( $header['new_oid'] ) ) {
			$git_response->append_error_packet_line( "error header is empty\n" );
			$git_response->append_error_packet_line( '0000' );

			return false;
		}

		$old_oid = $header['old_oid'];
		// @TODO: Verify the old_oid is the ref_name tip
		$new_oid  = $header['new_oid'];
		$ref_name = $header['ref_name'];

		// Validate ref name
		if ( ! preg_match( '|^refs/|', $ref_name ) ) {
			$git_response->append_error_packet_line( "error invalid ref name: $ref_name\n" );
			$git_response->append_error_packet_line( '0000' );

			// @TODO: Throw / catch?
			return false;
		}

		// Handle deletion
		if ( Commit::is_null_hash( $new_oid ) ) {
			if ( $this->repository->delete_branch( $ref_name ) ) {
				$git_response->append_packet_line( "ok $ref_name\n" );
			} else {
				$git_response->append_error_packet_line( "error $ref_name delete failed\n" );
				$git_response->append_error_packet_line( '0000' );
			}

			return false;
		}

		// Process the incoming pack
		try {
			$had_pack = false;
			while ( $protocol_reader->next_token() ) {
				if ( $protocol_reader->get_token_type() === '#pack' ) {
					$had_pack = true;
				}
			}
		} catch ( GitException $e ) {
			$git_response->append_error_packet_line( "error unpack failed\n" );
			$git_response->append_error_packet_line( '0000' );

			return false;
		}

		$git_response->append_sideband_packet_line( "unpack ok\n" );

		try {
			$this->repository->read_object( $new_oid );
			$this->repository->set_branch_tip( $ref_name, $new_oid );
		} catch ( GitException $e ) {
			$git_response->append_error_packet_line( "error processing pack: $new_oid\n" );
			$git_response->append_error_packet_line( '0000' );

			return false;
		}

		$git_response->append_sideband_packet_line( "ok $ref_name\n" );
		$git_response->append_sideband_packet_line( '0000' );
		$git_response->append_packet_line( '0000' );

		return true;
	}

	/**
	 * Parse a push request according to Git protocol v2
	 *
	 * @param  string $request_bytes  Raw request bytes
	 *
	 * @return array|false Parsed request data or false on error
	 */
	private function parse_push_header( GitProtocolDecoder $protocol_reader ) {
		while ( $protocol_reader->next_token() ) {
			switch ( $protocol_reader->get_token_type() ) {
				case '#packet-footer':
					$line = $protocol_reader->get_packet_body();
					if ( ! preg_match( '/^(?:([0-9a-f]{40}) )?([0-9a-f]{40}) (.+?)\0(.*?)$/', $line, $matches ) ) {
						throw new GitException( 'Invalid push request' );
					}

					return array(
						'old_oid'      => $matches[1],
						'new_oid'      => $matches[2],
						'ref_name'     => $matches[3],
						'capabilities' => explode( ' ', trim( $matches[4] ) ),
					);
			}
		}
	}

	private function parse_filter( $filter ) {
		if ( $filter === null ) {
			return array( 'type' => 'none' );
		} elseif ( $filter === 'blob:none' ) {
			return array(
				'type'   => 'blob',
				'filter' => 'none',
			);
		} elseif ( strncmp( $filter, 'blob:limit=', strlen( 'blob:limit=' ) ) === 0 ) {
			$limit = substr( $filter, strlen( 'blob:limit=' ) );

			return array(
				'type'   => 'blob',
				'filter' => 'limit',
				'size'   => intval( $limit ),
			);
		}

		return false;
	}

	public static function decode_next_packet_line( $pack_bytes, &$offset ) {
		$packet_length_bytes = substr( $pack_bytes, $offset, 4 );
		$offset             += 4;
		if (
			strlen( $packet_length_bytes ) !== 4 ||
			! preg_match( '/^[0-9a-f]{4}$/', $packet_length_bytes )
		) {
			return false;
		}
		switch ( $packet_length_bytes ) {
			case '0000':
				return array( 'type' => '#flush' );
			case '0001':
				return array( 'type' => '#delimiter' );
			case '0002':
				return array( 'type' => '#response-end' );
			default:
				$length  = intval( $packet_length_bytes, 16 ) - 4;
				$payload = substr( $pack_bytes, $offset, $length );
				if ( substr_compare( $payload, "\n", - strlen( "\n" ) ) === 0 ) {
					$payload = substr( $payload, 0, - 1 );
				}
				$offset += $length;

				return array(
					'type'    => '#packet',
					'payload' => $payload,
				);
		}
	}
}
