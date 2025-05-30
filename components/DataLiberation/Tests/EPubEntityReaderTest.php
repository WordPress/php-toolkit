<?php

use PHPUnit\Framework\TestCase;
use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\DataLiberation\EntityReader\EPubEntityReader;
use WordPress\Zip\ZipFilesystem;

class EPubEntityReaderTest extends TestCase {

	/**
	 * @dataProvider epub_byte_reader_data_provider
	 */
	public function test_entity_reader( $reader ) {
		$zip      = ZipFilesystem::create( $reader );
		$reader   = new EPubEntityReader( $zip );
		$entities = array();
		while ( $reader->next_entity() ) {
			$data       = $reader->get_entity()->get_data();
			$entities[] = array(
				'type' => $reader->get_entity()->get_type(),
				'data' => $data,
			);
		}
		$this->assertEquals( 3, count( $entities ) );
		$this->assertGreaterThan( 100, strlen( $entities[0]['data']['post_content'] ) );
		$this->assertGreaterThan( 1000, strlen( $entities[1]['data']['post_content'] ) );
		$this->assertGreaterThan( 1000, strlen( $entities[2]['data']['post_content'] ) );
	}

	public static function epub_byte_reader_data_provider() {
		return array(
			'Local file' => array(
				FileReadStream::from_path( __DIR__ . '/fixtures/epub-entity-reader/childrens-literature.epub' ),
			),
			// github.com does not support range requests
			// 'Remote file' => [
			// RemoteFileRangedReader::create( 'https://github.com/IDPF/epub3-samples/releases/download/20230704/childrens-literature.epub' )
			// ],
		);
	}
}
