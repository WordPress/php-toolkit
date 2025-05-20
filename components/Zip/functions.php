<?php

namespace WordPress\Zip;

use WordPress\ByteStream\NotEnoughDataException;
use WordPress\ByteStream\ReadStream\ByteReadStream;

function is_zip_file_stream( ByteReadStream $stream ) {
	if ( $stream->length() < 4 ) {
		return false;
	}

	try {
		$stream->pull( 4, ByteReadStream::PULL_EXACTLY );
	} catch ( NotEnoughDataException $e ) {
		return false;
	}

	return $stream->peek( 4 ) === "PK\x03\x04";
}
