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

		$this->client->event_loop_tick();
		return $this->client->read_bytes( $this->wrapper_data->request, $count );
	}

	public function stream_write( $data ) {
		$this->initialize();

		return parent::stream_write( $data );
	}

	public function stream_tell() {
		$this->initialize();

		return parent::stream_tell();
	}

	public function stream_close() {
		$this->initialize();

		return parent::stream_close();
	}

	public function stream_eof() {
		$this->initialize();

		return parent::stream_eof();
	}

	public function stream_seek( $offset, $whence ) {
		$this->initialize();

		return parent::stream_seek( $offset, $whence );
	}
}
