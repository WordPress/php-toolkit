<?php

namespace WordPress\HttpServer;

use Exception;
use VendorPrefix\Rowbot\URL\URL;
use WordPress\ByteStream\ReadStream\ByteReadStream;
use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\ByteStream\ReadStream\InflateReadStream;
use WordPress\DataLiberation\URL\WPURL;
use WordPress\HttpClient\ByteStream\ChunkedDecoderReadStream;
use WordPress\HttpClient\Request;

class IncomingRequest extends Request {

	public static function from_resource( $upstream ) {
		// Read request line.
		$line = fgets( $upstream );
		if ( false === $line ) {
			throw new Exception( 'Failed to read request line' );
		}
		$parts        = explode( ' ', trim( $line ), 3 );
		$request_info = array(
			'method'   => $parts[0] ?? 'GET',
			'pathname' => $parts[1] ?? '/',
			'headers'  => array(),
		);

		// Read headers.
		while ( false !== ( $line = fgets( $upstream ) ) ) {
			$line = trim( $line );
			if ( '' === $line ) {
				break;
			}
			$header_parts = explode( ':', $line, 2 );
			if ( 2 == count( $header_parts ) ) {
				$name                             = strtolower( trim( $header_parts[0] ) );
				$request_info['headers'][ $name ] = trim( $header_parts[1] );
			}
		}

		// @TODOL: Validate the Host, URL, throw an error if invalid.
		$request = new IncomingRequest(
		// @TODO: figure out protocol.
			'http://' . ( $request_info['headers']['host'] ?? 'unknown-host' ) . $request_info['pathname'],
			$request_info
		);

		$body_stream = FileReadStream::from_resource( $upstream );

		$wrapped_streams = array();

		$encoding = $request->get_header( 'Content-Encoding' );
		if ( $encoding ) {
			foreach ( explode( ',', $encoding ) as $encoding ) {
				$encoding = trim( $encoding );
				switch ( $encoding ) {
					case 'gzip':
						$wrapped_streams[] = $body_stream;
						$body_stream       = new InflateReadStream( $body_stream, ZLIB_ENCODING_GZIP );
						break;
					case 'deflate':
						$wrapped_streams[] = $body_stream;
						$body_stream       = new InflateReadStream( $body_stream );
						break;
					default:
						throw new Exception( "Unsupported content encoding: {$encoding}" );
				}
			}
		}

		// Support chunked transfer decoding.
		$transfer_encoding = $request->get_header( 'transfer-encoding' );
		if ( $transfer_encoding ) {
			foreach ( explode( ',', $transfer_encoding ) as $te ) {
				$te = strtolower( trim( $te ) );
				switch ( $te ) {
					case 'chunked':
						$wrapped_streams[] = $body_stream;
						$body_stream       = new ChunkedDecoderReadStream( $body_stream );
						break;
					// You can add support for other transfer-encodings here if needed.
					default:
						// Ignore or throw for unknown encodings if desired.
						break;
				}
			}
		}

		$request->body_stream     = $body_stream;
		$request->wrapped_streams = $wrapped_streams;

		return $request;
	}

	/**
	 * @var ByteReadStream
	 */
	public $body_stream;
	/**
	 * @var mixed[]
	 */
	private $wrapped_streams;
	/**
	 * @var URL|null
	 */
	private $parsed_url;

	// @TODO: Bake this into the body stream instance.
	public function close_body_stream() {
		// Do not close $this->body_stream as it would close
		// the tcp connection with the client. We need that
		// connection to send the response.
		foreach ( $this->wrapped_streams as $stream ) {
			$stream->close_reading();
		}
	}

	public function get_parsed_url() {
		if ( null === $this->parsed_url ) {
			$parsed_url = WPURL::parse( $this->url );
			if ( false === $parsed_url ) {
				throw new Exception( "Invalid URL: {$this->url}" );
			}
			$this->parsed_url = $parsed_url;
		}

		return $this->parsed_url;
	}
}
