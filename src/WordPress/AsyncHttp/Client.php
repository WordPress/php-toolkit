<?php

namespace WordPress\AsyncHttp;

use Exception;
use WordPress\Util\Map;
use WordPress\Streams\VanillaStreamWrapperData;
use WordPress\AsyncHttp\CountReadBytesStreamWrapper;

/**
 * An asynchronous HTTP client library designed for WordPress. Main features:
 *
 * **Streaming support**
 * Enqueuing a request returns a PHP resource that can be read by PHP functions like `fopen()`
 * and `stream_get_contents()`
 *
 * ```php
 * $client = new AsyncHttpClient();
 * $fp = $client->enqueue(
 *      new Request( "https://downloads.wordpress.org/plugin/gutenberg.17.7.0.zip" ),
 * );
 * // Read some data
 * $first_4_kilobytes = fread($fp, 4096);
 * // We've only waited for the first four kilobytes. The download
 * // is still in progress at this point, and yet we're free to do
 * // other work.
 * ```
 *
 * **Delayed execution and concurrent downloads**
 * The actual socket are not open until the first time the stream is read from:
 *
 * ```php
 * $client = new AsyncHttpClient();
 * // Enqueuing the requests does not start the data transmission yet.
 * $batch = $client->enqueue( [
 *     new Request( "https://downloads.wordpress.org/plugin/gutenberg.17.7.0.zip" ),
 *     new Request( "https://downloads.wordpress.org/theme/pendant.zip" ),
 * ] );
 * // Even though stream_get_contents() will return just the response body for
 * // one request, it also opens the network sockets and starts streaming
 * // both enqueued requests. The response data for $batch[1] is buffered.
 * $gutenberg_zip = stream_get_contents( $batch[0] )
 *
 * // At least a chunk of the pendant.zip have already been downloaded, let's
 * // wait for the rest of the data:
 * $pendant_zip = stream_get_contents( $batch[1] )
 * ```
 *
 * **Concurrency limits**
 * The `AsyncHttpClient` will only keep up to `$concurrency` connections open. When one of the
 * requests finishes, it will automatically start the next one.
 *
 * For example:
 * ```php
 * $client = new AsyncHttpClient();
 * // Process at most 10 concurrent request at a time.
 * $client->set_concurrency_limit( 10 );
 * ```
 *
 * **Progress monitoring**
 * A developer-provided callback (`AsyncHttpClient->set_progress_callback()`) receives progress
 * information about every HTTP request.
 *
 * ```php
 * $client = new AsyncHttpClient();
 * $client->set_progress_callback( function ( Request $request, $downloaded, $total ) {
 *      // $total is computed based on the Content-Length response header and
 *      // null if it's missing.
 *      echo "$request->url – Downloaded: $downloaded / $total\n";
 * } );
 * ```
 *
 * **HTTPS support**
 * TLS connections work out of the box.
 *
 * **Non-blocking sockets**
 * The act of opening each socket connection is non-blocking and happens nearly
 * instantly. The streams themselves are also set to non-blocking mode via `stream_set_blocking($fp, 0);`
 *
 * **Asynchronous downloads**
 * Start downloading now, do other work in your code, only block once you need the data.
 *
 * **PHP 7.0 support and no dependencies**
 * `AsyncHttpClient` works on any WordPress installation with vanilla PHP only.
 * It does not require any PHP extensions, CURL, or any external PHP libraries.
 *
 * **Supports custom request headers and body**
 */
class Client {
	protected $concurrency = 2;
	protected $requests;
	protected $onProgress;
	protected $is_processing_queue = false;

	/**
	 * Microsecond is 1 millionth of a second.
	 * 
	 * @var int
	 */
	const MICROSECONDS_TO_SECONDS = 1000000;

	/**
	 * 5/100th of a second
	 */
	const NONBLOCKING_TIMEOUT_MICROSECONDS = 0.05 * self::MICROSECONDS_TO_SECONDS;

