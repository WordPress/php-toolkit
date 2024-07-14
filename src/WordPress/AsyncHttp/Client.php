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
	protected $queue_needs_processing = false;
	protected $is_processing_queue = false;

	public function __construct() {
		$this->requests   = new Map();
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

	// /**
	//  * Returns the response stream associated with the given Request object.
	//  * Enqueues the Request if it hasn't been enqueued yet.
	//  *
	//  * @param Request $request
	//  *
	//  * @return resource
	//  */
	// public function get_stream( $request ) {
	// 	if ( ! isset( $this->requests[ $request ] ) ) {
	// 		$this->enqueue_request( $request );
	// 	}

	// 	if ( $this->queue_needs_processing ) {
	// 		$this->process_queue();
	// 	}

	// 	return $this->requests[ $request ]->get_response()->body_stream;
	// }

	/**
	 * @param \WordPress\AsyncHttp\Request $request
	 */
	protected function enqueue_request( $request ) {
		$this->requests[ $request ]   = $request;
		$this->queue_needs_processing = true;
		return $request->get_response();
	}

	/**
	 * Starts n enqueued request up to the $concurrency_limit.
	 */
	public function process_queue() {
		$this->queue_needs_processing = false;

		$active_requests = count(static::filter_requests($this->requests->values(), [
			// Request::STATE_SENDING,
			// Request::STATE_SENT,
			// Request::STATE_RECEIVING_HEADERS,
			Request::STATE_RECEIVING_BODY,
		]));
		$backfill        = $this->concurrency - $active_requests;
		if ( $backfill <= 0 ) {
			return;
		}

		$enqueued_requests = static::filter_requests($this->requests->values(), Request::STATE_ENQUEUED);
		$requests = array_slice( $enqueued_requests, 0, $backfill );
		if ( ! count( $requests ) ) {
			return;
		}

		static::init_requests_follow_redirects($requests, $this);
	}

	/**
	 * Waits for $length bytes to become available on the specidied stream,
	 * while polling all the other active streams.
	 *
	 * @param Request $request
	 * @param $length
	 *
	 * @return false|string
	 * @throws Exception
	 */
	public function read_bytes( $request, $length, $options = [] ) {
		$options = array_merge( [
			'mode' => 'poll_once', // or 'poll_once' or 'wait_for_all_requested_bytes' 
		], $options );

		if ( ! isset( $this->requests[ $request ] ) ) {
			return false;
		}

		if ( $this->queue_needs_processing ) {
			$this->process_queue();
		}

		$response = $request->get_response();
		$stream   = $response->internal_body_stream;
		if(!$stream) {
			return false;
		}

		$polled = false;
		while ( true ) {
			if ( ! $request->is_finished() && feof( $stream ) ) {
				$request->state = Request::STATE_FINISHED;
				fclose( $stream );
				$this->queue_needs_processing = true;
			}

			$active_requests = static::filter_requests( 
				$this->requests->values(), 
				Request::STATE_RECEIVING_BODY 
			);
			if ( ! count( $active_requests ) ) {
				return false;
			}

			if (
				($options['mode'] === 'return') ||
				($options['mode'] === 'poll_once' && ($polled || strlen( $response->buffer ))) ||
				($options['mode'] === 'wait_for_all_requested_bytes' && strlen( $response->buffer ) >= $length)
			) {
				$buffered         = substr( $response->buffer, 0, $length );
				$response->buffer = substr( $response->buffer, $length );

				return $buffered;
			} elseif ( $response->is_finished() ) {
				unset( $this->requests[ $request ] );

				return $response->buffer;
			}

			static::read_response_bytes(
				static::filter_requests($active_requests, Request::STATE_RECEIVING_BODY),
				$length - strlen( $response->buffer )
			);

			$polled = true;
		}
	}

	/**
	 * Sends multiple HTTP requests asynchronously and follows the redirects
	 * until the final response headers are received.
	 *
	 * @param  Request[]  $requests  An array of HTTP requests.
	 *
	 * @return array An array containing the final, redirected response objects.
	 */
	static private function init_requests_follow_redirects(array $requests, Client $client) {
		$final_requests = $requests;
		do {
			static::open_nonblocking_http_sockets( $requests, $client );
			static::send_request_body(
				static::filter_requests($requests, Request::STATE_SENDING)
			);
			static::await_response_headers(
				static::filter_requests($requests, Request::STATE_SENT)
			);

			$got_valid_headers = static::filter_requests(
				$requests,
				Request::STATE_RECEIVING_BODY
			);
			$redirects = [];
			foreach($got_valid_headers as $k => $request) {
				$response = $request->get_response();
				$code = $response->get_status_code();
				if(!($code >= 300 && $code < 400)) {
					continue;
				}
				$location = $response->get_header('location');
				if($location === null) {
					continue;
				}
				$redirect = (new Request($location))->set_redirected_from($request);
				$redirects[$k] = $redirect;
				$final_requests[$k] = $redirect;
			}
		} while (count($redirects) > 0);
		$requests = $final_requests;

		static::set_response_stream(
			static::filter_requests($requests, Request::STATE_RECEIVING_BODY),
			$client
		);
		return $requests;
	}

	/**
	 * Handle transfer encodings.
	 * 
	 * @param  Request[]  $requests  An array of HTTP requests.
	 */
	static private function set_response_stream(array $requests, Client $client) {
		foreach ( $requests as $request ) {
			$body_stream = $request->http_socket;
			$body_stream = CountReadBytesStreamWrapper::wrap(
				$body_stream,
				function ($downloaded) use ($request, $client) {
					$response				 = $request->get_response();
					$total                   = $response->get_header('content-length') ?: null;
					if($total !== null) {
						$total = (int) $total;
					}
					$onProgress = $client->onProgress;
					$onProgress($request, $downloaded, $total);
				}
			);

			$response = $request->get_response();
			if($response->body_stream && $response->body_stream !== $request->http_socket) {
				trigger_error('populate_body_stream: body_stream already set', E_USER_WARNING);
				continue;
			}

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
						$body_stream = ChunkedEncodingStreamWrapper::create_resource(new VanillaStreamWrapperData(
							$body_stream
						));
						break;
					case 'gzip':
					case 'deflate':
						$body_stream = InflateStreamWrapper::create_resource(new InflateStreamWrapperData(
							$body_stream,
							$transfer_encoding === 'gzip' ? ZLIB_ENCODING_GZIP : ZLIB_ENCODING_RAW
						));
						break;
					default:
						$request->set_error(new HttpError( 'Unsupported transfer encoding received from the server: ' . $transfer_encoding ));
						break;
				}
			}
			$response->internal_body_stream = $body_stream;
			$response->body_stream = StreamWrapper::create_resource(
				new StreamData($request, $client)
			);
		}
	}

	/**
	 * Sends HTTP requests using streams.
	 *
	 * Enables crypto on the $requests HTTP socksts and sends the request body asynchronously.
	 *
	 * @param  Request[]  $requests  An array of HTTP requests.
	 */
	static private function send_request_body(array $requests)
	{
		$read                 = $except = null;
		$unprocessed_requests = $requests;
		while ( count( $unprocessed_requests ) ) {
			$write = [];
			$streams = [];
			foreach ( $unprocessed_requests as $k => $request ) {
				$write[ $k ] = $request->http_socket;
				$streams[ $k ] = $request->http_socket;
			}
			// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
			$ready = @stream_select( $read, $write, $except, 0, 5000000 );
			if ( $ready === false ) {
				foreach ( $unprocessed_requests as $request ) {
					$request->set_error(new HttpError( 'Error: ' . error_get_last()['message'] ));
				}
				return;
			} elseif ( $ready <= 0 ) {
				foreach ( $unprocessed_requests as $request ) {
					$request->set_error(new HttpError( 'stream_select timed out' ));
				}
				return;
			}

			foreach ( $write as $k => $stream ) {
				if ( PHP_VERSION_ID <= 71999 ) {
					// In PHP <= 7.1, stream_select doesn't preserve the keys of the array
					$k = array_search( $stream, $streams, true );
				}
				$request = $requests[ $k ];
				if ($request->is_ssl) {
					$enabled_crypto = stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT);
					if (false === $enabled_crypto) {
						$request->set_error(new HttpError('Failed to enable crypto: ' . error_get_last()['message']));
						fclose($stream);
						unset($remaining_streams[$k]);
					} elseif (0 === $enabled_crypto) {
						// Wait for the handshake to complete
						continue;
					}
				}

				// SSL handshake complete, send the request headers
				$header_bytes = static::prepare_request_headers( $request );

				if ( PHP_VERSION_ID <= 72000 ) {
					// In PHP <= 7.1 and later, making the socket non-blocking before the
					// SSL handshake makes the stream_socket_enable_crypto() call always return
					// false. Therefore, we only make the socket non-blocking after the
					// SSL handshake.

					if(false === stream_set_blocking( $stream, 0 )) {
						$request->set_error(new HttpError( 'stream_set_blocking() failed for ' . $request->url ));
						fclose( $stream );
						unset( $unprocessed_requests[ $k ] );
						continue;
					}
				}
				
				if(false === fwrite( $stream, $header_bytes )) {
					$request->set_error(new HttpError( 'Failed to write request bytes to ' . $request->url ));
					fclose( $stream );
					unset( $unprocessed_requests[ $k ] );
					continue;
				}

				// @TODO: Send the request body stream as well
				$request->state = Request::STATE_SENT;
				unset( $unprocessed_requests[ $k ] );
			}
		}
	}

	/**
	 * Awaits and retrieves the HTTP response headers for multiple requests.
	 *
	 * @param  array  $requests  An array of requests.
	 */
	static private function await_response_headers( $requests ) {
		$headers = array();
		foreach ( $requests as $k => $request ) {
			$headers[ $k ] = '';
		}
		$remaining_requests = $requests;
		while ( true ) {
			$bytes = static::read_response_bytes( $remaining_requests, 1 );
			if ( false === $bytes ) {
				break;
			}
			foreach ( $remaining_requests as $k => $request ) {
				$headers[ $k ] .= $request->get_response()->buffer;
				$request->get_response()->buffer = '';
				if ( strlen( $headers[ $k ] ) >= 4 && substr_compare( $headers[ $k ], "\r\n\r\n", - strlen( "\r\n\r\n" ) ) === 0 ) {
					unset( $remaining_requests[ $k ] );
				}
			}
		}

		foreach ( $headers as $k => $header ) {
			$request = $requests[ $k ];
			$response = $request->get_response();
			$parsed = static::parse_http_headers( $header );
			$response->headers = $parsed['headers'];
			$response->statusCode = $parsed['status']['code'];
			$response->statusMessage = $parsed['status']['message'];
			$response->protocol = $parsed['status']['protocol'];
			$request->state = Request::STATE_RECEIVING_BODY;
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
	 * Opens multiple HTTP connections.
	 *
	 * @param  Request[]  $requests  An array of HTTP requests.
	 * @param  Client     $client    The Client instance to bind the sockets to.
	 * @see static::open_nonblocking_http_sockets
	 */
	static private function open_nonblocking_http_sockets(array $requests, Client $client)
	{
		foreach ($requests as $request) {
			static::open_nonblocking_http_socket($request, $client);
		}
	}

	/**
	 * Opens a HTTP or HTTPS stream using stream_socket_client() without blocking,
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
	static private function open_nonblocking_http_socket( Request $request, Client $client ) {
		$url = $request->url;
		$parts  = parse_url( $url );
		$scheme = $parts['scheme'];
		if ( ! in_array( $scheme, array( 'http', 'https' ) ) ) {
			$request->set_error( new HttpError( 'stream_http_open_nonblocking: Invalid scheme in URL ' . $url . ' – only http:// and https:// URLs are supported' ) );
			return false;
		}
	
		$is_ssl = $scheme === 'https';
		$port = $parts['port'] ?? ( $scheme === 'https' ? 443 : 80 );
		$host = $parts['host'];
	
		// Create stream context
		$context = stream_context_create(
			array(
				'socket' => array(
					'isSsl'       => $is_ssl,
					'originalUrl' => $url,
					'socketUrl'   => 'tcp://' . $host . ':' . $port,
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
		if ( $stream === false ) {
			$request->set_error(new HttpError( "stream_http_open_nonblocking: stream_socket_client() was unable to open a stream to $url. $errno: $errstr" ));
			return false;
		}
	
		// This seemed to have some relevance when experimenting with Transfer-Encoding: Chunked.
		// @TODO Let's eventually either remove or standardize it.
		// stream_set_read_buffer( $stream, 10 );
		if ( PHP_VERSION_ID >= 72000 ) {
			// In PHP <= 7.1 and later, making the socket non-blocking before the
			// SSL handshake makes the stream_socket_enable_crypto() call always return
			// false. Therefore, we only make the socket non-blocking after the
			// SSL handshake.
			if ( false === stream_set_blocking( $stream, 0 ) ) {
				$request->set_error(new HttpError( 'stream_http_open_nonblocking: stream_set_blocking() failed for ' . $url ));
				fclose( $stream );
				return false;
			}
		}

		$request->http_socket = $stream;
		$request->get_response()->internal_body_stream = $stream;
		$request->get_response()->body_stream = $stream;
		$request->state = Request::STATE_SENDING;
		
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

	/**
	 * Waits for response bytes to be available in the given streams.
	 *
	 * @param  array  $streams  The array of streams to wait for.
	 * @param  int  $length     The maximum number of bytes to read from each stream (you may get less than that).
	 * @param  int  $timeout_microseconds  The timeout in microseconds for the stream_select function.
	 */
	private static function read_response_bytes( array $requests, $length, $timeout_microseconds = 50000000 ) {
		$streams = [];
		foreach ( $requests as $k => $request ) {
			$streams[$k] = $request->http_socket;
		}

		$read = $streams;
		if ( count( $read ) === 0 ) {
			return false;
		}

		$write  = array();
		$except = null;

		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
		$ready = @stream_select( $read, $write, $except, 0, $timeout_microseconds );

		if ( $ready === false ) {
			throw new Exception( 'Could not retrieve response bytes: ' . error_get_last()['message'] );
		} elseif ( $ready <= 0 ) {
			throw new Exception( 'stream_select timed out' );
		}

		foreach ( $read as $k => $stream ) {
			if ( PHP_VERSION_ID <= 71999 ) {
				// In PHP <= 7.1, stream_select doesn't preserve the keys of the array
				$k = array_search( $stream, $streams, true );
			}

			$response = $requests[ $k ]->get_response();
			$response->buffer .= fread( $response->internal_body_stream, $length );
		}
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

	static private function get_streams( array $requests ) {
		$streams = [];
		foreach($requests as $k => $request) {
			$streams[$k] = $request->http_socket;
		}
		return $streams;
	}

}
