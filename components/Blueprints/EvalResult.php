<?php

namespace WordPress\Blueprints;

class EvalResult {
	/**
	 * @var string
	 */
	public $output_file_content;
	/**
	 * @var Process
	 */
	public $process;

	public function __construct( string $output_file_content, Process $process ) {
		$this->output_file_content = $output_file_content;
		$this->process             = $process;
	}
}
