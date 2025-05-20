<?php

use PHPUnit\Framework\TestCase;
use WordPress\ByteStream\MemoryPipe;
use WordPress\DataLiberation\EntityWriter\WXRWriter;
use WordPress\DataLiberation\ImportEntity;

class WXRWriterTest extends TestCase {

	private $wxr_writer;
	private $memory_pipe;

	protected function setUp(): void {
		$this->memory_pipe = new MemoryPipe();
		$this->wxr_writer  = new WXRWriter( $this->memory_pipe );
	}

	public function testAppendEntityPost() {
		$entity = new ImportEntity(
			'post',
			array(
				'post_title'     => 'Test Post',
				'post_date'      => '2023-10-01',
				'guid'           => '12345',
				'description'    => 'Test Description',
				'content'        => 'Test Content',
				'excerpt'        => 'Test Excerpt',
				'post_id'        => '1',
				'post_date_gmt'  => '2023-10-01T00:00:00',
				'comment_status' => 'open',
				'ping_status'    => 'open',
				'post_name'      => 'test-post',
				'status'         => 'publish',
				'post_parent'    => '0',
				'menu_order'     => '0',
				'post_type'      => 'post',
				'post_password'  => '',
				'is_sticky'      => '0',
			)
		);
		$this->wxr_writer->append_entity( $entity );
		$this->wxr_writer->finalize();
		$this->wxr_writer->close_writing();
		$this->memory_pipe->close_writing();

		$expected = <<<XML
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wfw="http://wellformedweb.org/CommentAPI/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
<item>
<title>Test Post</title>
<pubDate>2023-10-01</pubDate>
<guid>12345</guid>
<description>Test Description</description>
<content:encoded>Test Content</content:encoded>
<excerpt:encoded>Test Excerpt</excerpt:encoded>
<wp:post_id>1</wp:post_id>
<wp:post_date>2023-10-01</wp:post_date>
<wp:post_date_gmt>2023-10-01T00:00:00</wp:post_date_gmt>
<wp:comment_status>open</wp:comment_status>
<wp:ping_status>open</wp:ping_status>
<wp:post_name>test-post</wp:post_name>
<wp:status>publish</wp:status>
<wp:post_parent>0</wp:post_parent>
<wp:menu_order>0</wp:menu_order>
<wp:post_type>post</wp:post_type>
<wp:post_password></wp:post_password>
<wp:is_sticky>0</wp:is_sticky>
</item>
</channel>
</rss>

XML;
		$this->assertEquals( $expected, $this->memory_pipe->consume_all() );
	}

