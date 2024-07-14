<?php

namespace WordPress\AsyncHttp;

use WordPress\Streams\VanillaStreamWrapperData;

class StreamData extends VanillaStreamWrapperData {

	public $request;
	public $client;

	public function __construct( $request, Client $group ) {
		parent::__construct( null );
		$this->request = $request;
		$this->client  = $group;
	}
}
