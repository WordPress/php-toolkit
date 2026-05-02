#!/usr/bin/env python3
"""
Generates docs/<component>/index.html for every component plus the docs/index.html
landing page. The component catalog lives in bin/_docs_components.py so that
content and orchestration stay separate.
"""

import json
import os
import re
import sys
from html import escape as h

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _docs_components import (
    COMPONENTS,
    COMPONENT_GUIDES,
    COMPONENT_RELATIONS,
    STARTER_PATHS,
)

DOCS = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'docs', '_legacy')
EXPECTED_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), '_expected_outputs.json')
ASSET_VERSION = '20260429-concept-guide'

EXPECTED = {}
if os.path.exists(EXPECTED_PATH):
    with open(EXPECTED_PATH) as f:
        EXPECTED = {tuple(k.split('::')): v for k, v in json.load(f).items()}

PAGE_HEAD = '''<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{title} — PHP Toolkit</title>
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
\t\t<a href="../">Components</a>
\t\t<a href="https://github.com/WordPress/php-toolkit">GitHub</a>
\t</nav>
</header>
'''

PAGE_FOOT = '''<footer class="site">
\t<a href="https://github.com/WordPress/php-toolkit">WordPress/php-toolkit</a> · runnable docs powered by <a href="https://wordpress.github.io/wordpress-playground/">WordPress Playground</a>
</footer>
</body>
</html>
'''


def snippet_block(slug, name, code, runnable=True):
    # <script type="application/x-php"> content is parsed as raw text — entities
    # are not decoded — so the PHP must be inserted verbatim. Guard only against
    # the literal closing-tag string.
    safe = code.rstrip().replace('</script', '<\\/script')
    expected = EXPECTED.get((slug, name)) if runnable else None
    expected_block = ''
    if expected is not None:
        expected_safe = expected.rstrip().replace('</script', '<\\/script')
        # Pre-rendered output: <php-snippet> reads the script child and skips
        # booting the Playground runtime entirely, so first-Run is instant.
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
    if not runnable:
        return snippet_block(slug, name, code, False)
    return snippet_block(slug, name, code, True)


def slugify(text):
    return re.sub(r'[^\w\s-]', '', text.lower()).strip().replace(' ', '-')


def render_component(slug, title, lede, install, sections):
    nav_items = []
    for s, t, _, _, _ in COMPONENTS:
        cls = ' class="current"' if s == slug else ''
        nav_items.append(f'\t\t\t<li{cls}><a href="../{s}/">{h(t)}</a></li>')
    sidebar = (
        '\t<aside class="sidebar" aria-label="Component navigation">\n'
        '\t\t<button class="sidebar-toggle" type="button" aria-expanded="false">'
        'On this page ▾</button>\n'
        '\t\t<nav class="toc" aria-label="Table of contents"></nav>\n'
        '\t\t<details class="components-nav" open>\n'
        '\t\t\t<summary>All components</summary>\n'
        '\t\t\t<ol>\n'
        + '\n'.join(nav_items) + '\n'
        '\t\t\t</ol>\n'
        '\t\t</details>\n'
        '\t</aside>\n'
    )

    out = [PAGE_HEAD.format(
        title=h(title),
        description=h(re.sub(r'<[^>]+>', '', lede)),
        asset_version=ASSET_VERSION,
    )]
    out.append('<div class="layout">\n')
    out.append(sidebar)
    out.append('\t<article class="content">\n')
    out.append(f'\t\t<h1>{h(title)}</h1>\n')
    out.append(f'\t\t<p class="lede">{lede}</p>\n')
    if install:
        out.append(f'\t\t<code class="install">composer require {h(install)}</code>\n')

    purpose = None
    usage_sections = sections
    if sections and sections[0][0].lower() == 'why this exists':
        purpose = sections[0]
        usage_sections = sections[1:]

    if purpose:
        _, body_html, snippet = purpose
        if body_html:
            out.append(f'\t\t{body_html}\n')
        if snippet:
            out.append(render_example(slug, snippet))

    guide = COMPONENT_GUIDES.get(slug, {})
    if guide:
        mental_model = guide.get('mental_model')
        journey = guide.get('journey', ())
        if mental_model:
            out.append(f'\t\t{mental_model}\n')
        if journey:
            out.append('\t\t<p class="you-will-learn-label">You will learn to:</p>\n')
            out.append('\t\t<ul class="you-will-learn">\n')
            for label, _text in journey:
                out.append(f'\t\t\t<li>{h(label)}</li>\n')
            out.append('\t\t</ul>\n')

    if install:
        out.append(
            '\t\t<p class="runtime-note">Most snippets below run in the browser through WordPress Playground. '
            'Click <em>Run</em> on any example to execute it; edit the code and run again to see what changes. '
            'Static snippets show config or shell commands that need a real local environment.</p>\n'
        )

    for heading, body_html, snippet in usage_sections:
        out.append(f'\t\t<h2 id="{slugify(heading)}">{h(heading)}</h2>\n')
        if body_html:
            out.append(f'\t\t{body_html}\n')
        if snippet:
            out.append(render_example(slug, snippet))

    related = COMPONENT_RELATIONS.get(slug, ())
    if related:
        out.append('\t\t<h2 id="see-also">See also</h2>\n')
        out.append('\t\t<ul class="related-components">\n')
        for related_slug, related_title, reason in related:
            out.append(
                f'\t\t\t<li><a href="../{related_slug}/">{h(related_title)}</a>'
                f'<span>{reason}</span></li>\n'
            )
        out.append('\t\t</ul>\n')
    out.append('\t</article>\n</div>\n')
    out.append(PAGE_FOOT)
    return ''.join(out)