	public function __construct() {
		$this->requests   = [];
		$this->onProgress = function () {
		};
	}

	/**
	 * Sets the limit of concurrent connections this client will open.
	 *
	 * @param int $concurrency
	 */
	public function set_concurrency_limit( $concurrency ) {
		$this->concurrency = $concurrency;
	}

	/**
	 * Sets the callback called when response bytes are received on any of the enqueued
	 * requests.
	 *
	 * @param callable $onProgress A function of three arguments:
	 *                             Request $request, int $downloaded, int $total.
	 */
	public function set_progress_callback( $onProgress ) {
		$this->onProgress = $onProgress;
	}

	/**
	 * Enqueues one or multiple HTTP requests for asynchronous processing.
	 * It does not open the network sockets, only adds the Request objects to
	 * an internal queue. Network transmission is delayed until one of the returned
	 * streams is read from.
	 *
	 * @param Request|Request[] $requests The HTTP request(s) to enqueue. Can be a single request or an array of requests.
	 *
	 * @return Response[]|Response|array The enqueued streams.
	 */
	public function enqueue( $requests ) {
		if ( ! is_array( $requests ) ) {
			return $this->enqueue_request( $requests );
		}

		$enqueued_streams = array();
		foreach ( $requests as $request ) {
			$enqueued_streams[] = $this->enqueue_request( $request );
		}

		return $enqueued_streams;
	}

	/**
	 * Returns the response stream associated with the given Request object.
	 * Reading from that stream also runs this Client's event loop.
	 *
	 * @param Request $request
	 *
	 * @return resource
	 */
	public function get_stream( $request ) {
		throw new Exception('Not implemented yet');
		// if ( ! isset( $this->requests[ $request ] ) ) {
		// 	$this->enqueue_request( $request );
		// }

		// if ( $this->queue_needs_processing ) {
		// 	$this->process_queue();
		// }

		// StreamWrapper::create_resource(
		// 	new StreamData($request, $client)
		// )
	}

	/**
	 * @param \WordPress\AsyncHttp\Request $request
	 */
	protected function enqueue_request( $request ) {
		$this->requests[]   = $request;
		return $request->get_response();
	}


	const READ_NON_BLOCKING = 'READ_NON_BLOCKING';
	const READ_POLL_ANY = 'READ_POLL_ANY';
	const READ_POLL_ALL = 'READ_POLL_ALL';
	/**
	 * Reads $length bytes from the given request while also running
	 * non-blocking event loop operations.
	 *
	 * @param Request $request
	 * @param $length
	 */
	public function read_bytes( $request, $length, $mode = self::READ_NON_BLOCKING )
	{
		$response = $request->get_response();
		$buffered = '';
		do {
			$next_chunk = '';
			if (
				$request->state === Request::STATE_RECEIVING_BODY ||
				$request->state === Request::STATE_FINISHED
			) {
				$next_chunk = $response->consume_buffer($length - strlen($buffered));
				$buffered .= $next_chunk;
			}

			if (
				($mode === self::READ_NON_BLOCKING) ||
				($mode === self::READ_POLL_ANY && strlen($buffered)) ||
				($mode === self::READ_POLL_ALL && strlen($buffered) >= $length) ||
				// End of data
				($request->state === Request::STATE_FINISHED && feof($response->raw_response_stream))
			) {
				break;
			}
		} while ($this->event_loop_pass());

		return $buffered;
	}

	public function event_loop_pass()
	{
		if(count($this->get_concurrent_requests()) === 0) {
			return false;
		}
		echo "event_loop_pass\n";
		foreach($this->requests as $request) {
			echo "request state: $request->state\n";
		}
		sleep(1);
		static::open_nonblocking_http_sockets( 
			$this->get_concurrent_requests( Request::STATE_ENQUEUED )
		);

		static::enable_crypto( 
			$this->get_concurrent_requests( Request::STATE_WILL_ENABLE_CRYPTO )
		);

		static::send_request_headers(
			$this->get_concurrent_requests( Request::STATE_WILL_SEND_HEADERS )
		);

		static::send_request_body(
			$this->get_concurrent_requests( Request::STATE_WILL_SEND_BODY )
		);

		static::receive_response_headers(
			$this->get_concurrent_requests( Request::STATE_RECEIVING_HEADERS )
		);

		$this->receive_response_body(
			$this->get_concurrent_requests( Request::STATE_RECEIVING_BODY )
		);

		$this->handle_redirects(
			$this->get_concurrent_requests( Request::STATE_RECEIVED )
		);

		return true;
	}

