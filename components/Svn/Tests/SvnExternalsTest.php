<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WordPress\Svn\SvnException;

use function WordPress\Svn\svn_parse_externals;
use function WordPress\Svn\svn_resolve_url;

class SvnExternalsTest extends TestCase {
	const DIR_URL  = 'https://example.com/repo/trunk/vendor';
	const ROOT_URL = 'https://example.com/repo';

	public function test_modern_format_url_then_target() {
		$externals = svn_parse_externals( 'https://other.example.com/lib lib', self::DIR_URL, self::ROOT_URL );
		$this->assertSame(
			array(
				array(
					'url'      => 'https://other.example.com/lib',
					'target'   => 'lib',
					'revision' => null,
				),
			),
			$externals
		);
	}

	public function test_historical_format_target_then_url() {
		$externals = svn_parse_externals( 'lib https://other.example.com/lib', self::DIR_URL, self::ROOT_URL );
		$this->assertSame( 'lib', $externals[0]['target'] );
		$this->assertSame( 'https://other.example.com/lib', $externals[0]['url'] );
	}

	public function test_operative_revision_with_space() {
		$externals = svn_parse_externals( '-r 42 https://other.example.com/lib lib', self::DIR_URL, self::ROOT_URL );
		$this->assertSame( 42, $externals[0]['revision'] );
	}

	public function test_operative_revision_without_space() {
		$externals = svn_parse_externals( '-r42 https://other.example.com/lib lib', self::DIR_URL, self::ROOT_URL );
		$this->assertSame( 42, $externals[0]['revision'] );
	}

	public function test_historical_format_with_revision() {
		$externals = svn_parse_externals( 'lib -r 7 https://other.example.com/lib', self::DIR_URL, self::ROOT_URL );
		$this->assertSame( 7, $externals[0]['revision'] );
		$this->assertSame( 'lib', $externals[0]['target'] );
	}

	public function test_peg_revision_pins_the_external() {
		$externals = svn_parse_externals( 'https://other.example.com/lib@99 lib', self::DIR_URL, self::ROOT_URL );
		$this->assertSame( 99, $externals[0]['revision'] );
		$this->assertSame( 'https://other.example.com/lib', $externals[0]['url'] );
	}

	public function test_operative_revision_wins_over_peg() {
		$externals = svn_parse_externals( '-r 7 https://other.example.com/lib@99 lib', self::DIR_URL, self::ROOT_URL );
		$this->assertSame( 7, $externals[0]['revision'] );
	}

	public function test_repository_root_relative_url() {
		$externals = svn_parse_externals( '^/tags/1.0 stable', self::DIR_URL, self::ROOT_URL );
		$this->assertSame( 'https://example.com/repo/tags/1.0', $externals[0]['url'] );
	}

	public function test_parent_directory_relative_url() {
		$externals = svn_parse_externals( '../sibling lib', self::DIR_URL, self::ROOT_URL );
		$this->assertSame( 'https://example.com/repo/trunk/sibling', $externals[0]['url'] );
	}

	public function test_scheme_relative_url() {
		$externals = svn_parse_externals( '//cdn.example.com/assets assets', self::DIR_URL, self::ROOT_URL );
		$this->assertSame( 'https://cdn.example.com/assets', $externals[0]['url'] );
	}

	public function test_server_root_relative_url() {
		$externals = svn_parse_externals( '/other-repo/trunk other', self::DIR_URL, self::ROOT_URL );
		$this->assertSame( 'https://example.com/other-repo/trunk', $externals[0]['url'] );
	}

	public function test_quoted_target_with_spaces() {
		$externals = svn_parse_externals( 'https://other.example.com/lib "my lib"', self::DIR_URL, self::ROOT_URL );
		$this->assertSame( 'my lib', $externals[0]['target'] );
	}

	public function test_multiple_lines_with_comments_and_blanks() {
		$value     = "# vendored libraries\n\nhttps://a.example.com/x x\n   \nhttps://b.example.com/y y\n";
		$externals = svn_parse_externals( $value, self::DIR_URL, self::ROOT_URL );
		$this->assertCount( 2, $externals );
		$this->assertSame( 'x', $externals[0]['target'] );
		$this->assertSame( 'y', $externals[1]['target'] );
	}

	public function test_rejects_target_escaping_the_directory() {
		$this->expectException( SvnException::class );
		svn_parse_externals( 'https://other.example.com/lib ../../etc', self::DIR_URL, self::ROOT_URL );
	}

	public function test_rejects_line_without_url() {
		$this->expectException( SvnException::class );
		svn_parse_externals( 'one two', self::DIR_URL, self::ROOT_URL );
	}

	public function test_rejects_relative_url_escaping_the_server() {
		$this->expectException( SvnException::class );
		svn_parse_externals( '../../../../../up lib', self::DIR_URL, self::ROOT_URL );
	}

	public function test_resolve_url_keeps_absolute_urls() {
		$this->assertSame(
			'svn://example.org/repo',
			svn_resolve_url( 'svn://example.org/repo/', self::DIR_URL, self::ROOT_URL )
		);
	}

	public function test_resolve_url_handles_ports() {
		$this->assertSame(
			'http://example.com:8080/repo/tags',
			svn_resolve_url( '^/tags', 'http://example.com:8080/repo/trunk', 'http://example.com:8080/repo' )
		);
	}
}