def render_index():
    title_by_slug = {slug: title for slug, title, _, _, _ in COMPONENTS}
    cards = []
    for slug, title, lede, _, _ in COMPONENTS:
        clean = re.sub(r'<[^>]+>', '', lede)
        first = clean.split('.')[0]
        if len(first) > 110:
            first = first[:107].rsplit(' ', 1)[0] + '…'
        suffix = '' if first.endswith(('…', '.')) else '.'
        cards.append(
            f'\t\t<li><a href="{slug}/"><strong>{h(title)}</strong>'
            f'<span>{h(first)}{suffix}</span></a></li>'
        )
    cards_html = '\n'.join(cards)
    path_cards = []
    for title, description, slugs in STARTER_PATHS:
        links = ' '.join(
            f'<a href="{slug}/">{h(title_by_slug[slug])}</a>' for slug in slugs
        )
        path_cards.append(
            f'\t\t<li><strong>{h(title)}</strong><span>{h(description)}</span>'
            f'<nav aria-label="{h(title)} path">{links}</nav></li>'
        )
    paths_html = '\n'.join(path_cards)
    return f'''<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PHP Toolkit — runnable docs</title>
<meta name="description" content="Guided docs for eighteen pure-PHP libraries for WordPress and general PHP, with runnable examples powered by WordPress Playground.">
<link rel="stylesheet" href="assets/style.css?v={ASSET_VERSION}">
</head>
<body>
<header class="site">
\t<a class="brand" href="./">PHP Toolkit</a>
\t<nav>
\t\t<a href="https://github.com/WordPress/php-toolkit">GitHub</a>
\t</nav>
</header>
<main class="landing">
\t<h1>PHP Toolkit</h1>
\t<p class="lede">Eighteen standalone pure-PHP libraries for WordPress and general PHP, with no extension or Composer dependencies. Each guide starts with the story for that component, outlines the route through the page, names the main APIs, and then uses examples only where code clarifies the idea.</p>

\t<h2>Choose a Path</h2>
\t<ul class="starter-paths">
{paths_html}
\t</ul>

\t<h2>Components</h2>
\t<ul class="components">
{cards_html}
\t</ul>

\t<h2>How these examples work</h2>
\t<p>Most PHP examples embed <code>&lt;php-snippet&gt;</code> elements from <a href="https://playground.wordpress.net/">WordPress Playground</a>. The first <em>Run</em> click on a page boots a single shared PHP+WordPress runtime in your browser via WebAssembly and unzips the toolkit into it. Subsequent snippets reuse the same runtime, so only the first run pays the boot cost.</p>
\t<p>Examples that need a local listening port, a web server, or deployment-specific config are presented as static code blocks so the page does not imply they can run in the browser sandbox.</p>
\t<p>The toolkit bundle (<code>docs/assets/php-toolkit.zip</code>, ≈1.8&nbsp;MB) ships with the docs, so no third-party CDN is involved.</p>
</main>
<footer class="site">
\t<a href="https://github.com/WordPress/php-toolkit">WordPress/php-toolkit</a>
</footer>
</body>
</html>
'''


def main():
    with open(os.path.join(DOCS, 'index.html'), 'w') as f:
        f.write(render_index())

    for slug, title, lede, install, sections in COMPONENTS:
        out_dir = os.path.join(DOCS, slug)
        os.makedirs(out_dir, exist_ok=True)
        with open(os.path.join(out_dir, 'index.html'), 'w') as f:
            f.write(render_component(slug, title, lede, install, sections))
        print(f'  wrote {slug}/index.html')


if __name__ == '__main__':
    main()
