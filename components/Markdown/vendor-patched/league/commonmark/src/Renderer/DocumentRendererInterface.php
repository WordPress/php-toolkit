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

namespace VendorPrefix\League\CommonMark\Renderer;

use VendorPrefix\League\CommonMark\Node\Block\Document;
use VendorPrefix\League\CommonMark\Output\RenderedContentInterface;

/**
 * Renders a parsed Document AST
 */
interface DocumentRendererInterface extends MarkdownRendererInterface
{
    /**
     * Render the given Document node (and all of its children)
     */
    public function renderDocument(Document $document): RenderedContentInterface;
}
