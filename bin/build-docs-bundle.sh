#!/usr/bin/env bash
# Rebuilds docs/assets/php-toolkit.zip and regenerates the docs HTML pages.
# Run this whenever components/ changes or the docs page generator (bin/build-docs.py)
# changes.
set -euo pipefail

cd "$(dirname "$0")/.."

echo "==> composer install --no-dev --optimize-autoloader"
composer install --no-dev --optimize-autoloader --quiet

echo "==> bundling docs/assets/php-toolkit.zip"
rm -f docs/assets/php-toolkit.zip
zip -qr docs/assets/php-toolkit.zip components vendor bootstrap.php composer.json \
  -x "*/Tests/*" "*/tests/*" "*/.git/*" "*/.github/*" "*/node_modules/*"

echo "==> generating docs/*/index.html"
python3 bin/build-docs.py

echo "Done. docs/assets/php-toolkit.zip = $(du -h docs/assets/php-toolkit.zip | cut -f1)"
