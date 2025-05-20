<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Nette\Schema;


interface Schema
{
	/**
  * Normalization.
  * @return mixed
  * @param mixed $value
  */
 function normalize($value, Context $context);

	/**
  * Merging.
  * @return mixed
  * @param mixed $value
  * @param mixed $base
  */
 function merge($value, $base);

	/**
  * Validation and finalization.
  * @return mixed
  * @param mixed $value
  */
 function complete($value, Context $context);

	/**
	 * @return mixed
	 */
	function completeDefault(Context $context);
}
