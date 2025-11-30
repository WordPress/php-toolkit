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

namespace VendorPrefix\League\CommonMark\Environment;

use VendorPrefix\League\CommonMark\Delimiter\Processor\DelimiterProcessorCollection;
use VendorPrefix\League\CommonMark\Extension\ExtensionInterface;
use VendorPrefix\League\CommonMark\Node\Node;
use VendorPrefix\League\CommonMark\Normalizer\TextNormalizerInterface;
use VendorPrefix\League\CommonMark\Parser\Block\BlockStartParserInterface;
use VendorPrefix\League\CommonMark\Parser\Inline\InlineParserInterface;
use VendorPrefix\League\CommonMark\Renderer\NodeRendererInterface;
use VendorPrefix\League\Config\ConfigurationProviderInterface;
use VendorPrefix\Psr\EventDispatcher\EventDispatcherInterface;

interface EnvironmentInterface extends ConfigurationProviderInterface, EventDispatcherInterface
{
    /**
     * Get all registered extensions
     *
     * @return ExtensionInterface[]
     */
    public function getExtensions(): iterable;

    /**
     * @return iterable<BlockStartParserInterface>
     */
    public function getBlockStartParsers(): iterable;

    /**
     * @return iterable<InlineParserInterface>
     */
    public function getInlineParsers(): iterable;

    public function getDelimiterProcessors(): DelimiterProcessorCollection;

    /**
     * @psalm-param class-string<Node> $nodeClass
     *
     * @return iterable<NodeRendererInterface>
     */
    public function getRenderersForClass(string $nodeClass): iterable;

    public function getSlugNormalizer(): TextNormalizerInterface;
}
