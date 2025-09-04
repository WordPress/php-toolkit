<?php

namespace WordPress\DataLiberation\DataFormatProducer;

/**
 * Represents a Block Markup + Metadata -> {Data Format} producer.
 *
 * Used by the Data Liberation exporters for exporting posts to HTML, Markdown,
 * and other static formats etc.
 */
interface DataFormatProducer {
	/**
	 * Converts the input document specified in the constructor to block markup.
	 *
	 * @return string The data format representing the block markup and metadata
	 *                passed to the constructor.
	 */
	public function produce();
}
