#!/usr/bin/env bash
set -euo pipefail
need() { command -v "$1" >/dev/null || { echo "Missing: $1" >&2; exit 1; }; }
need jq; need composer; need php

ARTDIR="$(mktemp -d)"
WORKROOT="$(mktemp -d)"
smoke_php='<?php require __DIR__."/vendor/autoload.php"; echo "autoload ok\n";'

test_pkg() {
  local pkgdir="$1"
  local cj="$pkgdir/composer.json"
  [[ -f "$cj" ]] || { echo "No composer.json in $pkgdir"; return 1; }
  local name version
  name="$(jq -r '.name' "$cj")"
  version="$(jq -r '.version // "0.1.0"' "$cj")"

  (cd "$pkgdir" && composer validate --no-check-publish && composer archive --format=zip --dir="$ARTDIR" >/dev/null)

  local proj="$WORKROOT/$(basename "$pkgdir")-test"
  mkdir -p "$proj"
  cat > "$proj/composer.json" <<JSON
{
  "name": "prepub/smoke",
  "type": "project",
  "repositories": [{ "type": "artifact", "url": "$ARTDIR" }],
  "require": { "$name": "$version" }
}
JSON
  (cd "$proj" && composer install --no-interaction --no-progress --prefer-dist)
  echo "$smoke_php" > "$proj/smoke.php"
  php "$proj/smoke.php" >/dev/null
  echo "✓ $name installs and autoloads"
}

if [[ $# -eq 0 ]]; then echo "Usage: $0 packages/foo [components/bar ...]"; exit 1; fi
for d in "$@"; do test_pkg "$d"; done