	protected function get_concurrent_requests($states=null)
	{
		$processed_requests = $this->get_requests([
			Request::STATE_WILL_ENABLE_CRYPTO,
			Request::STATE_WILL_SEND_HEADERS,
			Request::STATE_WILL_SEND_BODY,
			Request::STATE_SENT,
			Request::STATE_RECEIVING_HEADERS,
			Request::STATE_RECEIVING_BODY,
			Request::STATE_RECEIVED,
		]);
		$enqueued_requests = $this->get_requests(Request::STATE_ENQUEUED);
		$backfill_enqueued_nb = min(
			count($enqueued_requests),
			$this->concurrency - count($processed_requests)
		);

		for($i = 0; $i < $backfill_enqueued_nb; $i++) {
			$processed_requests[] = $enqueued_requests[$i];
		}

		if($states !== null) {
			$processed_requests = static::filter_requests($processed_requests, $states);
		}

		return $processed_requests;
	}

	private function get_requests($states) {
		if(!is_array($states)) {
			$states = [$states];
		}
		return static::filter_requests($this->requests, $states);
	}

	/**
	 * Handle transfer encodings.
	 * 
	 * @param  Request[]  $requests  An array of HTTP requests.
	 */
	private function decode_and_monitor_response_body_stream(Request $request) {
		$wrapped_stream = CountReadBytesStreamWrapper::wrap(
			$request->http_socket,
			function ($downloaded) use ($request) {
				$response				 = $request->get_response();
				$total                   = $response->get_header('content-length') ?: null;
				if($total !== null) {
					$total = (int) $total;
				}
				$onProgress = $this->onProgress;
				$onProgress($request, $downloaded, $total);
			}
		);

		$response = $request->get_response();

		$transfer_encodings = array();

		$transfer_encoding = $response->get_header('transfer-encoding');
		if($transfer_encoding) {
			$transfer_encodings = array_map('trim', explode(',', $transfer_encoding));
		}

		$content_encoding = $response->get_header('content-encoding');
		if($content_encoding && !in_array($content_encoding, $transfer_encodings)) {
			$transfer_encodings[] = $content_encoding;
		}

		foreach($transfer_encodings as $transfer_encoding) {
			switch($transfer_encoding) {
				case 'chunked':
					/**
					 * Wrap the stream in a chunked encoding decoder.
					 * There was an attempt to use stream filters, but unfortunately 
					 * they are incompatible with stream_select().
					 */
					$wrapped_stream = ChunkedEncodingStreamWrapper::create_resource(new VanillaStreamWrapperData(
						$wrapped_stream
					));
					break;
				case 'gzip':
				case 'deflate':
					$wrapped_stream = InflateStreamWrapper::create_resource(new InflateStreamWrapperData(
						$wrapped_stream,
						$transfer_encoding === 'gzip' ? ZLIB_ENCODING_GZIP : ZLIB_ENCODING_RAW
					));
					break;
				case 'identity':
					// No-op
					break;
				default:
					$request->set_error(new HttpError( 'Unsupported transfer encoding received from the server: ' . $transfer_encoding ));
					break;
			}
		}
		return $wrapped_stream;
	}

