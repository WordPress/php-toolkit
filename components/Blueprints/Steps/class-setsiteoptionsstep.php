<?php

namespace WordPress\Blueprints\Steps;

use WordPress\Blueprints\Progress\Tracker;
use WordPress\Blueprints\Runtime;

/**
 * Represents the 'setSiteOptions' step.
 */
class SetSiteOptionsStep implements StepInterface {
	/**
	 * An associative array of option names to their JSON-compatible values.
	 *
	 * @var array<string, mixed>
	 */
	public $options;

	/**
	 * @param  array<string, mixed> $options  Site options to set.
	 */
	public function __construct( array $options ) {
		$this->options = $options;
	}

	public function run( Runtime $runtime, Tracker $tracker ) {
		$tracker->setCaption( 'Setting site options' );
		$runtime->eval_php_code_in_subprocess(
			'<?php
		require getenv(\'WP_CORE_DIR\'). \'/wp-load.php\';
		$site_options = getenv("OPTIONS") ? json_decode(getenv("OPTIONS"), true) : [];
		foreach($site_options as $name => $value) {
			update_option($name, $value);
		}
',
			array( 'OPTIONS' => json_encode( $this->options ) )
		);
	}
}
