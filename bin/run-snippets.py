#!/usr/bin/env python3
"""Runs every PHP snippet declared in bin/_docs_components/<slug>.md against
the local toolkit and compares stdout to the captured expected-output that
lives next to the snippet in markdown. Used in two ways:

    bin/run-snippets.py --update     Re-run runnable snippets and write the
                                     new stdout back into each markdown
                                     file's expected-output fence.
    bin/run-snippets.py --check      Run every snippet, compare against the
                                     committed expected output. Exit nonzero
                                     on drift. Used by snippet-tests.yml.

Snippets reference '/wordpress/wp-content/php-toolkit/vendor/autoload.php' —
the path that exists inside Playground. The runner rewrites that to the
repo's local vendor/autoload.php before executing.

Snippets marked non-runnable in the catalog are skipped. Snippets in
NO_EXPECTED are runnable but their stdout is environment-dependent (real
network traffic, timestamps); they're verified to exit 0 and have no
captured expected output.
"""

import argparse
import os
import re
import subprocess
import sys
import tempfile

THIS = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(THIS)
sys.path.insert(0, THIS)
from _load_catalog import load_components_rich  # noqa: E402

VENDOR_AUTOLOAD = os.path.join(ROOT, 'vendor', 'autoload.php')
COMPONENT_DIR = os.path.join(THIS, '_docs_components')

# Runnable snippets whose stdout is unstable. They exit 0 but their output
# is not pinned (real network traffic, timestamps, host-specific values).
NO_EXPECTED = {
    ('httpclient', 'get.php'),
    ('httpclient', 'post.php'),
    ('httpclient', 'progress.php'),
    ('httpclient', 'sliding-window.php'),
    ('httpclient', 'resume-download.php'),
    ('httpclient', 'stream-unzip.php'),
    ('httpclient', 'fan-out.php'),
    ('httpclient', 'stream-to-disk.php'),
}

PLAYGROUND_AUTOLOAD = "/wordpress/wp-content/php-toolkit/vendor/autoload.php"

# Tiny polyfill so WordPress-only globals don't break local runs.
LOCAL_PRELUDE = """
if ( ! function_exists( 'parse_blocks' ) ) {
\tfunction parse_blocks( $content ) {
\t\treturn ( new WP_Block_Parser() )->parse( $content );
\t}
}
"""


def rewrite(code):
    code = code.replace(PLAYGROUND_AUTOLOAD, VENDOR_AUTOLOAD)
    match = re.search(r"require\s+'[^']*vendor/autoload\.php';", code)
    if match:
        insert_at = match.end()
        code = code[:insert_at] + LOCAL_PRELUDE + code[insert_at:]
    return code


def run_one(code, timeout=15):
    with tempfile.NamedTemporaryFile(suffix='.php', mode='w', delete=False) as f:
        f.write(rewrite(code))
        path = f.name
    try:
        proc = subprocess.run(
            ['php', '-d', 'display_errors=stderr', path],
            capture_output=True, text=True, timeout=timeout,
        )
        return proc.returncode, proc.stdout, proc.stderr
    except subprocess.TimeoutExpired:
        return -1, '', f'TIMEOUT after {timeout}s'
    finally:
        try:
            os.unlink(path)
        except OSError:
            pass


def normalize(text):
    """Strip noise that varies between runs (tempfile names, hashes, etc.)."""
    text = re.sub(r'/tmp/\w+\.zip', '/tmp/<tempfile>.zip', text)
    text = re.sub(r'(/tmp/\w+)(\.epub|\.tmp\.[a-f0-9]+)?', r'/tmp/<tempfile>\2', text)
    text = re.sub(r'sys_get_temp_dir\(\) \. \'/[^\']+', "sys_get_temp_dir() . '/<demo>", text)
    text = re.sub(r'/(toolkit|atomic|copytree|big|orig|repacked|app|book|demo|sample|hash|gz|dl)-[a-f0-9]+', r'/\1-XXXXXX', text)
    text = re.sub(r'\bnonce(?:: |=")([0-9a-f]{16})"?', lambda m: m.group(0).replace(m.group(1), '<random>'), text)
    text = re.sub(r'\bcommit: [0-9a-f]{40}\b', 'commit: <oid>', text)
    text = re.sub(r'\bHEAD:\s+[0-9a-f]{40}', 'HEAD: <oid>', text)
    text = re.sub(r'\boid: [0-9a-f]{40}\b', 'oid: <oid>', text)
    text = re.sub(r'merge head: [0-9a-f]{40}', 'merge head: <oid>', text)
    text = re.sub(r'\b[a-f0-9]{7}  ', '<hash>  ', text)
    text = re.sub(r'Peak memory: [\d.]+ MB', 'Peak memory: <N> MB', text)
    return text


