<?php

namespace WordPress\DataLiberation\URL;

use Rowbot\URL\URL;
use WordPress\DataLiberation\BlockMarkup\BlockMarkupUrlProcessor;

require_once __DIR__ . '/class-urlrewritecache.php';

/**
 * Migrate URLs in post content. See WPRewriteUrlsTests for
 * specific examples. TODO: A better description.
 *
 * Example:
 *
 * ```php
 * php > wp_rewrite_urls([
 *   'block_markup' => '<!-- wp:image {"src": "http://legacy-blog.com/image.jpg"} -->',
 *   'url-mapping' => [
 *     'http://legacy-blog.com' => 'https://modern-webstore.org'
 *   ]
 * ])
 * <!-- wp:image {"src":"https:\/\/modern-webstore.org\/image.jpg"} -->
 * ```
 *
 * @TODO Use a proper JSON parser and encoder to:
 * * Support UTF-16 characters
 * * Gracefully handle recoverable encoding issues
 * * Avoid changing the whitespace in the same manner as
 *   we do in WP_HTML_Tag_Processor. e.g. if we start with:
 *
 * ```html
 * <!-- wp:block {"url":"https://w.org"}` -->
 *                     ^ no space here
 * ```
 *
 * then it would be nice to re-encode that block markup also without the space character. This is similar
 * to how the tag processor avoids changing parts of the tag it doesn't need to change.
 */
function wp_rewrite_urls( $options ) {
	static $rewrite_cache = null;

	if ( null === $rewrite_cache ) {
		$rewrite_cache = new URLRewriteCache();
	}

	if ( empty( $options['base_url'] ) ) {
		// Use first from-url as base_url if not specified.
		$from_urls           = array_keys( $options['url-mapping'] );
		$options['base_url'] = $from_urls[0];
	}

	$base_url_object = WPURL::parse( $options['base_url'] );
	if ( false === $base_url_object ) {
		return $options['block_markup'];
	}

	$url_mapping       = array();
	$mapping_key_parts = array( 'base=' . $options['base_url'] );
	foreach ( $options['url-mapping'] as $from_url_string => $to_url_string ) {
		$url_mapping[]       = array(
			'from_url' => WPURL::parse( $from_url_string ),
			'to_url'   => WPURL::parse( $to_url_string ),
		);
		$mapping_key_parts[] = $from_url_string . '=>' . $to_url_string;
	}
	$mapping_cache_key = sha1( implode( "\0", $mapping_key_parts ) );

	$compact_mapping = '';
	foreach ( $options['url-mapping'] as $from_url_string => $to_url_string ) {
		$compact_mapping .= $from_url_string . "\x1f" . $to_url_string . "\x1e";
	}

	$p = new BlockMarkupUrlProcessor( $options['block_markup'], $options['base_url'] );
	while ( $p->next_token() ) {
		if ( $p->rewrite_current_text_node_url_bases( $compact_mapping ) ) {
			continue;
		}

		while ( $p->next_url_in_current_token() ) {
			$token_type = $p->get_token_type();
			$raw_url    = $p->get_raw_url();
			$cache_key  = $mapping_cache_key . "\0" . $token_type . "\0" . $raw_url;

			$cached = $rewrite_cache->get( $cache_key );
			if ( null !== $cached ) {
				if ( false !== $cached ) {
					$p->set_url( $cached['raw_url'], $cached['parsed_url'] );
				}
				continue;
			}

			$parsed_url = $p->get_parsed_url();
			$converted  = false;
			foreach ( $url_mapping as $mapping ) {
				if ( is_child_url_of( $parsed_url, $mapping['from_url'] ) ) {
					$converted = WPURL::replace_base_url(
						$parsed_url,
						array(
							'old_base_url' => $base_url_object,
							'new_base_url' => $mapping['to_url'],
							'raw_url'      => $raw_url,
							'is_relative'  => (
								'#text' !== $token_type &&
								! WPURL::can_parse( $raw_url )
							),
						)
					);
					break;
				}
			}

			$cache_value = false;
			if ( false !== $converted ) {
				$cache_value = array(
					'raw_url'    => (string) $converted,
					'parsed_url' => $converted->new_url,
				);
				$p->set_url( $cache_value['raw_url'], $cache_value['parsed_url'] );
			}

			$rewrite_cache->set( $cache_key, $cache_value );
		}
	}

	return $p->get_updated_html();
}

/**
 * Check if a given URL matches the current site URL.
 *
 * @param  URL    $child  The URL to check.
 * @param  string $parent_url  The current site URL to compare against.
 *
 * @return bool Whether the URL matches the current site URL.
 */
function is_child_url_of( $child, $parent_url ) {
	$parent_url                       = is_string( $parent_url ) ? WPURL::parse( $parent_url ) : $parent_url;
	$child                            = is_string( $child ) ? WPURL::parse( $child ) : $child;
	$child_pathname_no_trailing_slash = rtrim( urldecode( $child->pathname ), '/' );

	if ( false === $child || false === $parent_url ) {
		return false;
	}

	if ( $parent_url->hostname !== $child->hostname ) {
		return false;
	}

	if ( $parent_url->protocol !== $child->protocol ) {
		return false;
	}

	$parent_pathname = urldecode( $parent_url->pathname );

	return (
		// Direct match.
		$parent_pathname === $child_pathname_no_trailing_slash ||
		$parent_pathname === $child_pathname_no_trailing_slash . '/' ||
		// Path prefix.
		0 === strncmp( $child_pathname_no_trailing_slash . '/', $parent_pathname, strlen( $parent_pathname ) )
	);
}

/**
 * Decodes the first n **encoded bytes** a URL-encoded string.
 *
 * For example, `urldecode_n( '%22is 6 %3C 6?%22 – asked Achilles', 1 )` returns
 * '"is 6 %3C 6?%22 – asked Achilles' because only the first encoded byte is decoded.
 *
 * @param  string $input  The string to decode.
 * @param  int    $decode_n  The number of bytes to decode in $input
 *
 * @return string The decoded string.
 */
function urldecode_n( $input, $decode_n ) {
	// Fast paths: nothing to do.
	if ( $decode_n <= 0 || false === strpos( $input, '%' ) ) {
			return $input;
	}

	$result = '';
	$at     = 0;
	while ( true ) {
		if ( $at + 3 > strlen( $input ) ) {
			break;
		}

		$last_at = $at;
		$at     += strcspn( $input, '%', $at );
		// Consume bytes except for the percent sign.
		$result .= substr( $input, $last_at, $at - $last_at );

		// If we've already decoded the requested number of bytes, stop.
		if ( strlen( $result ) >= $decode_n ) {
			break;
		}

		++$at;
		if ( $at > strlen( $input ) ) {
			break;
		}

		$decodable_length = strspn(
			$input,
			'0123456789ABCDEFabcdef',
			$at,
			2
		);

		if ( 2 === $decodable_length ) {
			// Decodes the urlencoded hex sequence from URL.
			// Note: This decodes bytes, not characters. It will recover the original byte sequence,
			// not necessarily any valid UTF-8 characters.
			$result .= chr( hexdec( $input[ $at ] . $input[ $at + 1 ] ) );
			$at     += 2;
		} else {
			// Consume the next byte and move on.
			$result .= '%';
		}
	}
	$result .= substr( $input, $at );

	return $result;
}
