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

class ProcessOutputStream extends BaseByteReadStream {
	private $process;
	private $pipe;
	private $output_file_path;
	private $output_file_fp;

	private $last_output;
	public function __construct(
		Process $process,
		$pipe,
		$output_file_path = null
	) {
		$this->process          = $process;
		$this->pipe             = $pipe;
		$this->output_file_path = $output_file_path;

		switch ( $this->pipe ) {
			case Process::OUT:
			case Process::STDOUT:
			case Process::ERR:
			case Process::STDERR:
				break;
			case Process::OUTPUT_FILE:
				$this->output_file_fp = fopen( $this->output_file_path, 'r' );
				break;
			default:
				throw new \InvalidArgumentException( 'Invalid pipe name "' . $this->pipe . '"' );
		}
	}

	protected function internal_pull( $n ): string {
		$output = $this->get_incremental_output();
		if ( ! strlen( $output ) ) {
			usleep( 300 );
			$output = $this->get_incremental_output();
		}
		$this->last_output .= $output;
		$retval             = substr( $this->last_output, 0, $n );
		$this->last_output  = substr( $this->last_output, $n );
		return $retval;
	}

	protected function get_incremental_output(): string {
		switch ( $this->pipe ) {
			case Process::OUT:
			case Process::STDOUT:
				return $this->process->getIncrementalOutput();
			case Process::ERR:
			case Process::STDERR:
				return $this->process->getIncrementalErrorOutput();
			case Process::OUTPUT_FILE:
				$result = fread( $this->output_file_fp, 8192 );
				if ( false === $result ) {
					return '';
				}
				return $result;
			default:
				throw new \Exception( 'Invalid pipe name "' . $this->pipe . '"' );
		}
	}

	protected function internal_reached_end_of_data(): bool {
		if ( false === $this->process->isTerminated() ) {
			return false;
		}
		$this->last_output .= $this->get_incremental_output();
		if ( strlen( $this->last_output ) > 0 ) {
			return false;
		}
		return true;
	}

	protected function internal_close_reading(): void {
		if ( $this->output_file_fp ) {
			fclose( $this->output_file_fp );
			$this->output_file_fp = null;
		}
	}
}
