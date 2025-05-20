<?php

namespace WordPress\HttpClient;

use WordPress\ByteStream\ByteTransformer\InflateTransformer;
use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\ByteStream\ReadStream\TransformedReadStream;
use WordPress\HttpClient\ByteStream\ChunkedDecoderReadStream;
use WordPress\HttpClient\ByteStream\ChunkedEncoderByteTransformer;
use WordPress\HttpClient\ByteStream\RequestReadStream;

/**
 * An asynchronous HTTP client library.
 *
 * This class makes non-blocking HTTP requests using just native PHP streams.
 * It uses an event-driven architecture.
 *
 * ### Key Features
 *
 * - **Streaming support:** Enables efficient handling of large response bodies.
 * - **Progress monitoring:** Track the progress of requests and responses.
 * - **Concurrency limits:** Control the number of simultaneous connections.
 * - **PHP 7.2+ support and no dependencies:** Works on vanilla PHP without external libraries.
 *
 * ### Usage Example
 *
 * ```php
 * $requests = [
 *     new Request("[https://wordpress.org/latest.zip](https://wordpress.org/latest.zip)"),
 *     new Request("[https://raw.githubusercontent.com/wpaccessibility/a11y-theme-unit-test/master/a11y-theme-unit-test-data.xml](https://raw.githubusercontent.com/wpaccessibility/a11y-theme-unit-test/master/a11y-theme-unit-test-data.xml)"),
 * ];
 *
 * $client = new Client();
 * $client->enqueue($requests);
 *
 * while ($client->await_next_event()) {
 *     $event = $client->get_event();
 *     $request = $client->get_request();
 *
 *     if ($event === Client::EVENT_BODY_CHUNK_AVAILABLE) {
 *         $chunk = $client->get_response_body_chunk();
 *         // Process the chunk...
 *     }
 *     // Handle other events...
 * }
 * ```
 *
 * @TODO
 * * Request headers – accept string lines such as "Content-type: text/plain" instead of key-value pairs. K/V pairs
 *   are confusing and lead to accidental errors such as `0: Content-type: text/plain`. They also diverge from the
 *   format that curl accepts.
 * * Response caching – add a custom cache handler for easy caching of the same URLs
 * * Response caching – support HTTP cache-control headers
 *
 * @since    Next Release
 * @package  WordPress
 * @subpackage Async_HTTP
 */
class Client {

	/**
	 * The maximum number of concurrent connections allowed.
	 *
	 * This is as a safeguard against:
	 * * Spreading our network bandwidth too thin and not making any real progress on any
	 *   request.
	 * * Overwhelming the server with too many requests.
	 *
	 * @var int
	 */
	protected $concurrency;

	/**
	 * The maximum number of redirects to follow for a single request.
	 *
	 * This prevents infinite redirect loops and provides a degree of control over the client's behavior.
	 * Setting it too high might lead to unexpected navigation paths.
	 *
	 * @var int
	 */
	protected $max_redirects = 3;

	/**
	 * All the HTTP requests ever enqueued with this Client.
	 *
	 * Each Request may have a different state, and this Client will manage them
	 * asynchronously, moving them through the various states as the network
	 * operations progress.
	 *
	 * @since Next Release
	 * @var Request[]
	 */
	protected $requests;

	/**
	 * Network connection details managed privately by this Client.
	 *
	 * Each Request has a corresponding Connection object that contains
	 * the network socket, response buffer, and other connection-specific details.
	 *
	 * These are internal, will change, and should not be exposed to the outside world.
	 *
	 * @var array
	 */
	protected $connections = array();
	protected $events = array();
	protected $event;
	protected $request;
	protected $response_body_chunk;
	protected $timeout;
	protected $requests_started_at = array();

	public function __construct( $options = array() ) {
		$this->concurrency   = $options['concurrency'] ?? 10;
		$this->max_redirects = $options['max_redirects'] ?? 3;
		$this->timeout       = $options['timeout'] ?? 30;
		$this->requests      = array();
	}

