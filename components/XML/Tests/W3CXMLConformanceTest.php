<?php
/**
 * W3C XML Conformance Tests for XMLProcessor
 *
 * Runs individual test cases from the W3C XML Conformance Test Suite
 * as PHPUnit test cases with direct assertions.
 *
 * @package WordPress
 * @subpackage XML-API
 */

use PHPUnit\Framework\TestCase;
use WordPress\XML\XMLProcessor;

/**
 * @group xml-api
 * @group w3c-conformance
 * 
 * @coversDefaultClass XMLProcessor
 */
class W3CXMLConformanceTest extends TestCase {

	/**
	 * Path to the W3C XML test suite directory
	 */
	private static $test_suite_path;

	/**
	 * Cache of parsed test cases
	 */
	private static $test_cases = null;

	private const XML_NAMESPACE = 'http://www.w3.org/XML/1998/namespace';

	public static function setUpBeforeClass(): void {
		self::$test_suite_path = __DIR__ . '/W3C-XML-Test-Suite';
		
		if (!is_dir(self::$test_suite_path)) {
			throw new Exception("W3C XML Test Suite not found at: " . self::$test_suite_path);
		}

		self::$test_suite_path = realpath(self::$test_suite_path);
	}
	
	/**
	 * Test individual W3C XML test cases
	 * 
	 * @dataProvider w3cTestCaseProvider
	 * @covers XMLProcessor::create_from_string
	 * @covers XMLProcessor::next_token
	 * @covers XMLProcessor::get_last_error
	 */
	public function test_w3c_xml_test_case($test_id, $test_type, $test_file, $description) {
		$xml_content = file_get_contents($test_file);
		$this->assertNotFalse($xml_content, "Could not read test file: {$test_file}");
		if(strpos($xml_content, "\xFF\xFE") !== false || strpos($xml_content, "\xFE\xFF") !== false) {
			$this->markTestSkipped("Skipping test case: {$test_id} – it uses a UTF-16 encoded document and XMLProcessor only supports UTF-8.");
			return;
		}

		if (in_array($test_id, [
			"not-sa04",
			"sa04",
			"ibm-valid-P01-ibm01v01.xml",
		])) {
			$this->markTestSkipped("Skipping test case: {$test_id} – XMLProcessor does not support custom processing directive targets (e.g. <?music ... ?>)");
			return;
		}

		if (in_array($test_id, [
			"ibm-1-1-valid-P02-ibm02v01.xml",
			"ibm-1-1-valid-P02-ibm02v02.xml",
			"ibm-1-1-valid-P02-ibm02v03.xml",
			"ibm-1-1-valid-P02-ibm02v04.xml",
			"ibm-1-1-valid-P02-ibm02v05.xml",
			"ibm-1-1-valid-P02-ibm02v06.xml",
			"ibm-1-1-valid-P03-ibm03v01.xml",
			"ibm-1-1-valid-P03-ibm03v02.xml",
			"ibm-1-1-valid-P03-ibm03v03.xml",
			"ibm-1-1-valid-P03-ibm03v04.xml",
			"ibm-1-1-valid-P03-ibm03v05.xml",
			"ibm-1-1-valid-P03-ibm03v06.xml",
			"ibm-1-1-valid-P03-ibm03v07.xml",
			"ibm-1-1-valid-P03-ibm03v08.xml",
			"ibm-1-1-valid-P03-ibm03v09.xml",
			"ibm-1-1-valid-P04-ibm04v01.xml",
			"ibm-1-1-valid-P04-ibm04av01.xml",
			"ibm-1-1-valid-P05-ibm05v01.xml",
			"ibm-1-1-valid-P05-ibm05v02.xml",
			"ibm-1-1-valid-P05-ibm05v03.xml",
			"ibm-1-1-valid-P05-ibm05v04.xml",
			"ibm-1-1-valid-P05-ibm05v05.xml",
			"ibm-1-1-valid-P047-ibm07v01.xml",
			"ibm-1-1-valid-P77-ibm77v01.xml",
			"ibm-1-1-valid-P77-ibm77v02.xml",
			"ibm-1-1-valid-P77-ibm77v03.xml",
			"ibm-1-1-valid-P77-ibm77v04.xml",
			"ibm-1-1-valid-P77-ibm77v05.xml",
			"ibm-1-1-valid-P77-ibm77v06.xml",
			"ibm-1-1-valid-P77-ibm77v07.xml",
			"ibm-1-1-valid-P77-ibm77v08.xml",
			"ibm-1-1-valid-P77-ibm77v09.xml",
			"ibm-1-1-valid-P77-ibm77v10.xml",
			"ibm-1-1-valid-P77-ibm77v11.xml",
			"ibm-1-1-valid-P77-ibm77v12.xml",
			"ibm-1-1-valid-P77-ibm77v13.xml",
			"ibm-1-1-valid-P77-ibm77v14.xml",
			"ibm-1-1-valid-P77-ibm77v15.xml",
			"ibm-1-1-valid-P77-ibm77v16.xml",
			"ibm-1-1-valid-P77-ibm77v17.xml",
			"ibm-1-1-valid-P77-ibm77v18.xml",
			"ibm-1-1-valid-P77-ibm77v19.xml",
			"ibm-1-1-valid-P77-ibm77v20.xml",
			"ibm-1-1-valid-P77-ibm77v21.xml",
			"ibm-1-1-valid-P77-ibm77v22.xml",
			"ibm-1-1-valid-P77-ibm77v23.xml",
			"ibm-1-1-valid-P77-ibm77v24.xml",
			"ibm-1-1-valid-P77-ibm77v25.xml",
			"ibm-1-1-valid-P77-ibm77v26.xml",
			"ibm-1-1-valid-P77-ibm77v27.xml",
			"ibm-1-1-valid-P77-ibm77v28.xml",
			"ibm-1-1-valid-P77-ibm77v29.xml",
			"ibm-1-1-valid-P77-ibm77v30.xml",
			"rmt-e2e-50",
			"rmt-006",
			"rmt-007",
			"rmt-023",
			"rmt-025",
			"rmt-027",
			"rmt-029",
			"rmt-031",
			"rmt-033",
			"rmt-035",
			"rmt-043",
			"rmt-045",
			"rmt-047",
			"rmt-049",
			"rmt-051",
			"rmt-054",
			"rmt-ns11-001",
			"rmt-ns11-002",
			"rmt-ns11-003",
			"rmt-ns11-004",
			"rmt-ns11-006",
		])) {
			$this->markTestSkipped("Skipping test case: {$test_id} – XMLProcessor does not support XML 1.1.");
			return;
		}

		if (in_array($test_id, [
			"valid-sa-012",
			"valid-sa-016",
			"valid-sa-017",
			"valid-sa-036",
			"valid-sa-017a",
			"valid-sa-039",
			"valid-sa-055",
			"valid-sa-063",
			"valid-sa-098",
			"pr-xml-utf-8",
			"o-p01pass2",
			"o-p22pass4",
			"o-p22pass5",
			"o-p43pass1",
			"ibm-valid-P16-ibm16v01",
			"ibm-valid-P16-ibm16v02",
			"ibm-valid-P16-ibm16v03",
			"ibm-valid-P17-ibm17v01",
			"ibm-valid-P27-ibm27v02",
			"ibm-valid-P43-ibm43v01",
			"rmt-e2e-15j",
			"rmt-e2e-15l",
			"rmt-e2e-22",
			"rmt-010",
			"rmt-012",
			"rmt-022",
			"rmt-026",
			"rmt-034",
			"rmt-040",
			"rmt-044",
			"rmt-050",
			"rmt-e3e-05b",
			"x-rmt-008b",
			"ibm-valid-P16-ibm16v01.xml",
			"ibm-valid-P16-ibm16v02.xml",
			"ibm-valid-P16-ibm16v03.xml",
			"ibm-valid-P17-ibm17v01.xml",
			"ibm-valid-P27-ibm27v02.xml",
			"ibm-valid-P43-ibm43v01.xml",
			"x-ibm-1-0.5-valid-P04-ibm04v01.xml",
			"x-ibm-1-0.5-valid-P05-ibm05v01.xml",
			"x-ibm-1-0.5-valid-P05-ibm05v02.xml",
			"x-ibm-1-0.5-valid-P05-ibm05v03.xml",
			"x-ibm-1-0.5-valid-P05-ibm05v04.xml",
		])) {
			$this->markTestSkipped("Skipping test case: {$test_id} – XMLProcessor does not apply custom DTDs.");
			return;
		}

		try {
			$processor = XMLProcessor::create_from_string($xml_content);

			// Process through all tokens to trigger any parsing errors
			if ($processor !== false) {
				while ($processor->next_token()) {
					// twiddle thumbs
				}
			}
			
			switch ($test_type) {
				case 'valid':
					$this->assertNotFalse($processor, 
						"Valid XML should parse successfully [{$test_id}]: {$description}");
					$this->assertNull($processor->get_exception(), 
						"Valid XML should not produce exceptions [{$test_id}]: {$description}");
					$this->assertNull($processor->get_last_error(), 
						"Valid XML should not produce errors [{$test_id}]: {$description}");
					break;
					
				case 'invalid':
					// Invalid XML should parse (non-validating parser) but may have validation errors
					// Since XMLProcessor is non-validating, invalid docs should still parse
					$this->assertNotFalse($processor, 
						"Invalid but well-formed XML should parse with non-validating parser [{$test_id}]: {$description}");
					break;
					
				case 'not-wf':
					// Not well-formed XML should fail to parse or produce errors
					$this->assertTrue(
						$processor === false || ($processor && $processor->get_last_error() !== null),
						"Not well-formed XML should be rejected [{$test_id}]: {$description}"
					);
					break;
					
				case 'error':
					// Error cases - behavior is implementation-defined
					// We'll just verify it doesn't crash
					$this->assertTrue(true, "Error test case completed without crashing [{$test_id}]: {$description}");
					break;
					
				default:
					$this->fail("Unknown test type: {$test_type} for test {$test_id}");
			}
			
		} catch (Exception $e) {
			// For 'not-wf' tests, exceptions might be expected
			if ($test_type === 'not-wf') {
				$this->assertTrue(true, "Expected exception for malformed XML [{$test_id}]: " . $e->getMessage());
			} else {
				throw $e;
			}
		}
	}
	
