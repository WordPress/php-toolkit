<?php

namespace WordPress\Svn;

/**
 * Streaming svndiff decoder.
 *
 * Applies an svndiff document to a source text and produces the target
 * text. svndiff bytes can be appended in arbitrarily small chunks – the
 * applier buffers data until a complete window is available, applies it,
 * and discards it. This is how both the svn:// protocol and mod_dav_svn
 * deliver file contents during checkouts and updates.
 *
 * The svndiff format, in short:
 *
 *     "SVN" <version byte> <window>*
 *
 * Each window is five varints – source view offset, source view length,
 * target view length, instructions length, new data length – followed by
 * the instructions section and the new data section. Instructions are:
 *
 *     0b00 – copy <length> bytes from the source view (offset varint follows)
 *     0b01 – copy <length> bytes from the target view (offset varint follows,
 *            may overlap the bytes being produced, like memmove cannot)
 *     0b10 – copy <length> bytes from the new data section
 *
 * The opcode lives in the two high bits of the first instruction byte and
 * the length in the low six bits; a zero length means a varint with the
 * real length follows.
 *
 * In svndiff1 (version byte 0x01) the instructions and new data sections
 * each start with a varint holding the uncompressed length. If that length
 * differs from the remaining section length, the section is zlib-compressed.
 */
class SvnDiffApplier {
	/**
	 * The source text new windows may copy from. Empty for plain
	 * full-text transmissions such as a fresh checkout.
	 *
	 * @var string
	 */
	private $source;

	/**
	 * Undecoded svndiff bytes received so far.
	 *
	 * @var string
	 */
	private $buffer = '';

	/**
	 * Format version parsed from the header, or null before the
	 * header was seen. Versions 0 and 1 are supported.
	 *
	 * @var int|null
	 */
	private $version;

	/**
	 * Target bytes produced so far across all windows.
	 *
	 * @var string
	 */
	private $target = '';

	/**
	 * @param string $source  The source text the delta applies to. Use an
	 *                        empty string when the server sends full texts.
	 */
	public function __construct( $source = '' ) {
		$this->source = $source;
	}

	/**
	 * Feeds more svndiff bytes into the applier and applies every window
	 * that became complete.
	 *
	 * @param  string $bytes  The next chunk of the svndiff document.
	 * @throws SvnException When the document is malformed or uses an unsupported version.
	 */
	public function append_bytes( $bytes ) {
		$this->buffer .= $bytes;

		if ( null === $this->version ) {
			if ( strlen( $this->buffer ) < 4 ) {
				return;
			}
			if ( 'SVN' !== substr( $this->buffer, 0, 3 ) ) {
				throw new SvnException( 'Malformed svndiff data: bad header.' );
			}
			$this->version = ord( $this->buffer[3] );
			if ( 0 !== $this->version && 1 !== $this->version ) {
				throw new SvnException( "Unsupported svndiff version {$this->version}. Only svndiff0 and svndiff1 are supported." );
			}
			$this->buffer = substr( $this->buffer, 4 );
		}

		while ( $this->apply_next_window() ) {
			continue;
		}
	}

	/**
	 * Returns the target text produced so far.
	 *
	 * @return string
	 */
	public function get_target() {
		return $this->target;
	}

	/**
	 * Returns the produced target text and forgets it so that long
	 * streams can be consumed with bounded memory.
	 *
	 * @return string
	 */
	public function flush_target() {
		$flushed      = $this->target;
		$this->target = '';

		return $flushed;
	}

	/**
	 * Signals the end of the svndiff document.
	 *
	 * @throws SvnException When unconsumed bytes remain – this indicates truncated input.
	 */
	public function finish() {
		if ( '' !== $this->buffer ) {
			throw new SvnException( 'Malformed svndiff data: trailing or truncated window data.' );
		}
	}

	/**
	 * Tries to decode and apply the next window from the buffer.
	 *
	 * @return bool Whether a complete window was applied.
	 * @throws SvnException When the window data is malformed.
	 */
	private function apply_next_window() {
		if ( '' === $this->buffer ) {
			return false;
		}

		$pos                = 0;
		$source_view_offset = $this->parse_varint( $pos );
		$source_view_length = $this->parse_varint( $pos );
		$target_view_length = $this->parse_varint( $pos );
		$instructions_size  = $this->parse_varint( $pos );
		$new_data_size      = $this->parse_varint( $pos );
		if ( null === $source_view_offset || null === $source_view_length || null === $target_view_length || null === $instructions_size || null === $new_data_size ) {
			// The window header is not complete yet.
			return false;
		}

		if ( strlen( $this->buffer ) - $pos < $instructions_size + $new_data_size ) {
			// The window body is not complete yet.
			return false;
		}

		$instructions = substr( $this->buffer, $pos, $instructions_size );
		$new_data     = substr( $this->buffer, $pos + $instructions_size, $new_data_size );
		$this->buffer = substr( $this->buffer, $pos + $instructions_size + $new_data_size );

		if ( 1 === $this->version ) {
			$instructions = $this->inflate_section( $instructions );
			$new_data     = $this->inflate_section( $new_data );
		}

		$source_view   = substr( $this->source, $source_view_offset, $source_view_length );
		$this->target .= $this->apply_window( $instructions, $new_data, $source_view, $target_view_length );

		return true;
	}

