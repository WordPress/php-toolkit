#!/usr/bin/env python3
"""Generates docs/reference/<slug>.html for every component.

The catalog comes from bin/_docs_components/<slug>.md (loaded via
bin/_docs_components.py). Every page uses the same concept-guide shape:
lede + install + context paragraphs + minimal example + refinements +
pitfalls + see also. There are no hand-authored exceptions.
"""

import json
import os
import re
import sys
from html import escape as h, unescape

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _docs_components import COMPONENTS, COMPONENT_RELATIONS, CREDITS

DOCS = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'docs', 'reference')
EXPECTED_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), '_expected_outputs.json')
ASSET_VERSION = '20260429-rewrite'

EXPECTED = {}
if os.path.exists(EXPECTED_PATH):
    with open(EXPECTED_PATH) as f:
        EXPECTED = {tuple(k.split('::')): v for k, v in json.load(f).items()}


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
    """Pull out paragraphs that begin with 'Footgun:' or 'Gotcha:' and return them
    as separate pitfall callouts. Return (rest_html, [pitfall_html, ...])."""
    pitfalls = []
    rest = []
    for chunk in re.findall(r'<p>.*?</p>', body_html, flags=re.DOTALL):
        plain = re.sub(r'<[^>]+>', '', chunk).strip()
        if plain.lower().startswith(('footgun', 'gotcha')):
            inner = chunk[3:-4]  # strip <p>...</p>
            inner = re.sub(r'^<strong>(Footgun|Gotcha)[^<]*</strong>\s*[—:.\s]*', '', inner)
            inner = re.sub(r'^(Footgun|Gotcha)[^a-z<]*', '', inner)
            pitfalls.append(inner.strip())
        else:
            rest.append(chunk)
    return ''.join(rest), pitfalls


def snippet_block(slug, name, code, runnable=True):
    safe = code.rstrip().replace('</script', '<\\/script')
    expected = EXPECTED.get((slug, name)) if runnable else None
    expected_block = ''
    if expected is not None:
        expected_safe = expected.rstrip().replace('</script', '<\\/script')
        expected_block = (
            f'<script type="text/expected-output">\n{expected_safe}\n</script>\n'
        )
    runnable_attr = '' if runnable else ' runnable="false"'
    return (
        f'<php-snippet blueprint="toolkit-setup" name="{h(name)}"{runnable_attr}>\n'
        f'<script type="application/x-php">\n{safe}\n</script>\n'
        f'{expected_block}'
        f'</php-snippet>\n'
    )


def render_example(slug, snippet):
    name, code = snippet[0], snippet[1]
    runnable = len(snippet) < 3 or snippet[2]
    return snippet_block(slug, name, code, runnable)


def sidebar(current_slug):
    items = []
    for slug, title, _, _, _ in COMPONENTS:
        href = f'{slug}.html'
        cls = ' class="current"' if slug == current_slug else ''
        items.append(f'\t\t\t<li{cls}><a href="{href}">{h(title)}</a></li>')
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


def render_component(slug, title, lede, install, sections):
    # Separate the "Why this exists" intro from the worked sections.
    purpose_html = ''
    pitfalls_from_purpose = []
    usage = sections
    if sections and sections[0][0].lower() == 'why this exists':
        _, body, _ = sections[0]
        purpose_html, pitfalls_from_purpose = split_pitfalls(unescape(body or ''))
        usage = sections[1:]

    out = [PAGE_HEAD.format(
        title=h(title),
        description=h(re.sub(r'<[^>]+>', '', lede)),
        asset_version=ASSET_VERSION,
    )]
    out.append(sidebar(slug))
    out.append('\t<article class="content">\n\n')
    out.append(f'<h1>{h(title)}</h1>\n\n')
    out.append(f'<p class="lede">{lede}</p>\n\n')
    if install:
        out.append(f'<pre><code class="install">composer require {h(install)}</code></pre>\n\n')
    if slug in CREDITS:
        title_credit, body_credit = CREDITS[slug]
        out.append(
            '<aside class="callout credit">\n'
            f'\t<strong>{h(title_credit)}.</strong> {body_credit}\n'
            '</aside>\n\n'
        )
    if purpose_html:
        out.append(unescape(purpose_html) + '\n\n')

    # Worked examples + accumulated pitfalls.
    pitfalls = list(pitfalls_from_purpose)
    minimal_emitted = False
    for heading, body_html, snippet in usage:
        # Pull pitfalls out of section body too.
        rest, found = split_pitfalls(unescape(body_html or ''))
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
            out.append(render_example(slug, snippet) + '\n')

    if pitfalls:
        out.append('<h2 id="pitfalls">Pitfalls</h2>\n\n')
        for p in pitfalls:
            out.append(f'<aside class="callout pitfall">{p}</aside>\n\n')

    related = COMPONENT_RELATIONS.get(slug, ())
    if related:
        out.append('<h2 id="see-also">See also</h2>\n\n')
        out.append('<ul class="related-components">\n')
        for rel_slug, rel_title, reason in related:
            href = f'{rel_slug}.html'
            out.append(
                f'\t<li><a href="{href}"><strong>{h(rel_title)}</strong></a>'
                f'<span>{reason}</span></li>\n'
            )
        out.append('</ul>\n\n')

    out.append(PAGE_FOOT)
    return ''.join(out)


def main():
    os.makedirs(DOCS, exist_ok=True)
    for slug, title, lede, install, sections in COMPONENTS:
        out = render_component(slug, title, lede, install, sections)
        path = os.path.join(DOCS, f'{slug}.html')
        with open(path, 'w') as f:
            f.write(out)
        print(f'wrote reference/{slug}.html')


if __name__ == '__main__':
    main()
