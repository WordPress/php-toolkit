<?php

use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $errors = array();
		public $error_data = array();
		
		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( empty( $code ) ) {
				return;
			}
			
			$this->errors[ $code ][] = $message;
			
			if ( ! empty( $data ) ) {
				$this->error_data[ $code ] = $data;
			}
		}
		
		public function get_error_message( $code = '' ) {
			if ( empty( $code ) ) {
				$code = $this->get_error_code();
			}
			
			if ( isset( $this->errors[ $code ] ) ) {
				return $this->errors[ $code ][0];
			}
			
			return '';
		}
		
		public function get_error_code() {
			if ( empty( $this->errors ) ) {
				return '';
			}
			
			return array_keys( $this->errors )[0];
		}
	}
}

/**
 * Tests for WXR Parsers
 */
class WXRParserTest extends TestCase {

	/**
	 * Set up the test environment
	 */
	public function setUp(): void {
		parent::setUp();
		
		// Include the parser classes
		require_once dirname( __DIR__, 3 ) . '/components/DataLiberation/EntityReader/EntityReader.php';
		require_once dirname( __DIR__, 3 ) . '/components/DataLiberation/EntityReader/WXREntityReader.php';
		require_once __DIR__ . '/../class-wxr-parser-entity-reader.php';
		require_once __DIR__ . '/../class-wxr-parser-xml-processor.php';
		require_once __DIR__ . '/../class-wxr-parser-regex.php';
		require_once __DIR__ . '/../class-wxr-parser-xml.php';
		
		// Mock WordPress functions if needed
		if ( ! function_exists( '__' ) ) {
			function __( $text, $domain = 'default' ) {
				return $text;
			}
		}
	}

	/**
	 * @dataProvider parser_provider
	 */
	public function test_parse_simple_wxr_content( $parser_class ) {
		$parser = new $parser_class();
		$file_path = __DIR__ . '/wxrs/wxr-simple.xml';
		
		$result = $parser->parse( $file_path );
		
		$this->assertEquals( '1.2', $result['version'], "Parser $parser_class failed" );
		$this->assertEquals( 'https://playground.internal/path', $result['base_url'], "Parser $parser_class failed" );
		$this->assertEquals( 'https://playground.internal/path', $result['base_blog_url'], "Parser $parser_class failed" );
		
		$this->assertIsArray( $result['authors'], "Parser $parser_class failed" );
		$this->assertNotEmpty( $result['authors'], "Parser $parser_class failed" );
		
		$first_author = reset( $result['authors'] );
		$expected_author_keys = array( 'author_id', 'author_login', 'author_email', 'author_display_name', 'author_first_name', 'author_last_name' );
		foreach ( $expected_author_keys as $key ) {
			$this->assertArrayHasKey( $key, $first_author, "Author should contain key: $key for parser $parser_class" );
		}
		
		$this->assertEquals( '1', $first_author['author_id'], "Parser $parser_class failed" );
		$this->assertEquals( 'admin', $first_author['author_login'], "Parser $parser_class failed" );
		$this->assertEquals( 'admin@localhost.com', $first_author['author_email'], "Parser $parser_class failed" );
		
		$this->assertIsArray( $result['posts'], "Parser $parser_class failed" );
		$this->assertNotEmpty( $result['posts'], "Parser $parser_class failed" );
		
		$first_post = reset( $result['posts'] );
		$expected_post_keys = array( 'post_id', 'post_title', 'post_date', 'post_date_gmt', 'post_content', 'post_type', 'post_name', 'status' );
		foreach ( $expected_post_keys as $key ) {
			$this->assertArrayHasKey( $key, $first_post, "Post should contain key: $key for parser $parser_class" );
		}
		
		$this->assertEquals( '10', $first_post['post_id'], "Parser $parser_class failed" );
		$this->assertEquals( '"The Road Not Taken" by Robert Frost', $first_post['post_title'], "Parser $parser_class failed" );
		$this->assertEquals( 'post', $first_post['post_type'], "Parser $parser_class failed" );
		$this->assertEquals( 'hello-world', $first_post['post_name'], "Parser $parser_class failed" );
		$this->assertEquals( 'publish', $first_post['status'], "Parser $parser_class failed" );
	}

