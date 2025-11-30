<?php

declare(strict_types=1);

/*
 * This file is part of the league/commonmark package.
 *
 * (c) Colin O'Dell <colinodell@gmail.com>
 *
 * Original code based on the CommonMark JS reference parser (https://bitly.com/commonmark-js)
 *  - (c) John MacFarlane
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace VendorPrefix\League\CommonMark\Reference;

/**
 * @psalm-immutable
 */
final class Reference implements ReferenceInterface
{
    /** @psalm-readonly
     * @var string */
    private $label;

    /** @psalm-readonly
     * @var string */
    private $destination;

    /** @psalm-readonly
     * @var string */
    private $title;

    public function __construct(string $label, string $destination, string $title)
    {
        $this->label       = $label;
        $this->destination = $destination;
        $this->title       = $title;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getDestination(): string
    {
        return $this->destination;
    }

    public function getTitle(): string
    {
        return $this->title;
    }
}
