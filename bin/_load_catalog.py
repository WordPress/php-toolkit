"""Loads bin/_docs_components/<slug>.md into the COMPONENTS data structure
that the build scripts and the snippet runner expect.

Markdown file format (one per component):

    ---
    slug: <slug>
    title: <Title>
    install: <wp-php-toolkit/...>      # optional
    ---

    <lede HTML, one or more paragraphs>

    ## Section heading

    <body HTML>

    <!-- snippet:
    filename: <name>.php
    runnable: true | false             # default: true
    -->
    ```php
    <?php
    require '...';
    ...
    ```

The fence holds the snippet code verbatim — no implicit prelude, no entity
decoding. Both lede HTML and body HTML are passed through to the renderer
as-is. Sections without a snippet are allowed (just omit the comment + fence).

The loader returns the same shape that bin/_docs_components.py used to expose:

    [(slug, title, lede_html, install, sections), ...]
    sections = [(heading, body_html, snippet_or_None), ...]
    snippet  = (filename, code) or (filename, code, runnable)

This keeps the build scripts unchanged.
"""

import os
import re

THIS = os.path.dirname(os.path.abspath(__file__))
COMPONENT_DIR = os.path.join(THIS, '_docs_components')

_FRONTMATTER_RE = re.compile(r'\A---\n(.*?)\n---\n?', re.DOTALL)
_SNIPPET_RE = re.compile(
    # The fence is a backreference so the closing run matches the opening
    # run exactly. Snippets that contain a literal triple-backtick (e.g. a
    # markdown sample inside a heredoc) are extracted with a 4-tick fence.
    r'<!--\s*snippet:\s*\n(?P<meta>.*?)\n-->\s*\n(?P<fence>`{3,})php\n(?P<code>.*?)\n(?P=fence)',
    re.DOTALL,
)


def _parse_frontmatter(text):
    m = _FRONTMATTER_RE.match(text)
    if not m:
        raise ValueError('Missing YAML-style frontmatter (--- ... ---)')
    fields = {}
    for line in m.group(1).splitlines():
        if not line.strip():
            continue
        if ':' not in line:
            raise ValueError(f'Bad frontmatter line: {line!r}')
        key, _, val = line.partition(':')
        fields[key.strip()] = val.strip()
    return fields, text[m.end():]


def _split_sections(body):
    """Split a markdown body on H2 boundaries (`## Heading`) at column 0.

    Returns (lede, [(heading, content), ...]) where ``lede`` is everything
    before the first ``## `` line (it may be empty) and each section's
    content is the lines following its heading until the next ``## ``.
    """
    parts = re.split(r'(?m)^##\s+(?P<h>.+?)\s*$', body)
    # parts: [lede, h1, content1, h2, content2, ...]
    lede = parts[0].strip()
    sections = []
    for i in range(1, len(parts), 2):
        heading = parts[i].strip()
        content = parts[i + 1] if i + 1 < len(parts) else ''
        sections.append((heading, content))
    return lede, sections


def _extract_snippet(content):
    """Pull the `<!-- snippet: ... -->\n```php ... ````` block out of a section.

    Returns (body_html, snippet_or_None) where snippet matches the legacy
    tuple shape: (filename, code) for runnable, (filename, code, False)
    for non-runnable.
    """
    m = _SNIPPET_RE.search(content)
    if not m:
        return content.strip(), None

    meta = {}
    for line in m.group('meta').splitlines():
        line = line.strip()
        if not line or ':' not in line:
            continue
        key, _, val = line.partition(':')
        meta[key.strip()] = val.strip()
    filename = meta.get('filename')
    if not filename:
        raise ValueError(f'Snippet missing filename: {m.group("meta")!r}')
    runnable_str = meta.get('runnable', 'true').lower()
    runnable = runnable_str not in ('false', 'no', '0')
    code = m.group('code')

    body = (content[:m.start()] + content[m.end():]).strip()

    if runnable:
        snippet = (filename, code)
    else:
        snippet = (filename, code, False)
    return body, snippet


def _join_blocks(text):
    """Re-flatten the markdown body back into the single-string HTML shape
    that the legacy Python catalog produced.

    Blocks in the markdown file are separated by *blank lines*, except blank
    lines that appear inside a ``<pre>...</pre>`` (or any preformatted) span,
    which are preserved verbatim so embedded code samples round-trip.

    The build-reference.py renderer concatenates body content directly into
    `<section>` markup, so blocks join with no separator between them.
    """
    if not text:
        return ''
    blocks = []
    current = []
    pre_depth = 0
    for line in text.split('\n'):
        is_blank = not line.strip()
        if is_blank and pre_depth == 0:
            if current:
                blocks.append('\n'.join(current).strip('\n'))
                current = []
            continue
        # Crude `<pre>` depth counter — good enough for this catalog. Counts
        # opens and closes that appear anywhere on the line; the catalog
        # never nests pre tags.
        opens = len(re.findall(r'<pre\b', line, re.IGNORECASE))
        closes = len(re.findall(r'</pre\s*>', line, re.IGNORECASE))
        pre_depth += opens - closes
        if pre_depth < 0:
            pre_depth = 0
        current.append(line)
    if current:
        blocks.append('\n'.join(current).strip('\n'))
    return ''.join(b for b in blocks if b)


def load_components():
    """Return the COMPONENTS list reconstructed from per-component markdown.

    Order is determined by the catalog manifest at `_docs_components/_order.txt`.
    If the manifest is missing, components are loaded in alphabetical order
    (so adding a new file Just Works) and a warning is printed to stderr.
    """
    components = []
    manifest = os.path.join(COMPONENT_DIR, '_order.txt')
    if os.path.exists(manifest):
        with open(manifest) as f:
            ordered_slugs = [line.strip() for line in f if line.strip() and not line.startswith('#')]
    else:
        import sys as _sys
        print(f'WARNING: {manifest} missing; loading components in filesystem order',
              file=_sys.stderr)
        ordered_slugs = sorted(
            os.path.splitext(name)[0]
            for name in os.listdir(COMPONENT_DIR)
            if name.endswith('.md') and not name.startswith('_')
        )

    for slug in ordered_slugs:
        path = os.path.join(COMPONENT_DIR, f'{slug}.md')
        with open(path, encoding='utf-8') as f:
            text = f.read()
        fields, body = _parse_frontmatter(text)
        if fields.get('slug') != slug:
            raise ValueError(f'{path}: frontmatter slug ({fields.get("slug")!r}) != filename ({slug!r})')
        title = fields.get('title')
        if not title:
            raise ValueError(f'{path}: missing title')
        install = fields.get('install') or None

        lede_md, raw_sections = _split_sections(body)
        lede = _join_blocks(lede_md)

        sections = []
        for heading, content in raw_sections:
            body_md, snippet = _extract_snippet(content)
            body_html = _join_blocks(body_md)
            sections.append((heading, body_html, snippet))

        components.append((slug, title, lede, install, sections))

    return components