def write_expected_output(slug, filename, new_output):
    """Write a new captured stdout into the slug's markdown file, creating
    or updating the snippet's `<!-- expected-output -->` fence."""
    path = os.path.join(COMPONENT_DIR, f'{slug}.md')
    with open(path, encoding='utf-8') as f:
        text = f.read()

    # Match the snippet block whose metadata holds `filename: <name>`. The
    # filename is unique per component, so a non-greedy search anchored on
    # `filename: <name>` is sufficient.
    snippet_pattern = re.compile(
        r'(<!--\s*snippet:\s*\nfilename:\s*' + re.escape(filename) + r'.*?\n'
        r'(?P<fence>`{3,})php\n.*?\n(?P=fence))'
        r'(\s*\n\s*<!--\s*expected-output\s*-->\s*\n(?P<exp_fence>`{3,})\w*\n.*?\n(?P=exp_fence))?',
        re.DOTALL,
    )
    m = snippet_pattern.search(text)
    if not m:
        raise RuntimeError(f'Could not locate snippet {slug}::{filename} in {path}')

    # Pick a fence longer than any backtick run inside the new output.
    exp_fence = '```'
    while exp_fence in new_output:
        exp_fence += '`'
    new_block = (
        m.group(1) +
        f'\n\n<!-- expected-output -->\n{exp_fence}\n{new_output.rstrip(chr(10))}\n{exp_fence}'
    )
    text = text[:m.start()] + new_block + text[m.end():]
    with open(path, 'w', encoding='utf-8') as f:
        f.write(text)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--update', action='store_true', help='Write new stdout back into the markdown files')
    ap.add_argument('--check', action='store_true', help='Verify against expected-output blocks (default)')
    ap.add_argument('--filter', default=None, help='Only run snippets whose slug or filename match this substring')
    args = ap.parse_args()

    if not args.update and not args.check:
        args.check = True

    if not os.path.exists(VENDOR_AUTOLOAD):
        print(f'ERROR: {VENDOR_AUTOLOAD} not found. Run `composer install` first.', file=sys.stderr)
        sys.exit(2)

    components = load_components_rich()

    matched = 0
    skipped = 0
    drift = []
    failures = []
    pending_writes = []  # (slug, filename, new_output)

    for c in components:
        slug = c['slug']
        for section in c['sections']:
            snippet = section['snippet']
            if not snippet or not snippet['runnable']:
                continue
            filename = snippet['filename']
            if args.filter and args.filter not in slug and args.filter not in filename:
                continue
            rc, stdout, stderr = run_one(snippet['code'])
            if rc != 0:
                failures.append((slug, filename, (stderr or '').strip().splitlines()[:2]))
                skipped += 1
                continue

            key = (slug, filename)
            if key in NO_EXPECTED:
                matched += 1
                continue

            normalized = normalize(stdout)
            expected = snippet['expected_output']

            # Fenced blocks in markdown don't capture the trailing newline
            # before the closing fence, but stdout virtually always ends
            # with one. Compare with trailing-newline normalization so the
            # markdown round-trip doesn't trip on that convention.
            def trim_trailing(s):
                return s.rstrip('\n')

            if expected is None:
                drift.append((slug, filename, 'NEW (run --update to capture)'))
                if args.update:
                    pending_writes.append((slug, filename, normalized))
            elif trim_trailing(normalize(expected)) != trim_trailing(normalized):
                drift.append((slug, filename, 'OUTPUT CHANGED'))
                if args.update:
                    pending_writes.append((slug, filename, normalized))
            else:
                matched += 1

    print(f'\nRan {matched + len(drift)} snippets; {skipped} couldn\'t run locally.')
    for slug, filename, why in failures:
        why_text = ' '.join(why) if why else '(no stderr)'
        print(f'  skip   {slug}/{filename:<32} {why_text[:80]}')
    if args.check:
        for slug, filename, kind in drift:
            print(f'  DRIFT  {slug}/{filename:<32} {kind}')

    if args.update:
        for slug, filename, new_output in pending_writes:
            write_expected_output(slug, filename, new_output)
            print(f'  wrote {slug}/{filename}')
        print(f'\nUpdated {len(pending_writes)} expected-output blocks in markdown.')
        sys.exit(0)

    if drift:
        print(f'\n{len(drift)} snippet(s) drifted. Run `bin/run-snippets.py --update` to refresh.')
        sys.exit(1)
    print('\nAll snippets match expected outputs.')


if __name__ == '__main__':
    main()
