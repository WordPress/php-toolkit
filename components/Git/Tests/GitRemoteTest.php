<?php

namespace WordPress\Git\Tests;

use PHPUnit\Framework\TestCase;
use WordPress\ByteStream\MemoryPipe;
use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Git\GitEndpoint;
use WordPress\Git\GitFilesystem;
use WordPress\Git\GitRemote;
use WordPress\Git\GitRemoteException;
use WordPress\Git\GitRepository;
use WordPress\Git\Protocol\GitProtocolEncoderPipe;
use WordPress\HttpClient\Response;

class GitRemoteTest extends TestCase {

	/**
	 * Verifies the issue #292 path: a caller can request the remote symbolic
	 * HEAD ref and read files from the fetched commit through GitFilesystem.
	 */
	public function test_pull_head_resolves_remote_head() {
		$remote_repository = new GitRepository(
			InMemoryFilesystem::create(),
			array(
				'default_branch' => 'main',
			)
		);
		$remote_head       = $remote_repository->commit(
			array(
				'updates' => array(
					'README.md' => 'Hello from HEAD',
				),
			)
		);

		$local_repository = new GitRepository( InMemoryFilesystem::create() );
		$local_repository->add_remote( 'origin', 'https://example.com/repo.git' );

		$remote = new GitRemote(
			$local_repository,
			'origin',
			array(
				'http_client' => new GitRemoteTestClient( new GitEndpoint( $remote_repository ) ),
			)
		);

		// The return value should be the commit advertised by the remote HEAD.
		$this->assertSame( $remote_head, $remote->pull( 'HEAD' ) );

		// The local repository should also point HEAD at that fetched commit.
		$this->assertSame( $remote_head, $local_repository->get_branch_tip( 'HEAD' ) );

		// The fetched commit's tree should be available for Blueprint git:directory use.
		$this->assertSame( 'Hello from HEAD', GitFilesystem::create( $local_repository )->get_contents( '/README.md' ) );
	}

	/**
	 * Verifies issue #306: branches, tags, and commit hashes resolve on the
	 * remote, not only HEAD.
	 *
	 * @dataProvider provide_refs_other_than_head
	 */
	public function test_pull_resolves_refs_other_than_head( $ref, $expected_commit, $expected_local_ref ) {
		$remote_repository = new GitRepository(
			InMemoryFilesystem::create(),
			array(
				'default_branch' => 'main',
			)
		);
		$commits           = array(
			'first'  => $remote_repository->commit( array( 'updates' => array( 'README.md' => 'first' ) ) ),
			'second' => $remote_repository->commit( array( 'updates' => array( 'README.md' => 'second' ) ) ),
		);
		$remote_repository->set_branch_tip( 'refs/heads/dist/main', $commits['first'] );
		$remote_repository->get_object_storage_filesystem()->mkdir( 'refs/tags' );
		$remote_repository->get_object_storage_filesystem()->put_contents( 'refs/tags/v1.0', $commits['first'] );

		$local_repository = new GitRepository( InMemoryFilesystem::create() );
		$local_repository->add_remote( 'origin', 'https://example.com/repo.git' );
		$remote = new GitRemote(
			$local_repository,
			'origin',
			array(
				'http_client' => new GitRemoteTestClient( new GitEndpoint( $remote_repository ) ),
			)
		);

		$ref           = str_replace( array( '{first}', '{second}' ), $commits, $ref );
		$pulled_commit = $remote->pull( $ref );

		$this->assertSame( $commits[ $expected_commit ], $pulled_commit );
		$this->assertSame( $commits[ $expected_commit ], $local_repository->get_branch_tip( $expected_local_ref ) );

		$local_repository->checkout( $pulled_commit );
		$this->assertSame( $expected_commit, GitFilesystem::create( $local_repository )->get_contents( '/README.md' ) );
	}

	public static function provide_refs_other_than_head() {
		return array(
			'short branch name'        => array( 'main', 'second', 'refs/heads/main' ),
			'short name with a slash'  => array( 'dist/main', 'first', 'refs/heads/dist/main' ),
			'full branch name'         => array( 'refs/heads/dist/main', 'first', 'refs/heads/dist/main' ),
			'short tag name'           => array( 'v1.0', 'first', 'HEAD' ),
			'full tag name'            => array( 'refs/tags/v1.0', 'first', 'HEAD' ),
			'commit hash below a tip'  => array( '{first}', 'first', 'HEAD' ),
		);
	}

	/**
	 * Verifies a fetched branch updates its remote-tracking ref, and a tag does not create one.
	 */
	public function test_fetch_updates_the_tracking_ref_only_for_branches() {
		$remote_repository = new GitRepository(
			InMemoryFilesystem::create(),
			array(
				'default_branch' => 'main',
			)
		);
		$commit            = $remote_repository->commit( array( 'updates' => array( 'README.md' => 'first' ) ) );
		$remote_repository->get_object_storage_filesystem()->mkdir( 'refs/tags' );
		$remote_repository->get_object_storage_filesystem()->put_contents( 'refs/tags/v1.0', $commit );

		$local_repository = new GitRepository( InMemoryFilesystem::create() );
		$local_repository->add_remote( 'origin', 'https://example.com/repo.git' );
		$remote = new GitRemote(
			$local_repository,
			'origin',
			array(
				'http_client' => new GitRemoteTestClient( new GitEndpoint( $remote_repository ) ),
			)
		);

		$this->assertSame( $commit, $remote->fetch( 'v1.0' ) );
		$this->assertFalse( $local_repository->branch_exists( 'refs/remotes/origin/v1.0' ) );
		$this->assertFalse( $local_repository->branch_exists( 'refs/heads/v1.0' ) );

		$this->assertSame( $commit, $remote->fetch( 'main' ) );
		$this->assertSame( $commit, $local_repository->get_branch_tip( 'refs/remotes/origin/main' ) );
	}

