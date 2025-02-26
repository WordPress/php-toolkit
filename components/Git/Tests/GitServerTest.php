<?php

use PHPUnit\Framework\TestCase;
use WordPress\ByteStream\MemoryPipe;
use WordPress\ByteStream\ReadStream\ProducerProducer;
use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Git\GitEndpoint;
use WordPress\Git\GitRepository;
use WordPress\Git\Model\Commit;
use WordPress\Git\Model\Tree;
use WordPress\Git\Model\TreeEntry;
use WordPress\Git\Protocol\GitProtocolEncoderPipe;
use WordPress\Git\Protocol\Parser\GitProtocolDecoder;
use WordPress\HttpServer\ResponseWriter\BufferingResponseWriter;

class GitServerTest extends TestCase {

	private $server;
	private $repository;
	private $main_branch_oid;
	private $dev_branch_oid;

	protected function setUp(): void {
		$this->repository = new GitRepository(
			InMemoryFilesystem::create(),
			array(
				'default_branch' => 'main',
			)
		);
		$this->repository->set_branch_tip( 'HEAD', 'ref: refs/heads/main' );
		$this->repository->set_branch_tip( 'ref: refs/heads/main', Commit::NULL_HASH );
		$this->main_branch_oid = $this->repository->commit(
			array(
				'updates' => array(
					'README.md' => 'Hello, world!',
				),
			)
		);

		$this->repository->set_branch_tip( 'refs/heads/main', $this->main_branch_oid );
		$this->repository->set_branch_tip( 'refs/heads/twin', $this->main_branch_oid );
		$this->repository->set_branch_tip( 'refs/heads/main-backup', $this->main_branch_oid );
		$this->repository->set_branch_tip( 'refs/heads/dev', $this->main_branch_oid );
		$this->repository->set_branch_tip( 'HEAD', 'ref: refs/heads/dev' );

		$this->dev_branch_oid = $this->repository->commit(
			array(
				'updates' => array(
					'DEV.md' => 'Another file!',
				),
			)
		);

		$this->server = new GitEndpoint( $this->repository );
	}

	/**
	 * @dataProvider provide_request_data
	 */
	public function test_parse_message( $request, $expected ) {
		$result = $this->server->parse_message( $request );
		$this->assertBinaryEquals( $expected, $result );
	}