	/**
	 * Decompresses an svndiff1 section.
	 *
	 * Sections start with a varint holding the uncompressed length.
	 * When it equals the remaining length the data is stored as-is,
	 * otherwise it is zlib-compressed.
	 *
	 * @param  string $section  The raw section bytes.
	 * @return string The decompressed section data.
	 * @throws SvnException When the compressed data is malformed.
	 */
	private function inflate_section( $section ) {
		$pos    = 0;
		$length = self::parse_varint_from( $section, $pos );
		if ( null === $length ) {
			throw new SvnException( 'Malformed svndiff1 section header.' );
		}
		$data = substr( $section, $pos );
		if ( strlen( $data ) === $length ) {
			return $data;
		}
		if ( ! function_exists( 'zlib_decode' ) ) {
			throw new SvnException( 'The server sent zlib-compressed svndiff1 data but the zlib extension is not available.' );
		}
		$inflated = zlib_decode( $data );
		if ( false === $inflated || strlen( $inflated ) !== $length ) {
			throw new SvnException( 'Malformed svndiff1 section: zlib decompression failed.' );
		}

		return $inflated;
	}

	/**
	 * Executes the instructions of a single window.
	 *
	 * @param  string $instructions        The decoded instructions section.
	 * @param  string $new_data            The decoded new data section.
	 * @param  string $source_view         The slice of the source text this window may copy from.
	 * @param  int    $target_view_length  The expected size of the produced data.
	 * @return string The produced target view.
	 * @throws SvnException When the instructions are malformed.
	 */
	private function apply_window( $instructions, $new_data, $source_view, $target_view_length ) {
		$target_view  = '';
		$new_data_pos = 0;
		$pos          = 0;
		$length       = strlen( $instructions );
		while ( $pos < $length ) {
			$first_byte  = ord( $instructions[ $pos ] );
			$opcode      = ( $first_byte >> 6 ) & 0x3;
			$copy_length = $first_byte & 0x3f;
			++$pos;
			if ( 0 === $copy_length ) {
				$copy_length = self::parse_varint_from( $instructions, $pos );
			}
			if ( null === $copy_length ) {
				throw new SvnException( 'Malformed svndiff window: truncated instruction length.' );
			}

			switch ( $opcode ) {
				case 0:
					// Copy from the source view.
					$offset = self::parse_varint_from( $instructions, $pos );
					if ( null === $offset || $offset + $copy_length > strlen( $source_view ) ) {
						throw new SvnException( 'Malformed svndiff window: source copy out of bounds.' );
					}
					$target_view .= substr( $source_view, $offset, $copy_length );
					break;

				case 1:
					// Copy from the target view. The copied range is allowed to
					// overlap the bytes being produced – a run like "offset 0,
					// length 100" over a 1-byte target repeats that byte 100
					// times – so copy in progressively doubling chunks instead
					// of a single substr().
					$offset = self::parse_varint_from( $instructions, $pos );
					if ( null === $offset || $offset >= strlen( $target_view ) ) {
						throw new SvnException( 'Malformed svndiff window: target copy out of bounds.' );
					}
					$available = strlen( $target_view ) - $offset;
					while ( $copy_length > 0 ) {
						$step         = min( $copy_length, $available );
						$target_view .= substr( $target_view, $offset, $step );
						$copy_length -= $step;
						$available   += $step;
					}
					break;

				case 2:
					// Copy from the new data section.
					if ( $new_data_pos + $copy_length > strlen( $new_data ) ) {
						throw new SvnException( 'Malformed svndiff window: new data copy out of bounds.' );
					}
					$target_view  .= substr( $new_data, $new_data_pos, $copy_length );
					$new_data_pos += $copy_length;
					break;

				default:
					throw new SvnException( 'Malformed svndiff window: invalid instruction opcode.' );
			}
		}

		if ( strlen( $target_view ) !== $target_view_length ) {
			throw new SvnException(
				sprintf(
					'Malformed svndiff window: produced %d bytes, expected %d.',
					strlen( $target_view ),
					$target_view_length
				)
			);
		}

		return $target_view;
	}

	/**
	 * Parses a varint from the internal buffer.
	 *
	 * @param  int $pos  Byte offset to parse at; advanced past the varint on success.
	 * @return int|null The parsed integer, or null when the buffer ends mid-varint.
	 */
	private function parse_varint( &$pos ) {
		return self::parse_varint_from( $this->buffer, $pos );
	}

	/**
	 * Parses a base-128 varint with continuation bits.
	 *
	 * @param  string $bytes  The string to parse from.
	 * @param  int    $pos    Byte offset to parse at; advanced past the varint on success.
	 * @return int|null The parsed integer, or null when the string ends mid-varint.
	 */
	public static function parse_varint_from( $bytes, &$pos ) {
		$value  = 0;
		$length = strlen( $bytes );
		for ( $i = $pos; $i < $length; $i++ ) {
			$byte  = ord( $bytes[ $i ] );
			$value = ( $value << 7 ) | ( $byte & 0x7f );
			if ( 0 === ( $byte & 0x80 ) ) {
				$pos = $i + 1;

				return $value;
			}
		}

		return null;
	}
}
