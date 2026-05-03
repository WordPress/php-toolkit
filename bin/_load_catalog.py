"""Loads each `components/<Name>/README.md` into the COMPONENTS data
structure the build scripts and the snippet runner consume.

The README *is* the catalog source: it doubles as the GitHub/Packagist
README and as the docs-site catalog. YAML-style frontmatter at the top
carries the slug/title/install/credit/see-also metadata; the body is
plain markdown with fenced PHP snippets and `<!-- expected-output -->`
fenced blocks. GitHub's renderer hides frontmatter from the README view
on github.com, so the metadata is invisible to readers but available to
the build pipeline.

Markdown file format (one per component):

    ---
    slug: <slug>
    title: <Title>
    install: <wp-php-toolkit/...>          # optional

    credit_title: <one-line summary>       # optional credit callout
    credit_body: |
      <multi-line HTML — one block per
       indented line, joined verbatim>

    see_also: <slug> | <Title> | <reason>  # optional, repeatable
    see_also: <slug> | <Title> | <reason>
    ---

    <lede HTML>

    ## Section heading

    <body HTML — paragraphs separated by blank lines>

    <!-- snippet:
    filename: <name>.php
    runnable: true | false                 # default: true
    -->
    ```php
    <?php
    require '...';
    ...
    ```

    <!-- expected-output -->
    ```
    <verbatim expected stdout>
    ```

The php fence holds the snippet verbatim. The optional expected-output
fence (immediately after the php fence, with the `<!-- expected-output -->`
marker on its own line) carries the captured stdout used by the docs site
for instant pre-render and by run-snippets.py for CI verification.

The loader exposes both a richer dict-shape catalog and a legacy
COMPONENTS list of (slug, title, lede, install, sections) tuples — the
latter for backward compatibility with build-reference.py call sites that
existed before this refactor.
"""

import os
import re

THIS = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(THIS)
COMPONENTS_ROOT = os.path.join(ROOT, 'components')

# Slug → component-directory mapping. Each component's README.md *is* the
# catalog source: it carries the YAML-style frontmatter, lede, sections,
# snippets, and expected-output blocks the docs site needs. The ordered
# tuple here also defines the order components appear on the landing page
# and in the reference sidebar.
COMPONENT_ORDER = (
    ('html',             'HTML'),
    ('zip',              'Zip'),
    ('bytestream',       'ByteStream'),
    ('filesystem',       'Filesystem'),
    ('blockparser',      'BlockParser'),
    ('markdown',         'Markdown'),
    ('xml',              'XML'),
    ('encoding',         'Encoding'),
    ('dataliberation',   'DataLiberation'),
    ('git',              'Git'),
    ('merge',            'Merge'),
    ('httpclient',       'HttpClient'),
    ('httpserver',       'HttpServer'),
    ('corsproxy',        'CORSProxy'),
    ('cli',              'CLI'),
    ('polyfill',         'Polyfill'),
    ('blueprints',       'Blueprints'),
    ('coding-standards', 'ToolkitCodingStandards'),
)

_FRONTMATTER_RE = re.compile(r'\A---\n(.*?)\n---\n?', re.DOTALL)
_SNIPPET_RE = re.compile(
    # Snippet metadata + ```php fence. The fence is a backreference so the
    # closing run matches the opening run exactly (snippets that contain a
    # literal triple-backtick are extracted with a 4-tick fence).
    # Optionally followed by an `<!-- expected-output -->` marker and a
    # second fence holding the captured stdout.
    r'<!--\s*snippet:\s*\n(?P<meta>.*?)\n-->\s*\n(?P<fence>`{3,})php\n(?P<code>.*?)\n(?P=fence)'
    r'(?:\s*\n\s*<!--\s*expected-output\s*-->\s*\n(?P<exp_fence>`{3,})\w*\n(?P<expected>.*?)\n(?P=exp_fence))?',
    re.DOTALL,
)


