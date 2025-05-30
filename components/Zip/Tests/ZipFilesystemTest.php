<?php

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\HttpClient\ByteStream\SeekableRequestReadStream;
use WordPress\HttpClient\Client;
use WordPress\Zip\ZipFilesystem;

use function WordPress\Filesystem\wp_join_unix_paths;

class ZipFilesystemTest extends TestCase {

	/**
	 * @var ZipFilesystem
	 */
	private $fs;

	protected function setUp(): void {
		$this->fs = ZipFilesystem::create( FileReadStream::from_path( __DIR__ . '/fixtures/childrens-literature.zip' ) );
	}

	public function testLs() {
		$this->assertEquals(
			array(
				'mimetype',
				'EPUB',
				'META-INF',
			),
			$this->fs->ls()
		);

		$this->assertEquals(
			array(
				'cover.xhtml',
				'css',
				'images',
				'nav.xhtml',
				'package.opf',
				's04.xhtml',
				'toc.ncx',
			),
			$this->fs->ls( '/EPUB' )
		);
	}

	public function testReadFile() {
		$this->assertEquals( 'application/epub+zip', $this->fs->get_contents( 'mimetype' ) );

		$cover = $this->fs->get_contents( 'EPUB/cover.xhtml' );
		$this->assertStringStartsWith( '<?xml version="1.0" encoding="UTF-8"?>', $cover );

		// Read the mimetype file again to ensure ZIPFilesystem can move back and forth between files.
		$mimetype = $this->fs->get_contents( 'mimetype' );
		$this->assertEquals( 'application/epub+zip', $mimetype );
	}

	/**
	 * @dataProvider chunkedEncodingProvider
	 */
	public function testReadRemoteZip( $chunked ) {
		$this->withServer( function ( $url ) use ( $chunked ) {
			$zip = ZipFilesystem::create(
				new SeekableRequestReadStream(
					"$url/childrens-literature.zip?chunked=$chunked",
					[ 'client' => new Client() ]
				)
			);
			$this->assertEquals(
				[ 'mimetype', 'EPUB', 'META-INF' ],
				$zip->ls()
			);
		} );
	}

	static function chunkedEncodingProvider() {
		return [
			[ 'yes' ],
			[ 'no' ],
		];
	}

	private function withServer( callable $callback, $host = '127.0.0.1', $port = 8940 ) {
		$test_server_root = wp_join_unix_paths( __DIR__, 'test-server' );
		$server           = new Process( [
			'php',
			wp_join_unix_paths( $test_server_root, 'run.php' ),
			$host,
			$port,
		], $test_server_root );
		$server->start();
		try {
			$attempts = 0;
			while ( $server->isRunning() ) {
				$output = $server->getIncrementalOutput();
				if ( strncmp( $output, 'Server started on http://', strlen( 'Server started on http://' ) ) === 0 ) {
					break;
				}
				usleep( 40000 );
				if ( ++ $attempts > 10 ) {
					$this->fail( 'Server did not start' );
				}
			}
			$callback( "http://{$host}:{$port}" );
		} finally {
			$server->stop( 0 );
		}
	}
}
