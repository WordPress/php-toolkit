#!/usr/bin/env bash
set -euo pipefail
if [[ -z "${PACKAGIST_USERNAME:-}" || -z "${PACKAGIST_TOKEN:-}" ]]; then
  echo "Set PACKAGIST_USERNAME and PACKAGIST_TOKEN" >&2; exit 1
fi
for repo in "$@"; do
  url="https://github.com/${repo}.git"
  curl -fsS -X POST \
    "https://packagist.org/api/update-package?username=${PACKAGIST_USERNAME}&apiToken=${PACKAGIST_TOKEN}" \
    -H 'Content-Type: application/json' \
    -d "{\"repository\":{\"url\":\"${url}\"}}"
  echo "Queued Packagist update: $repo"
done
