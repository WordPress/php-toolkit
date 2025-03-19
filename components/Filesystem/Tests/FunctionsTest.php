<?php

use PHPUnit\Framework\TestCase;
use function WordPress\Filesystem\wp_join_paths;

class FunctionsTest extends TestCase {
	public function testBasicPathJoining() {
		$this->assertEquals( 'foo/bar', wp_join_paths( 'foo', 'bar' ) );
		$this->assertEquals( '/foo/bar', wp_join_paths( '/foo', 'bar' ) );
	}

	public function testRemovesEmptySegments() {
		$this->assertEquals( 'foo/bar', wp_join_paths( 'foo', '', 'bar' ) );
		$this->assertEquals( 'foo/bar', wp_join_paths( '', 'foo', 'bar' ) );
	}

	public function testPreserveLeadingSlash() {
		$this->assertEquals( '/foo/bar', wp_join_paths( '/foo', '/bar' ) );
		$this->assertEquals( 'foo/bar', wp_join_paths( 'foo', '/bar' ) );
	}

	public function testDeduplicatesMultipleSlashes() {
		$this->assertEquals( '/foo/bar', wp_join_paths( '/foo/', '/bar' ) );
		$this->assertEquals( '/foo/bar', wp_join_paths( '/foo//', '//bar' ) );
	}

	public function testSingleArgument() {
		$this->assertEquals( '/foo', wp_join_paths( '/foo' ) );
		$this->assertEquals( 'foo', wp_join_paths( 'foo' ) );
	}

	public function testMultipleSegments() {
		$this->assertEquals( 'foo/bar/baz', wp_join_paths( 'foo', 'bar', 'baz' ) );
		$this->assertEquals( '/foo/bar/baz', wp_join_paths( '/foo', 'bar', 'baz' ) );
	}

	public function testEmptyStrings() {
		$this->assertEquals( '', wp_join_paths( '' ) );
		$this->assertEquals( '', wp_join_paths( '', '', '' ) );
	}

	public function testMixedSlashes() {
		$this->assertEquals( '/foo/bar/baz', wp_join_paths( '/foo/', '/bar/', '/baz' ) );
		$this->assertEquals( 'foo/bar/baz', wp_join_paths( 'foo/', '/bar/', '/baz' ) );
	}
}
