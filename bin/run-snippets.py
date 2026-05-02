#!/usr/bin/env python3
"""
Verifies the PHP snippets advertised by the docs site against the local
toolkit. Used in two ways:

    bin/run-snippets.py --update     Regenerate bin/_expected_outputs.json
                                     from snippets that ran successfully.
    bin/run-snippets.py --check      Run/syntax-check every snippet, compare
                                     against the committed JSON. Exit nonzero
                                     on any drift, runtime failure, or syntax
                                     error. Used by snippet-tests.yml.

How a snippet is verified depends on its catalog flags:

  - runnable=True  (default): executed end-to-end with `php -d
    display_errors=stderr`. Output is normalized and compared against
    `_expected_outputs.json`. A non-zero exit, a missing entry, or an
    output diff fails the run. Snippets in NO_EXPECTED are still required
    to exit zero, but their stdout is not pinned (they have unstable
    output: real network traffic, timestamps, host-specific values).

  - runnable=False: not executed (the snippet needs a listening port,
    a daemon, or some other non-CLI environment). It is still validated
    with `php -l`, so syntax errors fail the run.

There is no silent-skip path: every snippet is verified one way or the
other.

Snippets reference '/wordpress/wp-content/php-toolkit/vendor/autoload.php' —
the path that exists inside Playground. The runner rewrites that to the
repo's local vendor/autoload.php before executing.
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

# Snippets that run successfully but whose stdout is not stable. They are
# verified to exit 0; output is not pinned. Add an entry only when the
# snippet does real network I/O, depends on time, or otherwise produces
# host-specific output that can't be reproduced byte-for-byte in CI.
NO_EXPECTED = {
    ('httpclient', 'get.php'):              'real network response',
    ('httpclient', 'post.php'):             'real network response',
    ('httpclient', 'progress.php'):         'real network + progress timing',
    ('httpclient', 'sliding-window.php'):   'real network response',
    ('httpclient', 'resume-download.php'):  'real network response',
    ('httpclient', 'stream-unzip.php'):     'real network response',
    ('httpclient', 'fan-out.php'):          'real network response',
    ('httpclient', 'stream-to-disk.php'):   'real network response',
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


def lint_one(code):
    """Run `php -l` against a snippet (after autoload-path rewrite). Returns
    (rc, stderr) — rc 0 means valid syntax."""
    with tempfile.NamedTemporaryFile(suffix='.php', mode='w', delete=False) as f:
        f.write(rewrite(code))
        path = f.name
    try:
        proc = subprocess.run(
            ['php', '-l', path],
            capture_output=True, text=True, timeout=10,
        )
        return proc.returncode, proc.stderr or proc.stdout
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


def iter_snippets():
    """Yield (slug, filename, code, runnable) for every snippet in the catalog."""
    for slug, _, _, _, sections in COMPONENTS:
        for heading, _, snippet in sections:
            if not snippet:
                continue
            filename, code = snippet[0], snippet[1]
            runnable = len(snippet) < 3 or snippet[2]
            yield slug, filename, code, runnable


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--update', action='store_true', help='Regenerate _expected_outputs.json')
    ap.add_argument('--check', action='store_true', help='Verify against _expected_outputs.json (default)')
    ap.add_argument('--filter', default=None, help='Only run snippets whose slug or filename contain this substring')
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
    matched = 0
    syntax_only = 0
    runtime_failures = []   # snippet expected to run, blew up
    syntax_failures = []    # any snippet, php -l failed
    output_drift = []       # output changed or missing in JSON

    for slug, filename, code, runnable in iter_snippets():
        if args.filter and args.filter not in slug and args.filter not in filename:
            continue
        key = (slug, filename)

        if not runnable:
            rc, msg = lint_one(code)
            if rc != 0:
                syntax_failures.append((slug, filename, msg.strip().splitlines()[:3]))
            else:
                syntax_only += 1
            continue

        rc, stdout, stderr = run_one(code)
        if rc != 0:
            # Distinguish syntax errors from runtime errors so the message is
            # useful, but both are hard failures for a snippet that's
            # advertised as runnable.
            lint_rc, lint_msg = lint_one(code)
            if lint_rc != 0:
                syntax_failures.append((slug, filename, lint_msg.strip().splitlines()[:3]))
            else:
                runtime_failures.append((slug, filename, (stderr or '(no stderr)').strip().splitlines()[:3]))
            continue

        if key in NO_EXPECTED:
            matched += 1
            continue

        normalized = normalize(stdout)
        new[key] = normalized

        if args.check:
            expected = existing.get(key)
            if expected is None:
                output_drift.append((slug, filename, 'NEW (run --update to add)'))
            elif normalize(expected) != normalized:
                output_drift.append((slug, filename, 'OUTPUT CHANGED'))
            else:
                matched += 1
        else:
            matched += 1

    total = matched + syntax_only + len(runtime_failures) + len(syntax_failures) + len(output_drift)
    print(f'\nChecked {total} snippets:')
    print(f'  {matched} runnable, output verified')
    print(f'  {syntax_only} non-runnable, syntax verified')
    if syntax_failures:
        print(f'  {len(syntax_failures)} SYNTAX FAILURES:')
        for slug, filename, msg in syntax_failures:
            text = ' / '.join(msg) if msg else '(no message)'
            print(f'    {slug}/{filename:<32} {text[:120]}')
    if runtime_failures:
        print(f'  {len(runtime_failures)} RUNTIME FAILURES:')
        for slug, filename, msg in runtime_failures:
            text = ' / '.join(msg) if msg else '(no stderr)'
            print(f'    {slug}/{filename:<32} {text[:120]}')
    if output_drift and args.check:
        print(f'  {len(output_drift)} OUTPUT DRIFT:')
        for slug, filename, kind in output_drift:
            print(f'    {slug}/{filename:<32} {kind}')

    if args.update:
        joined = {f'{k[0]}::{k[1]}': v for k, v in sorted(new.items())}
        with open(EXPECTED_PATH, 'w') as f:
            json.dump(joined, f, indent=2, sort_keys=True)
            f.write('\n')
        print(f'\nWrote {len(joined)} expected outputs to {EXPECTED_PATH}')
        if syntax_failures or runtime_failures:
            sys.exit(1)
        sys.exit(0)

    if syntax_failures or runtime_failures or output_drift:
        if output_drift:
            print('\nFor output drift only: run `bin/run-snippets.py --update` to refresh expected outputs.')
        sys.exit(1)
    print('\nAll snippets verified.')


if __name__ == '__main__':
    main()
