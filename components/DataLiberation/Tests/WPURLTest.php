<?php

use PHPUnit\Framework\TestCase;
use WordPress\DataLiberation\URL\WPURL;

class WPURLTest extends TestCase {

	/**
	 * @dataProvider provider_test_parse_with_base_url
	 */
	public function test_parse_with_base_url( $url, $base_url, $expected_result ) {
		$parsed_url = WPURL::parse( $url, $base_url );
		$this->assertNotFalse( $parsed_url, 'URL parsing failed' );
		$this->assertEquals( $expected_result, $parsed_url->toString() );
	}

	public static function provider_test_parse_with_base_url() {
		return array(
			'Relative file path with base URL' => array(
				'nodejs-development-environment.md',
				'https://wordpress.org',
				'https://wordpress.org/nodejs-development-environment.md',
			),
		);
	}

	/**
	 * @dataProvider provider_test_replace_base_url_trailing_slash
	 */
	public function test_replace_base_url_trailing_slash( $url, $options, $expected_result ) {
		$result = WPURL::replace_base_url( $url, $options );
		$this->assertNotFalse( $result, 'replace_base_url() returned false' );
		$this->assertEquals( $expected_result, (string) $result );
	}

	public static function provider_test_replace_base_url_trailing_slash() {
		return array(
			'Origin-only URL without trailing slash preserves no-slash style' => array(
				'https://example.com/',
				array(
					'old_base_url' => 'https://example.com',
					'new_base_url' => 'https://newsite.com',
					'raw_url'      => 'https://example.com',
					'is_relative'  => false,
				),
				'https://newsite.com',
			),
			'Origin-only URL with trailing slash preserves slash'             => array(
				'https://example.com/',
				array(
					'old_base_url' => 'https://example.com',
					'new_base_url' => 'https://newsite.com',
					'raw_url'      => 'https://example.com/',
					'is_relative'  => false,
				),
				'https://newsite.com/',
			),
			'Origin-only URL without slash, new base has a path'             => array(
				'https://example.com/',
				array(
					'old_base_url' => 'https://example.com',
					'new_base_url' => 'https://newsite.com/blog/',
					'raw_url'      => 'https://example.com',
					'is_relative'  => false,
				),
				'https://newsite.com/blog',
			),
			'URL with path and no trailing slash – existing behavior'        => array(
				'https://example.com/page',
				array(
					'old_base_url' => 'https://example.com',
					'new_base_url' => 'https://newsite.com',
					'raw_url'      => 'https://example.com/page',
					'is_relative'  => false,
				),
				'https://newsite.com/page',
			),
			'URL with path and trailing slash – existing behavior'           => array(
				'https://example.com/page/',
				array(
					'old_base_url' => 'https://example.com',
					'new_base_url' => 'https://newsite.com',
					'raw_url'      => 'https://example.com/page/',
					'is_relative'  => false,
				),
				'https://newsite.com/page/',
			),
		);
	}
}
