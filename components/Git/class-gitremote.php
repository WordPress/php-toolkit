<?php

namespace WordPress\Git;

/**
 * The fetch() and pull() methods accept `HEAD`, short branch or tag names like
 * "trunk", full ref names like "refs/heads/trunk" or "refs/tags/v1.0", and
 * 40-character commit hashes. push() only supports branches.
 *
 * This comment is below the namespace so PHPCS doesn't complain.
 */

use WordPress\ByteStream\MemoryPipe;
use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Git\Model\Commit;
use WordPress\Git\Model\TreeEntry;
use WordPress\Git\Protocol\GitProtocolEncoderPipe;
use WordPress\Git\Protocol\Parser\GitProtocolDecoder;
use WordPress\HttpClient\Client;
use WordPress\HttpClient\Request;


class GitRemote {
	/**
	 * @var Client
	 */
	private $http_client;
	/**
	 * @var GitRepository
	 */
	private $repository;
	private $remote_name;

	public function __construct( GitRepository $repository, $remote_name, $options = array() ) {
		$this->remote_name = $remote_name;
		$this->repository  = $repository;
		$this->http_client = $options['http_client'] ?? new Client(
			array(
				'timeout_ms' => 300000,
			)
		);
	}

	public function ls_refs( $prefix = '' ) {
		$response = $this->http_request(
			'/git-upload-pack',
			GitProtocolEncoderPipe::encode_packet_lines(
				array(
					"command=ls-refs\n",
					"agent=git/2.37.3\n",
					"object-format=sha1\n",
					'0001',
					"peel\n",
					"ref-prefix $prefix\n",
					'0000',
				)
			),
			array(
				'Accept'       => 'application/x-git-upload-pack-advertisement',
				'Content-Type' => 'application/x-git-upload-pack-request',
				'Git-Protocol' => 'version=2',
			)
		);

		$refs     = array();
		$protocol = new GitProtocolDecoder( $response, array( 'will_process_pack' => false ) );
		while ( $protocol->next_token() ) {
			switch ( $protocol->get_token_type() ) {
				case '#packet-footer':
					$ref_line = $protocol->get_packet_body();
					$ref      = $this->parse_ref_line( $ref_line );
					if ( false === $ref ) {
						continue 2;
					}
					$refs[ $ref['ref_name'] ] = $ref['hash'];

					if ( 0 === strncmp( $ref['ref_name'], 'refs/heads/', strlen( 'refs/heads/' ) ) ) {
						$branch_name = substr( $ref['ref_name'], strlen( 'refs/heads/' ) );
						$this->repository->set_branch_tip( 'refs/remotes/' . $this->remote_name . '/' . $branch_name, $ref['hash'] );
					}
					break;
			}
		}

		return $refs;
	}

	public function get_name() {
		return $this->remote_name;
	}

	public function get_remote_head( $full_branch_name ) {
		$remote_refs = $this->ls_refs( $full_branch_name );
		if ( ! isset( $remote_refs[ $full_branch_name ] ) ) {
			throw new GitRemoteException( 'Branch "' . $full_branch_name . '" not found on remote ' . $this->remote_name );
		}

		return $remote_refs[ $full_branch_name ];
	}

	/**
	 * Resolves a ref to the commit it points to on the remote.
	 *
	 * Accepts `HEAD`, a full ref name such as `refs/tags/v1.0`, a 40-character
	 * commit hash, or a short branch or tag name. A short name is looked up as
	 * a branch first, then as a tag, as the JavaScript Blueprints runtime does.
	 * A commit hash is used as is, without asking the remote.
	 *
	 * @param string $ref_name Ref to resolve.
	 * @return array {
	 *     The resolved remote ref.
	 *
	 *     @type string      $hash        Commit hash. Annotated tags resolve to their commit.
	 *     @type string|null $branch_name Short branch name, `HEAD`, or null for a tag or commit hash.
	 * }
	 */
	private function resolve_remote_ref( $ref_name ) {
		$ref_name = trim( $ref_name );
		if ( self::is_commit_hash( $ref_name ) ) {
			return array(
				'hash'        => strtolower( $ref_name ),
				'branch_name' => null,
			);
		}
		if ( 'HEAD' === $ref_name ) {
			return array(
				'hash'        => $this->get_remote_head( 'HEAD' ),
				'branch_name' => 'HEAD',
			);
		}

		$candidates = 0 === strncmp( $ref_name, 'refs/', strlen( 'refs/' ) )
			? array( $ref_name )
			: array( 'refs/heads/' . $ref_name, 'refs/tags/' . $ref_name );
		foreach ( $candidates as $candidate ) {
			$remote_refs = $this->ls_refs( $candidate );
			if ( isset( $remote_refs[ $candidate ] ) ) {
				return array(
					'hash'        => $remote_refs[ $candidate ],
					'branch_name' => $this->is_branch_ref( $candidate ) ? substr( $candidate, strlen( 'refs/heads/' ) ) : null,
				);
			}
		}

		throw new GitRemoteException( 'Ref "' . $ref_name . '" not found on remote ' . $this->remote_name );
	}

