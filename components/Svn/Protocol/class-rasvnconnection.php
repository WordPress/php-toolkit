<?php

namespace WordPress\Svn\Protocol;

use WordPress\Svn\SvnException;

/**
 * Reads and writes ra_svn protocol items over a stream.
 *
 * This is the lowest layer of svn:// support: it knows how to tokenize
 * the wire format documented in Subversion's libsvn_ra_svn/protocol but
 * has no idea what the items mean. RaSvnSession builds the actual
 * client on top of it.
 *
 * The connection works on any PHP stream, which keeps it testable with
 * php://memory streams – no server required.
 */
class RaSvnConnection {
	/**
	 * @var resource
	 */
	private $stream;

	/**
	 * Receive buffer.
	 *
	 * @var string
	 */
	private $buffer = '';

	/**
	 * Parse offset into $buffer.
	 *
	 * @var int
	 */
	private $offset = 0;

	/**
	 * @param resource $stream  An open, connected stream.
	 */
	public function __construct( $stream ) {
		$this->stream = $stream;
	}

	/**
	 * Opens a TCP connection to an svn:// URL.
	 *
	 * @param  string $host        Server hostname.
	 * @param  int    $port        Server port, usually 3690.
	 * @param  array  $options     {
	 *     @type int $connect_timeout_ms  Connection timeout. Default 10000.
	 *     @type int $timeout_ms          Read timeout. Default 60000.
	 * }
	 * @return RaSvnConnection
	 * @throws SvnException When the connection cannot be established.
	 */
	public static function connect( $host, $port, $options = array() ) {
		$connect_timeout_ms = isset( $options['connect_timeout_ms'] ) ? $options['connect_timeout_ms'] : 10000;
		$timeout_ms         = isset( $options['timeout_ms'] ) ? $options['timeout_ms'] : 60000;

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- connection failures are reported via exceptions below.
		$stream = @stream_socket_client(
			'tcp://' . $host . ':' . $port,
			$error_code,
			$error_message,
			$connect_timeout_ms / 1000
		);
		if ( false === $stream ) {
			throw new SvnException( "Could not connect to svn://{$host}:{$port}: {$error_message}" );
		}
		stream_set_timeout( $stream, (int) ( $timeout_ms / 1000 ), (int) ( $timeout_ms % 1000 ) * 1000 );

		return new RaSvnConnection( $stream );
	}

	/**
	 * Reads the next protocol item.
	 *
	 * @return RaSvnItem
	 * @throws SvnException When the connection drops or the data is malformed.
	 */
	public function read_item() {
		$this->skip_whitespace();
		$char = $this->peek_char();

		if ( '(' === $char ) {
			++$this->offset;
			$items = array();
			while ( true ) {
				$this->skip_whitespace();
				if ( ')' === $this->peek_char() ) {
					++$this->offset;

					return new RaSvnItem( RaSvnItem::TYPE_LIST, $items );
				}
				$items[] = $this->read_item();
			}
		}

		if ( $char >= '0' && $char <= '9' ) {
			$digits = '';
			while ( true ) {
				$char = $this->next_char();
				if ( $char >= '0' && $char <= '9' ) {
					$digits .= $char;
					continue;
				}
				if ( ':' === $char ) {
					return new RaSvnItem( RaSvnItem::TYPE_STRING, $this->read_exact( (int) $digits ) );
				}
				if ( ' ' === $char || "\n" === $char ) {
					return new RaSvnItem( RaSvnItem::TYPE_NUMBER, (int) $digits );
				}
				throw new SvnException( 'Protocol error: malformed number on the wire.' );
			}
		}

		$word = '';
		while ( true ) {
			$char = $this->next_char();
			if ( ' ' === $char || "\n" === $char ) {
				if ( '' === $word ) {
					throw new SvnException( 'Protocol error: empty word on the wire.' );
				}

				return new RaSvnItem( RaSvnItem::TYPE_WORD, $word );
			}
			$word .= $char;
		}
	}

	/**
	 * Writes raw, already-encoded protocol bytes.
	 *
	 * @param  string $bytes  The bytes to send.
	 * @throws SvnException When the connection drops mid-write.
	 */
	public function write( $bytes ) {
		$written_total = 0;
		$length        = strlen( $bytes );
		while ( $written_total < $length ) {
			$written = @fwrite( $this->stream, substr( $bytes, $written_total ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- write failures are reported via exceptions below.
			if ( false === $written || 0 === $written ) {
				throw new SvnException( 'The svn:// connection was closed while sending data.' );
			}
			$written_total += $written;
		}
	}

	/**
	 * Encodes a string in the length-prefixed wire format.
	 *
	 * @param  string $value  Arbitrary bytes.
	 * @return string The encoded string, e.g. "5:hello".
	 */
	public static function encode_string( $value ) {
		return strlen( $value ) . ':' . $value;
	}

	/**
	 * Encodes a boolean as a protocol word.
	 *
	 * @param  bool $value  The boolean to encode.
	 * @return string Either "true" or "false".
	 */
	public static function encode_boolean( $value ) {
		return $value ? 'true' : 'false';
	}

	public function close() {
		if ( is_resource( $this->stream ) ) {
			fclose( $this->stream );
		}
	}

	private function fill_buffer() {
		$chunk = fread( $this->stream, 65536 );
		if ( '' === $chunk || false === $chunk ) {
			$meta = stream_get_meta_data( $this->stream );
			if ( ! empty( $meta['timed_out'] ) ) {
				throw new SvnException( 'Timed out while waiting for data on the svn:// connection.' );
			}
			throw new SvnException( 'The svn:// connection was closed by the server.' );
		}
		$this->buffer = substr( $this->buffer, $this->offset ) . $chunk;
		$this->offset = 0;
	}

	private function next_char() {
		if ( $this->offset >= strlen( $this->buffer ) ) {
			$this->fill_buffer();
		}

		return $this->buffer[ $this->offset++ ];
	}

	private function peek_char() {
		if ( $this->offset >= strlen( $this->buffer ) ) {
			$this->fill_buffer();
		}

		return $this->buffer[ $this->offset ];
	}

	private function read_exact( $length ) {
		while ( strlen( $this->buffer ) - $this->offset < $length ) {
			$this->fill_buffer();
		}
		$bytes         = substr( $this->buffer, $this->offset, $length );
		$this->offset += $length;

		return $bytes;
	}

	private function skip_whitespace() {
		while ( true ) {
			$char = $this->peek_char();
			if ( ' ' === $char || "\n" === $char ) {
				++$this->offset;
				continue;
			}

			return;
		}
	}
}
