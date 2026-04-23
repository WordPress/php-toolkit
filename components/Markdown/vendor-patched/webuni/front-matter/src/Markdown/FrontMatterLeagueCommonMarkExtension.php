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

namespace VendorPrefix\Webuni\FrontMatter\Markdown;

use VendorPrefix\Dflydev\DotAccessData\Data;
use VendorPrefix\League\CommonMark\Environment\EnvironmentBuilderInterface;
use VendorPrefix\League\CommonMark\Event\DocumentPreParsedEvent;
use VendorPrefix\League\CommonMark\Extension\ExtensionInterface;
use VendorPrefix\League\CommonMark\Input\MarkdownInput;
use VendorPrefix\Webuni\FrontMatter\FrontMatterInterface;

class FrontMatterLeagueCommonMarkExtension implements ExtensionInterface
{
    /** @var FrontMatterInterface */
    private $frontMatter;

    public function __construct(FrontMatterInterface $frontMatter)
    {
        $this->frontMatter = $frontMatter;
    }

    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addEventListener(DocumentPreParsedEvent::class, [$this, 'parse']);
    }

    public function parse(DocumentPreParsedEvent $event): void
    {
        $content = $event->getMarkdown()->getContent();
        $document = $this->frontMatter->parse($content);
        $data = $event->getDocument()->data;

        $data->import($document->getData(), Data::MERGE);
        $event->replaceMarkdown(new MarkdownInput($document->getContent()));
    }
}
