<?php

namespace WordPress\HttpClient;

use WordPress\ByteStream\WriteStream\ByteWriteStream;

interface CacheStorage {
	public function lookup( string $url ): ?CacheEntry;

	/**
	 * Opens a write stream for the response body of a given URL.
	 *
	 * The implementation should handle temporary storage and associate it with the URL.
	 *
	 * @param  string  $url  The URL for which to store the body.
	 *
	 * @return ByteWriteStream A stream to write the body content to.
	 */
	public function open_body_write_stream( string $url ): ByteWriteStream;

	/**
	 * Stores the metadata for a cached entry.
	 *
	 * Assumes the body content has already been written via the stream
	 * obtained from open_body_write_stream().
	 *
	 * @param  CacheEntry  $entry  The cache entry metadata.
	 */
	public function store( CacheEntry $entry ): void;

	public function invalidate( string $url ): void;

	public function get_body( CacheEntry $entry ): string;
}
