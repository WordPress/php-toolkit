<?php
/**
 * @TODO: Support different ref formats:
 * – nice branch names like "trunk"
 * – full branch names like "refs/heads/trunk"
 * – tags
 * – commit hashes
 * - ... what else? ...
 *
 * Currently, we only support full branch names.
 */

namespace WordPress\Git;

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
					$ref_line                 = $protocol->get_packet_body();
					$ref                      = $this->parse_ref_line( $ref_line );
					$refs[ $ref['ref_name'] ] = $ref['hash'];
					if ( false === $ref ) {
						continue 2;
					}

					if ( strncmp( $ref['ref_name'], 'refs/heads/', strlen( 'refs/heads/' ) ) === 0 ) {
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

	private function parse_ref_line( $ref_line ) {
		$space_pos = strpos( $ref_line, ' ' );
		if ( $space_pos === false ) {
			return false;
		}
		$hash     = substr( $ref_line, 0, $space_pos );
		$ref_name = substr( $ref_line, $space_pos + 1 );

		// Check for peeled hash at end
		if ( preg_match( '/^(.+) peeled:([a-f0-9]{40})$/', $ref_name, $matches ) ) {
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

		// @TODO: Respect a subpath
		$common_ancestor = $this->resolve_first_common_ancestor( $remote_commit, $push_commit );
		$delta           = $this->repository->find_objects_added_since( $push_commit, $common_ancestor );
		if ( ! count( $delta ) ) {
			// Don't push empty commits
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
		if ( $data_packets != $expected_response ) {
			throw new GitRemoteException( 'Push failed:' . var_export( $data_packets, true ) );
		}

		$this->repository->set_branch_tip( 'refs/remotes/' . $this->remote_name . '/' . $short_branch_name, $push_commit );
	}

	private function localize_ref_name( $ref_name ) {
		if ( strncmp( $ref_name, 'ref: ', strlen( 'ref: ' ) ) === 0 ) {
			$ref_name = trim( substr( $ref_name, 5 ) );
		}
		if ( strncmp( $ref_name, 'refs/heads/', strlen( 'refs/heads/' ) ) === 0 ) {
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
			// Sparse pull
			$remote_head = $this->fetch(
				$full_branch_name,
				array(
					'path'    => $options['path'],
					'shallow' => $options['shallow'] ?? false,
				)
			);
		} else {
			// Full pull
			$remote_head = $this->fetch( $full_branch_name, array() );
		}

		$local_head = $this->repository->get_branch_tip( $full_branch_name );
		if ( Commit::is_null_hash( $local_head ) ) {
			// If the local head is an unborn branch, there's nothing to merge.
			// We just pull and point the head to the remote head.
			$options['force'] = true;
		}

		if ( isset( $options['force'] ) && $options['force'] ) {
			$nice_branch_name = $this->localize_ref_name( $full_branch_name );
			$this->repository->set_branch_tip( 'refs/heads/' . $nice_branch_name, $remote_head );

			return $remote_head;
		}

		// Fetch the commits we need to perform the three-way merge
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

	public function fetch( $full_branch_name, $options = array() ) {
		$branch_name = $this->localize_ref_name( $full_branch_name );
		try {
			$last_fetched_head_ref = $this->repository->get_branch_tip( 'refs/remotes/' . $this->remote_name . '/' . $branch_name );
		} catch ( GitException $e ) {
			$last_fetched_head_ref = Commit::NULL_HASH;
		}
		if ( ! $this->repository->has_object( $last_fetched_head_ref ) ) {
			$last_fetched_head_ref = Commit::NULL_HASH;
		}

		$remote_head = $this->get_remote_head( 'refs/heads/' . $branch_name );
		try {
			if ( $remote_head === $last_fetched_head_ref ) {
				return $remote_head;
			}
			if ( isset( $options['path'] ) && $options['path'] ) {
				if ( ! isset( $options['shallow'] ) || $options['shallow'] !== true ) {
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
			$this->repository->set_branch_tip( "refs/remotes/{$this->remote_name}/{$branch_name}", $remote_head );

			return $remote_head;
		} finally {
			// Make double sure we have all the relevant objects from the remote commit.
			// @TODO: investigate why sometimes the root tree is missing and address the
			// root cause instead of plugging the hole with a bandaid.
			if ( ! isset( $options['path'] ) || $options['path'] === '/' || $options['path'] === '' ) {
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
			// Still no common ancestor available, let's fetch all the remote commit
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
		for ( $i = 0; $i < count( $want_refs ); $i ++ ) {
			$packet_line = "want {$want_refs[$i]}";
			if ( $i === 0 ) {
				$packet_line .= ' multi_ack_detailed no-done side-band-64k ofs-delta thin-pack agent=git/2.37.3 filter';
			}
			$packet_line    .= "\n";
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

	private function http_request( $path, $postData = null, $headers = array() ) {
		$remote = $this->repository->get_remote( $this->remote_name );
		if ( ! $remote ) {
			throw new GitRemoteException( 'Remote "' . $this->remote_name . '" not found' );
		}
		$url = $remote['url'] . $path;

		// @TODO: Make it configurable in Playground, maybe via a filter
		$request_info = array(
			'headers' => $headers,
		);
		if ( $postData ) {
			$request_info['method']      = 'POST';
			$request_info['body_stream'] = is_string( $postData ) ? new MemoryPipe( $postData ) : $postData;
		}

		$request = new Request( $url, $request_info );
		$reader  = $this->http_client->fetch( $request );

		$response = $reader->await_response();
		if ( $response->status_code > 299 || $response->status_code < 200 ) {
			// GitHub sometimes responds with 100 status code when the request is successful.
			if ( $response->status_code !== 100 ) {
				$reader->pull( 100 );
				throw new GitRemoteException( 'HTTP request failed with status code ' . $response->status_code . '. First 100 body bytes: ' . $reader->peek( 100 ) );
			}
		}

		return $reader;
	}
}
