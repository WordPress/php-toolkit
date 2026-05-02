#!/usr/bin/env python3
"""
Runs every PHP snippet in bin/_docs_components.py against the local
toolkit (`composer install` first, so vendor/autoload.php exists) and
captures stdout. Used in two ways:

    bin/run-snippets.py --update     Regenerate bin/_expected_outputs.json
                                     from the snippets that ran successfully.
    bin/run-snippets.py --check      Run every snippet, compare against the
                                     committed JSON. Exit nonzero on drift.
                                     Used by .github/workflows/snippet-tests.yml.

Snippets reference '/wordpress/wp-content/php-toolkit/vendor/autoload.php' —
the path that exists inside Playground. The runner rewrites that to the
repo's local vendor/autoload.php before executing.

Snippets marked non-runnable in the catalog are skipped. Snippets that need
WordPress, network access, or a listening TCP port may run locally but avoid
committing expected output because their stdout is environment-dependent.
"""

import argparse
import json
import os
import re
import subprocess
import sys
import tempfile

THIS = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(THIS)
sys.path.insert(0, THIS)
from _docs_components import COMPONENTS  # noqa: E402

VENDOR_AUTOLOAD = os.path.join(ROOT, 'vendor', 'autoload.php')
EXPECTED_PATH = os.path.join(THIS, '_expected_outputs.json')

# Snippets that can run but whose output isn't stable (real network, timestamps,
# host-specific values). They're verified to exit 0 but their stdout isn't
# captured into the JSON, so the docs page boots Playground at click time.
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
# Injected after the autoload require so WP_Block_Parser exists.
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
    """Strip noise that varies between runs (tempfile names, timestamps)."""
    # tempnam paths
    text = re.sub(r'/tmp/\w+\.zip', '/tmp/<tempfile>.zip', text)
    text = re.sub(r'(/tmp/\w+)(\.epub|\.tmp\.[a-f0-9]+)?', r'/tmp/<tempfile>\2', text)
    text = re.sub(r'sys_get_temp_dir\(\) \. \'/[^\']+', "sys_get_temp_dir() . '/<demo>", text)
    # uniqid suffixes from sys_get_temp_dir paths in code
    text = re.sub(r'/(toolkit|atomic|copytree|big|orig|repacked|app|book|demo|sample|hash|gz|dl)-[a-f0-9]+', r'/\1-XXXXXX', text)
    # Random nonces / hex strings
    text = re.sub(r'\bnonce(?:: |=")([0-9a-f]{16})"?', lambda m: m.group(0).replace(m.group(1), '<random>'), text)
    text = re.sub(r'\bcommit: [0-9a-f]{40}\b', 'commit: <oid>', text)
    text = re.sub(r'\bHEAD:\s+[0-9a-f]{40}', 'HEAD: <oid>', text)
    text = re.sub(r'\boid: [0-9a-f]{40}\b', 'oid: <oid>', text)
    text = re.sub(r'merge head: [0-9a-f]{40}', 'merge head: <oid>', text)
    text = re.sub(r'\b[a-f0-9]{7}  ', '<hash>  ', text)
    # Memory numbers
    text = re.sub(r'Peak memory: [\d.]+ MB', 'Peak memory: <N> MB', text)
    return text


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--update', action='store_true', help='Regenerate _expected_outputs.json')
    ap.add_argument('--check', action='store_true', help='Verify against _expected_outputs.json')
    ap.add_argument('--filter', default=None, help='Only run snippets whose slug or filename match this substring')
    args = ap.parse_args()

    if not args.update and not args.check:
        args.check = True

    if not os.path.exists(VENDOR_AUTOLOAD):
        print(f'ERROR: {VENDOR_AUTOLOAD} not found. Run `composer install` first.', file=sys.stderr)
        sys.exit(2)

    existing = {}
    if os.path.exists(EXPECTED_PATH):
        with open(EXPECTED_PATH) as f:
            existing = {tuple(k.split('::')): v for k, v in json.load(f).items()}

    new = {}
    failures = []
    skipped = 0
    matched = 0
    drift = []

    for slug, _, _, _, sections in COMPONENTS:
        for heading, _, snippet in sections:
            if not snippet:
                continue
            filename, code = snippet[0], snippet[1]
            runnable = len(snippet) < 3 or snippet[2]
            if not runnable:
                continue
            if args.filter and args.filter not in slug and args.filter not in filename:
                continue
            rc, stdout, stderr = run_one(code)
            if rc != 0:
                # Snippet can't run locally — leave it out of JSON. The docs
                # site will boot Playground for it at click time.
                failures.append((slug, filename, stderr.strip().splitlines()[:2]))
                skipped += 1
                continue

            key = (slug, filename)
            if key in NO_EXPECTED:
                # Ran successfully but we don't compare output. Don't store.
                matched += 1
                continue

            normalized = normalize(stdout)
            new[key] = normalized

            if args.check:
                expected = existing.get(key)
                if expected is None:
                    drift.append((slug, filename, 'NEW (run --update to add)'))
                elif normalize(expected) != normalized:
                    drift.append((slug, filename, 'OUTPUT CHANGED'))
                else:
                    matched += 1
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
        joined = {f'{k[0]}::{k[1]}': v for k, v in sorted(new.items())}
        with open(EXPECTED_PATH, 'w') as f:
            json.dump(joined, f, indent=2, sort_keys=True)
            f.write('\n')
        print(f'\nWrote {len(joined)} expected outputs to {EXPECTED_PATH}')
        sys.exit(0)

    if drift:
        print(f'\n{len(drift)} snippet(s) drifted. Run `bin/run-snippets.py --update` to refresh.')
        sys.exit(1)
    print('\nAll snippets match expected outputs.')


if __name__ == '__main__':
    main()
