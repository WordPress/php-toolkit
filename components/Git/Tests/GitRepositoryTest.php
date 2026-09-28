<?php

namespace WordPress\Git\Tests;

use PHPUnit\Framework\TestCase;
use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Git\GitRepository;
use WordPress\Git\Model\Commit;
use WordPress\Git\Model\Tree;
use WordPress\Git\Model\TreeEntry;
use WordPress\Git\Protocol\GitProtocolEncoderPipe;

use function WordPress\Git\get_all_descendant_oids_in_tree;

class GitRepositoryTest extends TestCase {

	public function test_add_new_object() {
		$blob_data = 'Hello, world!';
		$repo      = new GitRepository( InMemoryFilesystem::create() );
		$writer    = $repo->new_object_open_write_stream( 'blob', strlen( $blob_data ) );
		$writer->append_bytes( $blob_data );
		$writer->close_writing();

		$oid = $writer->get_hash();
		$this->assertEquals( '5dd01c177f5d7d1be5346a5bc18a569a7410c2ef', $oid );

		$reader   = $repo->read_object( $oid );
		$nb_bytes = $reader->pull( 8096 );
		$this->assertEquals( $blob_data, $reader->peek( $nb_bytes ) );
	}

	public function test_seek() {
		$blob_data = 'Hello, world!';
		$repo      = new GitRepository( InMemoryFilesystem::create() );
		$writer    = $repo->new_object_open_write_stream( 'blob', strlen( $blob_data ) );
		$writer->append_bytes( $blob_data );
		$writer->close_writing();

		$oid      = $writer->get_hash();
		$reader   = $repo->read_object( $oid );
		$nb_bytes = $reader->pull( 8096 );
		$this->assertEquals( $blob_data, $reader->peek( $nb_bytes ) );

		$reader->seek( 5 );
		$nb_bytes = $reader->pull( 7 );
		$this->assertEquals( ', world', $reader->peek( $nb_bytes ) );

		$reader->seek( 7 );
		$nb_bytes = $reader->pull( 5 );
		$this->assertEquals( 'world', $reader->peek( $nb_bytes ) );

		$reader->seek( 0 );
		$nb_bytes = $reader->pull( 10 );
		$this->assertEquals( 'Hello, wor', $reader->peek( $nb_bytes ) );
	}

	public function test_find_hash_by_path() {
		$repo              = new GitRepository( InMemoryFilesystem::create() );
		$blob_oid          = $repo->add_object( 'blob', 'Hello, world!' );
		$tree_oid          = $repo->add_object(
			'tree',
			GitProtocolEncoderPipe::encode_tree_bytes(
				new Tree(
					array(
						new TreeEntry(
							array(
								'mode' => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
								'name' => 'hello-world.txt',
								'hash' => $blob_oid,
							)
						),
					)
				)
			)
		);
		$root_tree_oid     = $repo->add_object(
			'tree',
			GitProtocolEncoderPipe::encode_tree_bytes(
				new Tree(
					array(
						new TreeEntry(
							array(
								'mode' => TreeEntry::FILE_MODE_DIRECTORY,
								'name' => 'subdirectory',
								'hash' => $tree_oid,
							)
						),
					)
				)
			)
		);
		$parent_commit_oid = Commit::NULL_HASH;
		$commit_oid        = $repo->add_object(
			'commit',
			<<<COMMIT
tree $root_tree_oid
parent $parent_commit_oid
author John Doe <john.doe@example.com> 1713542400 +0000
committer John Doe <john.doe@example.com> 1713542400 +0000
message Hello, world!
COMMIT
		);
		$this->assertEquals( $blob_oid, $repo->find_hash_by_path( '/subdirectory/hello-world.txt', $commit_oid ) );
	}