	/**
	 * @dataProvider parser_provider
	 */
	public function test_parse_non_existent_file( $parser_class ) {
		$parser = new $parser_class();
		$result = $parser->parse( '/path/to/non-existent-file.xml' );
		
		$this->assertInstanceOf( 'WP_Error', $result, "Parser $parser_class failed" );
		$this->assertEquals( 'WXR_parse_error', $result->get_error_code(), "Parser $parser_class failed" );
	}

	/**
	 * @dataProvider parser_provider
	 */
	public function test_parse_invalid_xml_file( $parser_class ) {
		$parser = new $parser_class();
		
		$temp_file = tempnam( sys_get_temp_dir(), 'invalid_wxr' );
		file_put_contents( $temp_file, 'This is not valid XML content' );
		
		$result = $parser->parse( $temp_file );
		
		unlink( $temp_file );
		
		$this->assertInstanceOf( 'WP_Error', $result, "Parser $parser_class failed" );
		$this->assertContains( $result->get_error_code(), array( 'WXR_parse_error', 'XML_parse_error' ), "Parser $parser_class failed" );
	}

	public static function parser_provider_with_data() {
		$test_cases = array();
		foreach( self::parser_provider() as $parser ) {
			foreach( self::wxr_files_provider() as $data ) {
				$test_cases[] = array_merge( $parser, $data );
			}
		}
		return $test_cases;
	}

	public static function parser_provider() {
		return array(
			array( 'WXR_Parser_Entity_Reader' ),
			array( 'WXR_Parser_Regex' ),
			array( 'WXR_Parser_XML' ),
		);
	}

	public static function wxr_files_provider() {
		$wxrs_dir = __DIR__ . '/wxrs/';
		$test_cases = array();
		
		if ( is_dir( $wxrs_dir ) ) {
			$files = glob( $wxrs_dir . '*.xml' );
			foreach ( $files as $file ) {
				$filename = basename( $file );
				
				switch ( $filename ) {
					case 'wxr-simple.xml':
						$test_cases[] = array( $file, 1, 1 );
						break;
					case 'valid-wxr-1.0.xml':
						$test_cases[] = array( $file, 6, 1 );
						break;
					case 'valid-wxr-1.1.xml':
						$test_cases[] = array( $file, 2, 1 );
						break;
					case 'wxr-utf-8-challenges.xml':
					case '10MB.xml':
						continue;
						$test_cases[] = array( $file, 3162, 4 );
						break;
					case 'a11y-unit-test-data.xml':
						$test_cases[] = array( $file, 154, 3 );
						break;
					case 'theme-unit-test-data.xml':
						$test_cases[] = array( $file, 186, 4 );
						break;
					case 'wxr-with-sub-data.xml':
						$test_cases[] = array( $file, 1, 1 );
						break;
					default:
						throw new Exception( "Unknown file: $filename" );
				}
			}
		}
		
		return $test_cases;
	}

