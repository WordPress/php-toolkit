<?php

namespace WordPress\AsyncHttp;

use WordPress\Streams\VanillaStreamWrapper;

class StreamWrapper extends VanillaStreamWrapper {

	const SCHEME = 'async-http';

	/** @var Client */
	private $client;

	protected function initialize() {
		if ( ! $this->stream ) {
			$this->stream = $this->wrapper_data->request->http_socket;
		}
	}

	public function stream_open( $path, $mode, $options, &$opened_path ) {
		if ( ! parent::stream_open( $path, $mode, $options, $opened_path ) ) {
			return false;
		}

		if ( ! $this->wrapper_data->client ) {
			return false;
		}
		$this->client = $this->wrapper_data->client;

		return true;
	}

	/**
	 * @param int $cast_as
	 */
	public function stream_cast( $cast_as ) {
		$this->initialize();

		return parent::stream_cast( $cast_as );
	}

	public function stream_read( $count ) {
		$this->initialize();

		return $this->client->read_bytes( $this->wrapper_data->request, $count );
	}

	public function stream_write( $data ) {
		$this->initialize();

		return parent::stream_write( $data );
	}

	public function stream_tell() {
		if ( ! $this->stream ) {
			return false;
		}

		return parent::stream_tell();
	}

	public function stream_close() {
		if ( ! $this->stream ) {
			return false;
		}

		if ( ! $this->has_valid_stream() ) {
			return false;
		}

		return parent::stream_close();
	}

	public function stream_eof() {
		if ( ! $this->stream ) {
			return false;
		}

		if ( ! $this->has_valid_stream() ) {
			return true;
		}

		return parent::stream_eof();
	}

	public function stream_seek( $offset, $whence ) {
		if ( ! $this->stream ) {
			return false;
		}

		return parent::stream_seek( $offset, $whence );
	}

	/*
	 * This stream_close call could be initiated not by the developer,
	 * but by the PHP internal request shutdown handler (written in C).
	 *
	 * The underlying resource ($this->stream) may have already been closed
	 * and freed independently from the resource represented by $this stream
	 * wrapper. In this case, the type of $this->stream will be "Unknown",
	 * and the fclose() call will trigger a fatal error.
	 *
	 * Let's refuse to call fclose() in that scenario.
	 */
	protected function has_valid_stream() {
		return get_resource_type( $this->stream ) !== 'Unknown';
	}
}
