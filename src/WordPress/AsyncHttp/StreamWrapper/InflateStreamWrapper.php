<?php

namespace WordPress\AsyncHttp\StreamWrapper;

use WordPress\Streams\StreamWrapper;

class InflateStreamWrapper extends StreamWrapper {

	const SCHEME = 'inflate-http-response';

	private $decoded_buffer = '';
	private $inflate_handle;

	/**
	 * @param  \WordPress\AsyncHttp\InflateStreamWrapperData  $data
	 */
	public static function wrap( $response_stream, $encoding ) {
		return parent::create_resource( [
			'response_stream' => $response_stream,
			'encoding'        => $encoding,
		] );
	}

	protected function do_initialize() {
		$this->stream         = $this->wrapper_data['response_stream'];
		$this->inflate_handle = inflate_init( $this->wrapper_data['encoding'] );
		if ( false === $this->inflate_handle ) {
			throw new \Exception( 'Failed to initialize inflate handle' );
		}
	}

	public function stream_read( $count ) {
		$bytes = parent::stream_read( $count );
		if ( $bytes === false ) {
			return false;
		}

		$result = inflate_add( $this->inflate_handle, $bytes );
		if ( $result === false || inflate_get_status( $this->inflate_handle ) === false ) {
			return false;
		}

		$this->decoded_buffer .= $result;
		$return_bytes         = substr( $this->decoded_buffer, 0, $count );
		$this->decoded_buffer = substr( $this->decoded_buffer, strlen( $return_bytes ) );

		return $return_bytes;
	}

}