	/**
	 * Returns a RemoteFileReader that streams the response body of the
	 * given request.
	 *
	 * @param  Request  $request  The request to stream.
	 *
	 * @return RequestReadStream
	 */
	public function fetch( $request, $options = array() ) {
		return new RequestReadStream( $request,
			array_merge( [ 'client' => $this ], is_array( $options ) ? $options : iterator_to_array( $options ) ) );
	}

	/**
	 * Returns an array of RemoteFileReader instances that stream the response bodies
	 * of the given requests.
	 *
	 * @param  Request[]  $requests  The requests to stream.
	 *
	 * @return RequestReadStream[]
	 */
	public function fetch_many( array $requests, $options = array() ) {
		$streams = array();

		foreach ( $requests as $request ) {
			$streams[] = $this->fetch( $request, $options );
		}

		return $streams;
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
			$requests = array( $requests );
		}

		foreach ( $requests as $request ) {
			$this->requests[]                          = apply_filters( 'wp_http_client_request', $request );
			$this->events[ $request->id ]              = array();
			$this->connections[ $request->id ]         = new Connection( $request );
			$this->requests_started_at[ $request->id ] = microtime( true );
		}
	}

	/**
	 * Returns the next event related to any of the HTTP
	 * requests enqueued in this client.
	 *
	 * ## Events
	 *
	 * The returned event is a ClientEvent with $event->name
	 * being one of the following:
	 *
	 * * `Client::EVENT_GOT_HEADERS`
	 * * `Client::EVENT_BODY_CHUNK_AVAILABLE`
	 * * `Client::EVENT_REDIRECT`
	 * * `Client::EVENT_FAILED`
	 * * `Client::EVENT_FINISHED`
	 *
	 * See the ClientEvent class for details on each event.
	 *
	 * Once an event is consumed, it is removed from the
	 * event queue and will not be returned again.
	 *
	 * When there are no events available, this function
	 * blocks and waits for the next one. If all requests
	 * have already finished, and we are not waiting for
	 * any more events, it returns false.
	 *
	 * ## Filtering
	 *
	 * The $query parameter can be used to filter the events
	 * that are returned. It can contain the following keys:
	 *
	 * * `request_id` – The ID of the request to consider.
	 *
	 * For example, to only consider the next `EVENT_GOT_HEADERS`
	 * event for a specific request, you can use:
	 *
	 * ```php
	 * $request = new Request( "https://w.org" );
	 *
	 * $client = new HttpClientClient();
	 * $client->enqueue( $request );
	 * $event = $client->await_next_event( [
	 *    'request_id' => $request->id,
	 * ] );
	 * ```
	 *
	 * Importantly, filtering does not consume unrelated events.
	 * You can await all the events for a request #2, and
	 * then await the next event for request #1 even if the
	 * request #1 has finished before you started awaiting
	 * events for request #2.
	 *
	 * @param $query
	 *
	 * @return bool
	 */
	public function await_next_event( $query = array() ) {
		$ordered_events            = array(
			self::EVENT_GOT_HEADERS,
			self::EVENT_BODY_CHUNK_AVAILABLE,
			self::EVENT_REDIRECT,
			self::EVENT_FAILED,
			self::EVENT_FINISHED,
		);
		$this->event               = null;
		$this->request             = null;
		$this->response_body_chunk = null;

		$start_time = microtime( true );
		$timeout    = $query['timeout'] ?? $this->timeout * 1000;

		do {
			if ( false !== $timeout && ( microtime( true ) - $start_time ) * 1000 >= $timeout ) {
				return false;
			}

			if ( empty( $query['requests'] ) ) {
				$events = array_keys( $this->events );
			} else {
				$events = array();
				foreach ( $query['requests'] as $query_request ) {
					$events[] = $query_request->id;
					while ( $query_request->redirected_to ) {
						$query_request = $query_request->redirected_to;
						$events[]      = $query_request->id;
					}
				}
			}

			foreach ( $events as $request_id ) {
				foreach ( $ordered_events as $considered_event ) {
					$needs_emitting = $this->events[ $request_id ][ $considered_event ] ?? false;
					if ( ! $needs_emitting ) {
						continue;
					}

					$this->events[ $request_id ][ $considered_event ] = false;

					$this->event   = $considered_event;
					$this->request = $this->get_request_by_id( $request_id );
					if ( $this->event === self::EVENT_BODY_CHUNK_AVAILABLE ) {
						$this->response_body_chunk = $this->next_response_body_bytes();
					}

					return true;
				}
			}
		} while ( $this->event_loop_tick() );

		return false;
	}

	public function has_pending_event( $request, $event_type ) {
		return $this->events[ $request->id ][ $event_type ] ?? false;
	}

	/**
	 * Returns the next event found by await_next_event().
	 *
	 * @return string|bool The next event, or false if no event is set.
	 */
	public function get_event() {
		if ( null === $this->event ) {
			return false;
		}

		return $this->event;
	}

	/**
	 * Returns the request associated with the last event found
	 * by await_next_event().
	 *
	 * @return Request
	 */
	public function get_request() {
		if ( null === $this->request ) {
			return false;
		}

		return $this->request;
	}

	/**
	 * Returns the response body chunk associated with the EVENT_BODY_CHUNK_AVAILABLE
	 * event found by await_next_event().
	 *
	 * @return string|false
	 */
	public function get_response_body_chunk() {
		if ( null === $this->response_body_chunk ) {
			return false;
		}

		return $this->response_body_chunk;
	}

	/**
	 * Asynchronously moves the enqueued Request objects through the
	 * various states of the HTTP request-response lifecycle.
	 *
	 * @return bool Whether any active requests were processed.
	 */
	protected function event_loop_tick() {
		if ( count( $this->get_active_requests() ) === 0 ) {
			return false;
		}

		foreach ( $this->get_active_requests() as $request ) {
			if ( microtime( true ) - $this->requests_started_at[ $request->id ] > $this->timeout ) {
				$this->set_error( $request, new HttpError( sprintf( 'Request timed out after %s seconds.', $this->timeout ) ) );
			}
		}

		$this->open_nonblocking_http_sockets(
			$this->get_active_requests( Request::STATE_ENQUEUED )
		);

		$this->enable_crypto(
			$this->get_active_requests( Request::STATE_WILL_ENABLE_CRYPTO )
		);

		$this->send_request_headers(
			$this->get_active_requests( Request::STATE_WILL_SEND_HEADERS )
		);

		$this->send_request_body(
			$this->get_active_requests( Request::STATE_WILL_SEND_BODY )
		);

		$nb_headers_received = $this->receive_response_headers(
			$this->get_active_requests( Request::STATE_RECEIVING_HEADERS )
		);

		$this->handle_redirects(
			$this->get_active_requests( Request::STATE_RECEIVED )
		);

		/**
		 * Allows the caller to consume the headers before we start polling
		 * for the body of those requests.
		 *
		 * This prevents the following scenario:
		 *
		 * 1. The consumer calls await_next_event() and they're only interested in
		 *    the EVENT_GOT_HEADERS event.
		 * 2. In the same event_loop_tick:
		 *    * The headers arrive
		 *    * The request is promoted to STATE_RECEIVING_BODY
		 *    * We poll for the response body
		 *    * We wait 10 more seconds before the body starts arriving
		 * 3. The consumer gets the EVENT_GOT_HEADERS event 10 seconds later
		 *    than they could have.
		 */
		if ( $nb_headers_received > 0 ) {
			return true;
		}

		$this->receive_response_body(
			$this->get_active_requests( Request::STATE_RECEIVING_BODY )
		);

		return true;
	}

	/**
	 * Consumes $length bytes received in response to a given request.
	 *
	 * @return string
	 */
	protected function next_response_body_bytes() {
		if ( null === $this->request ) {
			return false;
		}
		$request    = $this->request;
		$connection = $this->connections[ $request->id ];
		if (
			$request->state === Request::STATE_RECEIVING_BODY ||
			$request->state === Request::STATE_FINISHED
		) {
			return $connection->consume_buffer();
		}

		$end_of_data = $request->state === Request::STATE_FINISHED && (
				! is_resource( $this->connections[ $request->id ]->http_socket ) ||
				$this->connections[ $request->id ]->decoded_response_stream->reached_end_of_data()
			);
		if ( $end_of_data ) {
			return false;
		}

		return '';
	}

	protected function mark_finished( Request $request ) {
		$request->state                                       = Request::STATE_FINISHED;
		$this->events[ $request->id ][ self::EVENT_FINISHED ] = true;

		$this->close_connection( $request );
	}

	protected function set_error( Request $request, $error ) {
		$request->error                                     = $error;
		$request->state                                     = Request::STATE_FAILED;
		$this->events[ $request->id ][ self::EVENT_FAILED ] = true;
		$this->close_connection( $request );
	}

	protected function close_connection( Request $request ) {
		unset( $this->requests_started_at[ $request->id ] );
		$socket = $this->connections[ $request->id ]->http_socket;
		if ( $socket && is_resource( $socket ) ) {
			// Close the TCP socket
			if ( $this->connections[ $request->id ]->decoded_response_stream ) {
				$stream = $this->connections[ $request->id ]->decoded_response_stream;
				$stream->close_reading();
				unset( $this->connections[ $request->id ]->decoded_response_stream );
			} else {
				@fclose( $socket );
			}
		}
	}

	public function get_active_requests( $states = null ) {
		$processed_requests = $this->get_requests(
			array(
				Request::STATE_WILL_ENABLE_CRYPTO,
				Request::STATE_WILL_SEND_HEADERS,
				Request::STATE_WILL_SEND_BODY,
				Request::STATE_SENT,
				Request::STATE_RECEIVING_HEADERS,
				Request::STATE_RECEIVING_BODY,
				Request::STATE_RECEIVED,
			)
		);
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

	protected function get_requests( $states ) {
		if ( ! is_array( $states ) ) {
			$states = array( $states );
		}

		return static::filter_requests( $this->requests, $states );
	}

	protected function get_request_by_id( $request_id ) {
		foreach ( $this->requests as $request ) {
			if ( $request->id === $request_id ) {
				return $request;
			}
		}
	}

	/**
	 * Handle transfer encodings.
	 *
	 * @param  Request  $request
	 *
	 * @return false|resource
	 */
	protected function decode_and_monitor_response_body_stream( Request $request ) {
		$transfer_encodings = array();

		$transfer_encoding = $request->response->get_header( 'transfer-encoding' );
		if ( $transfer_encoding ) {
			$transfer_encodings = array_map( 'trim', explode( ',', $transfer_encoding ) );
		}

		$content_encoding = $request->response->get_header( 'content-encoding' );
		if ( $content_encoding && ! in_array( $content_encoding, $transfer_encodings ) ) {
			$transfer_encodings[] = $content_encoding;
		}

		$body_stream = FileReadStream::from_resource(
			$this->connections[ $request->id ]->http_socket
		);

		$transformers = array();
		foreach ( $transfer_encodings as $transfer_encoding ) {
			switch ( $transfer_encoding ) {
				case 'chunked':
					$body_stream = new ChunkedDecoderReadStream( $body_stream );
					break;
				case 'gzip':
					$transformers[] = new InflateTransformer(
						$transfer_encoding === 'gzip' ? ZLIB_ENCODING_GZIP : ZLIB_ENCODING_RAW
					);
					break;
				case 'identity':
					// No-op
					break;
				default:
					$this->set_error(
						$request,
						new HttpError( 'Unsupported transfer encoding received from the server: ' . $transfer_encoding )
					);
					break;
			}
		}

		return new TransformedReadStream(
			$body_stream,
			$transformers
		);
	}

	/**
	 * Sends HTTP requests using streams.
	 *
	 * Enables crypto on the $requests HTTP socksts and sends the request body asynchronously.
	 *
	 * @param  Request[]  $requests  An array of HTTP requests.
	 */
	protected function enable_crypto( array $requests ) {
		foreach ( $this->stream_select( $requests, static::STREAM_SELECT_WRITE ) as $request ) {
			stream_set_timeout( $this->connections[ $request->id ]->http_socket, 1 );
			$enabled_crypto = stream_socket_enable_crypto(
				$this->connections[ $request->id ]->http_socket,
				true,
				STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
			);
			if ( false === $enabled_crypto ) {
				$last_error = error_get_last();
				$this->set_error( $request,
					new HttpError( 'Failed to enable crypto: ' . ( is_array( $last_error ) ? $last_error['message'] : 'unknown' ) ) );
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
	protected function send_request_headers( array $requests ) {
		foreach ( $this->stream_select( $requests, static::STREAM_SELECT_WRITE ) as $request ) {
			$header_bytes = static::prepare_request_headers( $request );

			if ( false === @fwrite( $this->connections[ $request->id ]->http_socket, $header_bytes ) ) {
				$last_error = error_get_last();
				$this->set_error( $request,
					new HttpError( 'Failed to write request bytes - ' . ( is_array( $last_error ) ? $last_error['message'] : 'unknown' ) ) );
				continue;
			}

			if ( $request->upload_body_stream ) {
				$request->state = Request::STATE_WILL_SEND_BODY;

				if ( $request->get_header( 'transfer-encoding' ) === 'chunked' ) {
					$request->upload_body_stream = new TransformedReadStream(
						$request->upload_body_stream,
						array( new ChunkedEncoderByteTransformer() )
					);
				}
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
	protected function send_request_body( array $requests ) {
		foreach ( $this->stream_select( $requests, self::STREAM_SELECT_WRITE ) as $request ) {
			if ( $request->upload_body_stream->reached_end_of_data() ) {
				$request->upload_body_stream->close_reading();
				$request->upload_body_stream = null;
				$request->state              = Request::STATE_RECEIVING_HEADERS;
				continue;
			}

			$available_bytes = $request->upload_body_stream->pull( 65536 );
			if ( $available_bytes === 0 ) {
				// Not all pull() calls must yield bytes, maybe we just need to wait for the next chunk.
				// Let's continue and keep trying.
				// @TODO: Implement a generic timeout mechanism for pull() calls.
				continue;
			}

			$chunk = $request->upload_body_stream->consume( $available_bytes );
			if ( ! fwrite( $this->connections[ $request->id ]->http_socket, $chunk ) ) {
				$this->set_error( $request, new HttpError( 'Failed to write request bytes.' ) );
				continue;
			}
		}
	}

	/**
	 * Reads the next received portion of HTTP response headers for multiple requests.
	 *
	 * @param  array  $requests  An array of requests.
	 */
	protected function receive_response_headers( $requests ) {
		$nb_headers_received = 0;

		foreach ( $this->stream_select( $requests, static::STREAM_SELECT_READ ) as $request ) {
			if ( ! $request->response ) {
				$request->response = new Response( $request );
			}
			$connection = $this->connections[ $request->id ];
			$response   = $request->response;

			while ( true ) {
				// @TODO: Use a larger chunk size here and then scan for \r\n\r\n.
				// 1 seems slow and overly conservative.
				$header_byte = fread( $this->connections[ $request->id ]->http_socket, 1 );
				if ( false === $header_byte || '' === $header_byte ) {
					if (
						!is_resource($this->connections[ $request->id ]->http_socket) || 
						feof($this->connections[ $request->id ]->http_socket)
					) {
						$this->set_error($request, new HttpError('Connection closed while reading response headers.'));
						break;
					}
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

				$parsed = static::parse_http_headers( $connection->response_buffer );
				if ( false === $parsed ) {
					$this->set_error( $request, new HttpError( 'Malformed HTTP headers received from the server.' ) );
					break;
				}
				$connection->response_buffer = '';

				$response->headers        = $parsed['headers'];
				$response->status_code    = $parsed['status']['code'];
				$response->status_message = $parsed['status']['message'];
				$response->protocol       = $parsed['status']['protocol'];

				$total = $request->response->get_header( 'content-length' );
				if ( null !== $total ) {
					$response->total_bytes = (int) $total;
				}

				$this->events[ $request->id ][ self::EVENT_GOT_HEADERS ] = true;
				$nb_headers_received ++;

				if ( $response->total_bytes === 0 ) {
					$request->state = Request::STATE_RECEIVED;
					break;
				}

				// If we're being redirected, we don't need to wait for the body.
				if ( $response->status_code >= 300 && $response->status_code < 400 ) {
					$request->state = Request::STATE_RECEIVED;
					break;
				}

				$request->state = Request::STATE_RECEIVING_BODY;
				break;
			}
		}

		return $nb_headers_received;
	}

	/**
	 * Reads the next received portion of HTTP response headers for multiple requests.
	 *
	 * @param  array  $requests  An array of requests.
	 */
	protected function receive_response_body( $requests ) {
		// @TODO: Assume body is fully received when either
		// * Content-Length is reached
		// * The last chunk in Transfer-Encoding: chunked is received
		// * The connection is closed
		foreach ( $this->stream_select( $requests, static::STREAM_SELECT_READ ) as $request ) {
			if ( ! $this->connections[ $request->id ]->decoded_response_stream ) {
				$this->connections[ $request->id ]->decoded_response_stream = $this->decode_and_monitor_response_body_stream( $request );
			}

			$stream = $this->connections[ $request->id ]->decoded_response_stream;

			while ( true ) {
				$available_bytes = $stream->pull( 65536 );
				if ( $available_bytes > 0 ) {
					$body_chunk                                         = $stream->consume( $available_bytes );
					$request->response->received_bytes                  += $available_bytes;
					$this->connections[ $request->id ]->response_buffer .= $body_chunk;
					$this->events[ $request->id ][ self::EVENT_BODY_CHUNK_AVAILABLE ] = true;
					break; // Process one chunk per loop iteration
				} elseif ( $stream->reached_end_of_data() ) {
					$request->state = Request::STATE_RECEIVED;
					break;
				}
			}
		}
	}

	/**
	 * @param  array  $requests  An array of requests.
	 */
	protected function handle_redirects( $requests ) {
		foreach ( $requests as $request ) {
			$response = $request->response;
			if ( ! $response ) {
				continue;
			}
			$code = $response->status_code;
			$this->mark_finished( $request );
			if ( ! ( $code >= 300 && $code < 400 ) ) {
				continue;
			}

			$location = $response->get_header( 'location' );
			if ( null === $location ) {
				continue;
			}

			$redirects_so_far = 0;
			$cause            = $request;
			while ( $cause->redirected_from ) {
				++ $redirects_so_far;
				$cause = $cause->redirected_from;
			}

			if ( $redirects_so_far >= $this->max_redirects ) {
				$this->set_error( $request, new HttpError( 'Too many redirects' ) );
				continue;
			}

			$redirect_url = $location;
			if ( strpos( $redirect_url, 'http://' ) !== 0 && strpos( $redirect_url, 'https://' ) !== 0 ) {
				$current_url_parts = parse_url( $request->url );
				$redirect_url      = $current_url_parts['scheme'] . '://' . $current_url_parts['host'];
				if ( $current_url_parts['port'] ) {
					$redirect_url .= ':' . $current_url_parts['port'];
				}
				if ( strlen( $location ) === 0 || $location[0] !== '/' ) {
					$redirect_url .= '/';
				}
				$redirect_url .= $location;
			}

			// @TODO: Use a WHATWG-compliant URL parser
			if ( ! filter_var( $redirect_url, FILTER_VALIDATE_URL ) ) {
				$this->set_error( $request, new HttpError( 'Invalid redirect URL' ) );
				continue;
			}

			$this->events[ $request->id ][ self::EVENT_REDIRECT ] = true;
			$this->enqueue(
				new Request(
					$redirect_url,
					array(
						'method'          => $request->method,
						'redirected_from' => $request,
					)
				)
			);
		}
	}

	/**
	 * Parses an HTTP headers string into an array containing the status and headers.
	 *
	 * @param  string  $headers  The HTTP headers to parse.
	 *
	 * @return array An array containing the parsed status and headers.
	 */
	protected function parse_http_headers( string $headers ) {
		$lines  = explode( "\r\n", $headers );
		$status = array_shift( $lines );
		$status = explode( ' ', $status );
		if ( count( $status ) < 3 ) {
			return false;
		}
		$status  = array(
			'protocol' => $status[0],
			'code'     => (int) $status[1],
			'message'  => $status[2],
		);
		$headers = array();
		foreach ( $lines as $line ) {
			if ( strpos( $line, ': ' ) === false ) {
				// @TODO: Error, not a valid response
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
	protected function open_nonblocking_http_sockets( $requests ) {
		foreach ( $requests as $request ) {
			$url    = $request->url;
			$parts  = parse_url( $url );
			$scheme = $parts['scheme'];
			if ( ! in_array( $scheme, array( 'http', 'https' ) ) ) {
				$this->set_error(
					$request,
					new HttpError( 'stream_http_open_nonblocking: Invalid scheme in URL ' . $url . ' – only http:// and https:// URLs are supported' )
				);
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
				$this->timeout,
				STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
				$context
			);

			if ( $stream === false ) {
				$this->set_error(
					$request,
					new HttpError( "stream_http_open_nonblocking: stream_socket_client() was unable to open a stream to $url. $errno: $errstr" )
				);
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
	protected static function prepare_request_headers( Request $request ) {
		$url   = $request->url;
		$parts = parse_url( $url );
		$path  = ( isset( $parts['path'] ) ? $parts['path'] : '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );

		$headers = $request->headers;

		/**
		 * Disable the gzip transfer compression when requesting a byte range.
		 *
		 * When we're requesting a byte range AND gzipped transfer encoding,
		 * our intention is to get compressed bytes 0-X of the original file.
		 *
		 * However, some servers will compress the file first, and then return
		 * the compressed bytes 0-X. The result is both unpredictable and impossible
		 * to decompress.
		 */
		if ( ! array_key_exists( 'range', $headers ) && ! array_key_exists( 'accept-encoding', $headers ) ) {
			$headers['accept-encoding'] = 'gzip';
		}

		$request_parts = array(
			"$request->method $path HTTP/$request->http_version",
		);

		foreach ( $headers as $name => $value ) {
			$request_parts[] = "$name: $value";
		}

		return implode( "\r\n", $request_parts ) . "\r\n\r\n";
	}

	protected function filter_requests( array $requests, $states ) {
		if ( ! is_array( $states ) ) {
			$states = array( $states );
		}
		$results = array();
		foreach ( $requests as $request ) {
			if ( in_array( $request->state, $states ) ) {
				$results[] = $request;
			}
		}

		return $results;
	}


	protected function stream_select( $requests, $mode ) {
		if ( empty( $requests ) ) {
			return array();
		}

		$read  = array();
		$write = array();
		foreach ( $requests as $k => $request ) {
			if ( $mode & static::STREAM_SELECT_READ ) {
				$read[ $k ] = $this->connections[ $request->id ]->http_socket;
			}
			if ( $mode & static::STREAM_SELECT_WRITE ) {
				$write[ $k ] = $this->connections[ $request->id ]->http_socket;
			}
		}
		$except = null;
		if ( count( $read ) === 0 && count( $write ) === 0 ) {
			return array();
		}

		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
		$ready = @stream_select( $read, $write, $except, 0, static::NONBLOCKING_TIMEOUT_MICROSECONDS );
		if ( $ready === false ) {
			foreach ( $requests as $request ) {
				$this->set_error( $request, new HttpError( 'Error: ' . error_get_last()['message'] ) );
			}

			return array();
		} elseif ( $ready <= 0 ) {
			// @TODO allow at most X stream_select attempts per request
			// foreach ( $unprocessed_requests as $request ) {
			// $this->>set_error($request, new HttpError( 'stream_select timed out' ));
			// }
			return array();
		}

		$selected_requests = array();
		foreach ( array_keys( $read ) as $k ) {
			$selected_requests[ $k ] = $requests[ $k ];
		}
		foreach ( array_keys( $write ) as $k ) {
			$selected_requests[ $k ] = $requests[ $k ];
		}

		return $selected_requests;
	}

	protected const STREAM_SELECT_READ = 1;
	protected const STREAM_SELECT_WRITE = 2;

	const EVENT_GOT_HEADERS = 'EVENT_GOT_HEADERS';
	const EVENT_BODY_CHUNK_AVAILABLE = 'EVENT_BODY_CHUNK_AVAILABLE';
	const EVENT_REDIRECT = 'EVENT_REDIRECT';
	const EVENT_FAILED = 'EVENT_FAILED';
	const EVENT_FINISHED = 'EVENT_FINISHED';

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
}
