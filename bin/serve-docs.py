#!/usr/bin/env python3
"""
Local dev server for docs/. Adds CORS headers so the WordPress Playground
iframe can fetch docs/assets/php-toolkit.zip across origins.

GitHub Pages serves Access-Control-Allow-Origin: * by default, so this
server is only needed for `python3 -m http.server`-equivalent local previews.

Usage:
    python3 bin/serve-docs.py [port]
"""

import http.server
import os
import sys

PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8787
DOCS = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'docs')


class CorsHandler(http.server.SimpleHTTPRequestHandler):
    def end_headers(self):
        self.send_header('Access-Control-Allow-Origin', '*')
        self.send_header('Access-Control-Allow-Headers', '*')
        super().end_headers()


os.chdir(DOCS)

# Reference pages and the Playground bundle are build artifacts (gitignored).
# Remind the user to regenerate them if they're missing.
missing = []
if not os.path.exists('reference/html.html'):
    missing.append('python3 bin/build-reference.py')
if not os.path.exists('assets/php-toolkit.zip'):
    missing.append('bash bin/build-docs-bundle.sh')
if missing:
    print('Missing build artifacts. Run from the repo root first:')
    for cmd in missing:
        print(f'  {cmd}')
    print()

print(f'Serving {DOCS} on http://localhost:{PORT}/')
http.server.ThreadingHTTPServer(('', PORT), CorsHandler).serve_forever()
