<?php

namespace WordPress\Blueprints;

use Symfony\Component\Process\Process as SymfonyProcess;
use WordPress\ByteStream\ReadStream\BaseByteReadStream;

class Process extends SymfonyProcess {

	const OUTPUT_FILE = 'OUTPUT_FILE';

	private $output_streams = array();
	private $output_file_path;

	public function __construct( $commandline, $cwd = null, ?array $env = null, $input = null, $timeout = 60, ?array $options = array() ) {
		if ( isset( $options['output_file_path'] ) ) {
			$this->output_file_path = $options['output_file_path'];
		}
		parent::__construct( $commandline, $cwd, $env, $input, $timeout, $options );
	}

	public function getOutputStream( $pipe ) {
		if ( ! isset( $this->output_streams[ $pipe ] ) ) {
			$this->output_streams[ $pipe ] = new ProcessOutputStream( $this, $pipe, $this->output_file_path );
		}
		return $this->output_streams[ $pipe ];
	}
}

require_once __DIR__ . '/class-processoutputstream.php';
