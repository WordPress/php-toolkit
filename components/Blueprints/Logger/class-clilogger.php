<?php

namespace WordPress\Blueprints\Logger;

use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;

class CLILogger implements LoggerInterface {
	private $stream;
	private $verbosity;

	const LEVELS = [
		'emergency' => 0,
		'alert'     => 1,
		'critical'  => 2,
		'error'     => 3,
		'warning'   => 4,
		'notice'    => 5,
		'info'      => 6,
		'debug'     => 7,
	];

	const COLORS = [
		'emergency' => "\033[1;31m", // Red
		'alert'     => "\033[1;31m",     // Red
		'critical'  => "\033[1;31m",  // Red
		'error'     => "\033[0;31m",    // Light Red
		'warning'   => "\033[0;33m",  // Yellow
		'notice'    => "\033[0;32m",   // Green
		'info'      => "\033[0;36m",     // Cyan
		'debug'     => "\033[0;37m",    // White
	];

	const VERBOSITY_DEBUG = 7;
	const VERBOSITY_INFO = 6;
	const VERBOSITY_NOTICE = 5;
	const VERBOSITY_WARNING = 4;
	const VERBOSITY_ERROR = 3;
	const VERBOSITY_CRITICAL = 2;
	const VERBOSITY_ALERT = 1;
	const VERBOSITY_EMERGENCY = 0;

	public function __construct( $stream = 'php://stdout', $verbosity = self::VERBOSITY_DEBUG ) {
		$this->stream    = fopen( $stream, 'w' );
		$this->verbosity = $verbosity;
	}

	public function __destruct() {
		fclose( $this->stream );
	}

	private function logMessage( $level, string $message, array $context = [] ): void {
		if ( self::LEVELS[ $level ] > $this->verbosity ) {
			return;
		}

		$color            = self::COLORS[ $level ] ?? "\033[0m";
		$reset            = "\033[0m";
		$formatted_message = $this->interpolate( $message, $context );
		fwrite( $this->stream, "\n{$color}[{$level}] {$formatted_message}{$reset}\n" );
	}

	private function interpolate( string $message, array $context = [] ): string {
		$replace = [];
		foreach ( $context as $key => $val ) {
			$replace[ '{' . $key . '}' ] = $val;
		}

		return strtr( $message, $replace );
	}

	/**
	 * @param  string|Stringable  $message
	 */
	public function emergency( $message, array $context = [] ): void {
		$this->logMessage( 'emergency', $message, $context );
	}

	/**
	 * @param  string|Stringable  $message
	 */
	public function alert( $message, array $context = [] ): void {
		$this->logMessage( 'alert', $message, $context );
	}

	/**
	 * @param  string|Stringable  $message
	 */
	public function critical( $message, array $context = [] ): void {
		$this->logMessage( 'critical', $message, $context );
	}

	/**
	 * @param  string|Stringable  $message
	 */
	public function error( $message, array $context = [] ): void {
		$this->logMessage( 'error', $message, $context );
	}

	/**
	 * @param  string|Stringable  $message
	 */
	public function warning( $message, array $context = [] ): void {
		$this->logMessage( 'warning', $message, $context );
	}

	/**
	 * @param  string|Stringable  $message
	 */
	public function notice( $message, array $context = [] ): void {
		$this->logMessage( 'notice', $message, $context );
	}

	/**
	 * @param  string|Stringable  $message
	 */
	public function info( $message, array $context = [] ): void {
		$this->logMessage( 'info', $message, $context );
	}

	/**
	 * @param  string|Stringable  $message
	 */
	public function debug( $message, array $context = [] ): void {
		$this->logMessage( 'debug', $message, $context );
	}

	/**
	 * @param  string|Stringable  $message
	 */
	public function log( $level, $message, array $context = [] ): void {
		if ( ! isset( self::LEVELS[ $level ] ) ) {
			throw new InvalidArgumentException( 'Invalid log level: ' . $level );
		}
		$this->logMessage( $level, $message, $context );
	}
}

