<?php

namespace WordPress\DataLiberation\DataFormatConsumer;

/**
 * Represents a {Data Format} -> Block Markup + Metadata consumer.
 *
 * Used by the Data Liberation importers to accept data formatted as HTML, Markdown, etc.
 * and convert them to WordPress posts.
 */
interface DataFormatConsumer {
	/**
	 * Converts the input document specified in the constructor to block markup.
	 *
	 * @return BlocksWithMetadata The consumed block markup and metadata.
	 */
	public function consume();
}