	public function testAppendEntityPostWithMetaTermsComments() {
		$post = new ImportEntity(
			'post',
			array(
				'post_title'     => 'Test Post',
				'post_date'      => '2023-10-01',
				'guid'           => '12345',
				'description'    => 'Test Description',
				'content'        => 'Test Content',
				'excerpt'        => 'Test Excerpt',
				'post_id'        => '1',
				'post_date_gmt'  => '2023-10-01T00:00:00',
				'comment_status' => 'open',
				'ping_status'    => 'open',
				'post_name'      => 'test-post',
				'status'         => 'publish',
				'post_parent'    => '0',
				'menu_order'     => '0',
				'post_type'      => 'post',
				'post_password'  => '',
				'is_sticky'      => '0',
			)
		);
		$this->wxr_writer->append_entity( $post );

		$post_meta = new ImportEntity(
			'post_meta',
			array(
				'meta_key'   => 'key',
				'meta_value' => 'value',
			)
		);
		$this->wxr_writer->append_entity( $post_meta );

		$term = new ImportEntity(
			'term',
			array(
				'term_id'  => '1',
				'taxonomy' => 'category',
				'slug'     => 'test-term',
				'parent'   => '0',
			)
		);
		$this->wxr_writer->append_entity( $term );

		$comment = new ImportEntity(
			'comment',
			array(
				'comment_id'           => '1',
				'comment_author'       => 'Author',
				'comment_author_email' => 'author@example.com',
				'comment_author_url'   => 'http://example.com',
				'comment_author_IP'    => '127.0.0.1',
				'comment_date'         => '2023-10-01',
				'comment_date_gmt'     => '2023-10-01T00:00:00',
				'comment_content'      => 'Content',
				'comment_approved'     => '1',
				'comment_type'         => '',
				'comment_parent'       => '0',
				'comment_user_id'      => '1',
			)
		);
		$this->wxr_writer->append_entity( $comment );

		$this->wxr_writer->finalize();
		$this->wxr_writer->close_writing();
		$this->memory_pipe->close_writing();

		$expected = <<<XML
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wfw="http://wellformedweb.org/CommentAPI/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
<item>
<title>Test Post</title>
<pubDate>2023-10-01</pubDate>
<guid>12345</guid>
<description>Test Description</description>
<content:encoded>Test Content</content:encoded>
<excerpt:encoded>Test Excerpt</excerpt:encoded>
<wp:post_id>1</wp:post_id>
<wp:post_date>2023-10-01</wp:post_date>
<wp:post_date_gmt>2023-10-01T00:00:00</wp:post_date_gmt>
<wp:comment_status>open</wp:comment_status>
<wp:ping_status>open</wp:ping_status>
<wp:post_name>test-post</wp:post_name>
<wp:status>publish</wp:status>
<wp:post_parent>0</wp:post_parent>
<wp:menu_order>0</wp:menu_order>
<wp:post_type>post</wp:post_type>
<wp:post_password></wp:post_password>
<wp:is_sticky>0</wp:is_sticky>
<wp:postmeta>
<wp:meta_key>key</wp:meta_key>
<wp:meta_value>value</wp:meta_value>
</wp:postmeta>
<wp:term>
<wp:term_id>1</wp:term_id>
<wp:term_taxonomy>category</wp:term_taxonomy>
<wp:term_slug>test-term</wp:term_slug>
<wp:term_parent>0</wp:term_parent>
</wp:term>
<wp:comments>
<wp:comment>
<wp:comment_id>1</wp:comment_id>
<wp:comment_author>Author</wp:comment_author>
<wp:comment_author_email>author@example.com</wp:comment_author_email>
<wp:comment_author_url>http://example.com</wp:comment_author_url>
<wp:comment_author_IP>127.0.0.1</wp:comment_author_IP>
<wp:comment_date>2023-10-01</wp:comment_date>
<wp:comment_date_gmt>2023-10-01T00:00:00</wp:comment_date_gmt>
<wp:comment_content>Content</wp:comment_content>
<wp:comment_approved>1</wp:comment_approved>
<wp:comment_type></wp:comment_type>
<wp:comment_parent>0</wp:comment_parent>
<wp:comment_user_id>1</wp:comment_user_id>
</wp:comment>
</wp:comments>
</item>
</channel>
</rss>

XML;
		$this->assertEquals( $expected, $this->memory_pipe->consume_all() );
	}

