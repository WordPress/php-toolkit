#!/usr/bin/env python3
"""One-shot tool: dumps the current bin/_docs_components.py + sibling
data (CREDITS, COMPONENT_RELATIONS, _expected_outputs.json) into per-
component markdown files under bin/_docs_components/<slug>.md.

Run when migrating to or refreshing the markdown source. Each file ends
up self-describing — frontmatter for metadata, body for prose, fenced
blocks for snippets and their captured expected outputs.
"""

import json
import os
import re
import sys

THIS = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, THIS)

OUT_DIR = os.path.join(THIS, '_docs_components')
EXPECTED_PATH = os.path.join(THIS, '_expected_outputs.json')


def load_sources():
    """Pull legacy data straight from the original Python module so this
    script can be re-run after content updates. Falls back to importing
    only what's still defined."""
    from _docs_components import COMPONENTS  # noqa: E402
    try:
        from _docs_components import CREDITS
    except ImportError:
        CREDITS = {}
    try:
        from _docs_components import COMPONENT_RELATIONS
    except ImportError:
        COMPONENT_RELATIONS = {}
    expected = {}
    if os.path.exists(EXPECTED_PATH):
        with open(EXPECTED_PATH) as f:
            for k, v in json.load(f).items():
                slug, _, fname = k.partition('::')
                expected[(slug, fname)] = v
    return COMPONENTS, CREDITS, COMPONENT_RELATIONS, expected


def split_html_blocks(html):
    """Break a flat HTML body into top-level blocks for prettier markdown."""
    pattern = re.compile(
        r'(<(?:p|ul|ol|pre|blockquote|table|h[1-6]|div|aside)\b[^>]*>.*?</(?:p|ul|ol|pre|blockquote|table|h[1-6]|div|aside)>)',
        re.DOTALL | re.IGNORECASE,
    )
    parts = pattern.split(html)
    return [p.strip() for p in parts if p.strip()]


def write_component(slug, title, lede, install, sections, credit, see_also, expected):
    lines = [
        '---',
        f'slug: {slug}',
        f'title: {title}',
    ]
    if install:
        lines.append(f'install: {install}')
    if credit:
        credit_title, credit_body = credit
        lines.append('')
        lines.append(f'credit_title: {credit_title}')
        # Multi-line block: indent every line by 2 spaces.
        lines.append('credit_body: |')
        for chunk in split_html_blocks(credit_body) or [credit_body.strip()]:
            for sub in chunk.splitlines():
                lines.append(f'  {sub}')
    if see_also:
        lines.append('')
        for rel_slug, rel_title, reason in see_also:
            lines.append(f'see_also: {rel_slug} | {rel_title} | {reason}')
    lines.append('---')
    lines.append('')
    lines.append(lede.rstrip())
    lines.append('')

    for heading, body, snippet in sections:
        lines.append(f'## {heading}')
        lines.append('')
        if body:
            for chunk in split_html_blocks(body):
                lines.append(chunk)
                lines.append('')

        if snippet:
            filename = snippet[0]
            code = snippet[1]
            runnable = len(snippet) < 3 or snippet[2]
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

            exp = expected.get((slug, filename))
            if exp is not None:
                # Pick a fence longer than any backtick run inside the output.
                exp_fence = '```'
                while exp_fence in exp:
                    exp_fence += '`'
                lines.append('<!-- expected-output -->')
                lines.append(exp_fence)
                lines.append(exp.rstrip('\n'))
                lines.append(exp_fence)
                lines.append('')

    out = '\n'.join(lines).rstrip() + '\n'
    path = os.path.join(OUT_DIR, f'{slug}.md')
    with open(path, 'w', encoding='utf-8') as f:
        f.write(out)
    return path


def main():
    os.makedirs(OUT_DIR, exist_ok=True)
    components, credits, relations, expected = load_sources()
    written = []
    for slug, title, lede, install, sections in components:
        credit = credits.get(slug)
        see_also = relations.get(slug, ())
        path = write_component(
            slug, title, lede, install, sections,
            credit, see_also, expected,
        )
        written.append((slug, path))
    print(f'Extracted {len(written)} components to {OUT_DIR}/')
    for slug, path in written:
        print(f'  {slug:<20} {os.path.relpath(path)}')


if __name__ == '__main__':
    main()
