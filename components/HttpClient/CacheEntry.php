<?php

namespace WordPress\HttpClient;

final class CacheEntry {
	/**
	 * @var string
	 */
	public $url;
	/**
	 * @var int
	 */
	public $status;
	/**
	 * @var mixed[]
	 */
	public $headers;
	/**
	 * @var int
	 */
	public $stored_at;
	/**
	 * @var int|null
	 */
	public $max_age;
	/**
	 * @var string|null
	 */
	public $etag;
	/**
	 * @var string|null
	 */
	public $last_modified;
}
