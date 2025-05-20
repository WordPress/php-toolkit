<?php

use PHPUnit\Framework\TestCase;
use WordPress\DataLiberation\DataFormatConsumer\BlocksWithMetadata;
use WordPress\DataLiberation\DataFormatProducer\AnnotatedBlockMarkupProducer;
use WordPress\DataLiberation\EntityWriter\StaticPostFilesWriter;
use WordPress\DataLiberation\ImportEntity;
use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Markdown\MarkdownProducer;

class StaticPostFilesWriterTest extends TestCase {

	private $static_post_files_writer;
	private $filesystem;

	protected function setUp(): void {
		$this->filesystem = InMemoryFilesystem::create();
	}

	public function testAppendEntityPostWithDateScheme() {
		$this->static_post_files_writer = new StaticPostFilesWriter(
			$this->filesystem,
			array(
				'directory_scheme'                => StaticPostFilesWriter::SCHEME_DATE,
				'file_extension'                  => 'md',
				'static_content_producer_factory' => function ( BlocksWithMetadata $blocks_with_meta ) {
					return new AnnotatedBlockMarkupProducer( $blocks_with_meta );
				},
			)
		);

		$entity = new ImportEntity(
			'post',
			array(
				'post_title' => 'Test Post',
				'post_date'  => '2023-10-01',
				'content'    => '<!-- wp:paragraph -->Test Content<!-- /wp:paragraph -->',
				'post_id'    => '1',
			)
		);
		$this->static_post_files_writer->append_entity( $entity );
		$this->static_post_files_writer->close_writing();

		$expected_path = '/2023/10/01/test-post.md';
		$this->assertTrue( $this->filesystem->is_file( $expected_path ) );
		$this->assertStringContainsString( 'Test Content', $this->filesystem->get_contents( $expected_path ) );
	}

	public function testAppendEntityPostWithParentTrailScheme() {
		$this->static_post_files_writer = new StaticPostFilesWriter(
			$this->filesystem,
			array(
				'directory_scheme'                => StaticPostFilesWriter::SCHEME_PARENT_TRAIL,
				'file_extension'                  => 'md',
				'static_content_producer_factory' => function ( BlocksWithMetadata $blocks_with_meta ) {
					return new MarkdownProducer( $blocks_with_meta );
				},
			)
		);

		$entity = new ImportEntity(
			'post',
			array(
				'post_title' => 'Test Post',
				'post_date'  => '2023-10-01',
				'content'    => '<!-- wp:paragraph -->Test Content<!-- /wp:paragraph -->',
				'post_id'    => '1',
			)
		);
		$this->static_post_files_writer->append_entity( $entity );
		$this->static_post_files_writer->close_writing();

		$expected_path = '/test-post.md';
		$this->assertTrue( $this->filesystem->is_file( $expected_path ) );
		$this->assertStringContainsString( 'Test Content', $this->filesystem->get_contents( $expected_path ) );
	}

	public function testAppendEntityPostWithMetaAndDateScheme() {
		$this->static_post_files_writer = new StaticPostFilesWriter(
			$this->filesystem,
			array(
				'directory_scheme'                => StaticPostFilesWriter::SCHEME_DATE,
				'file_extension'                  => 'md',
				'static_content_producer_factory' => function ( BlocksWithMetadata $blocks_with_meta ) {
					return new MarkdownProducer( $blocks_with_meta );
				},
			)
		);

		$post = new ImportEntity(
			'post',
			array(
				'post_title' => 'Test Post',
				'post_date'  => '2023-10-01',
				'content'    => '<!-- wp:paragraph -->Test Content<!-- /wp:paragraph -->',
				'post_id'    => '1',
			)
		);
		$this->static_post_files_writer->append_entity( $post );

		$post_meta = new ImportEntity(
			'post_meta',
			array(
				'meta_key'   => 'key',
				'meta_value' => 'value',
				'post_id'    => '1',
			)
		);
		$this->static_post_files_writer->append_entity( $post_meta );
		$this->static_post_files_writer->close_writing();

		$expected_path = '/2023/10/01/test-post.md';
		$this->assertTrue( $this->filesystem->is_file( $expected_path ) );
		$file_contents = $this->filesystem->get_contents( $expected_path );
		$this->assertStringContainsString( 'Test Content', $file_contents );
		$this->assertStringContainsString( 'key: "value"', $file_contents );
	}