	/**
	 * Verifies a ref that is neither a branch nor a tag on the remote is reported by name.
	 */
	public function test_pull_reports_a_missing_ref() {
		$remote_repository = new GitRepository( InMemoryFilesystem::create() );
		$remote_repository->commit( array( 'updates' => array( 'README.md' => 'first' ) ) );

		$local_repository = new GitRepository( InMemoryFilesystem::create() );
		$local_repository->add_remote( 'origin', 'https://example.com/repo.git' );
		$remote = new GitRemote(
			$local_repository,
			'origin',
			array(
				'http_client' => new GitRemoteTestClient( new GitEndpoint( $remote_repository ) ),
			)
		);

		$this->expectException( GitRemoteException::class );
		$this->expectExceptionMessage( 'Ref "missing" not found on remote origin' );
		$remote->pull( 'missing' );
	}

	/**
	 * Verifies ls-refs attributes are parsed separately from ref names.
	 */
	public function test_ls_refs_strips_nul_attributes_and_reads_peeled_hash() {
		$tag_hash    = '1111111111111111111111111111111111111111';
		$peeled_hash = '2222222222222222222222222222222222222222';

		$local_repository = new GitRepository( InMemoryFilesystem::create() );
		$local_repository->add_remote( 'origin', 'https://example.com/repo.git' );

		$remote = new GitRemote(
			$local_repository,
			'origin',
			array(
				'http_client' => new GitRemoteStaticResponseClient(
					GitProtocolEncoderPipe::encode_packet_lines(
						array(
							$tag_hash . " refs/tags/v1.0\0peeled:" . $peeled_hash . "\n",
							'0000',
						)
					)
				),
			)
		);

		$this->assertSame(
			array( 'refs/tags/v1.0' => $peeled_hash ),
			$remote->ls_refs( 'refs/tags/v1.0' )
		);
	}
}

/**
 * Minimal in-process Git HTTP client used by GitRemoteTest.
 */
class GitRemoteTestClient {

	private $endpoint;

	public function __construct( GitEndpoint $endpoint ) {
		$this->endpoint = $endpoint;
	}

	public function fetch( $request, array $options = array() ) {
		$path         = parse_url( $request->url, PHP_URL_PATH );
		$request_body = $request->upload_body_stream ? $request->upload_body_stream->consume_all() : '';
		$response     = new GitProtocolEncoderPipe();

		// Route GitRemote's upload-pack requests to the in-memory GitEndpoint.
		if ( 0 === substr_compare( $path, '/git-upload-pack', - strlen( '/git-upload-pack' ) ) ) {
			$parsed  = $this->endpoint->parse_message( $request_body );
			$command = $parsed['capabilities']['command'] ?? 'fetch';
			switch ( $command ) {
				case 'ls-refs':
					$this->endpoint->handle_ls_refs_request( $request_body, $response );
					break;
				case 'fetch':
					if ( ! isset( $parsed['capabilities']['command'] ) ) {
						$request_body = $this->normalize_legacy_fetch_request( $request_body );
					}
					$this->endpoint->handle_fetch_request( $request_body, $response );
					break;
			}
		}

		return new GitRemoteTestResponseStream( $response->consume_all(), $request );
	}

	private function normalize_legacy_fetch_request( $request_body ) {
		/*
		 * GitRemote::git_upload_pack() currently emits a compact fetch request
		 * without the protocol v2 command header. The test endpoint expects that
		 * header, so normalize only the test request before routing it.
		 */
		$offset = 0;
		$lines  = array(
			"command=fetch\n",
			"agent=git/2.37.3\n",
			"object-format=sha1\n",
			'0001',
		);

		while ( $packet = GitEndpoint::decode_next_packet_line( $request_body, $offset ) ) {
			if ( '#packet' !== $packet['type'] ) {
				continue;
			}

			$payload = $packet['payload'];
			if ( 0 === strpos( $payload, 'want ' ) ) {
				$parts   = explode( ' ', $payload );
				$payload = 'want ' . $parts[1];
			}

			$lines[] = $payload . "\n";
		}
		$lines[] = '0000';

		return GitProtocolEncoderPipe::encode_packet_lines( $lines );
	}
}

/**
 * Memory-backed response stream with the await_response() method GitRemote expects.
 */
class GitRemoteTestResponseStream extends MemoryPipe {

	private $response;

	public function __construct( $bytes, $request ) {
		parent::__construct( $bytes );
		$this->response              = new Response( $request );
		$this->response->status_code = 200;
	}

	public function await_response() {
		return $this->response;
	}
}

class GitRemoteStaticResponseClient {

	private $response_bytes;

	public function __construct( $response_bytes ) {
		$this->response_bytes = $response_bytes;
	}

	public function fetch( $request, array $options = array() ) {
		return new GitRemoteTestResponseStream( $this->response_bytes, $request );
	}
}