def _parse_frontmatter(text):
    """Parse a small YAML-subset frontmatter block.

    Supported shapes:
      - ``key: value`` on a single line (string value).
      - ``key: |`` followed by indented continuation lines (multi-line
        string; indentation is stripped).
      - Repeated ``key: value`` lines for the same key (list, in source
        order).
    """
    m = _FRONTMATTER_RE.match(text)
    if not m:
        raise ValueError('Missing YAML-style frontmatter (--- ... ---)')
    fields = {}
    lines = m.group(1).splitlines()
    i = 0
    while i < len(lines):
        line = lines[i]
        if not line.strip():
            i += 1
            continue
        if ':' not in line:
            raise ValueError(f'Bad frontmatter line: {line!r}')
        key, _, val = line.partition(':')
        key = key.strip()
        val = val.strip()

        if val == '|':
            # Multi-line block: collect indented lines that follow.
            block = []
            i += 1
            while i < len(lines):
                nxt = lines[i]
                if nxt.startswith('  ') or nxt.startswith('\t'):
                    block.append(nxt[2:] if nxt.startswith('  ') else nxt[1:])
                    i += 1
                elif not nxt.strip():
                    block.append('')
                    i += 1
                else:
                    break
            value = '\n'.join(block).rstrip('\n')
        else:
            value = val
            i += 1

        if key in fields:
            existing = fields[key]
            if isinstance(existing, list):
                existing.append(value)
            else:
                fields[key] = [existing, value]
        else:
            fields[key] = value
    return fields, text[m.end():]


def _split_sections(body):
    """Split a markdown body on H2 boundaries (`## Heading`) at column 0,
    skipping `## ` lines that appear inside fenced code blocks (those are
    snippet content or expected output, not section headings).

    Returns (lede, [(heading, content), ...]).
    """
    lines = body.split('\n')
    fence = None  # current open fence string (e.g. "```", "````"), or None
    boundaries = []  # (line_index, heading) for each H2
    fence_re = re.compile(r'^(?P<f>`{3,})')
    h2_re = re.compile(r'^##\s+(.+?)\s*$')
    for i, line in enumerate(lines):
        m_fence = fence_re.match(line)
        if m_fence:
            f = m_fence.group('f')
            if fence is None:
                fence = f
            elif len(f) >= len(fence):
                fence = None
            continue
        if fence is None:
            m_h2 = h2_re.match(line)
            if m_h2:
                boundaries.append((i, m_h2.group(1).strip()))

    if not boundaries:
        return body.strip(), []
    lede = '\n'.join(lines[:boundaries[0][0]]).strip()
    sections = []
    for idx, (line_idx, heading) in enumerate(boundaries):
        end = boundaries[idx + 1][0] if idx + 1 < len(boundaries) else len(lines)
        content = '\n'.join(lines[line_idx + 1:end])
        sections.append((heading, content))
    return lede, sections


def _extract_snippet(content):
    """Pull `<!-- snippet: ... -->\n```php ... ``` [+ expected-output]` out
    of a section. Returns (body_html, snippet_or_None) where the snippet is
    a dict with keys: filename, code, runnable, expected_output (or None)."""
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
    expected = m.group('expected')

    snippet = {
        'filename': filename,
        'code': code,
        'runnable': runnable,
        'expected_output': expected if expected is not None else None,
    }
    body = (content[:m.start()] + content[m.end():]).strip()
    return body, snippet