	private function is_branch_ref( $ref_name ) {
		return 0 === strncmp( $ref_name, 'refs/heads/', strlen( 'refs/heads/' ) );
	}

	private static function is_commit_hash( $ref_name ) {
		return 1 === preg_match( '/^[0-9a-f]{40}$/i', $ref_name );
	}

	private function parse_ref_line( $ref_line ) {
		$space_pos = strpos( $ref_line, ' ' );
		if ( false === $space_pos ) {
			return false;
		}
		$hash     = substr( $ref_line, 0, $space_pos );
		$ref_name = substr( $ref_line, $space_pos + 1 );
		$attrs    = '';

		// Git may append NUL-separated attributes after the ref name, e.g. "HEAD\0symref=...".
		$attributes_pos = strpos( $ref_name, "\0" );
		if ( false !== $attributes_pos ) {
			$attrs    = substr( $ref_name, $attributes_pos + 1 );
			$ref_name = substr( $ref_name, 0, $attributes_pos );
		}

		// Protocol v2 advertises peeled tag hashes as NUL-separated attributes: "ref\0peeled:<hash>".
		if ( preg_match( '/(?:^| )peeled:([a-f0-9]{40})(?: |$)/', $attrs, $matches ) ) {
			$hash = $matches[1];
		} elseif ( preg_match( '/^(.+) peeled:([a-f0-9]{40})$/', $ref_name, $matches ) ) {
			// Keep compatibility with responses that append peeled hashes after the ref name: "ref peeled:<hash>".
			$ref_name = $matches[1];
			$hash     = $matches[2];
		}

		return array(
			'hash'     => $hash,
			'ref_name' => $ref_name,
		);
	}

	/**
	 * @TODO: Support pushing any branch such as refs/pull/123
	 */
	public function push( $short_branch_name = null ) {
		if ( ! $short_branch_name ) {
			$short_branch_name = $this->repository->get_current_branch_name();
			$short_branch_name = $this->localize_ref_name( $short_branch_name );
		}

		$push_commit = $this->repository->get_branch_tip( 'refs/heads/' . $short_branch_name );

		try {
			$remote_commit = $this->repository->get_branch_tip( 'refs/remotes/' . $this->remote_name . '/' . $short_branch_name );
		} catch ( GitException $e ) {
			$remote_commit = Commit::NULL_HASH;
		}

		// @TODO: Respect a subpath.
		$common_ancestor = $this->resolve_first_common_ancestor( $remote_commit, $push_commit );
		$delta           = $this->repository->find_objects_added_since( $push_commit, $common_ancestor );
		if ( ! count( $delta ) ) {
			// Don't push empty commits.
			return;
		}

		$producer = new GitProtocolEncoderPipe();
		$producer->append_packet_line( "$remote_commit $push_commit refs/heads/$short_branch_name\0report-status side-band-64k\n" );
		$producer->append_packet_line( '0000' );
		$producer->append_packfile( $this->repository, $delta );
		$producer->close_writing();

		$response = $this->http_request(
			'/git-receive-pack',
			$producer,
			array(
				'Content-Type' => 'application/x-git-receive-pack-request',
				'Accept'       => 'application/x-git-receive-pack-result',
			)
		);

		$data_packets = array();
		$protocol     = new GitProtocolDecoder(
			$response,
			array( 'will_process_pack' => false )
		);
		while ( $protocol->next_token() ) {
			switch ( $protocol->get_token_type() ) {
				case '#packet-footer':
					$data_packets[] = $protocol->get_packet_body();
					break;
			}
		}

		$expected_response = array(
			'unpack ok',
			'ok refs/heads/' . $short_branch_name,
		);
		if ( $data_packets !== $expected_response ) {
			throw new GitRemoteException( sprintf( 'Push failed: %s', esc_html( wp_json_encode( $data_packets ) ) ) );
		}

		$this->repository->set_branch_tip( 'refs/remotes/' . $this->remote_name . '/' . $short_branch_name, $push_commit );
	}

