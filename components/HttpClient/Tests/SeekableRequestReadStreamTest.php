<?php

namespace WordPress\HttpClient\Tests;

use PHPUnit\Framework\TestCase;
use WordPress\HttpClient\ByteStream\SeekableRequestReadStream;
use WordPress\HttpClient\Request;

require_once __DIR__ . '/RequestReadStreamTest.php'; // for WithTestServer trait

class SeekableRequestReadStreamTest extends TestCase {
	use WithTestServer;

	private $fixture = '/preface-to-pygmalion.txt';

	private function createStream($url): SeekableRequestReadStream {
		$request = new Request( $url . $this->fixture );
		return new SeekableRequestReadStream( $request );
	}

	private function getFixtureContent() {
		return file_get_contents(__DIR__ . '/fixtures' . $this->fixture);
	}

	public function testLength() {
		$this->withServer(function($url) {
			$stream = $this->createStream($url);
			$this->assertEquals( strlen( $this->getFixtureContent() ), $stream->length() );
		});
	}

	public function testTellAndSeek() {
		$this->withServer(function($url) {
			$stream = $this->createStream($url);
			$stream->await_response();
			$length = $stream->length();
			$seek = ($length && $length > 10) ? 10 : 0;
			$this->assertEquals( 0, $stream->tell() );
			$stream->seek( $seek );
			$this->assertEquals( $seek, $stream->tell() );
			$stream->seek( 0 );
			$this->assertEquals( 0, $stream->tell() );
		});
	}

	public function testPullPeekConsume() {
		$this->withServer(function($url) {
			$fixtureContent = $this->getFixtureContent();
			$stream = $this->createStream($url);
			$stream->await_response();
			$nb = $stream->pull( 20 );
			if ($nb === 0) {
				$this->markTestSkipped('No data pulled from stream');
				return;
			}
			$peeked = $stream->peek( 20 );
			$this->assertEquals( substr( $fixtureContent, 0, 20 ), $peeked );
			$consumed = $stream->consume( 20 );
			$this->assertEquals( $peeked, $consumed );
			$this->assertEquals( 20, $stream->tell() );
		});
	}

	public function testConsumeAll() {
		$this->withServer(function($url) {
			$fixtureContent = $this->getFixtureContent();
			$stream = $this->createStream($url);
			$stream->await_response();
			$all    = $stream->consume_all();
			$this->assertEquals( $fixtureContent, $all );
			$this->assertTrue( $stream->reached_end_of_data() );
		});
	}

	public function testReachedEndOfData() {
		$this->withServer(function($url) {
			$stream = $this->createStream($url);
			$stream->await_response();
			$this->assertFalse( $stream->reached_end_of_data() );
			$stream->consume_all();
			$this->assertTrue( $stream->reached_end_of_data() );
		});
	}

	public function testCloseReading() {
		$this->withServer(function($url) {
			$stream = $this->createStream($url);
			$stream->pull( 10 );
			$stream->close_reading();
			$this->expectNotToPerformAssertions(); // No exception means pass
		});
	}
}
