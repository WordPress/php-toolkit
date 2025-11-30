<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace VendorPrefix\Nette\Schema;

use Nette;
use VendorPrefix\Nette\Schema\Elements\AnyOf;
use VendorPrefix\Nette\Schema\Elements\Structure;
use VendorPrefix\Nette\Schema\Elements\Type;


/**
 * Schema generator.
 *
 * @method static Type scalar($default = null)
 * @method static Type string($default = null)
 * @method static Type int($default = null)
 * @method static Type float($default = null)
 * @method static Type bool($default = null)
 * @method static Type null()
 * @method static Type list($default = [])
 * @method static Type mixed($default = null)
 * @method static Type email($default = null)
 * @method static Type unicode($default = null)
 */
final class Expect
{
	public static function __callStatic(string $name, array $args): Type
	{
		$type = new Type($name);
		if ($args) {
			$type->default($args[0]);
		}

		return $type;
	}


	public static function type(string $type): Type
	{
		return new Type($type);
	}


	/**
  * @param mixed ...$set
  */
 public static function anyOf(...$set): AnyOf
	{
		return new AnyOf(...$set);
	}


	/**
	 * @param  Schema[]  $shape
	 */
	public static function structure(array $shape): Structure
	{
		return new Structure($shape);
	}


	public static function from(object $object, array $items = []): Structure
	{
		$ro = new \ReflectionObject($object);
		$props = $ro->hasMethod('__construct')
			? $ro->getMethod('__construct')->getParameters()
			: $ro->getProperties();

		foreach ($props as $prop) {
			$item = &$items[$prop->getName()];
			if (!$item) {
				$type = Helpers::getPropertyType($prop) ?? 'mixed';
				$item = new Type($type);
				if ($prop instanceof \ReflectionProperty ? $prop->isInitialized($object) : $prop->isOptional()) {
					$def = ($prop instanceof \ReflectionProperty ? $prop->getValue($object) : $prop->getDefaultValue());
					if (is_object($def)) {
						$item = static::from($def);
					} elseif ($def === null && !VendorPrefix\Nette\Utils\Validators::is(null, $type)) {
						$item->required();
					} else {
						$item->default($def);
					}
				} else {
					$item->required();
				}
			}
		}

		return (new Structure($items))->castTo($ro->getName());
	}


	/**
  * @param  mixed[]  $shape
  * @return \VendorPrefix\Nette\Schema\Elements\Structure|\VendorPrefix\Nette\Schema\Elements\Type
  */
 public static function array(?array $shape = [])
	{
		return VendorPrefix\Nette\Utils\Arrays::first($shape ?? []) instanceof Schema
			? (new Structure($shape))->castTo('array')
			: (new Type('array'))->default($shape);
	}


	/**
  * @param string|\VendorPrefix\Nette\Schema\Schema $valueType
  * @param string|\VendorPrefix\Nette\Schema\Schema|null $keyType
  */
 public static function arrayOf($valueType, $keyType = null): Type
	{
		return (new Type('array'))->items($valueType, $keyType);
	}


	/**
  * @param string|\VendorPrefix\Nette\Schema\Schema $type
  */
 public static function listOf($type): Type
	{
		return (new Type('list'))->items($type);
	}
}
