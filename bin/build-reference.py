#!/usr/bin/env python3
"""Generates docs/reference/<slug>.html for every component.

The catalog comes from components/<Name>/README.md (loaded via
bin/_load_catalog.py). Each README *is* the catalog source — frontmatter
+ lede + sections + snippets + expected-output fences. Every page uses
the same concept-guide shape: lede + install + context paragraphs +
minimal example + refinements + pitfalls + see also. There are no
hand-authored exceptions.
"""

import os
import re
import sys
from html import escape as h

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _load_catalog import load_components_rich

DOCS = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'docs', 'reference')
ASSET_VERSION = '20260503-fallback-explicit-hide'


PAGE_HEAD = '''<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{title} — PHP Toolkit reference</title>
<meta name="description" content="{description}">
<link rel="stylesheet" href="../assets/style.css?v={asset_version}">
<script type="module" src="https://playground.wordpress.net/php-code-snippet.js"></script>
<script id="toolkit-setup" type="application/json"></script>
<script src="../assets/page.js?v={asset_version}" defer></script>
</head>
<body>
<header class="site">
\t<a class="brand" href="../">PHP Toolkit</a>
\t<nav>
\t\t<a href="../learn/">Learn</a>
\t\t<a href="./">Reference</a>
\t\t<a href="https://github.com/WordPress/php-toolkit">GitHub</a>
\t</nav>
</header>

<div class="layout">
'''

PAGE_FOOT = '''\t</article>
</div>

<footer class="site">
\t<a href="https://github.com/WordPress/php-toolkit">WordPress/php-toolkit</a>
</footer>
</body>
</html>
'''


def slugify(text):
    return re.sub(r'[^\w\s-]', '', text.lower()).strip().replace(' ', '-')


def split_pitfalls(body_html):
    """Pull paragraphs that begin with 'Footgun:' or 'Gotcha:' out of a body
    and return them as separate pitfall callouts.

    Returns ``(rest_html, [pitfall_html, ...])`` where ``rest_html`` is the
    original body with only the matching ``<p>...</p>`` chunks removed —
    tables, lists, ``<pre>`` blocks, and any other markup are preserved
    verbatim. Earlier versions accidentally dropped non-``<p>`` content
    because they walked the body via a ``<p>`` regex and re-emitted only
    the matched chunks.
    """
    pitfalls = []
    def replace(match):
        chunk = match.group(0)
        plain = re.sub(r'<[^>]+>', '', chunk).strip()
        if plain.lower().startswith(('footgun', 'gotcha')):
            inner = chunk[3:-4]  # strip <p>...</p>
            inner = re.sub(r'^<strong>(Footgun|Gotcha)[^<]*</strong>\s*[—:.\s]*', '', inner)
            inner = re.sub(r'^(Footgun|Gotcha)[^a-z<]*', '', inner)
            pitfalls.append(inner.strip())
            return ''
        return chunk
    rest = re.sub(r'<p>.*?</p>', replace, body_html, flags=re.DOTALL)
    return rest, pitfalls


def snippet_block(snippet):
    """Render a snippet dict as a <php-snippet> custom-element block.

    Includes the captured expected-output (when present) so the docs page
    paints the result before WordPress Playground finishes booting, and
    emits a static <pre><code> fallback inside the same element so readers
    see the snippet code even if Playground's JS module fails to load
    (cross-origin block, slow network, adblocker, no-JS clients).

    CSS hides the fallback when the custom element is :defined, so the
    interactive widget owns the screen as soon as it registers.
    """
    name = snippet['filename']
    code = snippet['code']
    runnable = snippet['runnable']
    expected = snippet['expected_output'] if runnable else None

    safe = code.rstrip().replace('</script', '<\\/script')
    fallback = (
        '<pre class="snippet-fallback"><code class="language-php">'
        f'{h(code.rstrip())}'
        '</code></pre>\n'
    )
    expected_block = ''
    if expected is not None:
        expected_safe = expected.rstrip().replace('</script', '<\\/script')
        expected_block = (
            f'<script type="text/expected-output">\n{expected_safe}\n</script>\n'
        )
    runnable_attr = '' if runnable else ' runnable="false"'
    return (
        f'<php-snippet blueprint="toolkit-setup" name="{h(name)}"{runnable_attr}>\n'
        f'{fallback}'
        f'<script type="application/x-php">\n{safe}\n</script>\n'
        f'{expected_block}'
        f'</php-snippet>\n'
    )


