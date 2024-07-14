<?php

namespace WordPress\AsyncHttp;

use WordPress\Streams\StreamPeekerWrapper;
use WordPress\Streams\StreamPeekerData;

class CountReadBytesStreamWrapper extends StreamPeekerWrapper {

	const SCHEME = 'count-read-bytes';

	/**
	 * Monitors the progress of a stream while reading its content.
	 *
	 * @param  resource  $stream  The stream to monitor.
	 * @param  callable  $onProgress  The callback function to be called on each progress update.
	 *                             It should accept a single parameters: the number of bytes streamed so far.
	 *
	 * @return resource The wrapped stream resource.
	 */
	static public function wrap($stream, $onProgress)
	{
		return static::create_resource(
			new StreamPeekerData(
				$stream,
				function ($data) use ($onProgress) {
					static $streamedBytes = 0;
					$streamedBytes += strlen($data);
					$onProgress($streamedBytes);
				}
			)
		);
	}
	
}
