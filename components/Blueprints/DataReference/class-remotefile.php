<?php

namespace WordPress\Blueprints\DataReference;

use WordPress\Blueprints\Exception\DataResolutionException;
use WordPress\ByteStream\ReadStream\ByteReadStream;

/**
 * A file that is backed by a RequestReadStream.
 */
class RemoteFile extends File {

	public function getStream(): ByteReadStream {
		// @TODO: Only accept streams with await_response() and get_url() methods.
		$response = $this->stream->await_response();
		if ( ! $response->ok() ) {
			throw new DataResolutionException(
				sprintf(
					'Failed to load the URL from %s. Server responded with %d %s.',
					$response->request->url,
					$response->status_code,
					$response->get_reason_phrase()
				)
			);
		}

		return $this->stream;
	}
}