	public static function provide_request_data() {
		return array(
			'basic ls-refs request'                => array(
				GitProtocolEncoderPipe::encode_packet_lines(
					array(
						"command=ls-refs\n",
						'0000',
					)
				),
				array(
					'capabilities' => array(
						'command' => 'ls-refs',
					),
					'arguments'    => array(),
				),
			),
			'request with multiple capabilities'   => array(
				GitProtocolEncoderPipe::encode_packet_lines(
					array(
						"command=ls-refs\n",
						"agent=git/2.37.3\n",
						'0000',
					)
				),
				array(
					'capabilities' => array(
						'command' => 'ls-refs',
						'agent'   => 'git/2.37.3',
					),
					'arguments'    => array(),
				),
			),
			'request with multiple arguments'      => array(
				GitProtocolEncoderPipe::encode_packet_lines(
					array(
						"command=ls-refs\n",
						'0001',
						"peel\n",
						"ref-prefix HEAD\n",
						'0000',
					)
				),
				array(
					'capabilities' => array(
						'command' => 'ls-refs',
					),
					'arguments'    => array(
						'peel'       => array( true ),
						'ref-prefix' => array( 'HEAD' ),
					),
				),
			),
			'basic want request'                   => array(
				GitProtocolEncoderPipe::encode_packet_lines(
					array(
						"command=fetch\n",
						"agent=git/2.37.3\n",
						"object-format=sha1\n",
						'0000',
						"want e0d02a851d0c461a7c725dc69eb2d53f57f666a6\n",
						"done\n",
						'0000',
					)
				),
				array(
					'capabilities' => array(
						'command'       => 'fetch',
						'agent'         => 'git/2.37.3',
						'object-format' => 'sha1',
					),
					'arguments'    => array(
						'want' => array( 'e0d02a851d0c461a7c725dc69eb2d53f57f666a6' ),
						'done' => array( true ),
					),
				),
			),
			'want with have and filter'            => array(
				GitProtocolEncoderPipe::encode_packet_lines(
					array(
						"command=fetch\n",
						"agent=git/2.37.3\n",
						"object-format=sha1\n",
						'0000',
						"want e0d02a851d0c461a7c725dc69eb2d53f57f666a6\n",
						"have f5b97d7b9af357c81b5df5773329d50f764c2992\n",
						"filter blob:none\n",
						"done\n",
						'0000',
					)
				),
				array(
					'capabilities' => array(
						'command'       => 'fetch',
						'agent'         => 'git/2.37.3',
						'object-format' => 'sha1',
					),
					'arguments'    => array(
						'want'   => array( 'e0d02a851d0c461a7c725dc69eb2d53f57f666a6' ),
						'have'   => array( 'f5b97d7b9af357c81b5df5773329d50f764c2992' ),
						'filter' => array( 'blob:none' ),
						'done'   => array( true ),
					),
				),
			),
			'want with deepen and blob size limit' => array(
				GitProtocolEncoderPipe::encode_packet_lines(
					array(
						"command=fetch\n",
						"agent=git/2.37.3\n",
						"object-format=sha1\n",
						'0000',
						"want e0d02a851d0c461a7c725dc69eb2d53f57f666a6\n",
						"filter blob:limit=1000\n",
						"deepen 10\n",
						"done\n",
						'0000',
					)
				),
				array(
					'capabilities' => array(
						'command'       => 'fetch',
						'agent'         => 'git/2.37.3',
						'object-format' => 'sha1',
					),
					'arguments'    => array(
						'want'   => array( 'e0d02a851d0c461a7c725dc69eb2d53f57f666a6' ),
						'filter' => array( 'blob:limit=1000' ),
						'deepen' => array( '10' ),
						'done'   => array( true ),
					),
				),
			),
			'multiple want and have'               => array(
				GitProtocolEncoderPipe::encode_packet_lines(
					array(
						"command=fetch\n",
						'0000',
						"want e0d02a851d0c461a7c725dc69eb2d53f57f666a6\n",
						"want f10e2821bbbea527ea02200352313bc059445190\n",
						"have f5b97d7b9af357c81b5df5773329d50f764c2992\n",
						"have 0e747aaa0f03a7b7bb9a964f47fe7c508be7b086\n",
						"done\n",
						'0000',
					)
				),
				array(
					'capabilities' => array(
						'command' => 'fetch',
					),
					'arguments'    => array(
						'want' => array(
							'e0d02a851d0c461a7c725dc69eb2d53f57f666a6',
							'f10e2821bbbea527ea02200352313bc059445190',
						),
						'have' => array(
							'f5b97d7b9af357c81b5df5773329d50f764c2992',
							'0e747aaa0f03a7b7bb9a964f47fe7c508be7b086',
						),
						'done' => array( true ),
					),
				),
			),
		);
	}

	/**
	 * @dataProvider provide_ref_requests
	 */
	public function test_handle_ls_refs_returns_matching_refs( $request, $expected_response ) {
		// Replace placeholders with actual values in the test as $this->main_branch_oid and
		// $this->dev_branch_oid are not available in the data provider.
		$expected_response = str_replace(
			array( '{main_branch_oid}', '{dev_branch_oid}' ),
			array( $this->main_branch_oid, $this->dev_branch_oid ),
			$expected_response
		);
		$buffer            = new BufferingResponseWriter();
		$git_encoder       = new GitProtocolEncoderPipe( $buffer );
		$this->server->handle_ls_refs_request( $request, $git_encoder );
		$this->assertBinaryEquals( $expected_response, $git_encoder->consume_all() );
	}