	/**
	 * Sends HTTP requests using streams.
	 *
	 * Enables crypto on the $requests HTTP socksts and sends the request body asynchronously.
	 *
	 * @param  Request[]  $requests  An array of HTTP requests.
	 */
	static private function enable_crypto(array $requests)
	{
		foreach ( static::stream_select($requests, static::STREAM_SELECT_WRITE) as $request ) {
			$enabled_crypto = stream_socket_enable_crypto(
				$request->http_socket,
				true,
				STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
			);
			if (false === $enabled_crypto) {
				$request->set_error(new HttpError('Failed to enable crypto: ' . error_get_last()['message']));
				continue;
			} elseif (0 === $enabled_crypto) {
				// The SSL handshake isn't finished yet, let's skip it
				// for now and try again on the next event loop pass.
				continue;
			}
			// Headers sent! Let's promote the request to the next state.
			$request->state = Request::STATE_WILL_SEND_HEADERS;
		}
	}

	/**
	 * Sends HTTP request headers.
	 *
	 * @param  Request[]  $requests  An array of HTTP requests.
	 */
	static private function send_request_headers(array $requests)
	{
		foreach ( static::stream_select($requests, static::STREAM_SELECT_WRITE) as $request ) {
			$header_bytes = static::prepare_request_headers( $request );

			if(false === fwrite( $request->http_socket, $header_bytes )) {
				$request->set_error(new HttpError( 'Failed to write request bytes.' ));
				continue;
			}

			if ($request->upload_body_stream) {
				$request->state = Request::STATE_WILL_SEND_BODY;
			} else {
				$request->state = Request::STATE_RECEIVING_HEADERS;
			}
		}
	}
	
	/**
	 * Sends HTTP request body.
	 *
	 * @param  Request[]  $requests  An array of HTTP requests.
	 */
	static private function send_request_body(array $requests)
	{
		foreach ( static::stream_select($requests, self::STREAM_SELECT_WRITE) as $request ) {
			$chunk = fread( $request->upload_body_stream, 8192 );
			if ( false === $chunk ) {
				$request->set_error(new HttpError( 'Failed to read from the request body stream' ));
				continue;
			}

			if(false === fwrite( $request->http_socket, $chunk )) {
				$request->set_error(new HttpError( 'Failed to write request bytes.' ));
				continue;
			}

			if('' === $chunk || feof($request->upload_body_stream)) {
				fclose($request->upload_body_stream);
				$request->upload_body_stream = null;
				$request->state = Request::STATE_RECEIVING_HEADERS;
			}
		}
	}

	/**
	 * Reads the next received portion of HTTP response headers for multiple requests.
	 *
	 * @param  array  $requests  An array of requests.
	 */
	static private function receive_response_headers( $requests ) {
		foreach (static::stream_select($requests, static::STREAM_SELECT_READ) as $request) {
			$response = $request->get_response();

			while (true) {
				// @TODO: Use a larger chunk size here and then scan for \r\n\r\n.
				//        1 seems slow and overly conservative.
				$header_byte = fread($response->raw_response_stream, 1);
				if (false === $header_byte || '' === $header_byte) {
					break;
				}
				$response->buffer .= $header_byte;

				if (
					strlen($response->buffer) < 4 ||
					$response->buffer[strlen($response->buffer) - 4] !== "\r" ||
					$response->buffer[strlen($response->buffer) - 3] !== "\n" ||
					$response->buffer[strlen($response->buffer) - 2] !== "\r" ||
					$response->buffer[strlen($response->buffer) - 1] !== "\n"
				) {
					continue;
				}

				$parsed = static::parse_http_headers($response->buffer);
				$response->buffer = '';

				$response->headers = $parsed['headers'];
				$response->statusCode = $parsed['status']['code'];
				$response->statusMessage = $parsed['status']['message'];
				$response->protocol = $parsed['status']['protocol'];

				$content_length = $response->get_header('content-length');
				$transfer_encoding = $response->get_header('transfer-encoding');
				// If we're expecting a body, let's start receiving it.
				if(
					$transfer_encoding === 'chunked' || 
					($content_length !== null && (int) $content_length > 0)
				) {
					$request->state = Request::STATE_RECEIVING_BODY;
				} else {
					$request->state = Request::STATE_RECEIVED;
				}
				break;
			}
		}
	}

