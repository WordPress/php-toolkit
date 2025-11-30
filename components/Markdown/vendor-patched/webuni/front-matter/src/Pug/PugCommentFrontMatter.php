<?php

/*
 * This is part of the webuni/front-matter package.
 *
 * (c) Martin Hasoň <martin.hason@gmail.com>
 * (c) Webuni s.r.o. <info@webuni.cz>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace VendorPrefix\Webuni\FrontMatter\Pug;

use VendorPrefix\Webuni\FrontMatter\FrontMatter;
use VendorPrefix\Webuni\FrontMatter\Processor\ProcessorInterface;
use VendorPrefix\Webuni\FrontMatter\Processor\YamlProcessor;

/**
 * @see https://pugjs.org/language/comments.html#block-comments
 */
final class PugCommentFrontMatter
{
    /**
     * @codeCoverageIgnore
     */
    private function __construct()
    {
        // prevent any instantiation
    }

    public static function createWithEndComment(ProcessorInterface $processor = null): FrontMatter
    {
        return new FrontMatter($processor ?? new YamlProcessor(), '//-', '//-');
    }

    public static function create(ProcessorInterface $processor = null): FrontMatter
    {
        return new FrontMatter($processor ?? new YamlProcessor(), '//-', "\n");
    }
}
