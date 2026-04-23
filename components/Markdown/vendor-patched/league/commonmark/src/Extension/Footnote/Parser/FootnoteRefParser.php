<?php

/*
 * This file is part of the league/commonmark package.
 *
 * (c) Colin O'Dell <colinodell@gmail.com>
 * (c) Rezo Zero / Ambroise Maupate
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace VendorPrefix\League\CommonMark\Extension\Footnote\Parser;

use VendorPrefix\League\CommonMark\Extension\Footnote\Node\FootnoteRef;
use VendorPrefix\League\CommonMark\Parser\Inline\InlineParserInterface;
use VendorPrefix\League\CommonMark\Parser\Inline\InlineParserMatch;
use VendorPrefix\League\CommonMark\Parser\InlineParserContext;
use VendorPrefix\League\CommonMark\Reference\Reference;
use VendorPrefix\League\Config\ConfigurationAwareInterface;
use VendorPrefix\League\Config\ConfigurationInterface;

final class FootnoteRefParser implements InlineParserInterface, ConfigurationAwareInterface
{
    /**
     * @var \VendorPrefix\League\Config\ConfigurationInterface
     */
    private $config;

    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::regex('\[\^([^\s\]]+)\]');
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $inlineContext->getCursor()->advanceBy($inlineContext->getFullMatchLength());

        [$label] = $inlineContext->getSubMatches();
        $inlineContext->getContainer()->appendChild(new FootnoteRef($this->createReference($label)));

        return true;
    }

    private function createReference(string $label): Reference
    {
        return new Reference(
            $label,
            '#' . $this->config->get('footnote/footnote_id_prefix') . $label,
            $label
        );
    }

    public function setConfiguration(ConfigurationInterface $configuration): void
    {
        $this->config = $configuration;
    }
}
