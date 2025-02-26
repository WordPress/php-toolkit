<?php

namespace WordPress\HttpClient\ByteStream;

use WordPress\ByteStream\ByteStreamException;
use WordPress\ByteStream\ReadStream\BaseByteReadStream;
use WordPress\HttpClient\Client;
use WordPress\HttpClient\Request;
use WordPress\HttpClient\Response;

/**
 * Streams bytes from a remote file.
 */
class RequestReadStream extends BaseByteReadStream {

	/**
	 * @var Client
	 */
	private $client;
	/**
	 * @var Request
	 */
	private $request;
	/**
	 * @var Response
	 */
	private $response;
	/**
	 * @var bool
	 */
	private $is_enqueued = false;
	/**
	 * @var int
	 */
	private $remote_file_length;

	public function __construct( $request, $options = array() ) {
		if ( is_string( $request ) ) {
			$request = new Request( $request );
		}
		$this->client  = $options['client'] ?? new Client();
		$this->request = $request;
	}

	protected function seek_outside_of_buffer( int $target_offset ): void {
		throw new ByteStreamException(
			'Cannot seek() a RemoteFileReader instance once the request was initialized. ' .
			'Use RemoteFileRangedReader to seek() using range requests instead.'
		);
	}

	protected function internal_pull( $max_bytes = 8096 ): string {
		return $this->pull_until_event(
			array(
				'max_bytes' => $max_bytes,
				'event' => Client::EVENT_BODY_CHUNK_AVAILABLE,
			)
		);
	}

	private function pull_until_event( $options = array() ) {
		$stop_at_event = $options['event'] ?? Client::EVENT_BODY_CHUNK_AVAILABLE;

		if ( ! $this->is_enqueued ) {
			$this->client->enqueue( $this->request );
			$this->is_enqueued = true;
		}
		while ( $this->client->await_next_event(
			array(
				'requests' => array( $this->request ),
			)
		) ) {
			$request = $this->client->get_request();
			if ( $request->error ) {
				throw new ByteStreamException( 'HTTP request failed: ' . $request->error->message );
			}
			$response = $request->response;
			if ( ! $response ) {
				continue;
			}
			if ( $request->redirected_to ) {
				continue;
			}
			switch ( $this->client->get_event() ) {
				case Client::EVENT_GOT_HEADERS:
					$this->response = $response;
					if ( $stop_at_event === Client::EVENT_GOT_HEADERS ) {
						return true;
					}
					break;
				case Client::EVENT_BODY_CHUNK_AVAILABLE:
					if ( $stop_at_event === Client::EVENT_BODY_CHUNK_AVAILABLE ) {
						return $this->client->get_response_body_chunk();
					}
					break;
				case Client::EVENT_FINISHED:
					return '';
				case Client::EVENT_FAILED:
					// TODO: Think through error handling. Errors are expected when working with
					// the network. Should we auto retry? Make it easy for the caller to retry?
					// Something else?
					throw new ByteStreamException( 'HTTP request failed: ' . $this->client->get_request()->error );
			}
		}

		return '';
	}

	public function length(): ?int {
		if ( null !== $this->remote_file_length ) {
			return $this->remote_file_length;
		}

		if ( ! $this->response ) {
			$this->pull_until_event(
				array(
					'event' => Client::EVENT_GOT_HEADERS,
				)
			);
		}
		$content_length = $this->response->get_header( 'Content-Length' );
		if ( null === $content_length ) {
			return null;
		}
		$this->remote_file_length = (int) $content_length;
		return $this->remote_file_length;
	}

	public function await_response() {
		if ( ! $this->response ) {
			$this->pull_until_event(
				array(
					'event' => Client::EVENT_GOT_HEADERS,
				)
			);
		}
		if ( ! $this->response ) {
			throw new ByteStreamException( 'HTTP request failed – never received a response' );
		}
		return $this->response;
	}

	protected function internal_reached_end_of_data(): bool {
		return (
			Request::STATE_FINISHED === $this->request->latest_redirect()->state &&
			! $this->client->has_pending_event( $this->request, Client::EVENT_BODY_CHUNK_AVAILABLE ) &&
			strlen( $this->buffer ) === $this->offset_in_current_buffer
		);
	}

	protected function internal_close_reading(): void {
		$latest_redirect = $this->request->latest_redirect();
		if (
			$latest_redirect &&
			$latest_redirect->state !== Request::STATE_FINISHED &&
			$latest_redirect->state !== Request::STATE_FAILED
		) {
			throw new ByteStreamException( 'Cancelling the request is not implemented yet' );
		}
	}
}
