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

namespace Webuni\FrontMatter\Processor;

use VendorPrefix\Symfony\Component\Yaml\Yaml;

final class YamlProcessor implements ProcessorInterface
{
    /**
     * @var int
     */
    private $parseFlags;
    /**
     * @var int
     */
    private $dumpFlags;
    /**
     * @var int
     */
    private $inline;
    /**
     * @var int
     */
    private $indent;

    public function __construct(int $parseFlags = 0, int $dumpFlags = 0, int $inline = 2, int $indent = 4)
    {
        $this->parseFlags = $parseFlags;
        $this->dumpFlags = $dumpFlags;
        $this->inline = $inline;
        $this->indent = $indent;
    }

    public function parse(string $string): array
    {
        return (array) Yaml::parse($string, $this->parseFlags);
    }

    public function dump(array $data): string
    {
        if (empty($data)) {
            return '';
        }

        return Yaml::dump($data, $this->inline, $this->indent, $this->dumpFlags);
    }
}
