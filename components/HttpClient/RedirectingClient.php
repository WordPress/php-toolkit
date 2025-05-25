<?php

namespace WordPress\HttpClient;

use WordPress\DataLiberation\URL\WPURL;

/**
 * An HTTP client that supports redirects by wrapping a base client.
 *
 * This class decorates the base Client class to add redirect functionality.
 * It follows HTTP redirects automatically up to a configurable maximum number.
 *
 * @since Next Release
 * @package WordPress
 * @subpackage Async_HTTP
 */
class RedirectingClient implements ClientInterface {

	/**
	 * The underlying HTTP client that handles the actual requests.
	 *
	 * @var ClientInterface
	 */
	protected $client;

	/**
	 * The maximum number of redirects to follow for a single request.
	 *
	 * This prevents infinite redirect loops and provides a degree of control over the client's behavior.
	 * Setting it too high might lead to unexpected navigation paths.
	 *
	 * @var int
	 */
	protected $max_redirects;

	/**
	 * Constructor.
	 *
	 * @param ClientInterface|null $client      The underlying client to use. If null, creates a new Client.
	 * @param array               $options     Configuration options.
	 */
	public function __construct( ?ClientInterface $client = null, array $options = [] ) {
		$this->max_redirects = $options['max_redirects'] ?? 3;
		
		// Remove max_redirects from options passed to underlying client to avoid conflicts
		$client_options = $options;
		unset( $client_options['max_redirects'] );
		
		$this->client = $client ?? new Client( $client_options );
	}

	/**
	 * Returns a RemoteFileReader that streams the response body of the
	 * given request.
	 *
	 * @param  Request  $request  The request to stream.
	 * @param  array    $options  Options for the request.
	 *
	 * @return RequestReadStream
	 */
	public function fetch( Request $request, array $options = [] ): \WordPress\HttpClient\ByteStream\RequestReadStream {
		return $this->client->fetch( $request, $options );
	}

	/**
	 * Returns an array of RemoteFileReader instances that stream the response bodies
	 * of the given requests.
	 *
	 * @param  Request[]  $requests  The requests to stream.
	 * @param  array      $options   Options for the requests.
	 *
	 * @return RequestReadStream[]
	 */
	public function fetch_many( array $requests, array $options = [] ): array {
		return $this->client->fetch_many( $requests, $options );
	}

	/**
	 * Enqueues one or multiple HTTP requests for asynchronous processing.
	 *
	 * @param  Request[]  $requests  The HTTP request(s) to enqueue.
	 */
	public function enqueue( array $requests ): void {
		$this->client->enqueue( $requests );
	}

	/**
	 * Returns the next event related to any of the HTTP requests enqueued in this client.
	 *
	 * This method handles redirect events automatically and creates new requests for redirects.
	 *
	 * @param array $query Query parameters for filtering events.
	 *
	 * @return bool
	 */
	public function await_next_event( array $query = [] ): bool {
		// Add support for following redirected requests in the query
		if ( !empty( $query['requests'] ) ) {
			$all_requests = [];
			foreach ( $query['requests'] as $query_request ) {
				$all_requests[] = $query_request;
				// Follow the redirect chain
				while ( $query_request->redirected_to ) {
					$query_request = $query_request->redirected_to;
					$all_requests[] = $query_request;
				}
			}
			$query['requests'] = $all_requests;
		}

		$has_event = $this->client->await_next_event( $query );
		
		if ( $has_event ) {
			$event = $this->client->get_event();
			$request = $this->client->get_request();

			// Check if this is a redirect response
			if ( $event === Client::EVENT_FINISHED && $this->is_redirect_response( $request ) ) {
				$this->handle_redirect( $request );
				// Return a redirect event instead of finished
				return true;
			}
		}

		return $has_event;
	}

	/**
	 * Check if a request has a pending event.
	 *
	 * @param Request $request    The request to check.
	 * @param string  $event_type The event type to check for.
	 *
	 * @return bool
	 */
	public function has_pending_event( Request $request, string $event_type ): bool {
		return $this->client->has_pending_event( $request, $event_type );
	}

	/**
	 * Returns the next event found by await_next_event().
	 *
	 * @return string|bool The next event, or false if no event is set.
	 */
	public function get_event() {
		$event = $this->client->get_event();
		$request = $this->client->get_request();
		
		// Convert finished redirect responses to redirect events
		if ( $event === Client::EVENT_FINISHED && $this->is_redirect_response( $request ) ) {
			return self::EVENT_REDIRECT;
		}
		
		return $event;
	}

	/**
	 * Returns the request associated with the last event found by await_next_event().
	 *
	 * @return Request|false
	 */
	public function get_request() {
		return $this->client->get_request();
	}

	/**
	 * Returns the response associated with the last request.
	 *
	 * @return Response|false
	 */
	public function get_response() {
		return $this->client->get_response();
	}

	/**
	 * Returns the response body chunk associated with the EVENT_BODY_CHUNK_AVAILABLE event.
	 *
	 * @return string|false
	 */
	public function get_response_body_chunk() {
		return $this->client->get_response_body_chunk();
	}

	/**
	 * Check if a request response is a redirect.
	 *
	 * @param Request $request The request to check.
	 *
	 * @return bool
	 */
	protected function is_redirect_response( Request $request ): bool {
		if ( ! $request->response ) {
			return false;
		}

		$code = $request->response->status_code;
		return $code >= 300 && $code < 400 && $request->response->get_header( 'location' );
	}

	/**
	 * Handle HTTP redirects for requests.
	 *
	 * @param Request $request The request that received a redirect response.
	 */
	protected function handle_redirect( Request $request ): void {
		$response = $request->response;
		$location = $response->get_header( 'location' );
		
		if ( ! $location ) {
			return;
		}

		$redirects_so_far = 0;
		$cause = $request;
		while ( $cause->redirected_from ) {
			++$redirects_so_far;
			$cause = $cause->redirected_from;
		}

		if ( $redirects_so_far >= $this->max_redirects ) {
			$this->set_error( $request, new HttpError( 'Too many redirects' ) );
			return;
		}

		$redirect_url = $location;
		$parsed = WPURL::parse( $redirect_url, $request->url );
		if ( false === $parsed ) {
			$this->set_error( $request, new HttpError( sprintf( 'Invalid redirect URL: %s', $redirect_url ) ) );
			return;
		}
		$redirect_url = $parsed->toString();

		$redirect_request = new Request(
			$redirect_url,
			[
				// Redirects are always GET requests
				'method'          => 'GET',
				'redirected_from' => $request,
			]
		);

		// Set up the redirect relationship
		$request->redirected_to = $redirect_request;

		$this->client->enqueue( [ $redirect_request ] );
	}

	/**
	 * Set an error on a request.
	 *
	 * @param Request   $request The request to set the error on.
	 * @param HttpError $error   The error to set.
	 */
	protected function set_error( Request $request, HttpError $error ): void {
		$request->error = $error;
		$request->state = Request::STATE_FAILED;
	}

	/**
	 * Event constants
	 */
	const EVENT_GOT_HEADERS = Client::EVENT_GOT_HEADERS;
	const EVENT_BODY_CHUNK_AVAILABLE = Client::EVENT_BODY_CHUNK_AVAILABLE;
	const EVENT_REDIRECT = 'EVENT_REDIRECT';
	const EVENT_FAILED = Client::EVENT_FAILED;
	const EVENT_FINISHED = Client::EVENT_FINISHED;
}