	public function testAppendMultiplePostsWithComments() {
		$post1 = new ImportEntity(
			'post',
			array(
				'post_title'     => 'Post 1',
				'post_date'      => '2023-10-01',
				'guid'           => '12345',
				'description'    => 'Description 1',
				'content'        => 'Content 1',
				'excerpt'        => 'Excerpt 1',
				'post_id'        => '1',
				'post_date_gmt'  => '2023-10-01T00:00:00',
				'comment_status' => 'open',
				'ping_status'    => 'open',
				'post_name'      => 'post-1',
				'status'         => 'publish',
				'post_parent'    => '0',
				'menu_order'     => '0',
				'post_type'      => 'post',
				'post_password'  => '',
				'is_sticky'      => '0',
			)
		);
		$this->wxr_writer->append_entity( $post1 );

		$post2 = new ImportEntity(
			'post',
			array(
				'post_title'     => 'Post 2',
				'post_date'      => '2023-10-02',
				'guid'           => '12346',
				'description'    => 'Description 2',
				'content'        => 'Content 2',
				'excerpt'        => 'Excerpt 2',
				'post_id'        => '2',
				'post_date_gmt'  => '2023-10-02T00:00:00',
				'comment_status' => 'open',
				'ping_status'    => 'open',
				'post_name'      => 'post-2',
				'status'         => 'publish',
				'post_parent'    => '0',
				'menu_order'     => '0',
				'post_type'      => 'post',
				'post_password'  => '',
				'is_sticky'      => '0',
			)
		);
		$this->wxr_writer->append_entity( $post2 );

		$comment1 = new ImportEntity(
			'comment',
			array(
				'comment_id'           => '1',
				'comment_author'       => 'Author 1',
				'comment_author_email' => 'author1@example.com',
				'comment_author_url'   => 'http://example.com',
				'comment_author_IP'    => '127.0.0.1',
				'comment_date'         => '2023-10-02',
				'comment_date_gmt'     => '2023-10-02T00:00:00',
				'comment_content'      => 'Content 1',
				'comment_approved'     => '1',
				'comment_type'         => '',
				'comment_parent'       => '0',
				'comment_user_id'      => '1',
			)
		);
		$this->wxr_writer->append_entity( $comment1 );

		$comment2 = new ImportEntity(
			'comment',
			array(
				'comment_id'           => '2',
				'comment_author'       => 'Author 2',
				'comment_author_email' => 'author2@example.com',
				'comment_author_url'   => 'http://example.com',
				'comment_author_IP'    => '127.0.0.1',
				'comment_date'         => '2023-10-02',
				'comment_date_gmt'     => '2023-10-02T00:00:00',
				'comment_content'      => 'Content 2',
				'comment_approved'     => '1',
				'comment_type'         => '',
				'comment_parent'       => '0',
				'comment_user_id'      => '1',
			)
		);
		$this->wxr_writer->append_entity( $comment2 );

		$comment3 = new ImportEntity(
			'comment',
			array(
				'comment_id'           => '3',
				'comment_author'       => 'Author 3',
				'comment_author_email' => 'author3@example.com',
				'comment_author_url'   => 'http://example.com',
				'comment_author_IP'    => '127.0.0.1',
				'comment_date'         => '2023-10-02',
				'comment_date_gmt'     => '2023-10-02T00:00:00',
				'comment_content'      => 'Content 3',
				'comment_approved'     => '1',
				'comment_type'         => '',
				'comment_parent'       => '0',
				'comment_user_id'      => '1',
			)
		);
		$this->wxr_writer->append_entity( $comment3 );

		$post3 = new ImportEntity(
			'post',
			array(
				'post_title'     => 'Post 3',
				'post_date'      => '2023-10-03',
				'guid'           => '12347',
				'description'    => 'Description 3',
				'content'        => 'Content 3',
				'excerpt'        => 'Excerpt 3',
				'post_id'        => '3',
				'post_date_gmt'  => '2023-10-03T00:00:00',
				'comment_status' => 'open',
				'ping_status'    => 'open',
				'post_name'      => 'post-3',
				'status'         => 'publish',
				'post_parent'    => '0',
				'menu_order'     => '0',
				'post_type'      => 'post',
				'post_password'  => '',
				'is_sticky'      => '0',
			)
		);
		$this->wxr_writer->append_entity( $post3 );

		$comment4 = new ImportEntity(
			'comment',
			array(
				'comment_id'           => '4',
				'comment_author'       => 'Author 4',
				'comment_author_email' => 'author4@example.com',
				'comment_author_url'   => 'http://example.com',
				'comment_author_IP'    => '127.0.0.1',
				'comment_date'         => '2023-10-03',
				'comment_date_gmt'     => '2023-10-03T00:00:00',
				'comment_content'      => 'Content 4',
				'comment_approved'     => '1',
				'comment_type'         => '',
				'comment_parent'       => '0',
				'comment_user_id'      => '1',
			)
		);
		$this->wxr_writer->append_entity( $comment4 );

		$this->wxr_writer->finalize();
		$this->wxr_writer->close_writing();
		$this->memory_pipe->close_writing();

		$expected = <<<XML
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wfw="http://wellformedweb.org/CommentAPI/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
<item>
<title>Post 1</title>
<pubDate>2023-10-01</pubDate>
<guid>12345</guid>
<description>Description 1</description>
<content:encoded>Content 1</content:encoded>
<excerpt:encoded>Excerpt 1</excerpt:encoded>
<wp:post_id>1</wp:post_id>
<wp:post_date>2023-10-01</wp:post_date>
<wp:post_date_gmt>2023-10-01T00:00:00</wp:post_date_gmt>
<wp:comment_status>open</wp:comment_status>
<wp:ping_status>open</wp:ping_status>
<wp:post_name>post-1</wp:post_name>
<wp:status>publish</wp:status>
<wp:post_parent>0</wp:post_parent>
<wp:menu_order>0</wp:menu_order>
<wp:post_type>post</wp:post_type>
<wp:post_password></wp:post_password>
<wp:is_sticky>0</wp:is_sticky>
</item>
<item>
<title>Post 2</title>
<pubDate>2023-10-02</pubDate>
<guid>12346</guid>
<description>Description 2</description>
<content:encoded>Content 2</content:encoded>
<excerpt:encoded>Excerpt 2</excerpt:encoded>
<wp:post_id>2</wp:post_id>
<wp:post_date>2023-10-02</wp:post_date>
<wp:post_date_gmt>2023-10-02T00:00:00</wp:post_date_gmt>
<wp:comment_status>open</wp:comment_status>
<wp:ping_status>open</wp:ping_status>
<wp:post_name>post-2</wp:post_name>
<wp:status>publish</wp:status>
<wp:post_parent>0</wp:post_parent>
<wp:menu_order>0</wp:menu_order>
<wp:post_type>post</wp:post_type>
<wp:post_password></wp:post_password>
<wp:is_sticky>0</wp:is_sticky>
<wp:comments>
<wp:comment>
<wp:comment_id>1</wp:comment_id>
<wp:comment_author>Author 1</wp:comment_author>
<wp:comment_author_email>author1@example.com</wp:comment_author_email>
<wp:comment_author_url>http://example.com</wp:comment_author_url>
<wp:comment_author_IP>127.0.0.1</wp:comment_author_IP>
<wp:comment_date>2023-10-02</wp:comment_date>
<wp:comment_date_gmt>2023-10-02T00:00:00</wp:comment_date_gmt>
<wp:comment_content>Content 1</wp:comment_content>
<wp:comment_approved>1</wp:comment_approved>
<wp:comment_type></wp:comment_type>
<wp:comment_parent>0</wp:comment_parent>
<wp:comment_user_id>1</wp:comment_user_id>
</wp:comment>
<wp:comment>
<wp:comment_id>2</wp:comment_id>
<wp:comment_author>Author 2</wp:comment_author>
<wp:comment_author_email>author2@example.com</wp:comment_author_email>
<wp:comment_author_url>http://example.com</wp:comment_author_url>
<wp:comment_author_IP>127.0.0.1</wp:comment_author_IP>
<wp:comment_date>2023-10-02</wp:comment_date>
<wp:comment_date_gmt>2023-10-02T00:00:00</wp:comment_date_gmt>
<wp:comment_content>Content 2</wp:comment_content>
<wp:comment_approved>1</wp:comment_approved>
<wp:comment_type></wp:comment_type>
<wp:comment_parent>0</wp:comment_parent>
<wp:comment_user_id>1</wp:comment_user_id>
</wp:comment>
<wp:comment>
<wp:comment_id>3</wp:comment_id>
<wp:comment_author>Author 3</wp:comment_author>
<wp:comment_author_email>author3@example.com</wp:comment_author_email>
<wp:comment_author_url>http://example.com</wp:comment_author_url>
<wp:comment_author_IP>127.0.0.1</wp:comment_author_IP>
<wp:comment_date>2023-10-02</wp:comment_date>
<wp:comment_date_gmt>2023-10-02T00:00:00</wp:comment_date_gmt>
<wp:comment_content>Content 3</wp:comment_content>
<wp:comment_approved>1</wp:comment_approved>
<wp:comment_type></wp:comment_type>
<wp:comment_parent>0</wp:comment_parent>
<wp:comment_user_id>1</wp:comment_user_id>
</wp:comment>
</wp:comments>
</item>
<item>
<title>Post 3</title>
<pubDate>2023-10-03</pubDate>
<guid>12347</guid>
<description>Description 3</description>
<content:encoded>Content 3</content:encoded>
<excerpt:encoded>Excerpt 3</excerpt:encoded>
<wp:post_id>3</wp:post_id>
<wp:post_date>2023-10-03</wp:post_date>
<wp:post_date_gmt>2023-10-03T00:00:00</wp:post_date_gmt>
<wp:comment_status>open</wp:comment_status>
<wp:ping_status>open</wp:ping_status>
<wp:post_name>post-3</wp:post_name>
<wp:status>publish</wp:status>
<wp:post_parent>0</wp:post_parent>
<wp:menu_order>0</wp:menu_order>
<wp:post_type>post</wp:post_type>
<wp:post_password></wp:post_password>
<wp:is_sticky>0</wp:is_sticky>
<wp:comments>
<wp:comment>
<wp:comment_id>4</wp:comment_id>
<wp:comment_author>Author 4</wp:comment_author>
<wp:comment_author_email>author4@example.com</wp:comment_author_email>
<wp:comment_author_url>http://example.com</wp:comment_author_url>
<wp:comment_author_IP>127.0.0.1</wp:comment_author_IP>
<wp:comment_date>2023-10-03</wp:comment_date>
<wp:comment_date_gmt>2023-10-03T00:00:00</wp:comment_date_gmt>
<wp:comment_content>Content 4</wp:comment_content>
<wp:comment_approved>1</wp:comment_approved>
<wp:comment_type></wp:comment_type>
<wp:comment_parent>0</wp:comment_parent>
<wp:comment_user_id>1</wp:comment_user_id>
</wp:comment>
</wp:comments>
</item>
</channel>
</rss>

XML;
		$this->assertEquals( $expected, $this->memory_pipe->consume_all() );
	}

