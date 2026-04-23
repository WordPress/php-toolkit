<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace VendorPrefix\Nette\Utils;

use VendorPrefix\Nette;

if (false) {
	/** @deprecated use VendorPrefix\Nette\HtmlStringable */
	interface IHtmlString extends VendorPrefix\Nette\HtmlStringable
	{
	}
} elseif (!interface_exists(IHtmlString::class)) {
	class_alias(VendorPrefix\Nette\HtmlStringable::class, IHtmlString::class);
}

namespace VendorPrefix\Nette\Localization;

if (false) {
	/** @deprecated use VendorPrefix\Nette\Localization\Translator */
	interface ITranslator extends Translator
	{
	}
} elseif (!interface_exists(ITranslator::class)) {
	class_alias(Translator::class, ITranslator::class);
}
