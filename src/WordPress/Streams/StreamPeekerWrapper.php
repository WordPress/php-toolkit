<?php

namespace WordPress\Streams;

class StreamPeekerWrapper extends StreamWrapper {
	protected $on_data;
	protected $on_close;
	protected $position;

	const SCHEME = 'peek';

	public static function wrap( $response_stream, $on_data, $on_close=null ) {
		return parent::create_resource( [
			'stream' => $response_stream,
			'on_data' => $on_data,
			'on_close' => $on_close
		] );
	}

	protected function do_initialize() {
		$this->stream = $this->wrapper_data['stream'];
		$this->on_data = $this->wrapper_data['on_data'] ?? function ( $data ) {};
		$this->on_close = $this->wrapper_data['on_close'] ?? function () {};
		$this->position = 0;
	}

	// Reads from the stream
	public function stream_read( $count ) {
		$ret             = fread( $this->stream, $count );
		$this->position += strlen( $ret );

		$onChunk = $this->on_data;
		$onChunk( $ret );

		return $ret;
	}

	// Writes to the stream
	public function stream_write( $data ) {
		$written         = fwrite( $this->stream, $data );
		$this->position += $written;

		return $written;
	}

	// Closes the stream
	public function stream_close() {
		if(is_resource($this->stream)) {
			fclose( $this->stream );
		}
		$this->stream = null;
		$onClose = $this->on_close;
		$onClose();
	}

	// Returns the current position of the stream
	public function stream_tell() {
		return $this->position;
	}
}
