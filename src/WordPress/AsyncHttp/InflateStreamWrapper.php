<?php

namespace WordPress\AsyncHttp;

use WordPress\Streams\VanillaStreamWrapper;

class InflateStreamWrapper extends VanillaStreamWrapper {

	const SCHEME = 'inflate-http-response';

	private $initialized = false;
	private $decoded_buffer = '';
	private $inflate_handle;

	/**
	 * @param \WordPress\AsyncHttp\InflateStreamWrapperData $data
	 */
	public static function create_resource( $data ) {
		return parent::create_resource( $data );
	}

	protected function init()
	{
		if($this->initialized) {
			return;
		}
		$this->initialized = true;

		if(!($this->wrapper_data instanceof InflateStreamWrapperData)) {
			throw new \Exception('InflateStreamWrapper requires an instance of InflateStreamWrapperData');
		}

		$this->inflate_handle = inflate_init($this->wrapper_data->encoding);
		if(false === $this->inflate_handle) {
			throw new \Exception('Failed to initialize inflate handle');
		}
	}

	public function stream_read( $count ) {
		$this->init();

		$bytes = parent::stream_read( $count );
		if($bytes === false) {
			return false;
		}

		$result = inflate_add($this->inflate_handle, $bytes);
		if($result === false || inflate_get_status($this->inflate_handle) === false) {
			return false;
		}

		$this->decoded_buffer .= $result;
		$return_bytes = substr($this->decoded_buffer, 0, $count);
		$this->decoded_buffer = substr($this->decoded_buffer, strlen($return_bytes));

		return $return_bytes;
	}

}
