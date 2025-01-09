<?php

namespace WordPress\ByteReader;

use WordPress\Error\WordPressException;

/**
 * Streams bytes from a remote file.
 */
class WP_Remote_File_Reader extends WP_Byte_Reader {

	/**
	 * @var WordPress\AsyncHttp\Client
	 */
	private $client;
	private $url;
	private $headers;
	private $request;
	private $current_chunk;
	private $last_error;
	private $is_finished = false;
	private $bytes_already_read;
	private $remote_file_length;
	private $skip_bytes = 0;

	public function __construct( $url, $headers = array() ) {
		$this->client = new \WordPress\AsyncHttp\Client();
		$this->url    = $url;
		$this->headers = $headers;
	}

	public function tell(): int {
		return $this->bytes_already_read + $this->skip_bytes;
	}

	public function seek( $offset_in_file ): bool {
		if ( $this->request ) {
			throw new WordPressException(
				'Cannot seek() a WP_Remote_File_Reader instance once the request was initialized. ' .
				'Use WP_Remote_File_Ranged_Reader to seek() using range requests instead.'
			);
		}
		$this->skip_bytes = $offset_in_file;
		return true;
	}

	public function next_bytes(): bool {
		if ( null === $this->request ) {
			$this->request = new \WordPress\AsyncHttp\Request(
				$this->url,
				array( 'headers' => $this->headers )
			);
			if ( false === $this->client->enqueue( $this->request ) ) {
				throw new WordPressException(sprintf('Failed to enqueue the request to %s', $this->url));
			}
		}

		$this->after_chunk();

		while ( $this->client->await_next_event() ) {
			$request = $this->client->get_request();
			if ( ! $request ) {
				continue;
			}
			$response = $request->response;
			if ( false === $response ) {
				continue;
			}
			if ( $request->redirected_to ) {
				continue;
			}

			switch ( $this->client->get_event() ) {
				case \WordPress\AsyncHttp\Client::EVENT_GOT_HEADERS:
					if(null !== $this->remote_file_length) {
						continue 2;
					}
					$content_length = $response->get_header( 'Content-Length' );
					if ( false !== $content_length ) {
						$this->remote_file_length = (int) $content_length;
					}
					break;
				case \WordPress\AsyncHttp\Client::EVENT_BODY_CHUNK_AVAILABLE:
					$chunk = $this->client->get_response_body_chunk();
					if ( ! is_string( $chunk ) ) {
						throw new WordPressException(sprintf('Failed to get the response body chunk from %s', $this->url));
					}
					$this->current_chunk = $chunk;

					/**
					 * Naive seek() implementation – redownload the file from the start
					 * and ignore bytes until we reach the desired offset.
					 *
					 * @TODO: Use the range requests instead when the server supports them.
					 */
					if ( $this->skip_bytes > 0 ) {
						if ( $this->skip_bytes < strlen( $chunk ) ) {
							$this->current_chunk       = substr( $chunk, $this->skip_bytes );
							$this->bytes_already_read += $this->skip_bytes;
							$this->skip_bytes          = 0;
						} else {
							$this->skip_bytes -= strlen( $chunk );
							continue 2;
						}
					}
					return true;
				case \WordPress\AsyncHttp\Client::EVENT_FAILED:
					throw new WordPressException(sprintf('Failed to fetch data from %s', $this->url));
				case \WordPress\AsyncHttp\Client::EVENT_FINISHED:
					$this->is_finished = true;
					return false;
			}
		}
	}

	public function length(): ?int {
		if ( null !== $this->remote_file_length ) {
			return $this->remote_file_length;
		}

		$request = new \WordPress\AsyncHttp\Request(
			$this->url,
			array( 'method' => 'HEAD' )
		);
		if ( false === $this->client->enqueue( $request ) ) {
			throw new WordPressException(sprintf('Failed to enqueue the request to %s', $this->url));
		}
		while ( $this->client->await_next_event() ) {
			switch ( $this->client->get_event() ) {
				case \WordPress\AsyncHttp\Client::EVENT_GOT_HEADERS:
					$request = $this->client->get_request();
					if($request->redirected_to) {
						continue 2;
					}
					$response = $request->response;
					$content_length = $response->get_header( 'Content-Length' );
					if ( false === $content_length ) {
						return false;
					}
					$this->remote_file_length = (int) $content_length;
					break;
			}
		}
		if(null === $this->remote_file_length) {
			return false;
		}
		return $this->remote_file_length;
	}

	private function after_chunk() {
		if ( $this->current_chunk ) {
			$this->bytes_already_read += strlen( $this->current_chunk );
		}
		$this->current_chunk = null;
	}

	public function get_last_error(): ?string {
		return $this->last_error;
	}

	public function get_bytes(): ?string {
		return $this->current_chunk;
	}

	public function is_finished(): bool {
		return $this->is_finished;
	}

	public function close(): bool {
		throw new WordPressException('Not implemented yet');
	}
}
