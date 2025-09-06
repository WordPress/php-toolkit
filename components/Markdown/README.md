# Markdown

Convert between Markdown (with optional frontmatter) and WordPress block markup + metadata. Ships a producer (blocks -> Markdown) and consumer (Markdown -> blocks).

## Problems Solved
- Export posts/pages to readable Markdown with frontmatter
- Import Markdown into blocks while preserving structure (headings, lists, tables, quotes, code, images)

## Markdown -> Blocks
```php
use WordPress\Markdown\MarkdownConsumer;

$md = "---\ntitle: My Post\n---\n\n# Hello\nText";
$consumer = new MarkdownConsumer($md);
$result   = $consumer->consume(); // BlocksWithMetadata

$htmlBlocks = $result->get_block_markup();
$meta       = $result->get_all_metadata();
```

## Blocks -> Markdown
```php
use WordPress\Markdown\MarkdownProducer;
use WordPress\DataLiberation\DataFormatConsumer\BlocksWithMetadata;

$blocksWithMeta = new BlocksWithMetadata(
  '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->',
  ['title' => ['My Post']]
);

$producer = new MarkdownProducer($blocksWithMeta);
$markdown = $producer->produce();
```

## Notes
- Round‑tripping HTML -> Markdown -> HTML may produce semantically equivalent but not byte‑identical markup
- Uses a vendor‑patched CommonMark stack internally

