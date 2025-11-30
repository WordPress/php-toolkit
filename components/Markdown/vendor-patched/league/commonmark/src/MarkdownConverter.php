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

namespace VendorPrefix\League\CommonMark;

use VendorPrefix\League\CommonMark\Environment\EnvironmentInterface;
use VendorPrefix\League\CommonMark\Exception\CommonMarkException;
use VendorPrefix\League\CommonMark\Output\RenderedContentInterface;
use VendorPrefix\League\CommonMark\Parser\MarkdownParser;
use VendorPrefix\League\CommonMark\Parser\MarkdownParserInterface;
use VendorPrefix\League\CommonMark\Renderer\HtmlRenderer;
use VendorPrefix\League\CommonMark\Renderer\MarkdownRendererInterface;

class MarkdownConverter implements ConverterInterface, MarkdownConverterInterface
{
    /** @psalm-readonly
     * @var \VendorPrefix\League\CommonMark\Environment\EnvironmentInterface */
    protected $environment;

    /** @psalm-readonly
     * @var \VendorPrefix\League\CommonMark\Parser\MarkdownParserInterface */
    protected $markdownParser;

    /** @psalm-readonly
     * @var \VendorPrefix\League\CommonMark\Renderer\MarkdownRendererInterface */
    protected $htmlRenderer;

    public function __construct(EnvironmentInterface $environment)
    {
        $this->environment = $environment;

        $this->markdownParser = new MarkdownParser($environment);
        $this->htmlRenderer   = new HtmlRenderer($environment);
    }

    public function getEnvironment(): EnvironmentInterface
    {
        return $this->environment;
    }

    /**
     * Converts Markdown to HTML.
     *
     * @param string $input The Markdown to convert
     *
     * @return RenderedContentInterface Rendered HTML
     *
     * @throws CommonMarkException
     */
    public function convert(string $input): RenderedContentInterface
    {
        $documentAST = $this->markdownParser->parse($input);

        return $this->htmlRenderer->renderDocument($documentAST);
    }

    /**
     * Converts Markdown to HTML.
     *
     * @deprecated since 2.2; use {@link convert()} instead
     *
     * @param string $markdown The Markdown to convert
     *
     * @return RenderedContentInterface Rendered HTML
     *
     * @throws CommonMarkException
     */
    public function convertToHtml(string $markdown): RenderedContentInterface
    {
        \trigger_deprecation('league/commonmark', '2.2.0', 'Calling "convertToHtml()" on a %s class is deprecated, use "convert()" instead.', self::class);

        return $this->convert($markdown);
    }

    /**
     * Converts CommonMark to HTML.
     *
     * @see MarkdownConverter::convert()
     *
     * @throws CommonMarkException
     */
    public function __invoke(string $markdown): RenderedContentInterface
    {
        return $this->convert($markdown);
    }
}
