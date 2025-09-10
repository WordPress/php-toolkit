<?php

use PHPUnit\Framework\TestCase;
use WordPress\DataLiberation\URL\URLInTextProcessor;
use WordPress\DataLiberation\URL\WPURL;

class URLInTextProcessorWHATWGComplianceTest extends TestCase {

	/**
	 * Test URLInTextProcessor can find URLs using WHATWG test data.
	 * This tests both valid URL detection and validates against the WHATWG spec.
	 *
	 * @dataProvider data_whatwg_url_in_text
	 */
	public function test_whatwg_url_in_text( $example ) {
		$input_url = $example['input'];
		$base_url = $example['base'];
		$should_be_valid = !isset( $example['failure'] ) || !$example['failure'];
		
		$processor = new URLInTextProcessor( "Visit $input_url for more info", $base_url );
		if ( ! $should_be_valid ) {
			if ( false === $processor->next_url() ) {
				$this->assertTrue( true, "Should not have found URL in text: '$input_url'" );
				return;
			}
			$parsed_url = $processor->get_parsed_url();
			if ( ! $parsed_url ) {
				$this->assertTrue( true, "Should not have parsed URL: '$input_url'" );
				return;
			}
			$this->fail( "Should not have parsed URL: '$input_url'" );
			return;
		}

		$this->assertTrue( $processor->next_url(), "Should have found URL in text: '$input_url'" );

		$raw_url = $processor->get_raw_url();
		$parsed_url = $processor->get_parsed_url();
		
		// For valid URLs, check that we found something and it parsed correctly
		$this->assertNotEmpty( $raw_url, "Raw URL should have been found in text" );
		$this->assertNotFalse( $parsed_url, "Parsed URL should have been found in text" );
		
		// Additional validation for expected results
		$this->assertParsedUrl( $example, $parsed_url );
	}

	private function assertParsedUrl( $example, $parsed_url ) {
		if ( isset( $example['protocol'] ) ) {
			$this->assertEquals( $example['protocol'], $parsed_url->protocol );
		}
		if ( isset( $example['href'] ) ) {
			$this->assertEquals( $example['href'], $parsed_url->toString() );
		}
		if ( isset( $example['username'] ) ) {
			$this->assertEquals( $example['username'], $parsed_url->username );
		}
		if ( isset( $example['password'] ) ) {
			$this->assertEquals( $example['password'], $parsed_url->password );
		}
		if ( isset( $example['host'] ) ) {
			$this->assertEquals( $example['host'], $parsed_url->host );
		}
		if ( isset( $example['port'] ) ) {
			$this->assertEquals( $example['port'], $parsed_url->port );
		}
		if ( isset( $example['hostname'] ) ) {
			$this->assertEquals( $example['hostname'], $parsed_url->hostname );
		}
		if ( isset( $example['pathname'] ) ) {
			$this->assertEquals( $example['pathname'], $parsed_url->pathname );
		}
		if ( isset( $example['search'] ) ) {
			$this->assertEquals( $example['search'], $parsed_url->search );
		}
		if ( isset( $example['hash'] ) ) {
			$this->assertEquals( $example['hash'], $parsed_url->hash );
		}
		if ( isset( $example['origin'] ) ) {
			$this->assertEquals( $example['origin'], $parsed_url->origin );
		}
	}

	/**
	 * Data provider that uses WHATWG test data for URLInTextProcessor testing.
	 * Filters to include both valid and invalid URLs that are relevant for text processing.
	 */
	public static function data_whatwg_url_in_text() {
		static $test_examples = null;
		if ( null === $test_examples ) {
			$json = file_get_contents( __DIR__ . '/whatwg_url_inline_detection_test_data.json' );
			$test_examples = json_decode( $json, true );
		}

		$filtered_examples = array();
		foreach ( $test_examples as $example ) {
			if ( is_string( $example ) ) {
				continue;
			}
			
			$filtered_examples[] = array( $example );
		}

		return $filtered_examples;
	}
}
