#!/usr/bin/env bash
set -euo pipefail

# ---- Config (override with env vars) ----
DEFAULT_BRANCH="${DEFAULT_BRANCH:-trunk}"
VISIBILITY="${VISIBILITY:-public}"   # public|private|internal (internal only for orgs)
TOPICS="${TOPICS:-php,composer,monorepo,split}"
PKG_GLOB="${PKG_GLOB:-components/*}"
# If GH_ORG is unset, we'll derive it from composer "name" vendor part.
GH_ORG="${GH_ORG:-wp-php-toolkit}"
# Optional: auto-register on Packagist if you set these:
PACKAGIST_USERNAME="${PACKAGIST_USERNAME:-}"
PACKAGIST_TOKEN="${PACKAGIST_TOKEN:-}"

need() { command -v "$1" >/dev/null 2>&1 || { echo "Missing dependency: $1"; exit 1; }; }

echo "Preflight checks…"
need git
need gh
need jq
if command -v git-filter-repo >/dev/null 2>&1; then
  USE_FILTER_REPO=1
  echo "Using git-filter-repo."
else
  USE_FILTER_REPO=0
  echo "git-filter-repo not found, will fallback to git subtree (slower)."
fi

# Ensure we're on a branch and have tags fetched
git rev-parse --is-inside-work-tree >/dev/null
git fetch --tags --prune --quiet || true

create_repo_if_needed() {
  local org="$1" name="$2" desc="$3" homepage="$4"
  if gh repo view "${org}/${name}" >/dev/null 2>&1; then
    echo "Repo exists: ${org}/${name}"
  else
    echo "Creating repo: ${org}/${name}"
    local visflag="--${VISIBILITY}"
    gh repo create "${org}/${name}" ${visflag} --disable-wiki >/dev/null
    if [[ -n "$desc" ]]; then gh repo edit "${org}/${name}" --description "$desc" >/dev/null; fi
    if [[ -n "$homepage" ]]; then gh repo edit "${org}/${name}" --homepage "$homepage" >/dev/null; fi
    # Topics
    IFS=, read -ra arr <<<"$TOPICS"
    gh repo edit "${org}/${name}" --add-topic "${arr[@]}" >/dev/null || true
    # Default branch (after first push we'll set it again)
  fi
}

split_and_push() {
  local pkg_dir="$1" org="$2" repo_name="$3"
  local repo_ssh="git@github.com:${org}/${repo_name}.git"

  echo "==> Splitting ${pkg_dir} -> ${org}/${repo_name}"

  if [[ "$USE_FILTER_REPO" -eq 1 ]]; then
    local tmp
    tmp="$(mktemp -d)"
    git clone --no-hardlinks . "$tmp" >/dev/null
    pushd "$tmp" >/dev/null
      # Filter to that path and move it to repo root
      git filter-repo --force --path "$pkg_dir" --path-rename "${pkg_dir}/:" >/dev/null
      # Prefer DEFAULT_BRANCH if present; else keep current
      if git show-ref --verify --quiet "refs/heads/${DEFAULT_BRANCH}"; then
        git checkout -q "${DEFAULT_BRANCH}"
      fi
      git remote remove origin 2>/dev/null || true
      git remote add origin "$repo_ssh"
      git push -u origin --all
      git push origin --tags || true
    popd >/dev/null
    rm -rf "$tmp"
  else
    # Fallback: subtree split creates a synthetic branch with the path history
    local split_branch="split-$(basename "$pkg_dir")-$(date +%s)"
    git subtree split --prefix="$pkg_dir" -b "$split_branch" >/dev/null
    git push "$repo_ssh" "$split_branch:${DEFAULT_BRANCH}"
    # Push tags that include this history is non-trivial with subtree; skipping here
    git branch -D "$split_branch" >/dev/null
  fi

  # Set default branch explicitly
  gh repo edit "${org}/${repo_name}" --default-branch "${DEFAULT_BRANCH}" >/dev/null || true
}

register_packagist() {
  local org="$1" repo="$2"
  [[ -n "$PACKAGIST_USERNAME" && -n "$PACKAGIST_TOKEN" ]] || return 0
  local url="https://github.com/${org}/${repo}"
  echo "Registering on Packagist: ${url}"
  curl -fsS -X POST \
    "https://packagist.org/api/create-package?username=${PACKAGIST_USERNAME}&apiToken=${PACKAGIST_TOKEN}" \
    -H 'Content-Type: application/json' \
    -d "{\"repository\":{\"url\":\"${url}\"}}" >/dev/null || echo "Packagist create-package call failed (maybe already exists)."
}

update_packagist() {
  local org="$1" repo="$2"
  [[ -n "$PACKAGIST_USERNAME" && -n "$PACKAGIST_TOKEN" ]] || return 0
  local package_name="${org}/${repo}"
  echo "Updating Packagist package: ${package_name}"
  curl -fsS -X POST \
    "https://packagist.org/api/update-package?username=${PACKAGIST_USERNAME}&apiToken=${PACKAGIST_TOKEN}" \
    -H 'Content-Type: application/json' \
    -d "{\"repository\":{\"url\":\"https://github.com/${org}/${repo}\"}}" >/dev/null || echo "Packagist update-package call failed."
}

# Iterate packages
shopt -s nullglob
for composer in ${PKG_GLOB}/composer.json; do
  pkg_dir="$(dirname "$composer")"
  name="$(jq -r '.name // empty' "$composer")"
  desc="$(jq -r '.description // empty' "$composer")"
  homepage="$(jq -r '.homepage // empty' "$composer")"

  if [[ -z "$name" || "$name" == "null" ]]; then
    echo "Skipping ${pkg_dir}: missing composer name."
    continue
  fi

  vendor="${name%%/*}"
  pkg="${name#*/}"

  org="${GH_ORG:-$vendor}"
  repo_name="$pkg"

  create_repo_if_needed "$org" "$repo_name" "$desc" "$homepage"
  split_and_push "$pkg_dir" "$org" "$repo_name"
  register_packagist "$org" "$repo_name"
  update_packagist "$org" "$repo_name"

  echo ""
done

echo "Done."
