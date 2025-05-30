<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Nette\Schema\Elements;

use Nette;
use Nette\Schema\Context;
use Nette\Schema\Helpers;
use Nette\Schema\Schema;


final class Structure implements Schema
{
	use Base;

	/** @var Schema[] */
	private $items;

	/** for array|list
  * @var \Nette\Schema\Schema|null */
 private $otherItems;

	/** @var array{?int, ?int} */
	private $range = [null, null];
	/**
  * @var bool
  */
 private $skipDefaults = false;


	/**
	 * @param  Schema[]  $shape
	 */
	public function __construct(array $shape)
	{
		(function (Schema ...$items) {})(...array_values($shape));
		$this->items = $shape;
		$this->castTo('object');
		$this->required = true;
	}


	/**
  * @param mixed $value
  */
 public function default($value): self
	{
		throw new Nette\InvalidStateException('Structure cannot have default value.');
	}


	public function min(?int $min): self
	{
		$this->range[0] = $min;
		return $this;
	}


	public function max(?int $max): self
	{
		$this->range[1] = $max;
		return $this;
	}


	/**
  * @param string|\Nette\Schema\Schema $type
  */
 public function otherItems($type = 'mixed'): self
	{
		$this->otherItems = $type instanceof Schema ? $type : new Type($type);
		return $this;
	}


	public function skipDefaults(bool $state = true): self
	{
		$this->skipDefaults = $state;
		return $this;
	}


	/**
  * @param mixed[]|$this $shape
  */
 public function extend($shape): self
	{
		$shape = $shape instanceof self ? $shape->items : $shape;
		return new self(array_merge($this->items, $shape));
	}


	public function getShape(): array
	{
		return $this->items;
	}


	/********************* processing ****************d*g**/
 /**
  * @param mixed $value
  * @return mixed
  */
 public function normalize($value, Context $context)
	{
		if ($prevent = (is_array($value) && isset($value[Helpers::PreventMerging]))) {
			unset($value[Helpers::PreventMerging]);
		}

		$value = $this->doNormalize($value, $context);
		if (is_object($value)) {
			$value = (array) $value;
		}

		if (is_array($value)) {
			foreach ($value as $key => $val) {
				$itemSchema = $this->items[$key] ?? $this->otherItems;
				if ($itemSchema) {
					$context->path[] = $key;
					$value[$key] = $itemSchema->normalize($val, $context);
					array_pop($context->path);
				}
			}

			if ($prevent) {
				$value[Helpers::PreventMerging] = true;
			}
		}

		return $value;
	}


	/**
  * @param mixed $value
  * @param mixed $base
  * @return mixed
  */
 public function merge($value, $base)
	{
		if (is_array($value) && isset($value[Helpers::PreventMerging])) {
			unset($value[Helpers::PreventMerging]);
			$base = null;
		}

		if (is_array($value) && is_array($base)) {
			$index = $this->otherItems === null ? null : 0;
			foreach ($value as $key => $val) {
				if ($key === $index) {
					$base[] = $val;
					$index++;
				} else {
					$base[$key] = array_key_exists($key, $base) && ($itemSchema = $this->items[$key] ?? $this->otherItems)
						? $itemSchema->merge($val, $base[$key])
						: $val;
				}
			}

			return $base;
		}

		return $value ?? $base;
	}


	/**
  * @param mixed $value
  * @return mixed
  */
 public function complete($value, Context $context)
	{
		if ($value === null) {
			$value = []; // is unable to distinguish null from array in NEON
		}

		$this->doDeprecation($context);

		$isOk = $context->createChecker();
		Helpers::validateType($value, 'array', $context);
		$isOk() && Helpers::validateRange($value, $this->range, $context);
		$isOk() && $this->validateItems($value, $context);
		$isOk() && $value = $this->doTransform($value, $context);
		return $isOk() ? $value : null;
	}


	private function validateItems(array &$value, Context $context): void
	{
		$items = $this->items;
		if ($extraKeys = array_keys(array_diff_key($value, $items))) {
			if ($this->otherItems) {
				$items += array_fill_keys($extraKeys, $this->otherItems);
			} else {
				$keys = array_map('strval', array_keys($items));
				foreach ($extraKeys as $key) {
					$hint = Nette\Utils\Helpers::getSuggestion($keys, (string) $key);
					$context->addError('Unexpected item %path%' . ($hint ? ", did you mean '%hint%'?" : '.'), Nette\Schema\Message::UnexpectedItem, ['hint' => $hint])->path[] = $key;
				}
			}
		}

		foreach ($items as $itemKey => $itemVal) {
			$context->path[] = $itemKey;
			if (array_key_exists($itemKey, $value)) {
				$value[$itemKey] = $itemVal->complete($value[$itemKey], $context);
			} else {
				$default = $itemVal->completeDefault($context); // checks required item
				if (!$context->skipDefaults && !$this->skipDefaults) {
					$value[$itemKey] = $default;
				}
			}

			array_pop($context->path);
		}
	}


	/**
  * @return mixed
  */
 public function completeDefault(Context $context)
	{
		return $this->required
			? $this->complete([], $context)
			: null;
	}
}