def _join_blocks(text):
    """Re-flatten the markdown body back into the single-string HTML shape
    that the legacy Python catalog produced.

    Blocks separated by *blank lines* in the markdown file join with no
    separator in the runtime string, except blank lines inside a
    ``<pre>...</pre>`` span which are preserved verbatim so embedded code
    samples round-trip.
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
        opens = len(re.findall(r'<pre\b', line, re.IGNORECASE))
        closes = len(re.findall(r'</pre\s*>', line, re.IGNORECASE))
        pre_depth += opens - closes
        if pre_depth < 0:
            pre_depth = 0
        current.append(line)
    if current:
        blocks.append('\n'.join(current).strip('\n'))
    return ''.join(b for b in blocks if b)


def _parse_see_also(value):
    """Convert ``<target> | Title | reason`` lines into (href, title, reason).

    ``<target>`` is either a component slug (rendered as ``<slug>.html``) or
    a relative URL / absolute URL passed through verbatim. The detection is
    naive: anything containing ``/`` or ``.`` is treated as a URL,
    everything else is a slug. This lets entries point at sibling reference
    pages (``see_also: blockparser | BlockParser | …``) or at learn-path
    tutorials (``see_also: ../learn/01-rewriting-html.html | Tutorial — … | …``).
    """
    if value is None:
        return []
    items = value if isinstance(value, list) else [value]
    out = []
    for item in items:
        if not item.strip():
            continue
        parts = [p.strip() for p in item.split('|')]
        if len(parts) != 3:
            raise ValueError(f'see_also must have three pipe-separated fields, got {item!r}')
        target, title, reason = parts
        if '/' in target or '.' in target:
            href = target
        else:
            href = f'{target}.html'
        out.append((href, title, reason))
    return out


def _legacy_snippet_tuple(snippet):
    """Convert the rich snippet dict into the legacy tuple shape."""
    if snippet is None:
        return None
    if snippet['runnable']:
        return (snippet['filename'], snippet['code'])
    return (snippet['filename'], snippet['code'], False)


def load_components_rich():
    """Return per-component dicts with all metadata. Preferred for new code.

    Schema:
        [
          {
            'slug': 'html',
            'title': 'HTML',
            'install': 'wp-php-toolkit/html',
            'lede': '<HTML lede>',
            'credit': ('Ported from WordPress core', '<HTML body>') | None,
            'see_also': [(slug, title, reason), ...],
            'sections': [
              {
                'heading': 'A minimal example',
                'body': '<HTML body>',
                'snippet': {'filename', 'code', 'runnable', 'expected_output'} | None,
              },
              ...
            ],
          },
          ...
        ]
    """
    components = []
    for slug, dir_name in COMPONENT_ORDER:
        path = os.path.join(COMPONENTS_ROOT, dir_name, 'README.md')
        with open(path, encoding='utf-8') as f:
            text = f.read()
        fields, body = _parse_frontmatter(text)
        if fields.get('slug') != slug:
            raise ValueError(
                f'{path}: frontmatter slug ({fields.get("slug")!r}) != filename ({slug!r})'
            )
        title = fields.get('title')
        if not title:
            raise ValueError(f'{path}: missing title')
        install = fields.get('install') or None

        credit = None
        if fields.get('credit_title') or fields.get('credit_body'):
            credit_title = fields.get('credit_title') or ''
            credit_body = fields.get('credit_body') or ''
            credit = (credit_title, credit_body)

        see_also = _parse_see_also(fields.get('see_also'))

        lede_md, raw_sections = _split_sections(body)
        lede = _join_blocks(lede_md)

        sections = []
        for heading, content in raw_sections:
            body_md, snippet = _extract_snippet(content)
            body_html = _join_blocks(body_md)
            sections.append({
                'heading': heading,
                'body': body_html,
                'snippet': snippet,
            })

        components.append({
            'slug': slug,
            'title': title,
            'install': install,
            'lede': lede,
            'credit': credit,
            'see_also': see_also,
            'sections': sections,
        })

    return components


def load_components():
    """Backward-compatible loader returning the legacy tuple shape.

    [(slug, title, lede, install, sections), ...]
    sections = [(heading, body, snippet_tuple_or_None), ...]
    """
    out = []
    for c in load_components_rich():
        sections = [
            (s['heading'], s['body'], _legacy_snippet_tuple(s['snippet']))
            for s in c['sections']
        ]
        out.append((c['slug'], c['title'], c['lede'], c['install'], sections))
    return out
