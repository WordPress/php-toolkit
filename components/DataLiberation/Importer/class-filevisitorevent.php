<?php

namespace WordPress\DataLiberation\Importer;

class FileVisitorEvent {
	public $type;
	public $dir;
	public $files;

	const EVENT_ENTER = 'entering';
	const EVENT_EXIT  = 'exiting';

	public function __construct( $type, $dir, $files = array() ) {
		$this->type  = $type;
		$this->dir   = $dir;
		$this->files = $files;
	}

	public function is_entering() {
		return $this->type === self::EVENT_ENTER;
	}

	public function is_exiting() {
		return $this->type === self::EVENT_EXIT;
	}
}
