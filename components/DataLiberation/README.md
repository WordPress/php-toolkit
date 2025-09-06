# DataLiberation

Import/export utilities and common data formats for moving content into and out of WordPress. Includes WXR readers/writers, HTML/block processors, URL rewriting, and streaming import helpers.

## Problems Solved
- Convert between WordPress blocks + metadata and external formats (Markdown, HTML)
- Read/write WXR, iterate large archives/filesystems without loading all data
- Re‑write URLs using a public suffix list and robust URL parsing

## Highlights
- Entity Readers/Writers for WXR, filesystems, databases
- Importer helpers for streaming, retries, and attachment downloads
- URL utilities (`URL\WPURL`, `URL\functions.php`) for safe URL handling

## Example: Rewrite URLs in HTML
```php
use WordPress\DataLiberation\DataLiberationHTMLProcessor;

$html = '<a href="https://old.example.com/post">Link</a>';
$p = DataLiberationHTMLProcessor::create_fragment($html);
while ($p->next_tag('a')) {
  $href = $p->get_attribute('href');
  if ($href && 0 === strpos($href, 'https://old.example.com')) {
    $p->set_attribute('','href', str_replace('old.example.com','new.example.com',$href));
  }
}
$result = $p->get_updated_html();
```

## Example: Work with Blocks + Metadata
```php
use WordPress\DataLiberation\DataFormatConsumer\BlocksWithMetadata;

$blocks = new BlocksWithMetadata('<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->', [
  'title' => ['My Post']
]);

$markup   = $blocks->get_block_markup();
$metadata = $blocks->get_all_metadata();
```

Pairs with `components/Markdown` for Markdown <-> blocks conversion.

