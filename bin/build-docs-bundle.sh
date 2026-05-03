#!/usr/bin/env bash
# Rebuilds docs/assets/php-toolkit.zip and regenerates the docs HTML pages
# from each components/<Name>/README.md. Run this whenever a component's
# README.md or its source changes.
set -euo pipefail

cd "$(dirname "$0")/.."

echo "==> composer install --no-dev --optimize-autoloader"
composer install --no-dev --optimize-autoloader --quiet

echo "==> bundling docs/assets/php-toolkit.zip"
rm -f docs/assets/php-toolkit.zip
zip -qr docs/assets/php-toolkit.zip components vendor bootstrap.php composer.json \
  -x "*/Tests/*" "*/tests/*" "*/.git/*" "*/.github/*" "*/node_modules/*"

echo "==> regenerating docs/reference/*.html from markdown"
python3 bin/build-reference.py

echo "Done. docs/assets/php-toolkit.zip = $(du -h docs/assets/php-toolkit.zip | cut -f1)"
