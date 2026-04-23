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

namespace VendorPrefix\League\CommonMark\Parser\Block;

use VendorPrefix\League\CommonMark\Node\Block\Paragraph;
use VendorPrefix\League\CommonMark\Parser\Cursor;
use VendorPrefix\League\CommonMark\Parser\InlineParserEngineInterface;
use VendorPrefix\League\CommonMark\Reference\ReferenceInterface;
use VendorPrefix\League\CommonMark\Reference\ReferenceParser;

final class ParagraphParser extends AbstractBlockContinueParser implements BlockContinueParserWithInlinesInterface
{
    /** @psalm-readonly
     * @var \VendorPrefix\League\CommonMark\Node\Block\Paragraph */
    private $block;

    /** @psalm-readonly
     * @var \VendorPrefix\League\CommonMark\Reference\ReferenceParser */
    private $referenceParser;

    public function __construct()
    {
        $this->block           = new Paragraph();
        $this->referenceParser = new ReferenceParser();
    }

    public function canHaveLazyContinuationLines(): bool
    {
        return true;
    }

    public function getBlock(): \VendorPrefix\League\CommonMark\Node\Block\AbstractBlock
    {
        return $this->block;
    }

    public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): ?BlockContinue
    {
        if ($cursor->isBlank()) {
            return BlockContinue::none();
        }

        return BlockContinue::at($cursor);
    }

    public function addLine(string $line): void
    {
        $this->referenceParser->parse($line);
    }

    public function closeBlock(): void
    {
        $this->block->onlyContainsLinkReferenceDefinitions = $this->referenceParser->hasReferences() && $this->referenceParser->getParagraphContent() === '';
    }

    public function parseInlines(InlineParserEngineInterface $inlineParser): void
    {
        $content = $this->getContentString();
        if ($content !== '') {
            $inlineParser->parse($content, $this->block);
        }
    }

    public function getContentString(): string
    {
        return $this->referenceParser->getParagraphContent();
    }

    /**
     * @return ReferenceInterface[]
     */
    public function getReferences(): iterable
    {
        return $this->referenceParser->getReferences();
    }
}
