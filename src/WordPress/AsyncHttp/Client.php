<?php

namespace WordPress\AsyncHttp;

use WordPress\AsyncHttp\StreamWrapper\ChunkedEncodingWrapper;
use WordPress\AsyncHttp\StreamWrapper\CountReadBytesWrapper;
use WordPress\AsyncHttp\StreamWrapper\EventLoopWrapper;
use WordPress\AsyncHttp\StreamWrapper\InflateStreamWrapper;
use WordPress\Streams\StreamWrapperData;

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

	const STREAM_SELECT_READ = 1;
	const STREAM_SELECT_WRITE = 2;

	const READ_NON_BLOCKING = 'READ_NON_BLOCKING';
	const READ_POLL_ANY = 'READ_POLL_ANY';
	const READ_POLL_ALL = 'READ_POLL_ALL';

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

	protected $concurrency;
	protected $max_redirects = 3;

	protected $requests;
	protected $on_progress;
	protected $connections = array();

	public function __construct( $options = [] ) {
		$this->concurrency = $options['concurrency'] ?? 2;
		$this->requests    = [];
		$this->on_progress  = $options['on_progress'] ?? function () {
		};
		$this->max_redirects = $options['max_redirects'] ?? 3;
	}

	/**
	 * Enqueues one or multiple HTTP requests for asynchronous processing.
	 * It does not open the network sockets, only adds the Request objects to
	 * an internal queue. Network transmission is delayed until one of the returned
	 * streams is read from.
	 *
	 * @param  Request|Request[]  $requests  The HTTP request(s) to enqueue. Can be a single request or an array of requests.
	 */
	public function enqueue( $requests ) {
		if ( ! is_array( $requests ) ) {
			$this->requests[]                   = $requests;
			$this->connections[ $requests->id ] = new Connection( $requests );

			return;
		}

		foreach ( $requests as $request ) {
			$this->requests[]                  = $request;
			$this->connections[ $request->id ] = new Connection( $request );
		}
	}

	public function await_response_bytes() {
		do {
			foreach ( $this->requests as $request ) {
				$request = $request->latest_redirect();
				$connection = $this->connections[ $request->id ];
				if (
					$request->state !== Request::STATE_FAILED &&
					$request->response &&
					strlen( $connection->response_buffer ) > 0
				) {
					return $request;
				}
			}
		} while ( $this->event_loop_tick() );

		return false;
	}

	/**
	 * Reads $length bytes from the given request while also running
	 * non-blocking event loop operations.
	 *
	 * @param  Request  $request
	 * @param $length
	 *
	 * @return string
	 */
	public function read_bytes( Request $request, $length, $mode = self::READ_NON_BLOCKING ) {
		$buffered   = '';
		do {
			$request = $request->latest_redirect();
			$connection = $this->connections[ $request->id ];
			if (
				$request->state === Request::STATE_RECEIVING_BODY ||
				$request->state === Request::STATE_FINISHED
			) {
				$buffered .= $connection->consume_buffer( $length - strlen( $buffered ) );
			}

			if (
				( $mode === self::READ_NON_BLOCKING ) ||
				( $mode === self::READ_POLL_ANY && strlen( $buffered ) ) ||
				( $mode === self::READ_POLL_ALL && strlen( $buffered ) >= $length ) ||
				// End of data
				( $request->state === Request::STATE_FINISHED && (
					!is_resource($this->connections[ $request->id ]->http_socket) ||
					feof( $this->connections[ $request->id ]->http_socket )
				 ) )
			) {
				break;
			}
		} while ( $this->event_loop_tick() );

		return $buffered;
	}

	/**
	 * Returns the response stream associated with the given Request object.
	 * Reading from that stream also runs this Client's event loop.
	 *
	 * @param  Request  $request
	 *
	 * @return resource|bool
	 */
	public function await_response_stream( Request $request ) {
		do {
			$request = $request->latest_redirect();
			$connection = $this->connections[ $request->id ];
			if ( $connection->event_loop_decoded_response_stream ) {
				return $connection->event_loop_decoded_response_stream;
			}
			// If a request finished without opening a decoded response stream, it either failed
			// or it's a redirect. Let's return false.
			if ( $request->state === Request::STATE_FAILED || $request->state === Request::STATE_FINISHED ) {
				return false;
			}
		} while ( $this->event_loop_tick() );
		
		return false;
	}

	public function event_loop_tick() {
		if ( count( $this->get_concurrent_requests() ) === 0 ) {
			return false;
		}

		$this->open_nonblocking_http_sockets(
			$this->get_concurrent_requests( Request::STATE_ENQUEUED )
		);

		$this->enable_crypto(
			$this->get_concurrent_requests( Request::STATE_WILL_ENABLE_CRYPTO )
		);

		$this->send_request_headers(
			$this->get_concurrent_requests( Request::STATE_WILL_SEND_HEADERS )
		);

		$this->send_request_body(
			$this->get_concurrent_requests( Request::STATE_WILL_SEND_BODY )
		);

		$this->receive_response_headers(
			$this->get_concurrent_requests( Request::STATE_RECEIVING_HEADERS )
		);

		$this->receive_response_body(
			$this->get_concurrent_requests( Request::STATE_RECEIVING_BODY )
		);

		$this->handle_redirects(
			$this->get_concurrent_requests( Request::STATE_RECEIVED )
		);

		$this->cleanup_finished_and_consumed_requests(
			$this->get_requests( Request::STATE_FINISHED )
		);

		return true;
	}

	protected function mark_finished( Request $request ) {
		$request->state = Request::STATE_FINISHED;
		$this->close_connection( $request );
	}

	protected function set_error( Request $request, $error ) {
		$request->error = $error;
		$request->state = Request::STATE_FAILED;

		$this->close_connection( $request );
	}

	private function close_connection( Request $request ) {
		$socket = $this->connections[$request->id]->http_socket;
		if ( $socket && is_resource( $socket ) ) {
			@fclose( $socket );
			// No need to close all the dependent stream wrappers – they are
			// invalidated when the root resource is closed.
		}
	}

	protected function get_concurrent_requests( $states = null ) {
		$processed_requests = $this->get_requests( [
			Request::STATE_WILL_ENABLE_CRYPTO,
			Request::STATE_WILL_SEND_HEADERS,
			Request::STATE_WILL_SEND_BODY,
			Request::STATE_SENT,
			Request::STATE_RECEIVING_HEADERS,
			Request::STATE_RECEIVING_BODY,
			Request::STATE_RECEIVED,
		] );
		$available_slots    = $this->concurrency - count( $processed_requests );
		$enqueued_requests  = $this->get_requests( Request::STATE_ENQUEUED );
		for ( $i = 0; $i < $available_slots; $i ++ ) {
			if ( ! isset( $enqueued_requests[ $i ] ) ) {
				break;
			}
			$processed_requests[] = $enqueued_requests[ $i ];
		}
		if ( $states !== null ) {
			$processed_requests = static::filter_requests( $processed_requests, $states );
		}

		return $processed_requests;
	}

	public function get_failed_requests() {
		return $this->get_requests( Request::STATE_FAILED );
	}

	private function get_requests( $states ) {
		if ( ! is_array( $states ) ) {
			$states = [ $states ];
		}

		return static::filter_requests( $this->requests, $states );
	}

	/**
	 * Handle transfer encodings.
	 *
	 * @param  Request[]  $requests  An array of HTTP requests.
	 */
	private function decode_and_monitor_response_body_stream( Request $request ) {
		$wrapped_stream = CountReadBytesWrapper::wrap(
			$this->connections[ $request->id ]->http_socket,
			function ( $downloaded ) use ( $request ) {
				$total = $request->response->get_header( 'content-length' ) ?: null;
				if ( $total !== null ) {
					$total = (int) $total;
				}
				$onProgress = $this->on_progress;
				$onProgress( $request, $downloaded, $total );
			}
		);

		$transfer_encodings = array();

		$transfer_encoding = $request->response->get_header( 'transfer-encoding' );
		if ( $transfer_encoding ) {
			$transfer_encodings = array_map( 'trim', explode( ',', $transfer_encoding ) );
		}

		$content_encoding = $request->response->get_header( 'content-encoding' );
		if ( $content_encoding && ! in_array( $content_encoding, $transfer_encodings ) ) {
			$transfer_encodings[] = $content_encoding;
		}

		foreach ( $transfer_encodings as $transfer_encoding ) {
			switch ( $transfer_encoding ) {
				case 'chunked':
					/**
					 * Wrap the stream in a chunked encoding decoder.
					 * There was an attempt to use stream filters, but unfortunately
					 * they are incompatible with stream_select().
					 */
					$wrapped_stream = ChunkedEncodingWrapper::wrap( $wrapped_stream );
					break;
				case 'gzip':
				case 'deflate':
					$wrapped_stream = InflateStreamWrapper::wrap(
						$wrapped_stream,
						$transfer_encoding === 'gzip' ? ZLIB_ENCODING_GZIP : ZLIB_ENCODING_RAW
					);
					break;
				case 'identity':
					// No-op
					break;
				default:
					$this->set_error( $request,
						new HttpError( 'Unsupported transfer encoding received from the server: ' . $transfer_encoding ) );
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
	private function enable_crypto( array $requests ) {
		foreach ( $this->stream_select( $requests, static::STREAM_SELECT_WRITE ) as $request ) {
			$enabled_crypto = stream_socket_enable_crypto(
				$this->connections[ $request->id ]->http_socket,
				true,
				STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
			);
			if ( false === $enabled_crypto ) {
				$this->set_error( $request, new HttpError( 'Failed to enable crypto: ' . error_get_last()['message'] ) );
				continue;
			} elseif ( 0 === $enabled_crypto ) {
				// The SSL handshake isn't finished yet, let's skip it
				// for now and try again on the next event loop pass.
				continue;
			}
			// SSL connection established, let's send the headers.
			$request->state = Request::STATE_WILL_SEND_HEADERS;
		}
	}

	/**
	 * Sends HTTP request headers.
	 *
	 * @param  Request[]  $requests  An array of HTTP requests.
	 */
	private function send_request_headers( array $requests ) {
		foreach ( $this->stream_select( $requests, static::STREAM_SELECT_WRITE ) as $request ) {
			$header_bytes = static::prepare_request_headers( $request );

			if ( false === @fwrite( $this->connections[ $request->id ]->http_socket, $header_bytes ) ) {
				$this->set_error( $request, new HttpError( 'Failed to write request bytes – ' . error_get_last()['message'] ) );
				continue;
			}

			if ( $request->upload_body_stream ) {
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
	private function send_request_body( array $requests ) {
		foreach ( $this->stream_select( $requests, self::STREAM_SELECT_WRITE ) as $request ) {
			$chunk = fread( $request->upload_body_stream, 8192 );
			if ( false === $chunk ) {
				$this->set_error( $request, new HttpError( 'Failed to read from the request body stream' ) );
				continue;
			}

			if ( false === fwrite( $this->connections[ $request->id ]->http_socket, $chunk ) ) {
				$this->set_error( $request, new HttpError( 'Failed to write request bytes.' ) );
				continue;
			}

			if ( '' === $chunk || feof( $request->upload_body_stream ) ) {
				fclose( $request->upload_body_stream );
				$request->upload_body_stream = null;
				$request->state              = Request::STATE_RECEIVING_HEADERS;
			}
		}
	}

	/**
	 * Reads the next received portion of HTTP response headers for multiple requests.
	 *
	 * @param  array  $requests  An array of requests.
	 */
	private function receive_response_headers( $requests ) {
		foreach ( $this->stream_select( $requests, static::STREAM_SELECT_READ ) as $request ) {
			if ( ! $request->response ) {
				$request->response = new Response( $request );
			}
			$connection = $this->connections[ $request->id ];
			$response   = $request->response;

			while ( true ) {
				// @TODO: Use a larger chunk size here and then scan for \r\n\r\n.
				//        1 seems slow and overly conservative.
				$header_byte = fread( $this->connections[ $request->id ]->http_socket, 1 );
				if ( false === $header_byte || '' === $header_byte ) {
					break;
				}
				$connection->response_buffer .= $header_byte;

				$buffer_size = strlen( $connection->response_buffer );
				if (
					$buffer_size < 4 ||
					$connection->response_buffer[ $buffer_size - 4 ] !== "\r" ||
					$connection->response_buffer[ $buffer_size - 3 ] !== "\n" ||
					$connection->response_buffer[ $buffer_size - 2 ] !== "\r" ||
					$connection->response_buffer[ $buffer_size - 1 ] !== "\n"
				) {
					continue;
				}

				$parsed                      = static::parse_http_headers( $connection->response_buffer );
				$connection->response_buffer = '';

				$response->headers        = $parsed['headers'];
				$response->status_code    = $parsed['status']['code'];
				$response->status_message = $parsed['status']['message'];
				$response->protocol       = $parsed['status']['protocol'];

				// If we're being redirected, we don't need to wait for the body.
				if ( $response->status_code >= 300 && $response->status_code < 400 ) {
					$request->state = Request::STATE_RECEIVED;
					break;
				}

				$request->state = Request::STATE_RECEIVING_BODY;
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
		// @TODO: Assume body is fully received when either
		// * Content-Length is reached
		// * The last chunk in Transfer-Encoding: chunked is received
		// * The connection is closed
		foreach ( $this->stream_select( $requests, static::STREAM_SELECT_READ ) as $request ) {
			$response = $request->response;
			if ( ! $this->connections[ $request->id ]->decoded_response_stream ) {
				$this->connections[ $request->id ]->decoded_response_stream            = $this->decode_and_monitor_response_body_stream( $request );
				$this->connections[ $request->id ]->event_loop_decoded_response_stream = EventLoopWrapper::wrap(
					$request,
					$this->connections[ $request->id ]->http_socket,
					$this
				);
			}

			while ( true ) {
				if ( feof( $this->connections[ $request->id ]->decoded_response_stream ) ) {
					$request->state = Request::STATE_RECEIVED;
					break;
				}

				$body_bytes = fread( $this->connections[ $request->id ]->decoded_response_stream, 1024 );
				if ( false === $body_bytes || '' === $body_bytes ) {
					break;
				}

				$this->connections[ $request->id ]->response_buffer .= $body_bytes;
			}
		}
	}

	/**
	 * @param  array  $requests  An array of requests.
	 */
	private function handle_redirects( $requests ) {
		foreach ( $requests as $request ) {
			$response = $request->response;
			$code     = $response->status_code;
			$this->mark_finished( $request );
			if ( ! ( $code >= 300 && $code < 400 ) ) {
				continue;
			}

			$location = $response->get_header( 'location' );
			if ( $location === null ) {
				continue;
			}

			
			$redirects_so_far = 0;
			$cause = $request;
			while($cause->redirected_from) {
				++$redirects_so_far;
				$cause = $cause->redirected_from;
			}

			if($redirects_so_far >= $this->max_redirects) {
				$this->set_error($request, new HttpError('Too many redirects'));
				continue;
			}

			$redirect_url = $location;
			if(strpos($redirect_url, 'http://') !== 0 && strpos($redirect_url, 'https://') !== 0) {
				$current_url_parts = parse_url($request->url);
				$redirect_url = $current_url_parts['scheme'] . '://' . $current_url_parts['host'];
				if($current_url_parts['port']){
					$redirect_url .= ':' . $current_url_parts['port'];
				}
				if(!str_starts_with($location, '/')) {
					$redirect_url .= '/';
				}
				$redirect_url .= $location;
			}

			if (!filter_var($redirect_url, FILTER_VALIDATE_URL)) {
				$this->set_error($request, new HttpError('Invalid redirect URL'));
				continue;
			}

			$this->enqueue(new Request($redirect_url, ['redirected_from' => $request]));
		}
	}

	private function cleanup_finished_and_consumed_requests( $requests ) {
		foreach ( $requests as $request ) {
			// Interestingly, relying on foreach-provided $k => $request unsets
			// the wrong request. Is it a case of a non-sparse array being re-indexed
			// when iterating and unsetting?
			$request_key = array_search($request, $this->requests, true);
			if ( ! isset( $this->connections[ $request->id ] ) ) {
				// unset( $this->requests[ $request_key ] );
				continue;
			}

			$connection = $this->connections[ $request->id ];
			if ( ! $connection || ! $connection->response_buffer ) {
				// unset( $this->requests[ $request_key ] );
				// unset( $this->connections[ $request->id ] );
			}
		}
	}

	/**
	 * Parses an HTTP headers string into an array containing the status and headers.
	 *
	 * @param  string  $headers  The HTTP headers to parse.
	 *
	 * @return array An array containing the parsed status and headers.
	 */
	private function parse_http_headers( string $headers ) {
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
	private function open_nonblocking_http_sockets( $requests ) {
		foreach ( $requests as $request ) {
			$url    = $request->url;
			$parts  = parse_url( $url );
			$scheme = $parts['scheme'];
			if ( ! in_array( $scheme, array( 'http', 'https' ) ) ) {
				$this->set_error( $request,
					new HttpError( 'stream_http_open_nonblocking: Invalid scheme in URL ' . $url . ' – only http:// and https:// URLs are supported' ) );
				continue;
			}

			$is_ssl = $scheme === 'https';
			$port   = $parts['port'] ?? ( $scheme === 'https' ? 443 : 80 );
			$host   = $parts['host'];

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
				$this->set_error( $request,
					new HttpError( "stream_http_open_nonblocking: stream_socket_client() was unable to open a stream to $url. $errno: $errstr" ) );
				continue;
			}

			if ( PHP_VERSION_ID >= 72000 ) {
				// In PHP <= 7.1 and later, making the socket non-blocking before the
				// SSL handshake makes the stream_socket_enable_crypto() call always return
				// false. Therefore, we only make the socket non-blocking after the
				// SSL handshake.
				stream_set_blocking( $stream, 0 );
			}

			$this->connections[ $request->id ]->http_socket = $stream;
			if ( $is_ssl ) {
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
	 *
	 * @return string The prepared HTTP request string.
	 */
	static private function prepare_request_headers( Request $request ) {
		$url   = $request->url;
		$parts = parse_url( $url );
		$host  = $parts['host'];
		$path  = ( isset( $parts['path'] ) ? $parts['path'] : '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );

		$headers = [
			"Host"            => $host,
			"User-Agent"      => "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/93.0.4577.82 Safari/537.36",
			"Accept"          => "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9",
			"Accept-Encoding" => "gzip",
			"Accept-Language" => "en-US,en;q=0.9",
			"Connection"      => "close",
		];
		foreach ( $request->headers as $k => $v ) {
			$headers[ $k ] = $v;
		}


		$request_parts = array(
			"$request->method $path HTTP/$request->http_version",
		);

		foreach ( $headers as $name => $value ) {
			$request_parts[] = "$name: $value";
		}

		return implode( "\r\n", $request_parts ) . "\r\n\r\n";
	}

	private function filter_requests( array $requests, $states ) {
		if ( ! is_array( $states ) ) {
			$states = [ $states ];
		}
		$results = [];
		foreach ( $requests as $request ) {
			if ( in_array( $request->state, $states ) ) {
				$results[] = $request;
			}
		}

		return $results;
	}


	private function stream_select( $requests, $mode ) {
		if ( empty( $requests ) ) {
			return [];
		}

		$read  = [];
		$write = [];
		foreach ( $requests as $k => $request ) {
			if ( $mode & static::STREAM_SELECT_READ ) {
				$read[ $k ] = $this->connections[ $request->id ]->http_socket;
			}
			if ( $mode & static::STREAM_SELECT_WRITE ) {
				$write[ $k ] = $this->connections[ $request->id ]->http_socket;
			}
		}
		$except = null;

		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
		$ready = @stream_select( $read, $write, $except, 0, static::NONBLOCKING_TIMEOUT_MICROSECONDS );
		if ( $ready === false ) {
			foreach ( $requests as $request ) {
				$this->set_error( $request, new HttpError( 'Error: ' . error_get_last()['message'] ) );
			}

			return [];
		} elseif ( $ready <= 0 ) {
			// @TODO allow at most X stream_select attempts per request
			// foreach ( $unprocessed_requests as $request ) {
			// 	$this->>set_error($request, new HttpError( 'stream_select timed out' ));
			// }
			return [];
		}

		$selected_requests = [];
		foreach ( array_keys( $read ) as $k ) {
			$selected_requests[ $k ] = $requests[ $k ];
		}
		foreach ( array_keys( $write ) as $k ) {
			$selected_requests[ $k ] = $requests[ $k ];
		}

		return $selected_requests;
	}

}
