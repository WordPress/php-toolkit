<?php

namespace WordPress\HttpClient\Transport;

use WordPress\HttpClient\Request;

interface TransportInterface {

	public function event_loop_tick(): bool;

}
