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
			// var_dump(WPURL::parse( 'http://ho%2Fst/', $base_url ));
			// var_dump($parsed_url);
			// var_dump($processor->matched_url);
			// var_dump($processor->preprocessed_url);
			// var_dump($example);
			// die();
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

	public function myScratch() {
		$regex = '/
            (?:                                                      # scheme
                (?<scheme>https?:)?                                  # Only consider http and https
                \/\/                                                 # The protocol does not have to be there, but when
                                                                     # it is, is must be followed by \/\/
            )?
            (?:                                                        # userinfo
                (?:
                    (?<=\/{2})                                             # prefixed with \/\/
                    |                                                      # or
                    (?=[^\p{Sm}\p{Sc}\p{Sk}\p{P}])                         # start with not: mathematical, currency, modifier symbol, punctuation
                )
                (?<userinfo>[^\s<>@\/]+)                                   # not: whitespace, < > @ \/
                @                                                          # at
            )?
            (?=[^\p{Z}\p{Sm}\p{Sc}\p{Sk}\p{C}\p{P}])                   # followed by valid host char
            (?|                                                        # host
                (?<host>                                                   # host prefixed by scheme or userinfo (less strict)
                    (?<=\/\/|@)                                               # prefixed with \/\/ or @
                    (?=[^\-])                                                  # label start, not: -
                    (?:%|[^\p{Z}\p{Sm}\p{Sc}\p{Sk}\p{C}\p{P}]|-){1,63}         # label not: whitespace, mathematical, currency, modifier symbol, control point, punctuation | except -
                    (?<=[^\-])                                                 # label end, not: -
                    (?:                                                        # more label parts
                        \.
                        (?=[^\-])                                                  # label start, not: -
                        (?<tld>(?:[^\p{Z}\p{Sm}\p{Sc}\p{Sk}\p{C}\p{P}]|-){1,63})   # label not: whitespace, mathematical, currency, modifier symbol, control point, punctuation | except -
                        (?<=[^\-])                                                 # label end, not: -
                    )*
                )
                |                                                          # or
                (?<host>                                                   # host with tld (no scheme or userinfo)
                    (?=[^\-])                                                  # label start, not: -
                    (?:%|[^\p{Z}\p{Sm}\p{Sc}\p{Sk}\p{C}\p{P}]|-){1,63}         # label not: whitespace, mathematical, currency, modifier symbol, control point, punctuation | except -
                    (?<=[^\-])                                                 # label end, not: -
                    (?:                                                        # more label parts
                        \.
                        (?=[^\-])                                                  # label start, not: -
                        (?:%|[^\p{Z}\p{Sm}\p{Sc}\p{Sk}\p{C}\p{P}]|-){1,63}         # label not: whitespace, mathematical, currency, modifier symbol, control point, punctuation | except -
                        (?<=[^\-])                                                 # label end, not: -
                    )*
                    \.(?<tld>\w{2,63})                                         # tld
                )
            )
            (?:\:(?<port>\d+))?                                        # port
            (?<path>                                                   # path, query, fragment
                [\/?#]                                                 # prefixed with \/ or ? or #
                [^\s<>]*                                               # any chars except whitespace and <>
                (?<=[^\s<>({\[`!;:\'".,?«»“”‘’])                       # end with not a space or some punctuation chars
            )?
        /ixuJ';

		$example = 'Visit http://foo.09.. for more info';
		$matches = array();
		$found = preg_match( $regex, $example, $matches, PREG_OFFSET_CAPTURE );
		var_dump($found);
		die();
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
			
			// Filter out examples that are not relevant for text processing
			$input = $example['input'] ?? '';
			
			// Skip inputs that are just fragments or queries without a domain
			if ( preg_match( '/^[?#]/', $input ) ) {
				// continue;
			}
			
			$filtered_examples[] = array( $example );
		}

		return $filtered_examples;
	}
}