	/**
	 * Data provider for W3C XML test cases
	 */
	public static function w3cTestCaseProvider() {
		// Initialize path if not set (data providers run before setUpBeforeClass)
		if (self::$test_suite_path === null) {
			self::$test_suite_path = __DIR__ . '/W3C-XML-Test-Suite';
			
			if (!is_dir(self::$test_suite_path)) {
				throw new Exception("W3C XML Test Suite not found at: " . self::$test_suite_path);
			}

			self::$test_suite_path = realpath(self::$test_suite_path);
		}
		
		if (self::$test_cases === null) {
			self::$test_cases = self::parseAllTestCases();
		}
		
		return self::$test_cases;
	}

	/**
	 * Parse all test cases from the W3C XML test suite.
	 */
	private static function parseAllTestCases() {
		$main_config = self::$test_suite_path . '/xmlconf.xml';
		if ( ! file_exists( $main_config ) ) {
			throw new Exception( "Main test configuration not found: {$main_config}" );
		}

		$previous = libxml_use_internal_errors( true );
		$dom      = new DOMDocument();
		$options  = LIBXML_DTDLOAD | LIBXML_DTDATTR | LIBXML_NOENT;
		$loaded   = $dom->load( $main_config, $options );
		if ( ! $loaded ) {
			$errors = libxml_get_errors();
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );

			$message = 'Failed to parse xmlconf.xml';
			if ( ! empty( $errors ) ) {
				$first   = $errors[0];
				$message .= sprintf( ': %s on line %d', trim( $first->message ), $first->line );
			}

			throw new Exception( $message );
		}

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$test_cases = array();
		self::collectTestCases( $dom->documentElement, self::$test_suite_path, $test_cases );

