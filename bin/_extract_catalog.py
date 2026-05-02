#!/usr/bin/env python3
"""One-shot tool: dumps the current bin/_docs_components.py COMPONENTS list
into per-component markdown files under bin/_docs_components/<slug>.md.

Run once during the markdown-source migration (PR C). The output files are
the new source of truth; this script can stay in the tree as a regression
aid (extract again, diff, confirm round-trip).
"""

import os
import sys
import textwrap

THIS = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, THIS)
from _docs_components import COMPONENTS  # noqa: E402

OUT_DIR = os.path.join(THIS, '_docs_components')


def write_component(slug, title, lede, install, sections):
    lines = [
        '---',
        f'slug: {slug}',
        f'title: {title}',
    ]
    if install:
        lines.append(f'install: {install}')
    lines.append('---')
    lines.append('')
    # Lede is raw HTML. Stored verbatim — markdown allows inline HTML.
    lines.append(lede.rstrip())
    lines.append('')

    for heading, body, snippet in sections:
        lines.append(f'## {heading}')
        lines.append('')
        if body:
            # Body is raw HTML. Preserve verbatim. One block per "paragraph",
            # separated by a blank line so the loader can reassemble cleanly
            # (without crushing newlines inside <pre><code> blocks).
            for chunk in split_html_blocks(body):
                lines.append(chunk)
                lines.append('')

        if snippet:
            filename = snippet[0]
            code = snippet[1]
            runnable = len(snippet) < 3 or snippet[2]
            # If the snippet itself contains a triple-backtick run, lengthen
            # the fence so it can't terminate early (CommonMark: the closing
            # fence must be ≥ the opening fence length).
            fence = '```'
            while fence in code:
                fence += '`'
            lines.append('<!-- snippet:')
            lines.append(f'filename: {filename}')
            lines.append(f'runnable: {"true" if runnable else "false"}')
            lines.append('-->')
            lines.append(f'{fence}php')
            lines.append(code.rstrip('\n'))
            lines.append(fence)
            lines.append('')

    out = '\n'.join(lines).rstrip() + '\n'
    path = os.path.join(OUT_DIR, f'{slug}.md')
    with open(path, 'w', encoding='utf-8') as f:
        f.write(out)
    return path


def split_html_blocks(html):
    """Break a flat HTML body into top-level blocks for prettier markdown.

    The Python source typically writes body content as
    ``'<p>line1</p>'  '<p>line2</p>'`` with no separator, so the file ends
    up holding one long string. Splitting at end-tag boundaries keeps each
    paragraph/list/etc. on its own line in the markdown output.
    """
    import re

    # Top-level block elements as used in the catalog.
    pattern = re.compile(
        r'(<(?:p|ul|ol|pre|blockquote|table|h[1-6]|div)\b[^>]*>.*?</(?:p|ul|ol|pre|blockquote|table|h[1-6]|div)>)',
        re.DOTALL | re.IGNORECASE,
    )
    parts = pattern.split(html)
    blocks = []
    for part in parts:
        if not part.strip():
            continue
        blocks.append(part.strip())
    return blocks


def main():
    os.makedirs(OUT_DIR, exist_ok=True)
    written = []
    for slug, title, lede, install, sections in COMPONENTS:
        path = write_component(slug, title, lede, install, sections)
        written.append((slug, path))
    print(f'Extracted {len(written)} components to {OUT_DIR}/')
    for slug, path in written:
        print(f'  {slug:<20} {os.path.relpath(path)}')


if __name__ == '__main__':
    main()
