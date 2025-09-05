<?php

namespace WordPress\Blueprints\Steps;

use WordPress\Blueprints\DataReference\DataReference;
use WordPress\Blueprints\DataReference\File;
use WordPress\Blueprints\Exception\BlueprintExecutionException;
use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;

/**
 * Represents the 'runPHP' step.
 */
class RunPHPStep implements StepInterface {
	/**
	 * @var DataReference
	 */
	public $code;
	/**
	 * @var string|null
	 */
	public $scriptPath;
	/** @var array<string, string>|null */
	public $env;

	public function __construct( DataReference $code, ?array $env = null ) {
		$this->code = $code;
		$this->env  = $env;
	}

	public function run( Runtime $runtime, Tracker $tracker ) {
		$tracker->setCaption( 'Running custom PHP code' );

		$env          = $this->env ?? array();
		$resolvedCode = $runtime->resolve( $this->code );
		if ( $resolvedCode instanceof File ) {
			$code = $resolvedCode->getStream()->consume_all();
		} else {
			throw new BlueprintExecutionException( 'The code property must be a File reference.' );
		}
		$runtime->evalPhpCodeInSubProcess( $code, $env );
	}
}