	/**
	 * Reads the next received portion of HTTP response headers for multiple requests.
	 *
	 * @param  array  $requests  An array of requests.
	 */
	private function receive_response_body( $requests ) {
		foreach (static::stream_select($requests, static::STREAM_SELECT_READ) as $request) {
			$response = $request->get_response();
			if (!$response->decoded_response_stream) {
				$response->decoded_response_stream = $this->decode_and_monitor_response_body_stream($request);
				$response->event_loop_decoded_response_stream = StreamWrapper::create_resource(
					new StreamData($request, $this)
				);
			}

			while (true) {
				if(feof($response->decoded_response_stream)) {
					$request->state = Request::STATE_RECEIVED;
					break;
				}

				$body_bytes = fread($response->decoded_response_stream, 1024);
				if (false === $body_bytes || '' === $body_bytes) {
					break;
				}

				$response->buffer .= $body_bytes;
			}
		}
	}

	/**
	 * @TODO: Limit to n redirects.
	 *
	 * @param array  $requests  An array of requests.
	 */
	private function handle_redirects( $requests ) {
		foreach($requests as $request) {
			$response = $request->get_response();
			$code = $response->get_status_code();
			if(!($code >= 300 && $code < 400)) {
				$request->state = Request::STATE_FINISHED;
				continue;
			}
			
			$location = $response->get_header('location');
			if($location === null) {
				$request->state = Request::STATE_FINISHED;
				continue;
			}

			$request->state = Request::STATE_FINISHED;
			$redirect = (new Request($location))->set_redirected_from($request);
			$this->requests[] = $redirect;
		}
	}

	/**
	 * Parses an HTTP headers string into an array containing the status and headers.
	 *
	 * @param  string  $headers  The HTTP headers to parse.
	 *
	 * @return array An array containing the parsed status and headers.
	 */
	static private function parse_http_headers( string $headers ) {
		$lines   = explode( "\r\n", $headers );
		$status  = array_shift( $lines );
		$status  = explode( ' ', $status );
		$status  = array(
			'protocol' => $status[0],
			'code'     => $status[1],
			'message'  => $status[2],
		);
		$headers = array();
		foreach ( $lines as $line ) {
			if ( strpos( $line, ': ' ) === false ) {
				continue;
			}
			$line = explode( ': ', $line );
			/**
			 * Headers names are case-insensitive.
			 *
			 * RFC 7230 states:
			 *
			 * > Each header field consists of a case-insensitive field name followed by a colon (":"),
			 * > optional leading whitespace, the field value, and optional trailing whitespace."
			 */
			$headers[ strtolower( $line[0] ) ] = $line[1];
		}

		return array(
			'status'  => $status,
			'headers' => $headers,
		);
	}