	private function localize_ref_name( $ref_name ) {
		if ( 0 === strncmp( $ref_name, 'ref: ', strlen( 'ref: ' ) ) ) {
			$ref_name = trim( substr( $ref_name, 5 ) );
		}
		if ( 0 === strncmp( $ref_name, 'refs/heads/', strlen( 'refs/heads/' ) ) ) {
			return substr( $ref_name, strlen( 'refs/heads/' ) );
		}

		return $ref_name;
	}

	public function list_objects( $ref_hash ): GitRepository {
		$response = $this->request_objects_list( $ref_hash );
		$tmp_repo = new GitRepository( InMemoryFilesystem::create() );
		$tmp_repo->set_branch_tip( 'HEAD', $ref_hash );
		$protocol = new GitProtocolDecoder(
			$response,
			array(
				'write_to_repository'            => $tmp_repo,
				'resolve_deltas_from_repository' => $this->repository,
			)
		);
		$protocol->consume_stream();

		return $tmp_repo;
	}

	private function request_objects_list( $ref_hash ) {
		return $this->http_request(
			'/git-upload-pack',
			GitProtocolEncoderPipe::encode_packet_lines(
				array(
					"want {$ref_hash} multi_ack_detailed no-done side-band thin-pack ofs-delta agent=git/2.37.3 filter\n",
					"filter blob:none\n",
					"shallow {$ref_hash}\n",
					"deepen 1\n",
					'0000',
					"done\n",
					"done\n",
				)
			)
		);
	}

	private function resolve_missing_blobs_oids( $remote_commit_hash, $options ) {
		$path     = $options['path'] ?? '/';
		$response = $this->request_objects_list( $remote_commit_hash );
		$protocol = new GitProtocolDecoder(
			$response,
			array(
				'write_to_repository'            => $this->repository,
				'resolve_deltas_from_repository' => $this->repository,
			)
		);
		$protocol->consume_stream();

		$commit                = $this->repository->read_object( $remote_commit_hash )->as_commit();
		$subpath               = trim( $path, '/' );
		$requested_tree_oid    = $this->repository->find_hash_by_path( $subpath, $commit->hash );
		$descentant_blobs_oids = get_all_descendant_oids_in_tree(
			$this->repository,
			$requested_tree_oid,
			array(
				'object_types' => array(
					TreeEntry::FILE_MODE_REGULAR_EXECUTABLE,
					TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				),
			)
		);

		$blobs_oids = array();
		foreach ( $descentant_blobs_oids as $oid ) {
			if ( ! $this->repository->has_object( $oid ) ) {
				$blobs_oids[] = $oid;
			}
		}

		return $blobs_oids;
	}

	public function pull( $full_branch_name = null, $options = array() ) {
		$full_branch_name = $full_branch_name ?? $this->repository->get_current_branch_name();
		if ( isset( $options['path'] ) && $options['path'] ) {
			// Sparse pull.
			$remote_ref = $this->fetch_ref(
				$full_branch_name,
				array(
					'path'    => $options['path'],
					'shallow' => $options['shallow'] ?? false,
				)
			);
		} else {
			// Full pull.
			$remote_ref = $this->fetch_ref( $full_branch_name, array() );
		}
		$remote_head = $remote_ref['hash'];

		// Tags and commit hashes have no local branch, so they move HEAD itself.
		$local_ref = null === $remote_ref['branch_name'] || 'HEAD' === $remote_ref['branch_name']
			? 'HEAD'
			: 'refs/heads/' . $remote_ref['branch_name'];

		$local_head = $this->repository->branch_exists( $local_ref )
			? $this->repository->get_branch_tip( $local_ref )
			: Commit::NULL_HASH;
		if ( Commit::is_null_hash( $local_head ) ) {
			// If the local head is an unborn branch, there's nothing to merge.
			// We just pull and point the head to the remote head.
			$options['force'] = true;
		}

		if ( isset( $options['force'] ) && $options['force'] ) {
			$this->repository->set_branch_tip( $local_ref, $remote_head );

			return $remote_head;
		}

		// Fetch the commits we need to perform the three-way merge.
		$common_ancestor  = $this->resolve_first_common_ancestor(
			$remote_head,
			$local_head
		);
		$required_commits = array( $remote_head, $common_ancestor );
		$path             = $options['path'] ?? '/';
		foreach ( $required_commits as $commit ) {
			if ( ! $this->repository->has_all_objects_from_commit( $commit, $path ) ) {
				$this->git_upload_pack(
					array(
						'want_refs' => $this->resolve_missing_blobs_oids(
							$commit,
							array( 'path' => $path )
						),
						'shallow'   => array( $commit ),
						'deepen'    => 1,
					)
				)->consume_stream();
			}
		}

		return $this->repository->merge( $remote_head, $options['merge_options'] ?? array() );
	}

