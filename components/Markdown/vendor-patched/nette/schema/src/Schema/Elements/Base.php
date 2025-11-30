<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace VendorPrefix\Nette\Schema\Elements;

use VendorPrefix\Nette;
use VendorPrefix\Nette\Schema\Context;
use VendorPrefix\Nette\Schema\Helpers;


/**
 * @internal
 */
trait Base
{
	/**
  * @var bool
  */
 private $required = false;
	/**
  * @var mixed
  */
 private $default = null;

	/** @var ?callable */
	private $before;

	/** @var callable[] */
	private $transforms = [];
	/**
  * @var string|null
  */
 private $deprecated;


	/**
  * @param mixed $value
  */
 public function default($value): self
	{
		$this->default = $value;
		return $this;
	}


	public function required(bool $state = true): self
	{
		$this->required = $state;
		return $this;
	}


	public function before(callable $handler): self
	{
		$this->before = $handler;
		return $this;
	}


	public function castTo(string $type): self
	{
		return $this->transform(Helpers::getCastStrategy($type));
	}


	public function transform(callable $handler): self
	{
		$this->transforms[] = $handler;
		return $this;
	}


	public function assert(callable $handler, ?string $description = null): self
	{
		$expected = $description ?: (is_string($handler) ? "$handler()" : '#' . count($this->transforms));
		return $this->transform(function ($value, Context $context) use ($handler, $description, $expected) {
			if ($handler($value)) {
				return $value;
			}
			$context->addError('Failed assertion ' . ($description ? "'%assertion%'" : '%assertion%') . ' for %label% %path% with value %value%.', VendorPrefix\Nette\Schema\Message::FailedAssertion, ['value' => $value, 'assertion' => $expected]);
		});
	}


	/** Marks as deprecated */
	public function deprecated(string $message = 'The item %path% is deprecated.'): self
	{
		$this->deprecated = $message;
		return $this;
	}


	/**
  * @return mixed
  */
 public function completeDefault(Context $context)
	{
		if ($this->required) {
			$context->addError('The mandatory item %path% is missing.', VendorPrefix\Nette\Schema\Message::MissingItem);
			return null;
		}

		return $this->default;
	}


	/**
  * @param mixed $value
  * @return mixed
  */
 public function doNormalize($value, Context $context)
	{
		if ($this->before) {
			$value = ($this->before)($value);
		}

		return $value;
	}


	private function doDeprecation(Context $context): void
	{
		if ($this->deprecated !== null) {
			$context->addWarning($this->deprecated, VendorPrefix\Nette\Schema\Message::Deprecated);
		}
	}


	/**
  * @param mixed $value
  * @return mixed
  */
 private function doTransform($value, Context $context)
	{
		$isOk = $context->createChecker();
		foreach ($this->transforms as $handler) {
			$value = $handler($value, $context);
			if (!$isOk()) {
				return null;
			}
		}
		return $value;
	}


	/** @deprecated use VendorPrefix\Nette\Schema\Validators::validateType()
  * @param mixed $value */
 private function doValidate($value, string $expected, Context $context): bool
	{
		$isOk = $context->createChecker();
		Helpers::validateType($value, $expected, $context);
		return $isOk();
	}


	/** @deprecated use VendorPrefix\Nette\Schema\Validators::validateRange()
  * @param mixed $value */
 private static function doValidateRange($value, array $range, Context $context, string $types = ''): bool
	{
		$isOk = $context->createChecker();
		Helpers::validateRange($value, $range, $context, $types);
		return $isOk();
	}


	/** @deprecated use doTransform()
  * @param mixed $value
  * @return mixed */
 private function doFinalize($value, Context $context)
	{
		return $this->doTransform($value, $context);
	}
}
