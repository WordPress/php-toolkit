<?php

namespace WordPress\Git\Tests;

use PHPUnit\Framework\TestCase;
use WordPress\ByteStream\MemoryPipe;
use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Git\GitEndpoint;
use WordPress\Git\GitFilesystem;
use WordPress\Git\GitRemote;
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
