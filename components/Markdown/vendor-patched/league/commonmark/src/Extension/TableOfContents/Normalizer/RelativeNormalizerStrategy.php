<?php

declare(strict_types=1);

/*
 * This file is part of the league/commonmark package.
 *
 * (c) Colin O'Dell <colinodell@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace VendorPrefix\League\CommonMark\Extension\TableOfContents\Normalizer;

use VendorPrefix\League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use VendorPrefix\League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use VendorPrefix\League\CommonMark\Extension\TableOfContents\Node\TableOfContents;

final class RelativeNormalizerStrategy implements NormalizerStrategyInterface
{
    /** @psalm-readonly
     * @var \VendorPrefix\League\CommonMark\Extension\TableOfContents\Node\TableOfContents */
    private $toc;

    /**
     * @var array<int, ListItem>
     *
     * @psalm-readonly-allow-private-mutation
     */
    private $listItemStack = [];

    public function __construct(TableOfContents $toc)
    {
        $this->toc = $toc;
    }

    public function addItem(int $level, ListItem $listItemToAdd): void
    {
        end($this->listItemStack);
        $previousLevel = key($this->listItemStack);
        reset($this->listItemStack);

        // Pop the stack if we're too deep
        while ($previousLevel !== null && $level < $previousLevel) {
            \array_pop($this->listItemStack);
            end($this->listItemStack);
            $previousLevel = key($this->listItemStack);
            reset($this->listItemStack);
        }

        $lastListItem = \end($this->listItemStack);

        // Need to go one level deeper? Add that level
        if ($lastListItem !== false && $level > $previousLevel) {
            $targetListBlock = new ListBlock($lastListItem->getListData());
            $targetListBlock->setStartLine($listItemToAdd->getStartLine());
            $targetListBlock->setEndLine($listItemToAdd->getEndLine());
            $lastListItem->appendChild($targetListBlock);
        // Otherwise we're at the right level
        // If there's no stack we're adding this item directly to the TOC element
        } elseif ($lastListItem === false) {
            $targetListBlock = $this->toc;
        // Otherwise add it to the last list item
        } else {
            $targetListBlock = $lastListItem->parent();
        }

        $targetListBlock->appendChild($listItemToAdd);
        $this->listItemStack[$level] = $listItemToAdd;
    }
}
