<?php

namespace WordPress\AsyncHttp\StreamWrapper;

use WordPress\Streams\StreamPeekerData;
use WordPress\Streams\StreamPeekerWrapper;

class CountReadBytesWrapper extends StreamPeekerWrapper {

	const SCHEME = 'count-read-bytes';

	/**
	 * Monitors the progress of a stream while reading its content.
	 * @return resource The wrapped stream resource.
	 */
	static public function wrap( $stream, $on_data, $on_close=null ) {
		return parent::wrap(
			$stream,
			function ($data) use ($on_data, $on_close) {
				static $streamedBytes = 0;
				$streamedBytes += strlen($data);
				$on_data($streamedBytes);
			},
			$on_close
		);
	}

}
