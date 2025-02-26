<?php

use PHPUnit\Framework\TestCase;

use function WordPress\DataLiberation\URL\urldecode_n;

class UrldecodeNTest extends TestCase {

	/**
	 *
	 * @dataProvider provider_test_urldecode_n
	 */
	public function test_urldecode_n(
		$original_string,
		$decode_length,
		$expected_string
	) {
		$result = urldecode_n( $original_string, $decode_length );
		$this->assertEquals( $expected_string, $result, 'Failed to decode the first n bytes of the string' );
	}

	public static function provider_test_urldecode_n() {
		return array(
			'Encoded path segment with no encoded bytes later on' => array(
				'original_string' => '/%73/%63/image.png',
				'decode_length' => 4,
				'expected_string' => '/s/c/image.png',
			),
			'Encoded path segment with encoded bytes later on' => array(
				'original_string' => '/%73/%63/%73%63ience.png',
				'decode_length' => 4,
				'expected_string' => '/s/c/%73%63ience.png',
			),
			'Decode past the encoded path segment' => array(
				'original_string' => '/%73/%63/science.png',
				'decode_length' => 10,
				'expected_string' => '/s/c/science.png',
			),
			'Double percent sign – decode it' => array(
				'original_string' => '/%%73cience.png',
				'decode_length' => 3,
				'expected_string' => '/%science.png',
			),
			'Double percent sign – finish decoding after the first percent sign' => array(
				'original_string' => '/%%73cience.png',
				'decode_length' => 2,
				'expected_string' => '/%%73cience.png',
			),
			'UTF-8 encoded path segment' => array(
				'original_string' => '/%e4%b8%ad%e6%96%87',
				'decode_length' => 10,
				'expected_string' => '/中文',
			),
		);
	}
}
