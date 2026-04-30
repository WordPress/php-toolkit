#!/usr/bin/env bash
#
# Smoke-tests every published wp-php-toolkit/* Packagist package at the given
# version. For each package we install it into a fresh scratch dir with
# composer require, then walk every class/interface/trait declared in the
# downloaded files and verify the autoloader can reach it.
#
# Catches exactly the kind of regressions we have shipped before:
#   - autoload.files pointing at a non-existent path (v0.7.0 zip)
#   - sibling-component requires written for the monorepo layout
#     (v0.7.0 polyfill)
#   - package data files (HTML char references) never loaded standalone
#   - PSR-4 declarations against WP-style class-*.php filenames (Zip, Git, ...)
#
# Usage:
#   bin/smoke-published-packages.sh <version>
#
# Example:
#   bin/smoke-published-packages.sh 0.7.2
#
# The version is the bare semver (no leading "v"), matching what Packagist
# serves. The script polls Packagist briefly because update-package webhooks
# can lag a release tag by a minute or two.

set -euo pipefail

if [[ $# -lt 1 ]]; then
  echo "usage: $0 <version>" >&2
  exit 2
fi

VERSION="${1#v}"

# All Packagist package names under wp-php-toolkit/. Keep in sync with
# components/* — bin/split-code.sh is the source of truth for what gets
# published.
PACKAGES=(
  blockparser
  blueprints
  bytestream
  cli
  corsproxy
  data-liberation
  encoding
  filesystem
  git
  html
  http-client
  http-server
  markdown
  merge
  polyfill
  xml
  zip
)

REPO_ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
CHECK_SCRIPT="${REPO_ROOT}/bin/check-package-autoload.php"
COEXIST_SCRIPT="${REPO_ROOT}/bin/check-wp-coexistence.php"

for f in "${CHECK_SCRIPT}" "${COEXIST_SCRIPT}"; do
  if [[ ! -f "${f}" ]]; then
    echo "missing ${f}" >&2
    exit 2
  fi
done

# Wait for Packagist to expose the new version. The publish workflow calls
# the update-package webhook, but indexing can take a moment.
wait_for_packagist() {
  local pkg="$1" attempt
  for attempt in 1 2 3 4 5 6 7 8 9 10; do
    # Packagist stores versions with a leading `v`, so match either form.
    if curl -fsS "https://repo.packagist.org/p2/wp-php-toolkit/${pkg}.json" \
         | grep -Eq "\"version\":\"v?${VERSION}\""; then
      return 0
    fi
    echo "  waiting for wp-php-toolkit/${pkg} ${VERSION} on Packagist (attempt ${attempt}/10)"
    sleep 12
  done
  return 1
}

failed=()
for pkg in "${PACKAGES[@]}"; do
  echo "==> wp-php-toolkit/${pkg} @ ${VERSION}"

  if ! wait_for_packagist "${pkg}"; then
    echo "  FAIL: ${VERSION} never appeared on Packagist for wp-php-toolkit/${pkg}"
    failed+=( "${pkg}" )
    continue
  fi

  scratch="$( mktemp -d )"
  pushd "${scratch}" >/dev/null

  composer init --no-interaction --name="smoke/${pkg}" --stability=stable --quiet

  # Some packages depend on `dev-trunk` of related repos that don't exist
  # post-split. Allow stable resolution to fail so we at least report it
  # rather than aborting the whole sweep.
  if ! composer require --no-interaction --quiet "wp-php-toolkit/${pkg}:${VERSION}" 2>"${scratch}/install.err"; then
    echo "  FAIL: composer require"
    sed 's/^/    /' "${scratch}/install.err"
    failed+=( "${pkg}" )
    popd >/dev/null
    rm -rf "${scratch}"
    continue
  fi

  if ! php "${CHECK_SCRIPT}" "wp-php-toolkit/${pkg}" "vendor/wp-php-toolkit/${pkg}"; then
    failed+=( "${pkg}" )
  elif ! php "${COEXIST_SCRIPT}"; then
    # Even if every class autoloads, the package can still break consumers
    # that boot WordPress: any WP-core class declared at composer-bootstrap
    # time will fatal when WP later loads its own copy.
    failed+=( "${pkg}" )
  fi

  popd >/dev/null
  rm -rf "${scratch}"
done

echo
if [[ ${#failed[@]} -eq 0 ]]; then
  echo "All ${#PACKAGES[@]} packages OK at ${VERSION}"
  exit 0
fi

echo "FAILED (${#failed[@]}/${#PACKAGES[@]}): ${failed[*]}"
exit 1
