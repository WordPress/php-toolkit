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
}
