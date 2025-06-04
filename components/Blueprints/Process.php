<?php

namespace WordPress\Blueprints;

use Symfony\Component\Process\Process as SymfonyProcess;
use WordPress\ByteStream\ReadStream\BaseByteReadStream;
use WordPress\ByteStream\ReadStream\FileReadStream;

class Process extends SymfonyProcess {

	const OUTPUT_FILE = 'OUTPUT_FILE';

	private $output_streams = [];
	private $output_file_path;

	public function __construct($commandline, $cwd = null, ?array $env = null, $input = null, $timeout = 60, ?array $options = []) {
		if(isset($options['output_file_path'])) {
			$this->output_file_path = $options['output_file_path'];
		}
		parent::__construct($commandline, $cwd, $env, $input, $timeout, $options);
	}

	public function getOutputStream($pipe) {
		if(!isset($this->output_streams[$pipe])) {
			switch($pipe) {
				case Process::OUT:
				case Process::STDOUT:
					$resource = $this->stdout;
					break;
				case Process::ERR:
				case Process::STDERR:
					$resource = $this->stderr;
					break;
				case Process::OUTPUT_FILE:
					$resource = fopen($this->output_file_path, 'r');
					break;
				default:
					throw new \InvalidArgumentException('Invalid pipe name "' . $pipe . '"');
			}
			$this->output_streams[$pipe] = FileReadStream::from_resource($resource);
		}
		return $this->output_streams[$pipe];
	}
	
}
