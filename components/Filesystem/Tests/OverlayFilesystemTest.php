<?php

use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\OverlayFilesystem;
use WordPress\Filesystem\InMemoryFilesystem;

require_once __DIR__ . '/FilesystemTestCase.php';

class OverlayFilesystemTest extends FilesystemTestCase {
	/**
	 * @var InMemoryFilesystem
	 */
	private $mounted_fs;

	protected function create_fs(): Filesystem {
		$this->mounted_fs = InMemoryFilesystem::create();
		return new OverlayFilesystem();
	}

	public function testMountInRoot() {
		$fs      = new OverlayFilesystem(
			array(
				'/mounted' => $this->mounted_fs,
			)
		);
		$root_ls = $fs->ls( '/' );
		$this->assertEquals( array( 'mounted' ), $root_ls );
		$this->assertTrue( $fs->is_dir( '/mounted' ) );
	}

	public function testFilesystemVisitor() {
		$this->mounted_fs->mkdir( '/dir1' );
		$this->mounted_fs->put_contents( '/dir1/file1.txt', 'content1' );
		$this->mounted_fs->mkdir( '/dir1/subdir' );
		$this->mounted_fs->put_contents( '/dir1/subdir/file2.txt', 'content2' );

		$fs = new OverlayFilesystem(
			array(
				'/mounted' => $this->mounted_fs,
			)
		);

		$visitor = new \WordPress\Filesystem\Visitor\FilesystemVisitor( $fs );

		// First event should be entering root
		$this->assertTrue( $visitor->next() );
		$event = $visitor->get_event();
		$this->assertTrue( $event->is_entering() );
		$this->assertEquals( '/', $event->dir );
		$this->assertEquals( array(), $event->files );
		$this->assertEquals( 0, $visitor->get_current_depth() );

		// Second event should be entering mounted dir
		$this->assertTrue( $visitor->next() );
		$event = $visitor->get_event();
		$this->assertTrue( $event->is_entering() );
		$this->assertEquals( '/mounted', $event->dir );
		$this->assertEquals( array(), $event->files );
		$this->assertEquals( 1, $visitor->get_current_depth() );

		// Third event should be entering dir1
		$this->assertTrue( $visitor->next() );
		$event = $visitor->get_event();
		$this->assertTrue( $event->is_entering() );
		$this->assertEquals( '/mounted/dir1', $event->dir );
		$this->assertEquals( array( 'file1.txt' ), $event->files );
		$this->assertEquals( 2, $visitor->get_current_depth() );

		// Fourth event should be entering subdir
		$this->assertTrue( $visitor->next() );
		$event = $visitor->get_event();
		$this->assertTrue( $event->is_entering() );
		$this->assertEquals( '/mounted/dir1/subdir', $event->dir );
		$this->assertEquals( array( 'file2.txt' ), $event->files );
		$this->assertEquals( 3, $visitor->get_current_depth() );

		// Fifth event should be exiting subdir
		$this->assertTrue( $visitor->next() );
		$event = $visitor->get_event();
		$this->assertTrue( $event->is_exiting() );
		$this->assertEquals( '/mounted/dir1/subdir', $event->dir );
		$this->assertEquals( 2, $visitor->get_current_depth() );

		// Sixth event should be exiting dir1
		$this->assertTrue( $visitor->next() );
		$event = $visitor->get_event();
		$this->assertTrue( $event->is_exiting() );
		$this->assertEquals( '/mounted/dir1', $event->dir );
		$this->assertEquals( 1, $visitor->get_current_depth() );

		// Seventh event should be exiting mounted dir
		$this->assertTrue( $visitor->next() );
		$event = $visitor->get_event();
		$this->assertTrue( $event->is_exiting() );
		$this->assertEquals( '/mounted', $event->dir );
		$this->assertEquals( 0, $visitor->get_current_depth() );

		// Eighth event should be exiting root
		$this->assertTrue( $visitor->next() );
		$event = $visitor->get_event();
		$this->assertTrue( $event->is_exiting() );
		$this->assertEquals( '/', $event->dir );
		$this->assertEquals( -1, $visitor->get_current_depth() );

		// No more events
		$this->assertFalse( $visitor->next() );
	}

	public function testLsInMounted() {
		$this->mounted_fs->put_contents( '/test.txt', 'test' );
		$this->mounted_fs->put_contents( '/test2.txt', 'test2' );
		$this->mounted_fs->mkdir( '/subdir' );

		$fs         = new OverlayFilesystem(
			array(
				'/mounted' => $this->mounted_fs,
			)
		);
		$mounted_ls = $fs->ls( '/mounted' );
		$this->assertIsArray( $mounted_ls );
		$this->assertContains( 'test.txt', $mounted_ls );
		$this->assertContains( 'test2.txt', $mounted_ls );
		$this->assertContains( 'subdir', $mounted_ls );
	}

	public function testMountedFilesystemIsAccessible() {
		$this->mounted_fs->put_contents( '/test.txt', 'mounted content' );
		$fs = new OverlayFilesystem(
			array(
				'/mounted' => $this->mounted_fs,
			)
		);
		$this->assertEquals( 'mounted content', $fs->get_contents( '/mounted/test.txt' ) );
	}

	public function testUnmountedPathsUseMemoryFs() {
		$this->expectException( \WordPress\Filesystem\FilesystemException::class );
		$this->fs->put_contents( '/unmounted/test.txt', 'memory content' );
	}

	public function testCopyBetweenFilesystems() {
		$this->expectException( \WordPress\Filesystem\FilesystemException::class );
		$this->mounted_fs->put_contents( '/source.txt', 'test content' );
		$fs = new OverlayFilesystem(
			array(
				'/mounted' => $this->mounted_fs,
			)
		);
		$fs->copy( '/mounted/source.txt', '/unmounted/dest.txt' );

		$this->assertTrue( $fs->exists( '/mounted/source.txt' ) );
		$this->assertTrue( $fs->exists( '/unmounted/dest.txt' ) );
		$this->assertEquals( 'test content', $fs->get_contents( '/unmounted/dest.txt' ) );
	}

	public function testMountNewFilesystem() {
		$new_fs = InMemoryFilesystem::create();
		$new_fs->put_contents( '/file.txt', 'new content' );

		$this->fs->mount( '/new', $new_fs );
		$this->assertEquals( 'new content', $this->fs->get_contents( '/new/file.txt' ) );
	}

	public function testNestedMounts() {
		$nested_fs = InMemoryFilesystem::create();
		$nested_fs->put_contents( '/nested.txt', 'nested content' );

		$this->fs->mount( '/mounted/nested', $nested_fs );
		$this->assertEquals( 'nested content', $this->fs->get_contents( '/mounted/nested/nested.txt' ) );
	}
}