	/**
	 * @dataProvider contentProducerProvider
	 */
	public function testAppendMultiplePostsWithMetadataAndDateScheme( $file_extension, $content_producer_factory ) {
		$this->static_post_files_writer = new StaticPostFilesWriter(
			$this->filesystem,
			array(
				'directory_scheme'                => StaticPostFilesWriter::SCHEME_PARENT_TRAIL,
				'file_extension'                  => $file_extension,
				'static_content_producer_factory' => $content_producer_factory,
			)
		);

		$structure = array(
			array(
				'post_id'     => 1,
				'post_parent' => 0,
			),
			array(
				'post_id'     => 2,
				'post_parent' => 1,
			),
			array(
				'post_id'     => 3,
				'post_parent' => 2,
			),
			array(
				'post_id'     => 4,
				'post_parent' => 2,
			),
			array(
				'post_id'     => 5,
				'post_parent' => 4,
			),
			array(
				'post_id'     => 6,
				'post_parent' => 4,
			),
			array(
				'post_id'     => 7,
				'post_parent' => 0,
			),
		);

		foreach ( $structure as $post ) {
			$entity = new ImportEntity(
				'post',
				array(
					'post_title'  => 'Post ' . $post['post_id'],
					'post_date'   => '2023-10-01',
					'content'     => '<!-- wp:paragraph -->Content ' . $post['post_id'] . '<!-- /wp:paragraph -->',
					'post_id'     => $post['post_id'],
					'post_parent' => $post['post_parent'],
				)
			);

			$this->static_post_files_writer->append_entity( $entity );
			$entity = new ImportEntity(
				'post_meta',
				array(
					'meta_key'   => 'post_title',
					'meta_value' => 'Post ' . $post['post_id'] . ' title',
				)
			);

			$this->static_post_files_writer->append_entity( $entity );
		}

		$this->static_post_files_writer->close_writing();

		$this->assertTrue( $this->filesystem->is_file( '/post-1/index.' . $file_extension ) );
		$this->assertTrue( $this->filesystem->is_file( '/post-1/post-2/index.' . $file_extension ) );
		$this->assertTrue( $this->filesystem->is_file( '/post-1/post-2/post-3.' . $file_extension ) );
		$this->assertTrue( $this->filesystem->is_file( '/post-1/post-2/post-4/post-5.' . $file_extension ) );
		$this->assertTrue( $this->filesystem->is_file( '/post-1/post-2/post-4/post-6.' . $file_extension ) );
		$this->assertTrue( $this->filesystem->is_file( '/post-7.' . $file_extension ) );

		$this->assertStringContainsString( 'Content 1', $this->filesystem->get_contents( '/post-1/index.' . $file_extension ) );
		$this->assertStringContainsString( 'Content 2', $this->filesystem->get_contents( '/post-1/post-2/index.' . $file_extension ) );
		$this->assertStringContainsString( 'Content 3', $this->filesystem->get_contents( '/post-1/post-2/post-3.' . $file_extension ) );
		$this->assertStringContainsString( 'Content 5',
			$this->filesystem->get_contents( '/post-1/post-2/post-4/post-5.' . $file_extension ) );
		$this->assertStringContainsString( 'Content 6',
			$this->filesystem->get_contents( '/post-1/post-2/post-4/post-6.' . $file_extension ) );
		$this->assertStringContainsString( 'Content 7', $this->filesystem->get_contents( '/post-7.' . $file_extension ) );
	}

	public function contentProducerProvider() {
		return array(
			array(
				'md',
				function ( BlocksWithMetadata $blocks_with_meta ) {
					return new MarkdownProducer( $blocks_with_meta );
				},
			),
			array(
				'html',
				function ( BlocksWithMetadata $blocks_with_meta ) {
					return new AnnotatedBlockMarkupProducer( $blocks_with_meta );
				},
			),
		);
	}
}