	public static function provide_ref_requests() {
		return array(
			'all refs'        => array(
				GitProtocolEncoderPipe::encode_packet_lines(
					array(
						"command=ls-refs\n",
						'0000',
					)
				),
				<<<RESPONSE
015f{main_branch_oid} refs/heads/main\0multi_ack thin-pack side-band side-band-64k ofs-delta shallow deepen-since deepen-not deepen-relative no-progress include-tag multi_ack_detailed allow-tip-sha1-in-want allow-reachable-sha1-in-want no-done symref=HEAD:refs/heads/trunk filter object-format=sha1 agent=git/github-395dce4f6ecf
003d{main_branch_oid} refs/heads/twin
0044{main_branch_oid} refs/heads/main-backup
003c{dev_branch_oid} refs/heads/dev
0032{dev_branch_oid} HEAD
0000
RESPONSE
			,
			),
			'specific branch' => array(
				GitProtocolEncoderPipe::encode_packet_lines(
					array(
						"command=ls-refs\n",
						'0001',
						"peel\n",
						"ref-prefix refs/heads/main\n",
						'0000',
					)
				),
				<<<RESPONSE
015f{main_branch_oid} refs/heads/main\0multi_ack thin-pack side-band side-band-64k ofs-delta shallow deepen-since deepen-not deepen-relative no-progress include-tag multi_ack_detailed allow-tip-sha1-in-want allow-reachable-sha1-in-want no-done symref=HEAD:refs/heads/trunk filter object-format=sha1 agent=git/github-395dce4f6ecf
0044{main_branch_oid} refs/heads/main-backup
0000
RESPONSE
			,
			),
			'HEAD ref'        => array(
				GitProtocolEncoderPipe::encode_packet_lines(
					array(
						"command=ls-refs\n",
						'0001',
						"peel\n",
						"ref-prefix HEAD\n",
						'0000',
					)
				),
				<<<RESPONSE
0154{dev_branch_oid} HEAD\0multi_ack thin-pack side-band side-band-64k ofs-delta shallow deepen-since deepen-not deepen-relative no-progress include-tag multi_ack_detailed allow-tip-sha1-in-want allow-reachable-sha1-in-want no-done symref=HEAD:refs/heads/trunk filter object-format=sha1 agent=git/github-395dce4f6ecf
0000
RESPONSE
			,
			),
		);
	}

	public function test_handle_fetch_request_returns_packfile() {
		// Create a more complex repository structure for testing
		$readme_oid     = $this->repository->add_object(
			'blob',
			'# Hello World'
		);
		$large_file_oid = $this->repository->add_object(
			'blob',
			str_repeat( 'x', 2048 ) // 2KB file
		);

		$tree_oid = $this->repository->add_object(
			'tree',
			GitProtocolEncoderPipe::encode_tree_bytes(
				new Tree(
					array(
						new TreeEntry(
							array(
								'mode' => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
								'name' => 'README.md',
								'hash' => $readme_oid,
							)
						),
						new TreeEntry(
							array(
								'mode' => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
								'name' => 'large.txt',
								'hash' => $large_file_oid,
							)
						),
					)
				)
			)
		);

		$commit_oid = $this->repository->add_object(
			'commit',
			"tree $tree_oid\nparent 0000000000000000000000000000000000000000\nauthor Test <test@example.com> 1234567890 +0000\ncommitter Test <test@example.com> 1234567890 +0000\n\nInitial commit\n"
		);

		$test_cases = array(
			'basic fetch'                 => array(
				'request'       => GitProtocolEncoderPipe::encode_packet_lines(
					array(
						"command=fetch\n",
						'0000',
						"want $commit_oid\n",
						"done\n",
						'0000',
					)
				),
				'expected_oids' => array(
					$commit_oid,
					$tree_oid,
					$readme_oid,
					$large_file_oid,
				),
			),
			'fetch with blob:none filter' => array(
				'request'       => GitProtocolEncoderPipe::encode_packet_lines(
					array(
						"command=fetch\n",
						'0000',
						"want $commit_oid\n",
						"filter blob:none\n",
						"done\n",
						'0000',
					)
				),
				'expected_oids' => array(
					$commit_oid,
					$tree_oid,
				),
			),
			'fetch with blob size limit'  => array(
				'request'       => GitProtocolEncoderPipe::encode_packet_lines(
					array(
						"command=fetch\n",
						'0000',
						"want $commit_oid\n",
						"filter blob:limit=1000\n",
						"done\n",
						'0000',
					)
				),
				'expected_oids' => array(
					$commit_oid,
					$tree_oid,
					$readme_oid,
				),
			),
			'fetch with multiple wants'   => array(
				'request'       => GitProtocolEncoderPipe::encode_packet_lines(
					array(
						"command=fetch\n",
						'0000',
						"want $commit_oid\n",
						"want $tree_oid\n",
						"done\n",
						'0000',
					)
				),
				// same objects, just different entry point
				'expected_oids' => array(
					$commit_oid,
					$tree_oid,
					$readme_oid,
					$large_file_oid,
				),
			),
		);

		foreach ( $test_cases as $name => $test ) {
			/** @var BufferingResponseWriter */
			$response    = $this->getMockBuilder( BufferingResponseWriter::class )
								->onlyMethods( array( 'close_writing' ) )
								->getMock();
			$git_encoder = new GitProtocolEncoderPipe( $response );
			$this->server->handle_fetch_request( $test['request'], $git_encoder );

			// Verify response format
			$response                = $git_encoder->consume_all();
			$expected_response_start = GitProtocolEncoderPipe::encode_packet_lines(
				array(
					"packfile\n",
				)
			);
			$actual_response_start   = substr( $response, 0, strlen( $expected_response_start ) );
			$this->assertBinaryEquals(
				$expected_response_start,
				$actual_response_start,
				"$name: Response should start with packfile header"
			);

			$rest_of_response = substr( $response, strlen( $expected_response_start ) );
			$reader           = new GitProtocolDecoder(
				new MemoryPipe( $rest_of_response ),
				array(
					'write_to_repository' => $this->repository,
				)
			);

			$found_oids = array();
			while ( $reader->next_token() ) {
				if ( $reader->get_token_type() === '#object-hash' ) {
					$found_oids[] = $reader->get_pack_parser()->get_object_hash();
				}
			}

			$this->assertCount(
				count( $test['expected_oids'] ),
				$found_oids,
				"$name: Pack should contain expected number of objects"
			);
			foreach ( $found_oids as $oid ) {
				$this->assertContains( $oid, $test['expected_oids'] );
			}
		}
	}