		return $test_cases;
	}

	private static function collectTestCases( DOMNode $node, $base_path, array &$test_cases ) {
		if ( ! ( $node instanceof DOMElement ) ) {
			foreach ( $node->childNodes as $child ) {
				self::collectTestCases( $child, $base_path, $test_cases );
			}

			return;
		}

		$current_base = $base_path;
		if ( $node->hasAttributeNS( self::XML_NAMESPACE, 'base' ) ) {
			$current_base = self::resolvePath( $base_path, $node->getAttributeNS( self::XML_NAMESPACE, 'base' ) );
		} elseif ( $node->hasAttribute( 'xml:base' ) ) {
			$current_base = self::resolvePath( $base_path, $node->getAttribute( 'xml:base' ) );
		}

		if ( 'TEST' === $node->nodeName ) {
			$uri = $node->getAttribute( 'URI' );
			if ( '' === $uri ) {
				return;
			}

			$test_file = self::resolvePath( $current_base, $uri );
			if ( ! is_file( $test_file ) ) {
				return;
			}

			$test_id = $node->getAttribute( 'ID' );
			if ( '' === $test_id ) {
				$test_id = $uri;
			}

			$type = strtolower( $node->getAttribute( 'TYPE' ) );
			if ( '' === $type ) {
				$type = 'valid';
			}

			$description = trim( preg_replace( '/\s+/', ' ', $node->textContent ) );

			$test_cases[ $test_id ] = array(
				$test_id,
				$type,
				$test_file,
				$description,
			);

			return;
		}

		foreach ( $node->childNodes as $child ) {
			self::collectTestCases( $child, $current_base, $test_cases );
		}
	}

	private static function resolvePath( $base_path, $relative_path ) {
		if ( '' === $relative_path ) {
			return $base_path;
		}

		// If it's an absolute path, use it directly
		if ( $relative_path[0] === '/' || preg_match( '#^[a-zA-Z]:#', $relative_path ) ) {
			return $relative_path;
		}

		// Otherwise concatenate and let realpath() normalize it
		$candidate = rtrim( $base_path, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $relative_path;
		$resolved  = realpath( $candidate );

		// If realpath fails (file doesn't exist), return the candidate anyway
		// We check is_file() later, so non-existent paths will be skipped
		return false !== $resolved ? $resolved : $candidate;
	}
}