	public function test_read_object_by_path() {
		$repo              = new GitRepository( InMemoryFilesystem::create() );
		$blob_oid          = $repo->add_object( 'blob', 'Hello, world!' );
		$tree_oid          = $repo->add_object(
			'tree',
			GitProtocolEncoderPipe::encode_tree_bytes(
				new Tree(
					array(
						new TreeEntry(
							array(
								'mode' => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
								'name' => 'hello-world.txt',
								'hash' => $blob_oid,
							)
						),
					)
				)
			)
		);
		$root_tree_oid     = $repo->add_object(
			'tree',
			GitProtocolEncoderPipe::encode_tree_bytes(
				new Tree(
					array(
						new TreeEntry(
							array(
								'mode' => TreeEntry::FILE_MODE_DIRECTORY,
								'name' => 'subdirectory',
								'hash' => $tree_oid,
							)
						),
					)
				)
			)
		);
		$parent_commit_oid = Commit::NULL_HASH;
		$commit_oid        = $repo->add_object(
			'commit',
			<<<COMMIT
tree $root_tree_oid
parent $parent_commit_oid
author John Doe <john.doe@example.com> 1713542400 +0000
committer John Doe <john.doe@example.com> 1713542400 +0000
message Hello, world!
COMMIT
		);
		$this->assertEquals( 'Hello, world!', $repo->read_object_by_path( '/subdirectory/hello-world.txt', $commit_oid )->consume_all() );
	}

	public function test_get_ref_head() {
		$repo = new GitRepository( InMemoryFilesystem::create() );
		$repo->set_branch_tip( 'refs/heads/master', '6a59200c7c2330ebacd7830ea59e63e7c37f9287' );
		$repo->set_branch_tip( 'HEAD', 'ref: refs/heads/master' );
		$this->assertEquals( '6a59200c7c2330ebacd7830ea59e63e7c37f9287', $repo->get_branch_tip() );
	}

	public function test_commit() {
		$repo = new GitRepository( InMemoryFilesystem::create() );
		$repo->set_branch_tip( 'refs/heads/trunk', Commit::NULL_HASH );
		$repo->set_branch_tip( 'HEAD', 'ref: refs/heads/trunk' );
		$commit_oid = $repo->commit(
			array(
				'updates' => array(
					'hello-world.txt' => 'Hello, world!',
				),
			)
		);
		$this->assertEquals( $commit_oid, $repo->get_branch_tip() );
	}

	public function test_initial_commit_has_no_null_parent() {
		$repo = new GitRepository( InMemoryFilesystem::create() );
		$repo->set_branch_tip( 'refs/heads/trunk', Commit::NULL_HASH );
		$repo->set_branch_tip( 'HEAD', 'ref: refs/heads/trunk' );

		$commit_oid = $repo->commit(
			array(
				'updates' => array(
					'README.md' => 'Hello, world!',
				),
			)
		);

		$this->assertSame( array(), $repo->read_object( $commit_oid )->as_commit()->parents );
	}

	public function test_find_path_descendants() {
		$repo = new GitRepository( InMemoryFilesystem::create() );
		$repo->set_branch_tip( 'refs/heads/trunk', Commit::NULL_HASH );
		$repo->set_branch_tip( 'HEAD', 'ref: refs/heads/trunk' );
		$commit_oid  = $repo->commit(
			array(
				'updates' => array(
					'subdirectory/hello-world.txt' => 'Hello, world!',
					'subdirectory/README.md'       => '# README file',
				),
			)
		);
		$tree_oid    = $repo->find_hash_by_path( '/subdirectory' );
		$descendants = get_all_descendant_oids_in_tree( $repo, $tree_oid );
		$this->assertCount( 2, $descendants );
	}

	public function test_has_object() {
		$repo     = new GitRepository( InMemoryFilesystem::create() );
		$blob_oid = $repo->add_object( 'blob', 'Hello, world!' );

		$this->assertTrue( $repo->has_object( $blob_oid ) );
		$this->assertFalse( $repo->has_object( 'nonexistent' ) );
	}

