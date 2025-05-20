<?php

namespace WordPress\Blueprints\DataReference;

use WordPress\ByteStream\ReadStream\ByteReadStream;

/**
 * Represents a file-like object, similar to JavaScript's File.
 * It encapsulates a byte stream and a filename.
 */
class File extends DataReference {
	/**
	 * @var ByteReadStream The stream representing the file content.
	 */
	protected $stream;

	/**
	 * @var string The name of the file.
	 */
	public $filename;

	/**
	 * Constructor.
	 *
	 * @param  ByteReadStream  $stream  The stream representing the file content.
	 * @param  string  $filename  The name of the file.
	 */
	public function __construct( ByteReadStream $stream, string $filename ) {
		$this->stream   = $stream;
		$this->filename = $filename;
		parent::__construct();
	}

	/**
	 * Get a human-readable name for this reference.
	 * Used in the progress tracker.
	 *
	 * @return string The human-readable name.
	 */
	public function get_human_readable_name(): string {
		return "File: " . $this->filename;
	}

	public function getStream(): ByteReadStream {
		return $this->stream;
	}
}
