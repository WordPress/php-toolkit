# XML

Streaming‑friendly XML attribute processor focused on safe tag discovery and attribute updates. Implements a practical subset of XML 1.0 and supports UTF‑8 documents.

## Problems Solved
- Seek to tags (optionally within namespaces) and read/update/remove attributes
- Handle incomplete input gracefully for future streaming
- Avoid heavy DOM dependencies while offering precise control

## Usage
```php
use WordPress\XML\XMLProcessor;

$xml = '<root xmlns:wp="http://wordpress.org/export/1.2/"><wp:image src="cat.jpg"/></root>';
$p = XMLProcessor::create_from_string($xml);

// Find <wp:image> and set an attribute
if ($p->next_tag(['http://wordpress.org/export/1.2/', 'image'])) {
  $ns = $p->get_tag_namespace();
  $src = $p->get_attribute($ns, 'src');
  $p->set_attribute($ns, 'alt', 'A cat');
}

$updated = $p->get_updated_xml();
```

## Notes
- Unsupported XML constructs (e.g., DTD/ENTITY/ATTLIST) intentionally fail fast
- Complements the HTML processor for HTML documents

