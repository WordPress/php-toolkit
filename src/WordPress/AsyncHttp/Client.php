<?php

namespace WordPress\AsyncHttp;

use WordPress\AsyncHttp\StreamWrapper\ChunkedEncodingWrapper;
use WordPress\AsyncHttp\StreamWrapper\EventLoopWrapper;
use WordPress\AsyncHttp\StreamWrapper\InflateStreamWrapper;


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
	private $concurrency;
	
	/**
	* The maximum number of redirects to follow for a single request.
	* 
	* This prevents infinite redirect loops and provides a degree of control over the client's behavior. 
	* Setting it too high might lead to unexpected navigation paths.
	*
	* @var int
	*/
	private $max_redirects = 3;

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
	private $requests;

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
	private $connections = array();
	private $events = array();
	private $event;
	private $request;
	private $response_body_chunk;

	public function __construct( $options = [] ) {
		$this->concurrency   = $options['concurrency'] ?? 10;
		$this->max_redirects = $options['max_redirects'] ?? 3;
		$this->requests      = [];
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
			$requests = [ $requests ];
		}

		foreach ( $requests as $request ) {
			$this->requests[]                  = $request;
			$this->events[ $request->id ]      = [];
			$this->connections[ $request->id ] = new Connection( $request );
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
	 * $client = new AsyncHttpClient();
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
	public function await_next_event( $query = [] ) {
		$ordered_events            = [
			Client::EVENT_GOT_HEADERS,
			Client::EVENT_BODY_CHUNK_AVAILABLE,
			Client::EVENT_REDIRECT,
			Client::EVENT_FAILED,
			Client::EVENT_FINISHED,
		];
		$this->event               = null;
		$this->request             = null;
		$this->response_body_chunk = null;
		do {
			if ( empty( $query['requests'] ) ) {
				$events = array_keys( $this->events );
			} else {
				$events = [];
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
					if ( $this->event === Client::EVENT_BODY_CHUNK_AVAILABLE ) {
						$this->response_body_chunk = $this->next_response_body_bytes();
					}

					return true;
				}
			}
		} while ( $this->event_loop_tick() );

		return false;
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
	 * @return Request
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
	private function event_loop_tick() {
		if ( count( $this->get_active_requests() ) === 0 ) {
			return false;
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

		$this->receive_response_headers(
			$this->get_active_requests( Request::STATE_RECEIVING_HEADERS )
		);

		$this->receive_response_body(
			$this->get_active_requests( Request::STATE_RECEIVING_BODY )
		);

		$this->handle_redirects(
			$this->get_active_requests( Request::STATE_RECEIVED )
		);

		return true;
	}

	/**
	 * Consumes $length bytes received in response to a given request.
	 *
	 * @return string
	 */
	private function next_response_body_bytes() {
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
				feof( $this->connections[ $request->id ]->decoded_response_stream )
			);
		if ( $end_of_data ) {
			return false;
		}

		return '';
	}

	private function mark_finished( Request $request ) {
		$request->state                                         = Request::STATE_FINISHED;
		$this->events[ $request->id ][ Client::EVENT_FINISHED ] = true;

		$this->close_connection( $request );
	}

	private function set_error( Request $request, $error ) {
		$request->error                                       = $error;
		$request->state                                       = Request::STATE_FAILED;
		$this->events[ $request->id ][ Client::EVENT_FAILED ] = true;

		$this->close_connection( $request );
	}

	private function close_connection( Request $request ) {
		$socket = $this->connections[ $request->id ]->http_socket;
		if ( $socket && is_resource( $socket ) ) {
			// Close the TCP socket
			@fclose( $socket );

			// No need to close all the dependent stream wrappers – they are
			// invalidated when the root resource is closed.
		}
	}

	private function get_active_requests( $states = null ) {
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

	private function get_request_by_id( $request_id ) {
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
	private function decode_and_monitor_response_body_stream( Request $request ) {
		$wrapped_stream = $this->connections[ $request->id ]->http_socket;

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

				$total = $request->response->get_header( 'content-length' ) ?: null;
				if ( $total !== null ) {
					$response->total_bytes = (int) $total;
				}

				// If we're being redirected, we don't need to wait for the body.
				if ( $response->status_code >= 300 && $response->status_code < 400 ) {
					$request->state = Request::STATE_RECEIVED;
					break;
				}

				$request->state                                            = Request::STATE_RECEIVING_BODY;
				$this->events[ $request->id ][ Client::EVENT_GOT_HEADERS ] = true;
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
			if ( ! $this->connections[ $request->id ]->decoded_response_stream ) {
				$this->connections[ $request->id ]->decoded_response_stream = $this->decode_and_monitor_response_body_stream( $request );
			}

			while ( true ) {
				if ( feof( $this->connections[ $request->id ]->decoded_response_stream ) ) {
					$request->state = Request::STATE_RECEIVED;
					break;
				}

				$body_bytes = fread( $this->connections[ $request->id ]->decoded_response_stream, 8096 );
				if ( false === $body_bytes || '' === $body_bytes ) {
					break;
				}

				$request->response->received_bytes                  += strlen( $body_bytes );
				$this->connections[ $request->id ]->response_buffer .= $body_bytes;

				$this->events[ $request->id ][ Client::EVENT_BODY_CHUNK_AVAILABLE ] = true;
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

			$this->events[ $request->id ][ Client::EVENT_REDIRECT ] = true;
			$this->enqueue( new Request( $redirect_url, [ 'redirected_from' => $request ] ) );
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

	private const STREAM_SELECT_READ = 1;
	private const STREAM_SELECT_WRITE = 2;

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

