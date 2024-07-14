<?php

namespace WordPress\AsyncHttp;

class ClientEvent {

	const EVENT_GOT_HEADERS = 'EVENT_GOT_HEADERS';
	const EVENT_BODY_CHUNK_AVAILABLE = 'EVENT_BODY_CHUNK_AVAILABLE';
	const EVENT_REDIRECT = 'EVENT_REDIRECT';
	const EVENT_FAILED = 'EVENT_FAILED';
	const EVENT_FINISHED = 'EVENT_FINISHED';

	public $request;
	public $name;

	public function __construct($request, $name)
	{
		$this->request = $request;
		$this->name = $name;
	}

}