	/**
	 * Fetches the commit a ref points to on the remote.
	 *
	 * @param string $full_branch_name Ref to fetch. See resolve_remote_ref() for the accepted forms.
	 * @param array  $options          Fetch options.
	 * @return string Fetched commit hash.
	 */
	public function fetch( $full_branch_name, $options = array() ) {
		return $this->fetch_ref( $full_branch_name, $options )['hash'];
	}

	/**
	 * Fetches the commit a ref points to and returns what the ref resolved to.
	 *
	 * @param string $ref_name Ref to fetch. See resolve_remote_ref() for the accepted forms.
	 * @param array  $options  Fetch options.
	 * @return array {
	 *     The resolved remote ref.
	 *
	 *     @type string      $hash        Fetched commit hash.
	 *     @type string|null $branch_name Short branch name, `HEAD`, or null for a tag or commit hash.
	 * }
	 */
	private function fetch_ref( $ref_name, $options = array() ) {
		// Read the tracking ref before ls-refs, which overwrites it with the remote tip.
		$branch_name           = $this->localize_ref_name( $ref_name );
		$last_fetched_head_ref = Commit::NULL_HASH;
		if ( ! self::is_commit_hash( $branch_name ) && 0 !== strncmp( $branch_name, 'refs/', strlen( 'refs/' ) ) ) {
			try {
				$last_fetched_head_ref = $this->repository->get_branch_tip( 'refs/remotes/' . $this->remote_name . '/' . $branch_name );
			} catch ( GitException $e ) {
				$last_fetched_head_ref = Commit::NULL_HASH;
			}
		}

		$remote_ref  = $this->resolve_remote_ref( $ref_name );
		$remote_head = $remote_ref['hash'];
		if ( null === $remote_ref['branch_name'] || ! $this->repository->has_object( $last_fetched_head_ref ) ) {
			$last_fetched_head_ref = Commit::NULL_HASH;
		}
		try {
			if ( $remote_head === $last_fetched_head_ref ) {
				return $remote_ref;
			}
			if ( isset( $options['path'] ) && $options['path'] ) {
				if ( ! isset( $options['shallow'] ) || true !== $options['shallow'] ) {
					throw new GitRemoteException( 'When you pass a "path" to fetch(), you must also set the "shallow" option to true. Deep partial fetches are not supported.' );
				}
				$missing_oids = $this->resolve_missing_blobs_oids(
					$remote_head,
					array( 'path' => $options['path'] )
				);
				if ( count( $missing_oids ) > 0 ) {
					$this->git_upload_pack(
						array(
							'want_refs' => $missing_oids,
						)
					)->consume_stream();
				}
			} else {
				$this->git_upload_pack(
					array(
						'want_refs' => array( $remote_head ),
						'have_refs' => array( $last_fetched_head_ref ),
					)
				)->consume_stream();
			}
			if ( null !== $remote_ref['branch_name'] ) {
				$this->repository->set_branch_tip( "refs/remotes/{$this->remote_name}/{$remote_ref['branch_name']}", $remote_head );
			}

			return $remote_ref;
		} finally {
			// Make double sure we have all the relevant objects from the remote commit.
			// @TODO: investigate why sometimes the root tree is missing and address the.
			// root cause instead of plugging the hole with a bandaid.
			if ( ! isset( $options['path'] ) || '/' === $options['path'] || '' === $options['path'] ) {
				if ( ! $this->repository->has_all_objects_from_commit( $remote_head ) ) {
					$this->git_upload_pack(
						array(
							'want_refs' => array( $remote_head ),
							'shallow'   => array( $remote_head ),
							'deepen'    => 1,
						)
					)->consume_stream();
				}
			}
		}
	}

