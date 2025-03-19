<?php

namespace WordPress\HttpClient\Tests;

use WordPress\HttpClient\ByteStream\RequestReadStream;
use WordPress\HttpClient\Client;
use WordPress\HttpClient\HttpError;
use WordPress\HttpClient\Request;

class ClientTest extends \PHPUnit\Framework\TestCase {

	public function testInitialization() {
		$client = new TestClient();
		$this->assertEquals( 10, $client->getConcurrency() );
		$this->assertEquals( 3, $client->getMaxRedirects() );
	}

	/**
	 * @dataProvider gzip_provider
	 */
	public function test_streaming_body_with_chunked_encoding( $use_gzip ) {
		if ( extension_loaded( 'wasm_memory_storage' ) ) {
			$this->markTestSkipped( 'Test not supported yet in PHP.wasm' );
		}

		$this->withDevServer(
			function ( $address ) use ( $use_gzip ) {
				$client        = new TestClient();
				$request       = new Request(
					$address,
					array(
						'headers' => array(
							'Accept-Encoding' => $use_gzip ? 'gzip' : 'identity',
						),
					)
				);
				$body          = $client->fetch( $request );
				$entire_body   = $body->consume_all();
				$expected_body = <<<BODY
<!DOCTYPE html>
<html lang=en>
<head>
<meta charset='utf-8'>
<title>Chunked transfer encoding test</title>
</head>
<body><h1>Chunked transfer encoding test</h1>
<h5>This is a chunked response after 100 ms.</h5>
<h5>This is a chunked response after 1 second. The server should not close the stream before all chunks are sent to a client.</h5>
</body>
</html>

BODY;
				$this->assertEquals( $expected_body, $entire_body );
				$this->assertTrue( $body->reached_end_of_data() );
			}
		);
	}

	public function gzip_provider() {
		return array(
			'without gzip' => array( false ),
			'with gzip' => array( true ),
		);
	}

	private function withDevServer( callable $callback ) {
		$result  = $this->start_dev_server();
		$server  = $result['server'];
		$address = $result['address'];
		try {
			$callback( $address );
		} finally {
			proc_terminate( $server );
		}
	}

	private function start_dev_server() {
		$server = proc_open(
			'node ' . dirname( __DIR__ ) . '/chunked_encoding_server.js',
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);

		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		$output     = '';
		$start_time = microtime( true );
		while ( true ) {
			if ( microtime( true ) - $start_time > 2 ) {
				break;
			}
			if ( ! is_resource( $server ) ) {
				$this->fail( 'Failed to start chunked encoding test server' );
			}
			$errors = fread( $pipes[2], 65536 );
			if ( $errors ) {
				$this->fail( 'Failed to start chunked encoding dev server: ' . $errors );
			}
			if ( feof( $pipes[1] ) ) {
				$this->fail( 'Failed to start chunked encoding dev server' );
			}
			$output .= fread( $pipes[1], 65536 );
			if ( str_contains( $output, 'Server is listening on' ) ) {
				break;
			}
		}

		if ( ! str_contains( $output, 'Server is listening on' ) ) {
			$this->fail( 'Failed to start chunked encoding dev server' );
		}

		if ( preg_match( '/\d\.\d\.\d\.\d:(\d+)/', $output, $matches ) ) {
			$port = $matches[1];
		}

		if ( ! $port ) {
			$this->fail( 'Failed to find port in server output' );
		}
		$address = 'http://127.0.0.1:' . $port;

		return array(
			'server' => $server,
			'address' => $address,
			'pipes' => $pipes,
		);
	}

