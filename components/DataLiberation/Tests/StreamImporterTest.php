<?php

use PHPUnit\Framework\TestCase;
use WordPress\Blueprints\DataReference\DataReference;
use WordPress\DataLiberation\EntityReader\WXREntityReader;
use WordPress\DataLiberation\Importer\StreamImporter;
use WordPress\HttpClient\ByteStream\RequestReadStream;
use WordPress\HttpClient\ByteStream\SeekableRequestReadStream;
use WordPress\HttpClient\Request;

/**
 * Tests for the WPStreamImporter class.
 */
class StreamImporterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! isset( $_SERVER['SERVER_SOFTWARE'] ) || $_SERVER['SERVER_SOFTWARE'] !== 'PHP.wasm' ) {
			// $this->markTestSkipped( 'Test only runs in Playground' );
		}
	}

	/**
	 * @before
	 *
	 * TODO: Run each test in a fresh Playground instance instead of sharing the global
	 * state like this.
	 */
	public function clean_up_uploads(): void {
		$files = glob( '/wordpress/wp-content/uploads/*' );
		foreach ( $files as $file ) {
			if ( is_dir( $file ) ) {
				array_map( 'unlink', glob( "$file/*.*" ) );
				rmdir( $file );
			} else {
				unlink( $file );
			}
		}
	}

	public function test_import_simple_wxr() {
		$import = data_liberation_import( __DIR__ . '/wxr/small-export.xml' );

		$this->assertTrue( $import );
	}

	/**
	 *
	 */
	public function test_stylish_press_local_file() {
		$uploads_path = sys_get_temp_dir() . '/uploads';
		@mkdir( $uploads_path, 0777, true );

		$sink = new class() {
			public $imported_entities = [];
			public $imported_attachments = [];

			public function import_entity( $entity ) {
				$this->imported_entities[] = $entity;
				return true;
			}
			public function import_attachment( $filepath, $post_id = null ) {
				$this->imported_attachments[] = $filepath;
				return true;
			}
		};

		$importer = StreamImporter::create_for_wxr_file( __DIR__ . '/wxr/stylish-press.xml', [
			'new_site_content_root_url' => 'http://127.0.0.1:9400',
			'new_media_root_url' => 'http://127.0.0.1:9400/wp-content/uploads',
			'uploads_path' => $uploads_path,
			'entity_sink' => $sink
		] );
		while ( $importer->next_step() || $importer->advance_to_next_stage() ) {
			// noop
		}
		$this->assertCount( 10, $sink->imported_entities );
	}

	/**
	 *
	 */
	public function test_stylish_press_remote_stream() {
		$uploads_path = sys_get_temp_dir() . '/uploads';
		@mkdir( $uploads_path, 0777, true );

		$sink = new class() {
			public $imported_entities = [];
			public $imported_attachments = [];

			public function import_entity( $entity ) {
				$this->imported_entities[] = $entity;
				return true;
			}
			public function import_attachment( $filepath, $post_id = null ) {
				$this->imported_attachments[] = $filepath;
				return true;
			}
		};

		$entity_reader_factory = function ( $cursor ) {
			$stream = new RequestReadStream(new Request(
				'https://raw.githubusercontent.com/wordpress/blueprints/trunk/blueprints/stylish-press/site-content.wxr'
			));
			return WXREntityReader::create(
				$stream,
				$cursor
			);
		};

		$importer = StreamImporter::create( $entity_reader_factory, [
			'new_site_content_root_url' => 'http://127.0.0.1:9400',
			'new_media_root_url' => 'http://127.0.0.1:9400/wp-content/uploads',
			'uploads_path' => $uploads_path,
			'entity_sink' => $sink
		] );
		while ( $importer->next_step() || $importer->advance_to_next_stage() ) {
			// noop
		}
		$this->assertCount( 10, $sink->imported_entities );
	}

	public function test_frontloading() {
		$wxr_path = __DIR__ . '/wxr/frontloading-1-attachment.xml';
		$importer = StreamImporter::create_for_wxr_file( $wxr_path );
		$this->skip_to_stage( $importer, StreamImporter::STAGE_FRONTLOAD_ASSETS );
		while ( $importer->next_step() ) {
			// noop
		}
		$files = glob( '/wordpress/wp-content/uploads/*' );
		$this->assertCount( 1, $files );
		$this->assertStringEndsWith( '.jpg', $files[0] );
	}

	public function test_resume_frontloading() {
		$wxr_path = __DIR__ . '/wxr/frontloading-1-attachment.xml';
		$importer = StreamImporter::create_for_wxr_file( $wxr_path );
		$this->skip_to_stage( $importer, StreamImporter::STAGE_FRONTLOAD_ASSETS );

		$progress_url   = null;
		$progress_value = null;
		for ( $i = 0; $i < 20; ++ $i ) {
			$importer->next_step();
			$progress = $importer->get_frontloading_progress();
			if ( count( $progress ) === 0 ) {
				continue;
			}
			$progress_url   = array_keys( $progress )[0];
			$progress_value = array_values( $progress )[0];
			if ( null === $progress_value['received'] ) {
				continue;
			}
			break;
		}

		$this->assertIsArray( $progress_value );
		$this->assertIsInt( $progress_value['received'] );
		$this->assertEquals( 'https://wpthemetestdata.files.wordpress.com/2008/06/canola2.jpg', $progress_url );
		$this->assertGreaterThan( 0, $progress_value['total'] );

		$cursor   = $importer->get_reentrancy_cursor();
		$importer = StreamImporter::create_for_wxr_file( $wxr_path, array(), $cursor );
		// Rewind back to the entity we were on.
		$this->assertTrue( $importer->next_step() );

		// Restart the download of the same entity – from scratch.
		$progress_value = array();
		for ( $i = 0; $i < 20; ++ $i ) {
			$importer->next_step();
			$progress = $importer->get_frontloading_progress();
			if ( count( $progress ) === 0 ) {
				continue;
			}
			$progress_url   = array_keys( $progress )[0];
			$progress_value = array_values( $progress )[0];
			if ( null === $progress_value['received'] ) {
				continue;
			}
			break;
		}

		$this->assertIsInt( $progress_value['received'] );
		$this->assertEquals( 'https://wpthemetestdata.files.wordpress.com/2008/06/canola2.jpg', $progress_url );
		$this->assertGreaterThan( 0, $progress_value['total'] );
	}

	/**
	 *
	 */
	public function test_resume_entity_import() {
		$wxr_path = __DIR__ . '/wxr/entities-options-and-posts.xml';
		$importer = StreamImporter::create_for_wxr_file( $wxr_path );
		$this->skip_to_stage( $importer, StreamImporter::STAGE_IMPORT_ENTITIES );

		for ( $i = 0; $i < 11; ++ $i ) {
			$this->assertTrue( $importer->next_step() );
			$cursor   = $importer->get_reentrancy_cursor();
			$importer = StreamImporter::create_for_wxr_file( $wxr_path, array(), $cursor );
			// Rewind back to the entity we were on.
			// Note this means we may attempt to insert it twice. It's
			// the importer's job to detect that and skip the duplicate
			// insertion.
			$this->assertTrue( $importer->next_step() );
		}
		$this->assertFalse( $importer->next_step() );
	}

	private function skip_to_stage( StreamImporter $importer, string $stage ) {
		do {
			while ( $importer->next_step() ) {
				// noop
			}
			if ( $importer->get_next_stage() === $stage ) {
				break;
			}
		} while ( $importer->advance_to_next_stage() );
		$this->assertEquals( $stage, $importer->get_next_stage() );
		$this->assertTrue( $importer->advance_to_next_stage() );
	}
}
