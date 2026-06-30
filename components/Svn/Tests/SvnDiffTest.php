<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WordPress\Svn\SvnDiff;
use WordPress\Svn\SvnDiffApplier;
use WordPress\Svn\SvnException;

class SvnDiffTest extends TestCase {
	public function test_encode_varint_single_byte() {
		$this->assertSame( "\x00", SvnDiff::encode_varint( 0 ) );
		$this->assertSame( "\x7f", SvnDiff::encode_varint( 127 ) );
	}

	public function test_encode_varint_multi_byte() {
		$this->assertSame( "\x81\x00", SvnDiff::encode_varint( 128 ) );
		$this->assertSame( "\x86\x90\x23", SvnDiff::encode_varint( 100387 ) );
	}

	public function test_parse_varint_round_trips() {
		foreach ( array( 0, 1, 63, 64, 127, 128, 300, 102400, 99999999 ) as $number ) {
			$position = 0;
			$this->assertSame(
				$number,
				SvnDiffApplier::parse_varint_from( SvnDiff::encode_varint( $number ), $position )
			);
		}
	}

	public function test_parse_varint_returns_null_on_truncated_input() {
		$position = 0;
		$this->assertNull( SvnDiffApplier::parse_varint_from( "\x81", $position ) );
		$this->assertSame( 0, $position );
	}

	public function test_fulltext_round_trip() {
		$contents = "Hello, svndiff!\nSecond line.\n";
		$applier  = new SvnDiffApplier( '' );
		$applier->append_bytes( SvnDiff::encode_fulltext( $contents ) );
		$applier->finish();
		$this->assertSame( $contents, $applier->get_target() );
	}

	public function test_fulltext_round_trip_empty_string() {
		$applier = new SvnDiffApplier( '' );
		$applier->append_bytes( SvnDiff::encode_fulltext( '' ) );
		$applier->finish();
		$this->assertSame( '', $applier->get_target() );
	}

	public function test_fulltext_round_trip_larger_than_window_size() {
		$contents = str_repeat( "0123456789abcdef\n", 20000 ); // 340 KB, several windows.
		$applier  = new SvnDiffApplier( '' );
		$applier->append_bytes( SvnDiff::encode_fulltext( $contents ) );
		$applier->finish();
		$this->assertSame( $contents, $applier->get_target() );
	}

	public function test_byte_by_byte_feeding() {
		$contents = 'incremental decoding works';
		$svndiff  = SvnDiff::encode_fulltext( $contents );
		$applier  = new SvnDiffApplier( '' );
		for ( $i = 0; $i < strlen( $svndiff ); $i++ ) {
			$applier->append_bytes( $svndiff[ $i ] );
		}
		$applier->finish();
		$this->assertSame( $contents, $applier->get_target() );
	}

	public function test_copy_from_source_instruction() {
		// Window: copy bytes 4..9 of the source view, then 3 new bytes.
		$instructions = "\x06\x04" . "\x83";
		$window       = "\x00\x0a\x09" . SvnDiff::encode_varint( strlen( $instructions ) ) . "\x03" . $instructions . 'NEW';
		$applier      = new SvnDiffApplier( 'abcdefghij' );
		$applier->append_bytes( "SVN\x00" . $window );
		$applier->finish();
		$this->assertSame( 'efghijNEW', $applier->get_target() );
	}

	public function test_overlapping_copy_from_target_repeats_bytes() {
		// One new byte 'x', then a target copy of length 5 starting at
		// offset 0 – RLE-style expansion into 'xxxxxx'.
		$instructions = "\x81" . "\x45\x00";
		$window       = "\x00\x00\x06" . SvnDiff::encode_varint( strlen( $instructions ) ) . "\x01" . $instructions . 'x';
		$applier      = new SvnDiffApplier( '' );
		$applier->append_bytes( "SVN\x00" . $window );
		$applier->finish();
		$this->assertSame( 'xxxxxx', $applier->get_target() );
	}

	public function test_rejects_bad_header() {
		$this->expectException( SvnException::class );
		$applier = new SvnDiffApplier( '' );
		$applier->append_bytes( 'GIT0nope' );
	}

	public function test_rejects_unsupported_version() {
		$this->expectException( SvnException::class );
		$this->expectExceptionMessage( 'svndiff version 2' );
		$applier = new SvnDiffApplier( '' );
		$applier->append_bytes( "SVN\x02" );
	}

	public function test_rejects_window_with_wrong_target_length() {
		$this->expectException( SvnException::class );
		// Window claims 5 target bytes but the instruction only emits 3.
		$applier = new SvnDiffApplier( '' );
		$applier->append_bytes( "SVN\x00" . "\x00\x00\x05\x01\x03" . "\x83" . 'abc' );
	}

	public function test_finish_rejects_truncated_window() {
		$this->expectException( SvnException::class );
		$applier = new SvnDiffApplier( '' );
		$applier->append_bytes( substr( SvnDiff::encode_fulltext( 'truncated' ), 0, 8 ) );
		$applier->finish();
	}

	public function test_decodes_real_mod_dav_svn_delta() {
		// Captured from a live mod_dav_svn update-report response.
		$svndiff = base64_decode( 'U1ZOAAAADAEMjG5lc3RlZCBmaWxlCg==' );
		$applier = new SvnDiffApplier( '' );
		$applier->append_bytes( $svndiff );
		$applier->finish();
		$this->assertSame( "nested file\n", $applier->get_target() );
	}

	public function test_flush_target_returns_and_forgets() {
		$applier = new SvnDiffApplier( '' );
		$applier->append_bytes( SvnDiff::encode_fulltext( 'abc' ) );
		$this->assertSame( 'abc', $applier->flush_target() );
		$this->assertSame( '', $applier->get_target() );
	}
}
