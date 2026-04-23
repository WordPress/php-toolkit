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

namespace VendorPrefix\League\CommonMark\Extension\CommonMark\Parser\Block;

use VendorPrefix\League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use VendorPrefix\League\CommonMark\Parser\Block\AbstractBlockContinueParser;
use VendorPrefix\League\CommonMark\Parser\Block\BlockContinue;
use VendorPrefix\League\CommonMark\Parser\Block\BlockContinueParserInterface;
use VendorPrefix\League\CommonMark\Parser\Cursor;

final class ThematicBreakParser extends AbstractBlockContinueParser
{
    /** @psalm-readonly
     * @var \VendorPrefix\League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak */
    private $block;

    public function __construct()
    {
        $this->block = new ThematicBreak();
    }

    public function getBlock(): \VendorPrefix\League\CommonMark\Node\Block\AbstractBlock
    {
        return $this->block;
    }

    public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): ?BlockContinue
    {
        // a horizontal rule can never container > 1 line, so fail to match
        return BlockContinue::none();
    }
}
