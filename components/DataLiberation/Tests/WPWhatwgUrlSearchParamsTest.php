<?php

use PHPUnit\Framework\TestCase;
use WordPress\DataLiberation\URL\WPURL;
use WordPress\DataLiberation\URL\WPWhatwgUrl;

/**
 * Tests for the searchParams adapter that sits on top of PHP 8.5's native
 * Uri\WhatWg\Url. Skipped on older runtimes where the native parser does not
 * exist and WPURL falls back to Rowbot (which has its own searchParams).
 */
class WPWhatwgUrlSearchParamsTest extends TestCase {

	/**
	 * @before
	 */
	public function require_native_parser() {
		if ( ! class_exists( '\\Uri\\WhatWg\\Url' ) ) {
			$this->markTestSkipped( 'Uri\\WhatWg\\Url is only available on PHP 8.5+' );
		}
	}

	private function parse( $url ) {
		$parsed = WPURL::parse( $url );
		$this->assertInstanceOf( WPWhatwgUrl::class, $parsed );
		return $parsed;
	}

	public function test_get_returns_first_value_or_null() {
		$url = $this->parse( 'https://example.com/?foo=bar&baz=qux' );
		$this->assertSame( 'bar', $url->searchParams->get( 'foo' ) );
		$this->assertSame( 'qux', $url->searchParams->get( 'baz' ) );
		$this->assertNull( $url->searchParams->get( 'missing' ) );
	}

	public function test_get_returns_first_of_repeated_keys() {
		$url = $this->parse( 'https://example.com/?k=1&k=2&k=3' );
		$this->assertSame( '1', $url->searchParams->get( 'k' ) );
		$this->assertSame( array( '1', '2', '3' ), $url->searchParams->getAll( 'k' ) );
	}

	public function test_has() {
		$url = $this->parse( 'https://example.com/?chunked=yes' );
		$this->assertTrue( $url->searchParams->has( 'chunked' ) );
		$this->assertFalse( $url->searchParams->has( 'missing' ) );
	}

	public function test_empty_query() {
		$url = $this->parse( 'https://example.com/' );
		$this->assertSame( 0, $url->searchParams->size );
		$this->assertNull( $url->searchParams->get( 'anything' ) );
	}

	public function test_set_replaces_first_and_drops_duplicates() {
		$url = $this->parse( 'https://example.com/?k=1&k=2&other=x' );
		$url->searchParams->set( 'k', 'new' );
		$this->assertSame( 'new', $url->searchParams->get( 'k' ) );
		$this->assertSame( array( 'new' ), $url->searchParams->getAll( 'k' ) );
		$this->assertSame( 'x', $url->searchParams->get( 'other' ) );
	}

	public function test_set_appends_when_missing_and_updates_url_search() {
		$url = $this->parse( 'https://example.com/' );
		$url->searchParams->set( 'a', '1' );
		$this->assertSame( '?a=1', $url->search );
		$this->assertSame( 'https://example.com/?a=1', $url->toString() );
	}

	public function test_append_keeps_existing_values() {
		$url = $this->parse( 'https://example.com/?k=1' );
		$url->searchParams->append( 'k', '2' );
		$this->assertSame( array( '1', '2' ), $url->searchParams->getAll( 'k' ) );
	}

	public function test_delete_removes_all_matching() {
		$url = $this->parse( 'https://example.com/?k=1&k=2&other=x' );
		$url->searchParams->delete( 'k' );
		$this->assertFalse( $url->searchParams->has( 'k' ) );
		$this->assertSame( '?other=x', $url->search );
	}

	public function test_delete_clears_query_when_empty() {
		$url = $this->parse( 'https://example.com/?only=1' );
		$url->searchParams->delete( 'only' );
		$this->assertSame( '', $url->search );
		$this->assertSame( 0, $url->searchParams->size );
	}

	public function test_size_reflects_pair_count() {
		$url = $this->parse( 'https://example.com/?a=1&b=2&a=3' );
		$this->assertSame( 3, $url->searchParams->size );
		$this->assertCount( 3, $url->searchParams );
	}

	public function test_iteration_yields_pairs_in_order() {
		$url = $this->parse( 'https://example.com/?a=1&b=2&a=3' );
		$pairs = array();
		foreach ( $url->searchParams as $pair ) {
			$pairs[] = $pair;
		}
		$this->assertSame(
			array(
				array( 'a', '1' ),
				array( 'b', '2' ),
				array( 'a', '3' ),
			),
			$pairs
		);
	}

	public function test_percent_encoded_values_round_trip() {
		$url = $this->parse( 'https://example.com/?path=%2Ffoo%2Fbar&q=hello+world' );
		$this->assertSame( '/foo/bar', $url->searchParams->get( 'path' ) );
		$this->assertSame( 'hello world', $url->searchParams->get( 'q' ) );
	}

	public function test_set_encodes_special_characters() {
		$url = $this->parse( 'https://example.com/' );
		$url->searchParams->set( 'q', 'hello world&more' );
		$this->assertSame( 'hello world&more', $url->searchParams->get( 'q' ) );
		$this->assertStringContainsString( 'q=hello+world%26more', $url->search );
	}

	public function test_to_string_serializes_current_pairs() {
		$url = $this->parse( 'https://example.com/?a=1&b=2' );
		$this->assertSame( 'a=1&b=2', $url->searchParams->toString() );
		$this->assertSame( 'a=1&b=2', (string) $url->searchParams );
	}
}
