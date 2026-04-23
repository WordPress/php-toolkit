<?php

/*
 * This file is part of the league/commonmark package.
 *
 * (c) Colin O'Dell <colinodell@gmail.com>
 * (c) Rezo Zero / Ambroise Maupate
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace VendorPrefix\League\CommonMark\Extension\Footnote;

use VendorPrefix\League\CommonMark\Environment\EnvironmentBuilderInterface;
use VendorPrefix\League\CommonMark\Event\DocumentParsedEvent;
use VendorPrefix\League\CommonMark\Extension\ConfigurableExtensionInterface;
use VendorPrefix\League\CommonMark\Extension\Footnote\Event\AnonymousFootnotesListener;
use VendorPrefix\League\CommonMark\Extension\Footnote\Event\FixOrphanedFootnotesAndRefsListener;
use VendorPrefix\League\CommonMark\Extension\Footnote\Event\GatherFootnotesListener;
use VendorPrefix\League\CommonMark\Extension\Footnote\Event\NumberFootnotesListener;
use VendorPrefix\League\CommonMark\Extension\Footnote\Node\Footnote;
use VendorPrefix\League\CommonMark\Extension\Footnote\Node\FootnoteBackref;
use VendorPrefix\League\CommonMark\Extension\Footnote\Node\FootnoteContainer;
use VendorPrefix\League\CommonMark\Extension\Footnote\Node\FootnoteRef;
use VendorPrefix\League\CommonMark\Extension\Footnote\Parser\AnonymousFootnoteRefParser;
use VendorPrefix\League\CommonMark\Extension\Footnote\Parser\FootnoteRefParser;
use VendorPrefix\League\CommonMark\Extension\Footnote\Parser\FootnoteStartParser;
use VendorPrefix\League\CommonMark\Extension\Footnote\Renderer\FootnoteBackrefRenderer;
use VendorPrefix\League\CommonMark\Extension\Footnote\Renderer\FootnoteContainerRenderer;
use VendorPrefix\League\CommonMark\Extension\Footnote\Renderer\FootnoteRefRenderer;
use VendorPrefix\League\CommonMark\Extension\Footnote\Renderer\FootnoteRenderer;
use VendorPrefix\League\Config\ConfigurationBuilderInterface;
use VendorPrefix\Nette\Schema\Expect;

final class FootnoteExtension implements ConfigurableExtensionInterface
{
    public function configureSchema(ConfigurationBuilderInterface $builder): void
    {
        $builder->addSchema('footnote', Expect::structure([
            'backref_class' => Expect::string('footnote-backref'),
            'backref_symbol' => Expect::string('↩'),
            'container_add_hr' => Expect::bool(true),
            'container_class' => Expect::string('footnotes'),
            'ref_class' => Expect::string('footnote-ref'),
            'ref_id_prefix' => Expect::string('fnref:'),
            'footnote_class' => Expect::string('footnote'),
            'footnote_id_prefix' => Expect::string('fn:'),
        ]));
    }

    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addBlockStartParser(new FootnoteStartParser(), 51);
        $environment->addInlineParser(new AnonymousFootnoteRefParser(), 35);
        $environment->addInlineParser(new FootnoteRefParser(), 51);

        $environment->addRenderer(FootnoteContainer::class, new FootnoteContainerRenderer());
        $environment->addRenderer(Footnote::class, new FootnoteRenderer());
        $environment->addRenderer(FootnoteRef::class, new FootnoteRefRenderer());
        $environment->addRenderer(FootnoteBackref::class, new FootnoteBackrefRenderer());

        $environment->addEventListener(DocumentParsedEvent::class, [new AnonymousFootnotesListener(), 'onDocumentParsed'], 40);
        $environment->addEventListener(DocumentParsedEvent::class, [new FixOrphanedFootnotesAndRefsListener(), 'onDocumentParsed'], 30);
        $environment->addEventListener(DocumentParsedEvent::class, [new NumberFootnotesListener(), 'onDocumentParsed'], 20);
        $environment->addEventListener(DocumentParsedEvent::class, [new GatherFootnotesListener(), 'onDocumentParsed'], 10);
    }
}
