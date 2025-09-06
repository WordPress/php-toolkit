# HTML

A small, robust HTML5 processor with cursor‑based APIs to find, inspect, and mutate tags and attributes. Optimized for server‑side transformations in WordPress.

## Problems Solved
- Traverse and modify HTML without heavy DOM extensions
- Attribute read/write, tag seeking, and safe text replacements
- Streaming‑friendly design used by higher‑level components

## Usage
```php
// Find links and add rel=noopener
$p = new \WP_HTML_Tag_Processor('<p><a href="/x">x</a></p>');
while ($p->next_tag('a')) {
  $p->set_attribute('','rel','noopener');
}
$html = $p->get_updated_html();
```

## Tips
- Use `next_tag()` with a string tag name or query array for fine‑grained matching
- Complements `components/XML` for XML attribute changes