	public function test_streaming_body() {
		$reference = <<<PYGMALION
PREFACE TO PYGMALION.

A Professor of Phonetics.

As will be seen later on, Pygmalion needs, not a preface, but a sequel,
which I have supplied in its due place. The English have no respect for
their language, and will not teach their children to speak it. They
spell it so abominably that no man can teach himself what it sounds
like. It is impossible for an Englishman to open his mouth without
making some other Englishman hate or despise him. German and Spanish
are accessible to foreigners: English is not accessible even to
Englishmen. The reformer England needs today is an energetic phonetic
enthusiast: that is why I have made such a one the hero of a popular
play. There have been heroes of that kind crying in the wilderness for
many years past. When I became interested in the subject towards the
end of the eighteen-seventies, Melville Bell was dead; but Alexander J.
Ellis was still a living patriarch, with an impressive head always
covered by a velvet skull cap, for which he would apologize to public
meetings in a very courtly manner. He and Tito Pagliardini, another
phonetic veteran, were men whom it was impossible to dislike. Henry
Sweet, then a young man, lacked their sweetness of character: he was
about as conciliatory to conventional mortals as Ibsen or Samuel
Butler. His great ability as a phonetician (he was, I think, the best
of them all at his job) would have entitled him to high official
recognition, and perhaps enabled him to popularize his subject, but for
his Satanic contempt for all academic dignitaries and persons in
general who thought more of Greek than of phonetics. Once, in the days
when the Imperial Institute rose in South Kensington, and Joseph
Chamberlain was booming the Empire, I induced the editor of a leading
monthly review to commission an article from Sweet on the imperial
importance of his subject. When it arrived, it contained nothing but a
savagely derisive attack on a professor of language and literature
whose chair Sweet regarded as proper to a phonetic expert only. The
article, being libelous, had to be returned as impossible; and I had to
renounce my dream of dragging its author into the limelight. When I met
him afterwards, for the first time for many years, I found to my
astonishment that he, who had been a quite tolerably presentable young
man, had actually managed by sheer scorn to alter his personal
appearance until he had become a sort of walking repudiation of Oxford
and all its traditions. It must have been largely in his own despite
that he was squeezed into something called a Readership of phonetics
there. The future of phonetics rests probably with his pupils, who all
swore by him; but nothing could bring the man himself into any sort of
compliance with the university, to which he nevertheless clung by
divine right in an intensely Oxonian way. I daresay his papers, if he
has left any, include some satires that may be published without too
destructive results fifty years hence. He was, I believe, not in the
least an ill-natured man: very much the opposite, I should say; but he
would not suffer fools gladly.
PYGMALION;

		// @TODO: Use a local dev server instead of relying on an external service
		$pygmalion_url = 'https://gist.githubusercontent.com/adamziel/f6cdffb3b4a8a8ccfd10e72cde1f9078/raw/cfbf4bf236dcf13fed5eb4e8babf40ae791326eb/pygmalion.md';
		$client        = new TestClient();
		$request       = new Request( $pygmalion_url );
		$body          = $client->fetch( $request );

		$accumulated = '';

		// Get a 100 bytes and confirm they're what we expect
		$body->pull( 100 );
		$accumulated .= $body->consume( 100 );
		$this->assertEquals( 100, strlen( $accumulated ) );

		// Get another 100 bytes and confirm they're what we expect
		$body->pull( 100 );
		$accumulated .= $body->consume( 100 );
		$this->assertEquals( 200, strlen( $accumulated ) );

		// Get the rest of the data and confirm that it all matches the
		// expected Pygmalion fragment.
		$accumulated .= $body->consume_all();
		$this->assertEquals( 3108, strlen( $accumulated ) );

		$this->assertEquals( $reference, $accumulated );
		$this->assertTrue( $body->reached_end_of_data() );
	}

	public function testEnqueueRequests() {
		$client  = new TestClient();
		$request = new Request( 'https://wordpress.org' );
		$client->enqueue( $request );

		$this->assertCount( 1, $client->getRequests() );
	}

	public function testFetchMethod() {
		$client  = new TestClient();
		$request = new Request( 'https://wordpress.org' );
		$reader  = $client->fetch( $request );

		$this->assertInstanceOf( RequestReadStream::class, $reader );
	}

	public function testAwaitNextEvent() {
		$client  = new TestClient();
		$request = new Request( 'https://wordpress.org' );
		$client->enqueue( $request );

		// Simulate an event
		$client->simulateEvent( Client::EVENT_GOT_HEADERS, $request );

		$this->assertTrue( $client->await_next_event() );
		$this->assertEquals( Client::EVENT_GOT_HEADERS, $client->get_event() );
	}

	public function testErrorHandling() {
		$client  = new TestClient();
		$request = new Request( 'https://no-such-site.wordpress.org/' );
		$client->enqueue( $request );

		// Simulate an error
		$client->simulateError( $request, new HttpError( 'Test error' ) );
		$this->assertTrue( $client->await_next_event() );

		$this->assertEquals( Request::STATE_FAILED, $request->state );
	}

	public function testRedirectHandling() {
		$client  = new TestClient( array( 'max_redirects' => 2 ) );
		$request = new Request( 'https://wordpress.org' );
		$client->enqueue( $request );

		// Simulate a redirect
		$client->simulateRedirect( $request, 'https://redirected.com' );
		$client->await_next_event();

		$this->assertEquals( 1, $client->getRedirectCount( $request ) );
	}
}
