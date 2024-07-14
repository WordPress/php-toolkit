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

	public function __toString( ) {
		$message = '';
		switch($this->name) {
			case self::EVENT_GOT_HEADERS:
				$message = print_r( $this->request->response->get_headers(), true );
				break;
			case self::EVENT_BODY_CHUNK_AVAILABLE:
				$message = "(".$this->request->response->received_bytes."/".$this->request->response->total_bytes.")";
				break;
			case self::EVENT_FAILED:
				$message = "Failed request to {$this->request->url} – {$this->request->error}";
				break;
			case self::EVENT_REDIRECT:
				$message = "Redirected from {$this->request->url} to {$this->request->redirected_to->url}";
				break;
		}

		return "Request " . $this->request->id . ": $this->name – " . $message;

	}

}