	/**
	 * Opens HTTP or HTTPS streams using stream_socket_client() without blocking,
	 * and returns nearly immediately.
	 *
	 * The act of opening a stream is non-blocking itself. This function uses
	 * a tcp:// stream wrapper, because both https:// and ssl:// wrappers would block
	 * until the SSL handshake is complete.
	 * The actual socket it then switched to non-blocking mode using stream_set_blocking().
	 *
	 * @param  Request  $request  The Request to open the socket for.
	 *
	 * @return bool Whether the stream was opened successfully.
	 */
	static private function open_nonblocking_http_sockets($requests) {
		foreach ($requests as $request) {
			$url = $request->url;
			$parts = parse_url($url);
			$scheme = $parts['scheme'];
			if (!in_array($scheme, array('http', 'https'))) {
				$request->set_error(new HttpError('stream_http_open_nonblocking: Invalid scheme in URL ' . $url . ' – only http:// and https:// URLs are supported'));
				continue;
			}

			$is_ssl = $scheme === 'https';
			$port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
			$host = $parts['host'];

			// Create stream context
			$context = stream_context_create(
				array(
					'socket' => array(
						'isSsl' => $is_ssl,
						'originalUrl' => $url,
						'socketUrl' => 'tcp://' . $host . ':' . $port,
					),
				)
			);

			$stream = @stream_socket_client(
				'tcp://' . $host . ':' . $port,
				$errno,
				$errstr,
				30,
				STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
				$context
			);
			if ($stream === false) {
				$request->set_error(new HttpError("stream_http_open_nonblocking: stream_socket_client() was unable to open a stream to $url. $errno: $errstr"));
				continue;
			}
					
			if ( PHP_VERSION_ID >= 72000 ) {
				// In PHP <= 7.1 and later, making the socket non-blocking before the
				// SSL handshake makes the stream_socket_enable_crypto() call always return
				// false. Therefore, we only make the socket non-blocking after the
				// SSL handshake.
				stream_set_blocking( $stream, 0 );
			}

			$request->http_socket = $stream;
			$request->get_response()->raw_response_stream = $stream;
			if($is_ssl) {
				$request->state = Request::STATE_WILL_ENABLE_CRYPTO;
			} else {
				$request->state = Request::STATE_WILL_SEND_HEADERS;
			}
		}
			
		return true;
	}

	/**
	 * Prepares an HTTP request string for a given URL.
	 *
	 * @param  Request  $request  The Request to prepare the HTTP headers for.
	 * @return string The prepared HTTP request string.
	 */
	private static function prepare_request_headers( Request $request ) {
		$url   = $request->url;
		$parts = parse_url( $url );
		$host  = $parts['host'];
		$path  = (isset($parts['path']) ? $parts['path'] : '/') . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );

		$headers = [
			"Host" => $host,
			"User-Agent" => "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/93.0.4577.82 Safari/537.36",
			"Accept" => "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9",
			"Accept-Encoding" => "gzip",
			"Accept-Language" => "en-US,en;q=0.9",
			"Connection" => "close",
		];
		foreach($request->headers as $k => $v) {
			$headers[$k] = $v;
		}


		$request_parts = array(
			"$request->method $path HTTP/$request->http_version",
		);

		foreach ( $headers as $name => $value ) {
			$request_parts[] = "$name: $value";
		}

		return implode( "\r\n", $request_parts ) . "\r\n\r\n";
	}

	static private function filter_requests( array $requests, $states ) {
		if(!is_array($states)) {
			$states = [$states];
		}
		$results = [];
		foreach($requests as $k => $request) {
			if(in_array($request->state, $states)) {
				$results[$k] = $request;
			}
		}
		return $results;
	}


	const STREAM_SELECT_READ = 1;
	const STREAM_SELECT_WRITE = 2;
	static private function stream_select( $requests, $mode ) {
		if(empty($requests)) {
			return [];
		}

		$read = [];
		$write = [];
		foreach ( $requests as $k => $request ) {
			if($mode & static::STREAM_SELECT_READ) {
				$read[ $k ] = $request->http_socket;
			}
			if($mode & static::STREAM_SELECT_WRITE) {
				$write[ $k ] = $request->http_socket;
			}
		}
		$except = null;

		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
		$ready = @stream_select( $read, $write, $except, 0, static::NONBLOCKING_TIMEOUT_MICROSECONDS );
		if ( $ready === false ) {
			foreach ( $requests as $request ) {
				$request->set_error(new HttpError( 'Error: ' . error_get_last()['message'] ));
			}
			return [];
		} elseif ( $ready <= 0 ) {
			// @TODO allow at most X stream_select attempts per request
			// foreach ( $unprocessed_requests as $request ) {
			// 	$request->set_error(new HttpError( 'stream_select timed out' ));
			// }
			return [];
		}

		$selected_requests = [];
		foreach (array_keys($read) as $k) {
			$selected_requests[ $k ] = $requests[ $k ];
		}
		foreach (array_keys($write) as $k) {
			$selected_requests[ $k ] = $requests[ $k ];
		}
		return $selected_requests;
	}

}
