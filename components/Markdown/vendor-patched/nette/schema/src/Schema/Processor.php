<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace VendorPrefix\Nette\Schema;

use VendorPrefix\Nette;


/**
 * Schema validator.
 */
final class Processor
{
	/**
  * @var mixed[]
  */
 public $onNewContext = [];
	/**
  * @var \VendorPrefix\Nette\Schema\Context
  */
 private $context;
	/**
  * @var bool
  */
 private $skipDefaults = false;


	public function skipDefaults(bool $value = true): void
	{
		$this->skipDefaults = $value;
	}


	/**
  * Normalizes and validates data. Result is a clean completed data.
  * @throws ValidationException
  * @param mixed $data
  * @return mixed
  */
 public function process(Schema $schema, $data)
	{
		$this->createContext();
		$data = $schema->normalize($data, $this->context);
		$this->throwsErrors();
		$data = $schema->complete($data, $this->context);
		$this->throwsErrors();
		return $data;
	}


	/**
  * Normalizes and validates and merges multiple data. Result is a clean completed data.
  * @throws ValidationException
  * @return mixed
  */
 public function processMultiple(Schema $schema, array $dataset)
	{
		$this->createContext();
		$flatten = null;
		$first = true;
		foreach ($dataset as $data) {
			$data = $schema->normalize($data, $this->context);
			$this->throwsErrors();
			$flatten = $first ? $data : $schema->merge($data, $flatten);
			$first = false;
		}

		$data = $schema->complete($flatten, $this->context);
		$this->throwsErrors();
		return $data;
	}


	/**
	 * @return string[]
	 */
	public function getWarnings(): array
	{
		$res = [];
		foreach ($this->context->warnings as $message) {
			$res[] = $message->toString();
		}

		return $res;
	}


	private function throwsErrors(): void
	{
		if ($this->context->errors) {
			throw new ValidationException(null, $this->context->errors);
		}
	}


	private function createContext(): void
	{
		$this->context = new Context;
		$this->context->skipDefaults = $this->skipDefaults;
		VendorPrefix\Nette\Utils\Arrays::invoke($this->onNewContext, $this->context);
	}
}
