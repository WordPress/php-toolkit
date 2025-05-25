<?php

namespace WordPress\HttpClient;

interface ClientInterface {
	public function fetch( Request $request, array $options = [] );
	public function fetch_many( array $requests, array $options = [] );
	public function enqueue( array $requests );
	public function await_next_event( array $query = [] );
	public function has_pending_event( Request $request, string $event_type );
	public function get_event();
	public function get_request();
	public function get_response();
	public function get_response_body_chunk();
}