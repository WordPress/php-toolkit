<?php

namespace WordPress\Blueprints\Exception;

use Throwable;

class PermissionsException extends BlueprintExecutionException {
	/**
	 * @var string
	 */
	private $permission;
	/**
	 * @var string
	 */
	private $php_api_tip;

	public function __construct(
		string $permission,
		string $message = '',
		string $php_api_tip = '',
		int $code = 0,
		?Throwable $previous = null
	) {
		parent::__construct( $message, $code, $previous );
		$this->permission  = $permission;
		$this->php_api_tip = $php_api_tip;
	}

	public function getPermission(): string {
		return $this->permission;
	}

	public function getPhpApiTip(): string {
		return $this->php_api_tip;
	}
}
