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

namespace VendorPrefix\League\CommonMark\Extension\TableOfContents;

use VendorPrefix\League\CommonMark\Extension\TableOfContents\Node\TableOfContentsPlaceholder;
use VendorPrefix\League\CommonMark\Parser\Block\AbstractBlockContinueParser;
use VendorPrefix\League\CommonMark\Parser\Block\BlockContinue;
use VendorPrefix\League\CommonMark\Parser\Block\BlockContinueParserInterface;
use VendorPrefix\League\CommonMark\Parser\Block\BlockStart;
use VendorPrefix\League\CommonMark\Parser\Block\BlockStartParserInterface;
use VendorPrefix\League\CommonMark\Parser\Cursor;
use VendorPrefix\League\CommonMark\Parser\MarkdownParserStateInterface;
use VendorPrefix\League\Config\ConfigurationAwareInterface;
use VendorPrefix\League\Config\ConfigurationInterface;

final class TableOfContentsPlaceholderParser extends AbstractBlockContinueParser
{
    /** @psalm-readonly
     * @var \VendorPrefix\League\CommonMark\Extension\TableOfContents\Node\TableOfContentsPlaceholder */
    private $block;

    public function __construct()
    {
        $this->block = new TableOfContentsPlaceholder();
    }

    public function getBlock(): \VendorPrefix\League\CommonMark\Node\Block\AbstractBlock
    {
        return $this->block;
    }

    public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): ?BlockContinue
    {
        return BlockContinue::none();
    }

    public static function blockStartParser(): BlockStartParserInterface
    {
        return new class () implements BlockStartParserInterface, ConfigurationAwareInterface {
            /** @psalm-readonly-allow-private-mutation
             * @var \VendorPrefix\League\Config\ConfigurationInterface */
            private $config;

            public function tryStart(Cursor $cursor, MarkdownParserStateInterface $parserState): ?BlockStart
            {
                $placeholder = $this->config->get('table_of_contents/placeholder');
                if ($placeholder === null) {
                    return BlockStart::none();
                }

                // The placeholder must be the only thing on the line
                if ($cursor->match('/^' . \preg_quote($placeholder, '/') . '$/') === null) {
                    return BlockStart::none();
                }

                return BlockStart::of(new TableOfContentsPlaceholderParser())->at($cursor);
            }

            public function setConfiguration(ConfigurationInterface $configuration): void
            {
                $this->config = $configuration;
            }
        };
    }
}
