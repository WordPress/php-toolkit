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

use VendorPrefix\League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use VendorPrefix\League\CommonMark\Node\Node;
use VendorPrefix\League\CommonMark\Renderer\ChildNodeRendererInterface;
use VendorPrefix\League\CommonMark\Renderer\NodeRendererInterface;
use VendorPrefix\League\CommonMark\Util\HtmlElement;
use VendorPrefix\League\CommonMark\Util\Xml;
use VendorPrefix\League\CommonMark\Xml\XmlNodeRendererInterface;

final class CodeRenderer implements NodeRendererInterface, XmlNodeRendererInterface
{
    /**
     * @param Code $node
     *
     * {@inheritDoc}
     *
     * @psalm-suppress MoreSpecificImplementedParamType
     */
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable
    {
        Code::assertInstanceOf($node);

        $attrs = $node->data->get('attributes');

        return new HtmlElement('code', $attrs, Xml::escape($node->getLiteral()));
    }

    public function getXmlTagName(Node $node): string
    {
        return 'code';
    }

    /**
     * {@inheritDoc}
     */
    public function getXmlAttributes(Node $node): array
    {
        return [];
    }
}