	// public function test_handle_fetch_request_validates_filter() {
	// $this->expectException(Exception::class);
	// $this->expectExceptionMessage('Invalid filter: invalid:filter');

	// $request = GitPackProcessor::encode_packet_lines([
	// "command=fetch\n",
	// "0000",
	// "want " . $this->main_branch_oid . "\n",
	// "filter invalid:filter\n",
	// "done\n",
	// "0000",
	// ]);

	// $this->server->handle_fetch_request($request);
	// }

	// public function test_handle_fetch_request_requires_want() {
	// $request = GitPackProcessor::encode_packet_lines([
	// "command=fetch\n",
	// "0000",
	// "done\n",
	// "0000",
	// ]);

	// $this->assertFalse(
	// $this->server->handle_fetch_request($request),
	// "Fetch request without want should return false"
	// );
	// }

	public function test_handle_push_request() {
		// Create test objects
		$readme_oid = $this->repository->add_object(
			'blob',
			'# New Content'
		);

		$tree_oid = $this->repository->add_object(
			'tree',
			GitProtocolEncoderPipe::encode_tree_bytes(
				new Tree(
					array(
						new TreeEntry(
							array(
								'mode' => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
								'name' => 'README.md',
								'hash' => $readme_oid,
							)
						),
					)
				)
			)
		);

		$commit_oid = $this->repository->add_object(
			'commit',
			"tree $tree_oid\nparent 0000000000000000000000000000000000000000\nauthor Test <test@example.com> 1234567890 +0000\ncommitter Test <test@example.com> 1234567890 +0000\n\nPush test\n"
		);

		$test_cases = array(
			'basic push' => array(
				'request'      => GitProtocolEncoderPipe::encode_packet_lines(
					array(
						"0000000000000000000000000000000000000000 $commit_oid refs/heads/main\0\n",
						'0000',
					)
				),
				'expected_ref' => 'refs/heads/main',
				'expected_oid' => $commit_oid,
			),
			'delete ref' => array(
				'request'      => GitProtocolEncoderPipe::encode_packet_lines(
					array(
						"$commit_oid 0000000000000000000000000000000000000000 refs/heads/main\0\n",
						'0000',
					)
				),
				'expected_ref' => 'refs/heads/main',
				'expected_oid' => null,
			),
		);

		foreach ( $test_cases as $name => $test ) {
			/** @var BufferingResponseWriter */
			$response = $this->getMockBuilder( BufferingResponseWriter::class )
							->onlyMethods( array( 'close_writing' ) )
							->getMock();

			$git_encoder = new GitProtocolEncoderPipe( $response );
			$this->server->handle_push_request( $test['request'], $git_encoder );

			$response_body = $git_encoder->consume_all();
			// Should contain "ok" response
			$this->assertStringContainsString(
				'ok ' . $test['expected_ref'] . "\n",
				$response_body,
				"$name: Response should contain success message"
			);

			if ( $test['expected_oid'] === null ) {
				// Should be deleted
				$this->assertFalse(
					$this->repository->branch_exists( $test['expected_ref'] ),
					"$name: Ref should be deleted"
				);
			} else {
				// Should be updated
				$this->assertBinaryEquals(
					$test['expected_oid'],
					$this->repository->get_branch_tip( $test['expected_ref'] ),
					"$name: Ref should be updated to new commit"
				);
			}
		}
	}

	public function assertBinaryEquals( $expected, $actual, $message = null ) {
		$this->assertEquals(
			var_export( $expected, true ),
			var_export( $actual, true ),
			$message ?? 'Binary data should be equal'
		);
	}
}