	public function testCloseWriting() {
		$this->wxr_writer->finalize();
		$this->wxr_writer->close_writing();
		$this->memory_pipe->close_writing();

		$expected = <<<XML
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wfw="http://wellformedweb.org/CommentAPI/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
</channel>
</rss>

XML;
		$this->assertEquals( $expected, $this->memory_pipe->consume_all() );
	}

	public function testGetReentrancyCursor() {
		$entity = new ImportEntity( 'post', array( 'post_title' => 'Test Post' ) );
		$this->wxr_writer->append_entity( $entity );
		$cursor = $this->wxr_writer->get_reentrancy_cursor();
		$this->assertJson( $cursor );
		$this->assertEquals( json_encode( array( 'open_tags' => array( 'item' ) ) ), $cursor );
	}

	public function testPauseAndResumeWritingWithReentrancyCursor() {
		// Start writing the first post
		$post1 = new ImportEntity(
			'post',
			array(
				'post_title'     => 'Post 1',
				'post_date'      => '2023-10-01',
				'guid'           => '12345',
				'description'    => 'Description 1',
				'content'        => 'Content 1',
				'excerpt'        => 'Excerpt 1',
				'post_id'        => '1',
				'post_date_gmt'  => '2023-10-01T00:00:00',
				'comment_status' => 'open',
				'ping_status'    => 'open',
				'post_name'      => 'post-1',
				'status'         => 'publish',
				'post_parent'    => '0',
				'menu_order'     => '0',
				'post_type'      => 'post',
				'post_password'  => '',
				'is_sticky'      => '0',
			)
		);
		$this->wxr_writer->append_entity( $post1 );

		// Get the reentrancy cursor
		$cursor = $this->wxr_writer->get_reentrancy_cursor();

		// Close the current writer
		$this->wxr_writer->close_writing();

		// Create a new writer with the same memory pipe and resume from the cursor
		$this->wxr_writer = new WXRWriter( $this->memory_pipe, $cursor );

		// Append a second post
		$post2 = new ImportEntity(
			'post',
			array(
				'post_title'     => 'Post 2',
				'post_date'      => '2023-10-02',
				'guid'           => '12346',
				'description'    => 'Description 2',
				'content'        => 'Content 2',
				'excerpt'        => 'Excerpt 2',
				'post_id'        => '2',
				'post_date_gmt'  => '2023-10-02T00:00:00',
				'comment_status' => 'open',
				'ping_status'    => 'open',
				'post_name'      => 'post-2',
				'status'         => 'publish',
				'post_parent'    => '0',
				'menu_order'     => '0',
				'post_type'      => 'post',
				'post_password'  => '',
				'is_sticky'      => '0',
			)
		);
		$this->wxr_writer->append_entity( $post2 );

		// Close writing
		$this->wxr_writer->finalize();
		$this->wxr_writer->close_writing();
		$this->memory_pipe->close_writing();

		// Expected XML output
		$expected = <<<XML
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wfw="http://wellformedweb.org/CommentAPI/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
<item>
<title>Post 1</title>
<pubDate>2023-10-01</pubDate>
<guid>12345</guid>
<description>Description 1</description>
<content:encoded>Content 1</content:encoded>
<excerpt:encoded>Excerpt 1</excerpt:encoded>
<wp:post_id>1</wp:post_id>
<wp:post_date>2023-10-01</wp:post_date>
<wp:post_date_gmt>2023-10-01T00:00:00</wp:post_date_gmt>
<wp:comment_status>open</wp:comment_status>
<wp:ping_status>open</wp:ping_status>
<wp:post_name>post-1</wp:post_name>
<wp:status>publish</wp:status>
<wp:post_parent>0</wp:post_parent>
<wp:menu_order>0</wp:menu_order>
<wp:post_type>post</wp:post_type>
<wp:post_password></wp:post_password>
<wp:is_sticky>0</wp:is_sticky>
</item>
<item>
<title>Post 2</title>
<pubDate>2023-10-02</pubDate>
<guid>12346</guid>
<description>Description 2</description>
<content:encoded>Content 2</content:encoded>
<excerpt:encoded>Excerpt 2</excerpt:encoded>
<wp:post_id>2</wp:post_id>
<wp:post_date>2023-10-02</wp:post_date>
<wp:post_date_gmt>2023-10-02T00:00:00</wp:post_date_gmt>
<wp:comment_status>open</wp:comment_status>
<wp:ping_status>open</wp:ping_status>
<wp:post_name>post-2</wp:post_name>
<wp:status>publish</wp:status>
<wp:post_parent>0</wp:post_parent>
<wp:menu_order>0</wp:menu_order>
<wp:post_type>post</wp:post_type>
<wp:post_password></wp:post_password>
<wp:is_sticky>0</wp:is_sticky>
</item>
</channel>
</rss>

XML;
		$this->assertEquals( $expected, $this->memory_pipe->consume_all() );
	}
}