def sidebar(components, current_slug):
    items = []
    for c in components:
        href = f'{c["slug"]}.html'
        cls = ' class="current"' if c['slug'] == current_slug else ''
        items.append(f'\t\t\t<li{cls}><a href="{href}">{h(c["title"])}</a></li>')
    return (
        '\t<aside class="sidebar" aria-label="Reference navigation">\n'
        '\t\t<button class="sidebar-toggle" type="button" aria-expanded="false">'
        'On this page ▾</button>\n'
        '\t\t<nav class="toc" aria-label="Table of contents"></nav>\n'
        '\t\t<details class="components-nav" open>\n'
        '\t\t\t<summary>All components</summary>\n'
        '\t\t\t<ol>\n'
        + '\n'.join(items) + '\n'
        '\t\t\t</ol>\n'
        '\t\t</details>\n'
        '\t</aside>\n'
    )


def render_component(components, c):
    # Separate the "Why this exists" intro from the worked sections.
    purpose_html = ''
    pitfalls_from_purpose = []
    sections = c['sections']
    usage = sections
    if sections and sections[0]['heading'].lower() == 'why this exists':
        body = sections[0]['body'] or ''
        purpose_html, pitfalls_from_purpose = split_pitfalls(body)
        usage = sections[1:]

    out = [PAGE_HEAD.format(
        title=h(c['title']),
        description=h(re.sub(r'<[^>]+>', '', c['lede'])),
        asset_version=ASSET_VERSION,
    )]
    out.append(sidebar(components, c['slug']))
    out.append('\t<article class="content">\n\n')
    out.append(f'<h1>{h(c["title"])}</h1>\n\n')
    out.append(f'<p class="lede">{c["lede"]}</p>\n\n')
    if c['install']:
        out.append(f'<pre><code class="install">composer require {h(c["install"])}</code></pre>\n\n')
    if c['credit']:
        title_credit, body_credit = c['credit']
        out.append(
            '<aside class="callout credit">\n'
            f'\t<strong>{h(title_credit)}.</strong> {body_credit}\n'
            '</aside>\n\n'
        )
    if purpose_html:
        out.append(purpose_html + '\n\n')

    # Worked examples + accumulated pitfalls.
    pitfalls = list(pitfalls_from_purpose)
    minimal_emitted = False
    for section in usage:
        heading = section['heading']
        body_html = section['body'] or ''
        snippet = section['snippet']
        rest, found = split_pitfalls(body_html)
        pitfalls.extend(found)
        h2 = heading
        if not minimal_emitted and snippet:
            h2 = 'A minimal example'
            minimal_emitted = True
        elif snippet:
            h2 = f'Refinement: {heading[0].lower() + heading[1:]}' if heading else heading
        out.append(f'<h2 id="{slugify(h2)}">{h(h2)}</h2>\n\n')
        if rest:
            out.append(rest + '\n\n')
        if snippet:
            out.append(snippet_block(snippet) + '\n')

    if pitfalls:
        out.append('<h2 id="pitfalls">Pitfalls</h2>\n\n')
        for p in pitfalls:
            out.append(f'<aside class="callout pitfall">{p}</aside>\n\n')

    if c['see_also']:
        out.append('<h2 id="see-also">See also</h2>\n\n')
        out.append('<ul class="related-components">\n')
        for href, rel_title, reason in c['see_also']:
            out.append(
                f'\t<li><a href="{href}"><strong>{h(rel_title)}</strong></a>'
                f'<span>{reason}</span></li>\n'
            )
        out.append('</ul>\n\n')

    out.append(PAGE_FOOT)
    return ''.join(out)


def main():
    os.makedirs(DOCS, exist_ok=True)
    components = load_components_rich()
    for c in components:
        out = render_component(components, c)
        path = os.path.join(DOCS, f'{c["slug"]}.html')
        with open(path, 'w') as f:
            f.write(out)
        print(f'wrote reference/{c["slug"]}.html')


if __name__ == '__main__':
    main()