	public function resolve_first_common_ancestor( $remote_commit_hash, $local_commit_hash ) {
		try {
			return $this->repository->find_first_common_ancestor(
				$remote_commit_hash,
				$local_commit_hash
			);
		} catch ( GitException $e ) {
			// No common ancestor available, let's fetch the missing commits.
		}

		$this->git_upload_pack(
			array(
				'want_refs' => array( $remote_commit_hash ),
				'have_refs' => $this->repository->get_ancestors_hashes(
					array(
						'commit_hash' => $local_commit_hash,

						// Arbitrary number of ancestors to send to the remote server.
						// Hopefully one of them is also an ancestor of the remote commit.
						// @TODO: Find an exact solution instead of handwaving.
						'count'       => 100,

						// Just get as many parents as we have. Don't enforce having
						// exactly 100 hashes available.
						'on_missing'  => 'return-early',
					)
				),
				// Only fetch the commits. Ignore any associated trees and blobs.
				// We're answering a question about a common ancestor in the commit
				// graph. We don't need all the extra downloads to do that.
				'filter'    => 'tree:0',
			)
		)->consume_stream();

		try {
			return $this->repository->find_first_common_ancestor(
				$remote_commit_hash,
				$local_commit_hash
			);
		} catch ( GitException $e ) {
			// Still no common ancestor available, let's fetch all the remote commit.
			// ancestors.
		}

		$this->git_upload_pack(
			array(
				'want_refs' => array( $remote_commit_hash ),
				// Don't advertise we have any related commits available. This way the remote
				// will send all the ancestor commits of $remote_commit_hash.
				'have_refs' => array(),
				// Only fetch the commits. Ignore any associated trees and blobs.
				// We're answering a question about a common ancestor in the commit
				// graph. We don't need all the extra downloads to do that.
				'filter'    => 'tree:0',
			)
		)->consume_stream();

		try {
			return $this->repository->find_first_common_ancestor(
				$remote_commit_hash,
				$local_commit_hash
			);
		} catch ( GitException $e ) {
			throw new GitRemoteException(
				"Remote commit $remote_commit_hash has no common ancestors with the local commit $local_commit_hash.",
				0,
				$e
			);
		}
	}

	public function git_upload_pack( $options = array() ) {
		if ( empty( $options['want_refs'] ) ) {
			throw new GitRemoteException( '$want_refs argument was empty. At least one commit hash must be provided.' );
		}
		$want_refs    = $options['want_refs'];
		$packet_lines = array();
		for ( $i = 0; $i < count( $want_refs ); $i++ ) {
			$packet_line = "want {$want_refs[$i]}";
			if ( 0 === $i ) {
				$packet_line .= ' multi_ack_detailed no-done side-band-64k ofs-delta thin-pack agent=git/2.37.3 filter';
			}
			$packet_line   .= "\n";
			$packet_lines[] = $packet_line;
		}

		if ( isset( $options['filter'] ) ) {
			$packet_lines[] = "filter {$options['filter']}\n";
		}

		if ( isset( $options['shallow'] ) ) {
			foreach ( $options['shallow'] as $oid ) {
				$packet_lines[] = "shallow {$oid}\n";
			}
		}

		if ( isset( $options['deepen'] ) ) {
			$packet_lines[] = 'deepen ' . $options['deepen'] . "\n";
		}

		$have_refs = $options['have_refs'] ?? array();
		if ( count( $have_refs ) > 0 ) {
			$sent_flush = false;
			foreach ( $have_refs as $have_ref ) {
				if ( Commit::is_null_hash( $have_ref ) ) {
					continue;
				}
				if ( ! $sent_flush ) {
					$packet_lines[] = '0000';
					$sent_flush     = true;
				}
				$packet_lines[] = "have {$have_ref}\n";
			}
		}
		$packet_lines[]  = '0000';
		$packet_lines[]  = "done\n";
		$response_stream = $this->http_request(
			'/git-upload-pack',
			GitProtocolEncoderPipe::encode_packet_lines( $packet_lines ),
			array(
				'Accept'       => 'application/x-git-upload-pack-advertisement',
				'Content-Type' => 'application/x-git-upload-pack-request',
			)
		);

		return new GitProtocolDecoder(
			$response_stream,
			array(
				'write_to_repository' => $this->repository,
			)
		);
	}

	private function http_request( $path, $post_data = null, $headers = array() ) {
		$remote = $this->repository->get_remote( $this->remote_name );
		if ( ! $remote ) {
			throw new GitRemoteException( 'Remote "' . $this->remote_name . '" not found' );
		}
		$url = $remote['url'] . $path;

		// @TODO: Make it configurable in Playground, maybe via a filter.
		$request_info = array(
			'headers' => $headers,
		);
		if ( $post_data ) {
			$request_info['method']      = 'POST';
			$request_info['body_stream'] = is_string( $post_data ) ? new MemoryPipe( $post_data ) : $post_data;
		}

		$request = new Request( $url, $request_info );
		$reader  = $this->http_client->fetch( $request );

		$response = $reader->await_response();
		if ( $response->status_code > 299 || $response->status_code < 200 ) {
			// GitHub sometimes responds with 100 status code when the request is successful.
			if ( 100 !== $response->status_code ) {
				$reader->pull( 100 );
				throw new GitRemoteException( 'HTTP request failed with status code ' . $response->status_code . '. First 100 body bytes: ' . $reader->peek( 100 ) );
			}
		}

		return $reader;
	}
}
