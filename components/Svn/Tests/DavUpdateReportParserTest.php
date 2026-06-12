<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WordPress\Svn\Protocol\DavUpdateReportParser;
use WordPress\Svn\SvnException;
use WordPress\Svn\Tests\RecordingEditor;

require_once __DIR__ . '/RecordingEditor.php';

class DavUpdateReportParserTest extends TestCase {
	public function test_parses_checkout_report_captured_from_mod_dav_svn() {
		$editor = new RecordingEditor();
		$parser = new DavUpdateReportParser( $editor );
		$parser->append_bytes( file_get_contents( __DIR__ . '/fixtures/update-report-checkout.xml' ) );
		$parser->finish();

		$this->assertSame( 2, $editor->target_revision );
		$this->assertContains( 'open-root', $editor->events );
		$this->assertContains( 'add-directory: sub', $editor->events );
		$this->assertContains( 'add-directory: sub/deep', $editor->events );
		$this->assertContains( 'add-file: sub/deep/nested.txt', $editor->events );
		$this->assertContains( 'close-file: sub/deep/nested.txt (checksum ok)', $editor->events );
		$this->assertContains( 'close-edit', $editor->events );

		$this->assertSame( "hello world\n", $editor->files['hello.txt'] );
		// multi.txt has svn:eol-style CRLF, and Subversion stores fixed
		// eol-style files with that line ending in the repository.
		$this->assertSame( "line1\r\nline2\r\nline3\r\n", $editor->files['multi.txt'] );
		$this->assertSame( "nested file\n", $editor->files['sub/deep/nested.txt'] );

		// The svn:entry:* bookkeeping properties must not leak through,
		// real versioned properties must.
		$this->assertSame( array( 'svn:eol-style' => 'CRLF' ), $editor->properties['multi.txt'] );
		$this->assertArrayNotHasKey( 'hello.txt', $editor->properties );
	}

	public function test_chunked_feeding_produces_identical_results() {
		$fixture = file_get_contents( __DIR__ . '/fixtures/update-report-checkout.xml' );

		$editor = new RecordingEditor();
		$parser = new DavUpdateReportParser( $editor );
		foreach ( str_split( $fixture, 7 ) as $chunk ) {
			$parser->append_bytes( $chunk );
		}
		$parser->finish();

		$reference_editor = new RecordingEditor();
		$reference_parser = new DavUpdateReportParser( $reference_editor );
		$reference_parser->append_bytes( $fixture );
		$reference_parser->finish();

		$this->assertSame( $reference_editor->events, $editor->events );
		$this->assertSame( $reference_editor->files, $editor->files );
	}

	public function test_parses_update_with_deletes_and_prop_changes() {
		$xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n" .
			'<S:update-report xmlns:S="svn:" xmlns:V="http://subversion.tigris.org/xmlns/dav/" xmlns:D="DAV:" send-all="true" inline-props="true">' .
			'<S:target-revision rev="9"/>' .
			'<S:open-directory rev="7">' .
			'<S:delete-entry name="gone.txt"/>' .
			'<S:open-directory name="sub" rev="7">' .
			'<S:set-prop name="svn:ignore">*.tmp</S:set-prop>' .
			'<S:remove-prop name="custom:flag"/>' .
			'</S:open-directory>' .
			'<S:open-file name="changed.txt" rev="7">' .
			'<S:txdelta>' . base64_encode( "SVN\x00" . "\x00\x00\x03\x01\x03" . "\x83" . 'new' ) . '</S:txdelta>' .
			'<S:prop><V:md5-checksum>' . md5( 'new' ) . '</V:md5-checksum></S:prop>' .
			'</S:open-file>' .
			'</S:open-directory>' .
			'</S:update-report>';

		$editor = new RecordingEditor();
		$parser = new DavUpdateReportParser( $editor );
		$parser->append_bytes( $xml );
		$parser->finish();

		$this->assertContains( 'delete-entry: gone.txt', $editor->events );
		$this->assertContains( 'open-directory: sub', $editor->events );
		$this->assertContains( 'open-file: changed.txt', $editor->events );
		$this->assertContains( 'close-file: changed.txt (checksum ok)', $editor->events );
		$this->assertSame( 'new', $editor->files['changed.txt'] );
		$this->assertSame( array( 'svn:ignore' => '*.tmp', 'custom:flag' => null ), $editor->properties['sub'] );
	}

	public function test_rejects_update_paths_that_escape_the_working_copy() {
		$this->expectException( SvnException::class );

		$xml = '<?xml version="1.0" encoding="utf-8"?>' .
			'<S:update-report xmlns:S="svn:" send-all="true">' .
			'<S:target-revision rev="1"/>' .
			'<S:open-directory rev="1">' .
			'<S:add-directory name="../escape"/>' .
			'</S:open-directory>' .
			'</S:update-report>';

		$parser = new DavUpdateReportParser( new RecordingEditor() );
		$parser->append_bytes( $xml );
		$parser->finish();
	}

	public function test_decodes_base64_encoded_property_values() {
		$xml = '<?xml version="1.0" encoding="utf-8"?>' .
			'<S:update-report xmlns:S="svn:" xmlns:V="http://subversion.tigris.org/xmlns/dav/" send-all="true">' .
			'<S:target-revision rev="1"/>' .
			'<S:open-directory rev="1">' .
			'<S:set-prop name="custom:binary" V:encoding="base64">' . base64_encode( "\x00\xff binary" ) . '</S:set-prop>' .
			'</S:open-directory>' .
			'</S:update-report>';

		$editor = new RecordingEditor();
		$parser = new DavUpdateReportParser( $editor );
		$parser->append_bytes( $xml );
		$parser->finish();

		$this->assertSame( "\x00\xff binary", $editor->properties['']['custom:binary'] );
	}

	public function test_finish_throws_on_truncated_document() {
		$this->expectException( SvnException::class );
		$parser = new DavUpdateReportParser( new RecordingEditor() );
		$parser->append_bytes( '<S:update-report xmlns:S="svn:"><S:target-revision rev="1"/>' );
		$parser->finish();
	}
}