	public function test_find_objects_added_in() {
		$repo = new GitRepository( InMemoryFilesystem::create() );

		// Create first commit
		$blob1_oid   = $repo->add_object( 'blob', 'First file' );
		$tree1_oid   = $repo->add_object(
			'tree',
			GitProtocolEncoderPipe::encode_tree_bytes(
				new Tree(
					array(
						new TreeEntry(
							array(
								'mode' => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
								'name' => 'file1.txt',
								'hash' => $blob1_oid,
							)
						),
					)
				)
			)
		);
		$commit1_oid = $repo->add_object( 'commit', "tree $tree1_oid\n\nFirst commit" );

		// Create second commit
		$blob2_oid   = $repo->add_object( 'blob', 'Second file' );
		$tree2_oid   = $repo->add_object(
			'tree',
			GitProtocolEncoderPipe::encode_tree_bytes(
				new Tree(
					array(
						new TreeEntry(
							array(
								'mode' => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
								'name' => 'file1.txt',
								'hash' => $blob1_oid,
							)
						),
						new TreeEntry(
							array(
								'mode' => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
								'name' => 'file2.txt',
								'hash' => $blob2_oid,
							)
						),
					)
				)
			)
		);
		$commit2_oid = $repo->add_object( 'commit', "tree $tree2_oid\nparent $commit1_oid\n\nSecond commit" );

		// Test finding new objects between commits
		$new_objects = $repo->find_objects_added_in( $commit2_oid, $commit1_oid );
		$this->assertCount( 3, $new_objects );
		$this->assertContains( $commit2_oid, $new_objects );
		$this->assertContains( $tree2_oid, $new_objects );
		$this->assertContains( $blob2_oid, $new_objects );
	}

	public function test_merge_no_conflicts() {
		$repo         = new GitRepository( InMemoryFilesystem::create() );
		$initial_hash = $repo->commit(
			array(
				'updates' => array(
					'dir1/file1.txt' => 'Initial content of file1',
					'dir2/file2.txt' => 'Initial content of file2',
				),
			)
		);

		$branch_a_hash = $repo->commit(
			array(
				'updates' => array(
					'dir1/file1.txt'         => 'Updated content of file1 in branch A',
					'dir1/subdir1/file3.txt' => 'New content of file3 in branch A',
				),
			)
		);

		$repo->create_branch( 'refs/heads/branch_b', $initial_hash );
		$repo->checkout( 'refs/heads/branch_b' );

		$branch_b_hash = $repo->commit(
			array(
				'updates' => array(
					'dir2/file2.txt'         => 'Updated content of file2 in branch B',
					'dir2/subdir2/file4.txt' => 'New content of file4 in branch B',
				),
			)
		);

		$repo->checkout( 'refs/heads/trunk' );

		$result = $repo->merge( 'refs/heads/branch_b' );
		$this->assertEquals( 40, strlen( $result['new_head'] ) );
		$this->assertEquals( array(), $result['conflicts'] );
		$this->assertEquals( 'Updated content of file2 in branch B', $repo->read_object_by_path( '/dir2/file2.txt' )->consume_all() );
		$this->assertEquals( 'Updated content of file1 in branch A', $repo->read_object_by_path( '/dir1/file1.txt' )->consume_all() );
		$this->assertEquals( 'New content of file3 in branch A', $repo->read_object_by_path( '/dir1/subdir1/file3.txt' )->consume_all() );
	}


	public function test_merge_conflicts() {
		$repo         = new GitRepository( InMemoryFilesystem::create() );
		$initial_hash = $repo->commit(
			array(
				'updates' => array(
					'dir1/file1.txt' => 'Initial content of file1',
					'dir2/file2.txt' => 'Initial content of file2',
				),
			)
		);

		$branch_a_hash = $repo->commit(
			array(
				'updates' => array(
					'dir1/file1.txt'         => 'Updated content of file1 in branch A',
					'dir1/subdir1/file3.txt' => 'New content of file3 in branch A',
				),
			)
		);

		$repo->create_branch( 'refs/heads/branch_b', $initial_hash );
		$repo->set_branch_tip( 'HEAD', 'ref: refs/heads/branch_b' );

		$branch_b_hash = $repo->commit(
			array(
				'updates' => array(
					'dir1/file1.txt' => 'Updated content of file1 in branch B',
					'dir2/file2.txt' => 'Updated content of file2 in branch B',
				),
			)
		);

		$repo->set_branch_tip( 'HEAD', 'ref: refs/heads/trunk' );

		$result = $repo->merge( 'refs/heads/branch_b' );
		$this->assertEquals( 40, strlen( $result['new_head'] ) );
		$this->assertEquals( array( '/dir1/file1.txt' ), $result['conflicts'] );
	}
}
