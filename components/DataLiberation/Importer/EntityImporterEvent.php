<?php

namespace WordPress\DataLiberation\Importer;

class EntityImporterEvent {

	const SUCCESS = '#success';
	const FAILURE = '#failure';
	const WARNING = '#warning';
	const SKIPPED = '#skipped';

	public $type;
	public $entity_type;
	public $entity_data;
	public $message;
	public $error;

	public function __construct( $entity_type, $type, $message = null, $entity_data = null, $error = null ) {
		$this->entity_type = $entity_type;
		$this->type        = $type;
		$this->message     = $message;
		$this->entity_data = $entity_data;
		$this->error       = $error;
	}
} 