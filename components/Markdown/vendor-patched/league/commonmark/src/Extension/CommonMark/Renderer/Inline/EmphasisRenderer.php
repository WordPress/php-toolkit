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

namespace VendorPrefix\League\CommonMark\Extension\CommonMark\Renderer\Inline;

use VendorPrefix\League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use VendorPrefix\League\CommonMark\Node\Node;
use VendorPrefix\League\CommonMark\Renderer\ChildNodeRendererInterface;
use VendorPrefix\League\CommonMark\Renderer\NodeRendererInterface;
use VendorPrefix\League\CommonMark\Util\HtmlElement;
use VendorPrefix\League\CommonMark\Xml\XmlNodeRendererInterface;

final class EmphasisRenderer implements NodeRendererInterface, XmlNodeRendererInterface
{
    /**
     * @param Emphasis $node
     *
     * {@inheritDoc}
     *
     * @psalm-suppress MoreSpecificImplementedParamType
     */
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable
    {
        Emphasis::assertInstanceOf($node);

        $attrs = $node->data->get('attributes');

        return new HtmlElement('em', $attrs, $childRenderer->renderNodes($node->children()));
    }

    public function getXmlTagName(Node $node): string
    {
        return 'emph';
    }

    /**
     * {@inheritDoc}
     */
    public function getXmlAttributes(Node $node): array
    {
        return [];
    }
}
