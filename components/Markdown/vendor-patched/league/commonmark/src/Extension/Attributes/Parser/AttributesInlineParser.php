<?php

/*
 * This file is part of the league/commonmark package.
 *
 * (c) Colin O'Dell <colinodell@gmail.com>
 * (c) 2015 Martin Hasoň <martin.hason@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace VendorPrefix\League\CommonMark\Extension\Attributes\Parser;

use VendorPrefix\League\CommonMark\Extension\Attributes\Node\AttributesInline;
use VendorPrefix\League\CommonMark\Extension\Attributes\Util\AttributesHelper;
use VendorPrefix\League\CommonMark\Node\StringContainerInterface;
use VendorPrefix\League\CommonMark\Parser\Inline\InlineParserInterface;
use VendorPrefix\League\CommonMark\Parser\Inline\InlineParserMatch;
use VendorPrefix\League\CommonMark\Parser\InlineParserContext;

final class AttributesInlineParser implements InlineParserInterface
{
    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::string('{');
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $cursor = $inlineContext->getCursor();
        $char   = (string) $cursor->peek(-1);

        $attributes = AttributesHelper::parseAttributes($cursor);
        if ($attributes === []) {
            return false;
        }

        if ($char === ' ' && ($prev = $inlineContext->getContainer()->lastChild()) instanceof StringContainerInterface) {
            $prev->setLiteral(\rtrim($prev->getLiteral(), ' '));
        }

        if ($char === '') {
            $cursor->advanceToNextNonSpaceOrNewline();
        }

        $node = new AttributesInline($attributes, $char === ' ' || $char === '');
        $inlineContext->getContainer()->appendChild($node);

        return true;
    }
}
