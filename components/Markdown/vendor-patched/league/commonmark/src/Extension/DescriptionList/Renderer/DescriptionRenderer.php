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

namespace VendorPrefix\League\CommonMark\Extension\DescriptionList\Renderer;

use VendorPrefix\League\CommonMark\Extension\DescriptionList\Node\Description;
use VendorPrefix\League\CommonMark\Node\Node;
use VendorPrefix\League\CommonMark\Renderer\ChildNodeRendererInterface;
use VendorPrefix\League\CommonMark\Renderer\NodeRendererInterface;
use VendorPrefix\League\CommonMark\Util\HtmlElement;

final class DescriptionRenderer implements NodeRendererInterface
{
    /**
     * @param Description $node
     *
     * {@inheritDoc}
     *
     * @psalm-suppress MoreSpecificImplementedParamType
     */
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable
    {
        Description::assertInstanceOf($node);

        return new HtmlElement('dd', [], $childRenderer->renderNodes($node->children()));
    }
}