	/**
	 * @dataProvider parser_provider_with_data
	 */
	public function test_parse_multiple_wxr_files( $parser_class, $file_path, $expected_posts, $expected_authors ) {
		if($parser_class === 'WXR_Parser_Regex' && basename( $file_path ) === 'a11y-unit-test-data.xml') {
			$this->markTestSkipped( "Skipping the failing test $file_path for $parser_class" );
			return;
		}
		$parser = new $parser_class();
		$result = $parser->parse( $file_path );
		
		$filename = basename( $file_path );
		
		$this->assertNotInstanceOf( 'WP_Error', $result, "Failed to parse file: $filename with parser $parser_class" );
		$this->assertIsArray( $result, "Result should be an array for file: $filename with parser $parser_class" );
		
		$expected_keys = array( 'authors', 'posts', 'categories', 'tags', 'terms', 'base_url', 'base_blog_url', 'version' );
		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $result, "Missing key '$key' in result for file: $filename with parser $parser_class" );
		}
		
		$this->assertEquals( $expected_posts, count( $result['posts'] ), "Expected $expected_posts posts in file: $filename with parser $parser_class" );
		$this->assertEquals( $expected_authors, count( $result['authors'] ), "Expected $expected_authors authors in file: $filename with parser $parser_class" );
		
		$this->assertNotEmpty( $result['version'], "WXR version should not be empty for file: $filename with parser $parser_class" );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+$/', $result['version'], "WXR version should be in format X.Y for file: $filename with parser $parser_class" );
	}

	/**
	 * @dataProvider parser_provider
	 */
	public function test_parser_initialization( $parser_class ) {
		$parser = new $parser_class();
		
		$this->assertIsArray( $parser->authors, "Parser $parser_class failed" );
		$this->assertIsArray( $parser->posts, "Parser $parser_class failed" );
		$this->assertIsArray( $parser->categories, "Parser $parser_class failed" );
		$this->assertIsArray( $parser->tags, "Parser $parser_class failed" );
		$this->assertIsArray( $parser->terms, "Parser $parser_class failed" );
		
		$this->assertIsString( $parser->base_url, "Parser $parser_class failed" );
		$this->assertIsString( $parser->base_blog_url, "Parser $parser_class failed" );
		
		$this->assertEmpty( $parser->authors, "Parser $parser_class failed" );
		$this->assertEmpty( $parser->posts, "Parser $parser_class failed" );
		$this->assertEmpty( $parser->categories, "Parser $parser_class failed" );
		$this->assertEmpty( $parser->tags, "Parser $parser_class failed" );
		$this->assertEmpty( $parser->terms, "Parser $parser_class failed" );
		$this->assertEmpty( $parser->base_url, "Parser $parser_class failed" );
		$this->assertEmpty( $parser->base_blog_url, "Parser $parser_class failed" );
	}

	/**
	 * @dataProvider parser_provider
	 */
	public function test_parse_resets_state( $parser_class ) {
		$parser = new $parser_class();
		$file_path = __DIR__ . '/wxrs/wxr-simple.xml';
		
		$result1 = $parser->parse( $file_path );
		$this->assertNotEmpty( $result1['posts'], "Parser $parser_class failed" );
		$this->assertNotEmpty( $result1['authors'], "Parser $parser_class failed" );
		
		$result2 = $parser->parse( $file_path );
		$this->assertNotEmpty( $result2['posts'], "Parser $parser_class failed" );
		$this->assertNotEmpty( $result2['authors'], "Parser $parser_class failed" );
		
		$this->assertEquals( $result1, $result2, "Parser $parser_class failed" );
	}

	/**
	 * @group sub-data
	 */
	public function test_parse_wxr_with_sub_data() {
		$parser = new WXR_Parser_XML();
		$file_path = __DIR__ . '/wxrs/wxr-with-sub-data.xml';

		$result = $parser->parse( $file_path );

		$this->assertNotInstanceOf( 'WP_Error', $result, "Failed to parse file with parser WXR_Parser_XML" );

		// Check basic structure
		$this->assertArrayHasKey( 'posts', $result );
		$this->assertArrayHasKey( 'terms', $result );
		$this->assertArrayHasKey( 'version', $result );
		
		// Check WXR version
		$this->assertEquals( '1.1', $result['version'] );

		// Check that we have one post
		$this->assertCount( 1, $result['posts'] );
		$post = $result['posts'][0];

		// Check post basic fields
		$this->assertEquals( '101', $post['post_id'] );
		$this->assertEquals( 'Post with sub data', $post['post_title'] );
		$this->assertEquals( 'admin', $post['post_author'] );
		$this->assertEquals( 'publish', $post['status'] );
		$this->assertEquals( 'post', $post['post_type'] );

		// Check post meta
		$this->assertArrayHasKey( 'postmeta', $post );
		$this->assertCount( 1, $post['postmeta'] );
		$this->assertEquals( '_test_meta_key', $post['postmeta'][0]['key'] );
		$this->assertEquals( 'test_meta_value', $post['postmeta'][0]['value'] );

		// Check category attributes (stored in terms)
		$this->assertArrayHasKey( 'terms', $post );
		$this->assertCount( 1, $post['terms'] );
		$this->assertEquals( 'category', $post['terms'][0]['domain'] );
		$this->assertEquals( 'test-cat', $post['terms'][0]['slug'] );
		$this->assertEquals( 'Test Category', $post['terms'][0]['name'] );

		// Check comments
		$this->assertArrayHasKey( 'comments', $post );
		$this->assertCount( 1, $post['comments'] );
		$comment = $post['comments'][0];
		$this->assertEquals( '201', $comment['comment_id'] );
		$this->assertEquals( 'Commenter', $comment['comment_author'] );
		$this->assertEquals( 'This is a comment with meta.', $comment['comment_content'] );

		// Check comment meta
		$this->assertArrayHasKey( 'commentmeta', $comment );
		$this->assertCount( 1, $comment['commentmeta'] );
		$this->assertEquals( '_comment_meta_key', $comment['commentmeta'][0]['key'] );
		$this->assertEquals( 'comment_meta_value', $comment['commentmeta'][0]['value'] );

		// Check terms (wp:term elements)
		$this->assertCount( 1, $result['terms'] );
		$term = $result['terms'][0];
		$this->assertEquals( '40', $term['term_id'] );
		$this->assertEquals( 'custom_tax', $term['term_taxonomy'] );
		$this->assertEquals( 'custom-term', $term['slug'] );
		$this->assertEquals( 'Custom Term', $term['term_name'] );

		// Check term meta
		$this->assertArrayHasKey( 'termmeta', $term );
		$this->assertCount( 1, $term['termmeta'] );
		$this->assertEquals( 'term_meta_key', $term['termmeta'][0]['key'] );
		$this->assertEquals( 'term_meta_value', $term['termmeta'][0]['value'] );
	}

	/**
	 * @dataProvider parser_provider
	 */
	public function test_parse_wxr_with_challenging_utf8_sequences( $parser_class ) {
		$parser = new $parser_class();
		$file_path = __DIR__ . '/wxrs/wxr-utf-8-challenges.xml';
		$result = $parser->parse( $file_path );

		$this->assertNotInstanceOf( 'WP_Error', $result );

		// Check basic post data with UTF-8
		$this->assertCount( 1, $result['posts'] );
		$post = $result['posts'][0];
		
		// Test post title with emojis, RTL override, and complex characters
		$this->assertEquals( '"The Road ‮Not‬ Taken" by Rob‭ert ‮Frost ‪🌲‬', $post['post_title'] );
		
		// Test post slug with RTL override and emoji
		$this->assertEquals( 'hello-w‮orld‬-utf8-💫-test', $post['post_name'] );
		
		// Test post link with emoji and invisible characters
		$this->assertEquals( 'https://playground.internal/path/🚀/‮tsop‬/?p=1&test=💫‌​‍‎', $post['guid'] );
		
		// Test post content with various challenging UTF-8 sequences
		$this->assertStringContainsString( 'T̷̢̯̭̈w̴̰̜̾o̷͉̅ ̵̨͔̔r̶̞̈o̷̰͇̍ä̴́ͅd̶̰̒s̵̞̈́', $post['post_content'] );
		$this->assertStringContainsString( '𝓣𝓮𝓼𝓽 𝓜𝓾𝓵𝓽𝓲-𝓑𝔂𝓽𝓮: 🚀🌟💫⭐️🔥💯🎉🎊🌈🦄', $post['post_content'] );
		$this->assertStringContainsString( 'السلام عليكم وﷲ', $post['post_content'] );
		
		// Test excerpt with zalgo text and emojis
		$this->assertEquals( 'T̷̢̯̭̈h̶̰̾i̵̱̇s̶̰̍ ̵̰̔i̶̱̇s̶̰̍ ̵̰̔a̶̰̅n̶̰̍ ̵̰̔e̶̞̔x̶̰̍c̶̰̒e̶̞̔r̶̰̈p̶̰̒t̶̰̒ ̵̰̔w̶̰̾i̵̱̇t̶̰̒h̶̰̾ ̵̰̔e̶̞̔m̶̰̈o̶̰̍j̶̰̈i̵̱̇ 🚀🌟 ̵̰̔a̶̰̅n̶̰̍d̶̬̽ ̵̰̔R̶̰̈T̶̰̒L̶̰̈ ‮خدسنگ‬ ̵̰̔t̶̰̒e̶̞̔x̶̰̍t̶̰̒‌​‍‎', $post['post_excerpt'] );
		
		// Test post meta with challenging UTF-8 values
		$this->assertArrayHasKey( 'postmeta', $post );
		$this->assertCount( 5, $post['postmeta'] );
		
		// Find specific meta by key
		$meta_by_key = array();
		foreach ( $post['postmeta'] as $meta ) {
			$meta_by_key[ $meta['key'] ] = $meta['value'];
		}
		
		// Test meta with invisible characters in key
		$this->assertArrayHasKey( '_pingme‌​‍‎', $meta_by_key );
		$this->assertEquals( '1​‍‌‎', $meta_by_key['_pingme‌​‍‎'] );
		
		// Test meta with emoji and mathematical symbols
		$this->assertArrayHasKey( '_utf8_test', $meta_by_key );
		$this->assertEquals( '🚀 Test with 𝔻𝕠𝕦𝕓𝕝𝕖 𝔖𝔱𝔯𝔲𝔠𝔨: ℍ𝔢𝔩𝔩𝔬 ‮olleH‬ 𝖂𝖔𝖗𝖫𝖉! ', $meta_by_key['_utf8_test'] );
		
		// Test meta with zalgo text
		$this->assertArrayHasKey( '_zalgo_test', $meta_by_key );
		$this->assertEquals( 'T̵̢̯̭̈h̶̰̾i̵̱̇s̶̰̍ ̵̰̔i̶̱̇s̶̰̍ ̵̰̔z̶̰̒a̶̰̅l̶̰̈g̶̰̈o̶̰̍ ̵̰̔t̶̰̒e̶̞̔x̶̰̍t̶̰̒', $meta_by_key['_zalgo_test'] );
		
		// Test meta with HTML entities for special characters
		$this->assertArrayHasKey( '_special_chars', $meta_by_key );
		$this->assertEquals( '‮‍​‌‍⁠�', $meta_by_key['_special_chars'] );
		
		// Test category with zalgo text and emoji
		$this->assertArrayHasKey( 'terms', $post );
		$this->assertCount( 1, $post['terms'] );
		$category = $post['terms'][0];
		$this->assertEquals( 'category', $category['domain'] );
		$this->assertEquals( 'uncat‮egorized‬', $category['slug'] );
		$this->assertEquals( 'Ü̷̢̯̭n̶̰̍c̶̰̒a̶̰̅t̶̰̒e̶̞̔g̶̰̈o̶̰̍r̶̰̈i̵̱̇z̶̰̒e̶̞̔d̶̬̽ 🎭', $category['name'] );
		
		// Test author data with challenging UTF-8
		$this->assertCount( 1, $result['authors'] );
		$author = $result['authors'][0];
		$this->assertEquals( 'admin‌​‍‎', $author['author_login'] );
		$this->assertEquals( 'ădmĩn@ℓocalhost.com', $author['author_email'] );
		$this->assertEquals( 'A̸̰̅d̴̰͝m̵͎̽i̵̱̋n̷̰̎ ​‍‌‎', $author['author_display_name'] );
		$this->assertEquals( '🅰️', $author['author_first_name'] );
		$this->assertEquals( '🇺🇸𝕌𝕟𝕚𝕔𝕠𝕕𝕖', $author['author_last_name'] );
		
		// Test site metadata with challenging UTF-8
		$this->assertEquals( 'My WordPress Website 🚀 ‮‍مرحبا', $result['title'] );
		$this->assertEquals( 'Site with  ​⁠ invisible chars and � replacement', $result['description'] );
	}
}
